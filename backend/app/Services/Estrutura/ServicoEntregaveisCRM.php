<?php

namespace App\Services\Estrutura;

use App\Models\AtividadeComercialCRM;
use App\Models\Colaborador;
use App\Models\EntregavelTarefaUnidade;
use App\Models\ExecucaoEntregavel;
use App\Models\OportunidadeVendaCRM;
use App\Services\CRM\RegrasCRM;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ponte CRM → entregáveis das unidades (Fase J, ADR-078 — js/modules/estrutura/entregaveis_crm.js). Uma PONTE, não um modelo
 * novo: o entregável marca-se `origem_dados = CRM` com `crm_filtro` {tipos[], funil_vendas_crm_id, so_ganhas} e cada actividade
 * CONCLUÍDA que cumpre o filtro vale UMA entrega (quantidade 1, data = concluída em, origem CRM, chave `CRM|<entregável>|<actividade>`),
 * que nasce PENDENTE como qualquer outra: concluir no CRM não é, por si, autorização para pagar. O colaborador vem do utilizador
 * que concluiu (senão o responsável) → utilizador_empresa.colaborador_id.
 * `sincronizar` cria, corrige datas e retira as órfãs (actividade reaberta, apagada ou já fora do filtro) SÓ se ainda PENDENTES e
 * não convertidas; as validadas/convertidas ficam presas e são reportadas. Os ganchos chamados pelo CRM nunca impedem a operação
 * do CRM: qualquer falha fica no log.
 */
final class ServicoEntregaveisCRM
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoEntregaveis $entregaveis,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /** nome de utilizador (minúsculas) → colaborador_id, na empresa activa (mapaUtilizadorColaborador, :36). */
    public function mapaUtilizadorColaborador(): array
    {
        return DB::table('utilizador_empresa as ue')->join('utilizadores as u', 'u.id', '=', 'ue.utilizador_id')->where('ue.empresa_id', $this->contexto->obrigatorio())
            ->whereNotNull('ue.colaborador_id')->get(['u.nome_utilizador', 'ue.colaborador_id'])
            ->mapWithKeys(fn ($l) => [mb_strtolower((string) $l->nome_utilizador) => (int) $l->colaborador_id])->all();
    }

    /** @return array{tipos: list<string>, funil_vendas_crm_id: ?int, so_ganhas: bool} */
    public static function filtroDe(EntregavelTarefaUnidade $e): array
    {
        $f = (array) ($e->crm_filtro ?? []);

        return ['tipos' => array_values(array_filter(array_map('strval', (array) ($f['tipos'] ?? [])))), 'funil_vendas_crm_id' => ! empty($f['funil_vendas_crm_id']) ? (int) $f['funil_vendas_crm_id'] : null,
            'so_ganhas' => (bool) ($f['so_ganhas'] ?? false)];
    }

    /** A actividade cumpre o filtro (sem olhar à conclusão — `serve`, :48)? */
    public static function cumpreFiltro(AtividadeComercialCRM $a, array $filtro, ?OportunidadeVendaCRM $o): bool
    {
        if ($filtro['tipos'] && ! in_array((string) $a->tipo, $filtro['tipos'], true)) {
            return false;
        }
        if ($filtro['funil_vendas_crm_id'] && (! $o || (int) $o->funil_vendas_crm_id !== $filtro['funil_vendas_crm_id'])) {
            return false;
        }
        if ($filtro['so_ganhas'] && (! $o || $o->estado !== 'GANHA')) {
            return false;
        }

        return true;
    }

    public static function chave(int $entregavel, int $actividade): string
    {
        return "CRM|{$entregavel}|{$actividade}";
    }

    private static function dataDe(AtividadeComercialCRM $a): ?string
    {
        $d = $a->concluida_em?->toDateString() ?? $a->data_prevista?->toDateString();

        return $d ? min($d, now()->toDateString()) : null;
    }

    /**
     * Estado da ponte para um entregável CRM (estado, :116): previstas (todas as que o filtro apanha), feitas, por fazer (sem
     * execução), órfãs (execução cuja actividade já não cumpre / foi apagada / não concluída — removíveis ou presas), por
     * actualizar (data diferente) e sem colaborador (não há utilizador ligado).
     *
     * @return array<string, mixed>
     */
    public function analisar(EntregavelTarefaUnidade $e, ?string $de = null, ?string $ate = null): array
    {
        if ($e->origem_dados !== 'CRM') {
            return ['entregavel_id' => (int) $e->id, 'origem_dados' => $e->origem_dados, 'previstas' => 0, 'feitas' => 0, 'por_fazer' => [], 'por_criar' => [], 'orfas_removiveis' => [], 'orfas_presas' => [],
                'por_actualizar' => [], 'sem_colaborador' => [], 'importadas' => 0];
        }
        $filtro = self::filtroDe($e);
        $acts = AtividadeComercialCRM::query()->when($filtro['tipos'], fn ($q, $t) => $q->whereIn('tipo', $t))->get();
        $opps = OportunidadeVendaCRM::query()->whereIn('id', $acts->pluck('oportunidade_crm_id')->filter()->unique()->all() ?: [0])->get()->keyBy('id');
        $exs = ExecucaoEntregavel::query()->where('entregavel_id', $e->id)->where('origem', 'CRM')->whereNotNull('origem_chave')->get()->keyBy('origem_chave');
        $mapa = $this->mapaUtilizadorColaborador();
        $noIntervalo = fn (AtividadeComercialCRM $a) => ($d = self::dataDe($a)) && (! $de || $d >= $de) && (! $ate || $d <= $ate);
        $minhas = $acts->filter(fn ($a) => $noIntervalo($a) && self::cumpreFiltro($a, $filtro, $a->oportunidade_crm_id ? $opps->get((int) $a->oportunidade_crm_id) : null));
        $feitas = $minhas->filter(fn ($a) => (bool) $a->concluida);
        $porFazer = $minhas->filter(fn ($a) => ! $a->concluida);
        $chavesFeitas = $feitas->mapWithKeys(fn ($a) => [self::chave((int) $e->id, (int) $a->id) => $a]);
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', array_values(array_unique($mapa)) ?: [0])->pluck('nome_completo', 'id');
        $linha = function (AtividadeComercialCRM $a) use ($e, $opps, $mapa, $nomes) {
            $o = $a->oportunidade_crm_id ? $opps->get((int) $a->oportunidade_crm_id) : null;
            $utilizador = mb_strtolower(trim((string) ($a->concluida_por ?: $a->responsavel)));
            $colab = $mapa[$utilizador] ?? null;

            return ['actividade_id' => (int) $a->id, 'chave' => self::chave((int) $e->id, (int) $a->id), 'tipo' => $a->tipo, 'titulo' => (RegrasCRM::TIPOS_ATIVIDADE[$a->tipo] ?? $a->tipo).': '.$a->titulo,
                'data' => self::dataDe($a), 'concluida' => (bool) $a->concluida, 'utilizador' => trim((string) ($a->concluida_por ?: $a->responsavel)), 'colaborador_id' => $colab,
                'colaborador_nome' => $colab ? ($nomes[$colab] ?? "#{$colab}") : null, 'oportunidade' => $o ? ['id' => (int) $o->id, 'titulo' => $o->titulo, 'estado' => $o->estado] : null,
                'resultado' => $a->resultado];
        };
        $porCriar = $feitas->filter(fn ($a) => ! $exs->has(self::chave((int) $e->id, (int) $a->id)))->map($linha);
        $orfas = $exs->filter(fn ($x) => ! isset($chavesFeitas[$x->origem_chave]));
        $porActualizar = [];
        foreach ($exs as $x) {
            $a = $chavesFeitas[$x->origem_chave] ?? null;
            if ($a && ($nova = self::dataDe($a)) && $nova !== $x->data->toDateString() && $x->validacao === 'PENDENTE' && ! $x->convertida()) {
                $porActualizar[] = ['execucao_id' => (int) $x->id, 'actividade_id' => (int) $a->id, 'data_actual' => $x->data->toDateString(), 'data_nova' => $nova];
            }
        }
        $semColaborador = $porCriar->filter(fn ($l) => ! $l['colaborador_id'])->values()->all();
        $orfaLinha = fn ($x) => ['execucao_id' => (int) $x->id, 'origem_chave' => $x->origem_chave, 'data' => $x->data->toDateString(), 'quantidade' => (float) $x->quantidade, 'validacao' => $x->validacao,
            'convertida' => $x->convertida(), 'motivo' => $x->convertida() ? 'já convertida em produtividade' : 'já '.mb_strtolower(ExecucaoEntregavel::ROTULOS_VALIDACAO[$x->validacao] ?? $x->validacao)];

        return ['entregavel_id' => (int) $e->id, 'origem_dados' => 'CRM', 'filtro' => $filtro, 'previstas' => $minhas->count(), 'feitas' => $feitas->count(), 'importadas' => $exs->count(),
            'por_fazer' => $porFazer->map($linha)->map(fn ($l) => $l + ['atrasada' => $l['data'] !== null && $l['data'] < now()->toDateString()])->sortBy('data')->values()->all(),
            'por_criar' => $porCriar->filter(fn ($l) => $l['colaborador_id'])->sortBy('data')->values()->all(), 'sem_colaborador' => $semColaborador, 'por_actualizar' => $porActualizar,
            'orfas_removiveis' => $orfas->filter(fn ($x) => $x->validacao === 'PENDENTE' && ! $x->convertida())->map($orfaLinha)->values()->all(),
            'orfas_presas' => $orfas->filter(fn ($x) => $x->validacao !== 'PENDENTE' || $x->convertida())->map($orfaLinha)->values()->all()];
    }

    /**
     * «Actualizar»: cria o que foi concluído, corrige as datas que mudaram e retira as órfãs removíveis (sincronizar, :198).
     *
     * @return array{criadas: int, actualizadas: int, removidas: int, presas: list<array<string, mixed>>, sem_colaborador: int}
     */
    public function sincronizar(EntregavelTarefaUnidade $e, ?string $de = null, ?string $ate = null): array
    {
        $st = $this->analisar($e, $de, $ate);
        $r = ['criadas' => 0, 'actualizadas' => 0, 'removidas' => 0, 'presas' => $st['orfas_presas'], 'sem_colaborador' => count($st['sem_colaborador'])];
        DB::transaction(function () use ($e, $st, &$r) {
            foreach ($st['por_criar'] as $l) {
                $this->criarEntrega($e, $l);
                $r['criadas']++;
            }
            foreach ($st['por_actualizar'] as $u) {
                ExecucaoEntregavel::query()->whereKey($u['execucao_id'])->update(['data' => $u['data_nova'], 'atualizado_em' => now()]);
                $r['actualizadas']++;
            }
            if ($st['orfas_removiveis']) {
                $r['removidas'] = ExecucaoEntregavel::query()->whereIn('id', array_column($st['orfas_removiveis'], 'execucao_id'))->where('validacao', 'PENDENTE')->whereNull('periodo_produtividade_id')->delete();
            }
        });
        if ($r['criadas'] || $r['actualizadas'] || $r['removidas']) {
            $this->auditoria->registar(ServicoEntregaveis::MODULO_AUDITORIA, 'Actualizar entregáveis pelo CRM', "{$e->nome}: {$r['criadas']} nova(s), {$r['actualizadas']} corrigida(s), {$r['removidas']} retirada(s)",
                'entregaveis_tarefa_unidade', $e->id);
        }

        return $r;
    }

    private function criarEntrega(EntregavelTarefaUnidade $e, array $l): ExecucaoEntregavel
    {
        $obs = implode(' · ', array_filter([$l['titulo'], $l['oportunidade'] ? 'Oportunidade: '.$l['oportunidade']['titulo'] : '', (string) ($l['resultado'] ?? '')]));

        return $this->entregaveis->gravarExecucao($e, ['colaborador_id' => $l['colaborador_id'], 'quantidade' => 1, 'data' => $l['data'], 'observacoes' => mb_substr($obs, 0, 300),
            'origem_chave' => $l['chave'], 'registado_por_colaborador_id' => $l['colaborador_id']], null, 'CRM');
    }

    /** @return Collection<int, EntregavelTarefaUnidade> entregáveis CRM activos (de tarefas activas) */
    private function entregaveisCRM(): Collection
    {
        return EntregavelTarefaUnidade::query()->where('origem_dados', 'CRM')->where('ativo', true)->whereHas('tarefa', fn ($q) => $q->where('ativo', true))->get();
    }

    /** Gancho do CRM: actividade concluída → uma entrega em cada entregável CRM cujo filtro a aceita. Nunca falha para o CRM. */
    public function aoConcluirActividade(AtividadeComercialCRM $a): int
    {
        try {
            $a->refresh();
            if (! $a->concluida) {
                return 0;
            }
            $o = $a->oportunidade_crm_id ? OportunidadeVendaCRM::query()->find($a->oportunidade_crm_id) : null;
            $mapa = $this->mapaUtilizadorColaborador();
            $colab = $mapa[mb_strtolower(trim((string) ($a->concluida_por ?: $a->responsavel)))] ?? null;
            $n = 0;
            foreach ($this->entregaveisCRM() as $e) {
                if (! self::cumpreFiltro($a, self::filtroDe($e), $o) || ExecucaoEntregavel::query()->where('origem_chave', self::chave((int) $e->id, (int) $a->id))->exists()) {
                    continue;
                }
                if (! $colab) {
                    continue;   // sem utilizador ligado a um colaborador: fica por criar (aparece em «sem colaborador» na análise)
                }
                $this->criarEntrega($e, ['colaborador_id' => $colab, 'data' => self::dataDe($a), 'titulo' => (RegrasCRM::TIPOS_ATIVIDADE[$a->tipo] ?? $a->tipo).': '.$a->titulo,
                    'oportunidade' => $o ? ['titulo' => $o->titulo] : null, 'resultado' => $a->resultado, 'chave' => self::chave((int) $e->id, (int) $a->id)]);
                $n++;
            }

            return $n;
        } catch (Throwable $erro) {
            report($erro);

            return 0;
        }
    }

    /**
     * Gancho do CRM: actividade reaberta ou apagada → a entrega que dela nasceu sai, se ainda PENDENTE e não convertida; senão fica
     * e devolve-se o aviso. Nunca falha para o CRM.
     *
     * @return array{removidas: int, presas: int, aviso: ?string}
     */
    public function aoSairActividade(AtividadeComercialCRM $a): array
    {
        try {
            $exs = ExecucaoEntregavel::query()->where('origem', 'CRM')->where('origem_chave', 'like', 'CRM|%|'.(int) $a->id)->get();
            $removiveis = $exs->filter(fn ($x) => $x->validacao === 'PENDENTE' && ! $x->convertida());
            if ($removiveis->isNotEmpty()) {
                ExecucaoEntregavel::query()->whereIn('id', $removiveis->pluck('id')->all())->delete();
            }
            $presas = $exs->count() - $removiveis->count();

            return ['removidas' => $removiveis->count(), 'presas' => $presas,
                'aviso' => $presas ? "A actividade já tinha dado {$presas} entrega(s) validada(s) ou pagas no entregável da unidade. Essas ficam — trate-as na estrutura orgânica." : null];
        } catch (Throwable $erro) {
            report($erro);

            return ['removidas' => 0, 'presas' => 0, 'aviso' => null];
        }
    }
}
