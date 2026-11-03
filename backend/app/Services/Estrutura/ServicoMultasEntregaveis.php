<?php

namespace App\Services\Estrutura;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\EntregavelTarefaUnidade;
use App\Models\InfotipoSalarial;
use App\Models\LinhaFolhaSalarial;
use App\Models\MultaEntregavel;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\TarefaUnidadeOrganica;
use App\Services\RH\RegrasContrato;
use App\Services\RH\ServicoCalendarioRH;
use App\Services\RH\ServicoConfiguracaoRH;
use App\Services\Sistema\NotificadorRH;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Multas de entregáveis na folha (Fase K, ADR-079). No legado a multa era só um valor informativo no atraso; aqui há um circuito:
 *   PROPOSTA (o sistema propõe a partir dos atrasos) → APLICADA (uma pessoa decide) → DESCONTADA (lançada na folha), ou
 *   DISPENSADA (com motivo). NADA se aplica sozinho: só depois de uma pessoa com `est_multas_gerir` aplicar é que a multa pode ser
 *   descontada, e nunca é o próprio penalizado a decidir (AUTO_DECISAO).
 *   - penalizado: o dono do entregável; sem dono, o responsável da tarefa; sem nenhum, o responsável da unidade; sem ninguém, não se
 *     propõe e fica nos avisos. Uma multa por (entregável, período, penalizado): propor é idempotente;
 *   - tecto opcional (`multas_entregavel_max_pct`, % do salário base do contrato vigente no mês): o que o ultrapassa fica APLICADA e
 *     vai para os avisos do lançamento; a rubrica (`infotipo_multa_entregavel_id`) tem de ser um DESCONTO e é explícita;
 *   - lançar segue ServicoEmprestimos::lancarNoPeriodo: período ABERTO, uma linha por (período, colaborador, rubrica) com a soma
 *     das multas aplicadas, origem MULTA_ENTREGAVEL; anular retira as linhas e repõe as multas para APLICADA.
 * Aviso legal: descontos por penalização pecuniária podem estar limitados pela Lei Geral do Trabalho (Lei n.º 12/23). Por isso a
 * decisão é humana, o tecto é opcional e a rubrica explícita; recomenda-se validação jurídica antes de a activar.
 */
final class ServicoMultasEntregaveis
{
    public const MODULO_AUDITORIA = 'Estrutura orgânica › Multas';

    public function __construct(
        private readonly ServicoEntregaveis $entregaveis,
        private readonly ServicoAuditoria $auditoria,
        private readonly ServicoConfiguracaoRH $configuracao,
        private readonly NotificadorRH $notificador,
    ) {}

    // ───────────── Propor ─────────────

    /**
     * Cria PROPOSTA para os atrasos com multa (valor > 0) sem impedimento aceite e ainda sem multa para (entregável, período,
     * penalizado). Idempotente. `unidades`: só estas unidades e as de baixo (omissão: todas).
     *
     * @param  list<int>|null  $unidadeIds
     * @return array{criadas: int, ignoradas: int, avisos: list<string>, propostas: list<array<string, mixed>>}
     */
    public function proporDoPeriodo(?array $unidadeIds = null): array
    {
        $opcoes = ['soComAlerta' => false];
        if ($unidadeIds) {
            $opcoes['unidadeIds'] = $this->entregaveis->descendentes(array_map('intval', $unidadeIds));
        }
        $atrasos = array_filter($this->entregaveis->entregaveisEmAtraso($opcoes), fn ($a) => (float) ($a['multa_valor'] ?? 0) > 0);
        $unidades = $this->entregaveis->unidades();
        $tarefas = TarefaUnidadeOrganica::query()->whereIn('id', array_column(array_column($atrasos, 'tarefa'), 'id') ?: [0])->get()->keyBy('id');
        $activos = Colaborador::query()->where('estado', 'ACTIVO')->pluck('id')->flip();
        $user = Auth::user()?->nome_utilizador;
        $res = ['criadas' => 0, 'ignoradas' => 0, 'avisos' => [], 'propostas' => []];
        foreach ($atrasos as $a) {
            $penalizado = $this->penalizado($a, $tarefas[$a['tarefa']['id']] ?? null, $unidades, $activos);
            if ($penalizado === null) {
                $res['avisos'][] = "«{$a['entregavel']['nome']}» ({$a['periodo_de']}): sem dono, nem responsável da tarefa ou da unidade activo — a multa não se propõe.";

                continue;
            }
            if (MultaEntregavel::query()->where('entregavel_id', $a['entregavel_id'])->where('periodo_de', $a['periodo_de'])->where('colaborador_id', $penalizado)->exists()) {
                $res['ignoradas']++;

                continue;
            }
            try {
                $m = DB::transaction(fn () => MultaEntregavel::create(['entregavel_id' => $a['entregavel_id'], 'tarefa_unidade_id' => $a['tarefa']['id'], 'unidade_organica_id' => $a['unidade_organica_id'],
                    'colaborador_id' => $penalizado, 'periodo_de' => $a['periodo_de'], 'periodo_ate' => $a['periodo_ate'], 'limite' => $a['limite'], 'dias_atraso' => $a['dias_atraso'],
                    'valor' => round((float) $a['multa_valor'], 2), 'descricao' => mb_substr((string) ($a['multa_descricao'] ?: "Atraso na entrega de «{$a['entregavel']['nome']}»"), 0, 255),
                    'estado' => 'PROPOSTA', 'criado_por' => $user]));
            } catch (UniqueConstraintViolationException) {
                $res['ignoradas']++;   // outra proposta ao mesmo tempo

                continue;
            }
            $res['criadas']++;
            $res['propostas'][] = $m->only(['id', 'entregavel_id', 'colaborador_id', 'periodo_de', 'valor', 'estado']);
        }
        if ($res['criadas'] > 0) {
            $this->auditoria->registar(self::MODULO_AUDITORIA, 'Propor multas de entregáveis', "{$res['criadas']} proposta(s), {$res['ignoradas']} já existente(s)");
            $this->notificador->paraPermissao('est_multas_gerir', 'Multas de entregáveis por decidir', "{$res['criadas']} multa(s) por atraso de entregáveis foram propostas e aguardam a sua decisão.",
                '/m/estrutura/est_estrutura', 'estrutura', 'AVISO');
        }

        return $res;
    }

    /** Dono do entregável → responsável da tarefa → responsável da unidade (o primeiro activo). */
    private function penalizado(array $atraso, ?TarefaUnidadeOrganica $tarefa, $unidades, $activos): ?int
    {
        $candidatos = [$atraso['dono']['id'] ?? null, $tarefa?->colaborador_responsavel_id, $unidades->get((int) $atraso['unidade_organica_id'])?->colaborador_responsavel_id];
        foreach ($candidatos as $c) {
            if ($c && isset($activos[(int) $c])) {
                return (int) $c;
            }
        }

        return null;
    }

    // ───────────── Decidir ─────────────

    /** Quem decide: `est_multas_gerir`, nunca o próprio penalizado. */
    private function exigirDecisor(MultaEntregavel $m): ?int
    {
        if (! Gate::allows('est_multas_gerir')) {
            throw new ErroNegocio('Só quem tem a permissão de gerir multas de entregáveis decide.', 'SEM_PERMISSAO', 403);
        }
        $eu = $this->entregaveis->colaboradorDe();
        if ($eu !== null && (int) $m->colaborador_id === $eu) {
            throw new ErroNegocio('Não pode decidir uma multa que lhe foi aplicada a si: cabe a outra pessoa.', 'AUTO_DECISAO', 403);
        }

        return $eu;
    }

    public function aplicar(MultaEntregavel $m, ?string $nota = null): MultaEntregavel
    {
        return $this->decidir($m, 'APLICADA', $nota);
    }

    public function dispensar(MultaEntregavel $m, string $motivo): MultaEntregavel
    {
        if (mb_strlen(trim($motivo)) < 3) {
            throw new ErroNegocio('Indique o motivo da dispensa da multa.', 'MOTIVO_EM_FALTA', 422);
        }

        return $this->decidir($m, 'DISPENSADA', $motivo);
    }

    private function decidir(MultaEntregavel $m, string $estado, ?string $nota): MultaEntregavel
    {
        $eu = $this->exigirDecisor($m);

        $res = DB::transaction(function () use ($m, $estado, $nota, $eu) {
            $m = MultaEntregavel::query()->lockForUpdate()->findOrFail($m->id);
            if ($m->estado !== 'PROPOSTA') {
                throw new ErroNegocio('Só se decidem multas propostas (esta está '.strtolower(MultaEntregavel::ROTULOS[$m->estado] ?? $m->estado).').', 'ESTADO_INVALIDO', 422);
            }
            $m->update(['estado' => $estado, 'decidido_por' => Auth::user()?->nome_utilizador, 'decidido_por_colaborador_id' => $eu, 'decidido_em' => now(), 'motivo_decisao' => trim((string) $nota) ?: null]);
            $this->auditoria->registar(self::MODULO_AUDITORIA, $estado === 'APLICADA' ? 'Aplicar multa de entregável' : 'Dispensar multa de entregável',
                "{$m->valor} a #{$m->colaborador_id} ({$m->periodo_de->toDateString()})".(trim((string) $nota) !== '' ? ' — '.trim((string) $nota) : ''), 'multas_entregavel', $m->id);
            $nome = EntregavelTarefaUnidade::query()->whereKey($m->entregavel_id)->value('nome') ?? 'entregável';
            $this->notificador->paraColaborador((int) $m->colaborador_id, $estado === 'APLICADA' ? 'Multa aplicada' : 'Multa dispensada',
                $estado === 'APLICADA' ? "Foi-lhe aplicada uma multa de {$m->valor} pelo atraso em «{$nome}» ({$m->periodo_de->toDateString()}); será descontada na folha."
                    : "A multa de {$m->valor} pelo atraso em «{$nome}» ({$m->periodo_de->toDateString()}) foi dispensada".(trim((string) $nota) !== '' ? ': '.trim((string) $nota) : '.'),
                '/m/rh/rh_portal', 'estrutura', $estado === 'APLICADA' ? 'AVISO' : 'SUCESSO');

            return $m->refresh();
        });

        return $res;
    }

    /** Reabrir uma multa DISPENSADA (ou APLICADA ainda por descontar) para PROPOSTA. */
    public function repor(MultaEntregavel $m): MultaEntregavel
    {
        $this->exigirDecisor($m);

        return DB::transaction(function () use ($m) {
            $m = MultaEntregavel::query()->lockForUpdate()->findOrFail($m->id);
            if (! in_array($m->estado, ['DISPENSADA', 'APLICADA'], true)) {
                throw new ErroNegocio('Só se reabrem multas dispensadas ou aplicadas ainda por descontar.', 'ESTADO_INVALIDO', 422);
            }
            $m->update(['estado' => 'PROPOSTA', 'decidido_por' => null, 'decidido_por_colaborador_id' => null, 'decidido_em' => null, 'motivo_decisao' => null]);
            $this->auditoria->registar(self::MODULO_AUDITORIA, 'Repor multa de entregável como proposta', null, 'multas_entregavel', $m->id);

            return $m->refresh();
        });
    }

    // ───────────── Consulta ─────────────

    /**
     * @param  array{estado?: ?string, unidade_id?: ?int, colaborador_id?: ?int, de?: ?string, ate?: ?string}  $f
     * @return list<array<string, mixed>>
     */
    public function listar(array $f = []): array
    {
        $unidades = ! empty($f['unidade_id']) ? $this->entregaveis->descendentes([(int) $f['unidade_id']]) : null;
        $lista = MultaEntregavel::query()->when($f['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))->when($unidades !== null, fn ($q) => $q->whereIn('unidade_organica_id', $unidades ?: [0]))
            ->when($f['colaborador_id'] ?? null, fn ($q, $c) => $q->where('colaborador_id', $c))->when($f['de'] ?? null, fn ($q, $d) => $q->where('periodo_de', '>=', $d))
            ->when($f['ate'] ?? null, fn ($q, $d) => $q->where('periodo_de', '<=', $d))->orderByDesc('periodo_de')->orderByDesc('id')->limit(1000)->get();
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', $lista->pluck('colaborador_id')->all() ?: [0])->pluck('nome_completo', 'id');
        $ents = EntregavelTarefaUnidade::query()->whereIn('id', $lista->pluck('entregavel_id')->unique()->all() ?: [0])->pluck('nome', 'id');
        $tarefas = TarefaUnidadeOrganica::query()->whereIn('id', $lista->pluck('tarefa_unidade_id')->unique()->all() ?: [0])->pluck('nome', 'id');
        $unidadesTodas = $this->entregaveis->unidades();
        $eu = $this->entregaveis->colaboradorDe();
        $decide = Gate::allows('est_multas_gerir');

        return $lista->map(fn (MultaEntregavel $m) => $m->toArray() + ['colaborador_nome' => $nomes[$m->colaborador_id] ?? "#{$m->colaborador_id}", 'entregavel_nome' => $ents[$m->entregavel_id] ?? '—',
            'tarefa_nome' => $tarefas[$m->tarefa_unidade_id] ?? '—', 'unidade_nome' => (string) ($unidadesTodas->get((int) $m->unidade_organica_id)?->nome ?? ''),
            'rotulo_estado' => MultaEntregavel::ROTULOS[$m->estado] ?? $m->estado,
            'pode_decidir' => $decide && $m->estado === 'PROPOSTA' && ($eu === null || (int) $m->colaborador_id !== $eu)])->values()->all();
    }

    // ───────────── Folha ─────────────

    /**
     * «Importar Multas» (segue ServicoEmprestimos::lancarNoPeriodo): as multas APLICADAS (ainda não descontadas) somam-se numa linha
     * por colaborador na rubrica das multas; as DESCONTADA ficam ligadas à linha e ao período. Com tecto, o excedente fica APLICADA.
     *
     * @return array{lancados: int, actualizados: int, ignorados: int, total: string, avisos: list<string>}
     */
    public function lancarNoPeriodo(PeriodoProcessamentoSalarial $p, bool $substituir = false): array
    {
        $cfg = $this->configuracao->config();
        $rubrica = $cfg['infotipo_multa_entregavel_id'] ? InfotipoSalarial::query()->find($cfg['infotipo_multa_entregavel_id']) : null;
        if (! $rubrica) {
            throw new ErroNegocio('Configure primeiro a rubrica de desconto das multas de entregáveis (RH › Configuração).', 'RUBRICA_EM_FALTA', 422);
        }
        if ($rubrica->tipo !== 'DESCONTO') {
            throw new ErroNegocio("A rubrica {$rubrica->nome} não é um desconto.", 'RUBRICA_NAO_DESCONTO', 422);
        }
        $pct = (int) $cfg['multas_entregavel_max_pct'];

        return DB::transaction(function () use ($p, $substituir, $rubrica, $pct) {
            $p = PeriodoProcessamentoSalarial::query()->lockForUpdate()->findOrFail($p->id);
            if ($p->estado !== 'ABERTO') {
                throw new ErroNegocio("O processamento de {$p->mes_ano} não está aberto.", 'PERIODO_ESTADO_INVALIDO', 422);
            }
            $res = ['lancados' => 0, 'actualizados' => 0, 'ignorados' => 0, 'total' => '0.00', 'avisos' => []];
            $mes = ServicoCalendarioRH::mes($p->mes_ano);
            $porColaborador = MultaEntregavel::query()->where('estado', 'APLICADA')->whereNull('linha_folha_salarial_id')->orderBy('periodo_de')->orderBy('id')->lockForUpdate()->get()->groupBy('colaborador_id');
            $contratos = $pct > 0 ? ContratoTrabalho::query()->whereIn('colaborador_id', $porColaborador->keys()->all() ?: [0])->get()->groupBy('colaborador_id') : collect();
            $nomes = Colaborador::query()->withTrashed()->whereIn('id', $porColaborador->keys()->all() ?: [0])->pluck('nome_completo', 'id');
            foreach ($porColaborador as $colaborador => $multas) {
                $colaborador = (int) $colaborador;
                $nome = $nomes[$colaborador] ?? "#{$colaborador}";
                $aDescontar = $multas;
                $linha = LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->where('colaborador_id', $colaborador)->where('infotipo_salarial_id', $rubrica->id)->first();
                if ($pct > 0) {
                    $base = RegrasContrato::salarioBase(RegrasContrato::emVigor($contratos[$colaborador] ?? collect(), "{$mes}-01", date('Y-m-t', strtotime("{$mes}-01"))));
                    $limite = bcdiv(bcmul($base, (string) $pct, 4), '100', 2);
                    // o que este colaborador já tem descontado por multas neste período conta para o tecto
                    $usado = (string) MultaEntregavel::query()->where('estado', 'DESCONTADA')->where('periodo_processamento_salarial_id', $p->id)->where('colaborador_id', $colaborador)->sum('valor');
                    $disponivel = bccomp($base, '0', 2) > 0 ? bcsub($limite, number_format((float) $usado, 2, '.', ''), 2) : '0.00';
                    $aDescontar = collect();
                    foreach ($multas as $m) {
                        if (bccomp($disponivel, (string) $m->valor, 2) >= 0) {
                            $aDescontar->push($m);
                            $disponivel = bcsub($disponivel, (string) $m->valor, 2);
                        } else {
                            $res['avisos'][] = bccomp($base, '0', 2) > 0
                                ? "{$nome}: a multa de {$m->valor} ({$m->periodo_de->toDateString()}) excede o tecto de {$pct}% do salário base ({$limite}) e fica por descontar."
                                : "{$nome}: sem salário base apurável no contrato — o tecto de {$pct}% não se pode aplicar e a multa de {$m->valor} fica por descontar.";
                        }
                    }
                    if ($aDescontar->isEmpty()) {
                        continue;
                    }
                }
                $total = $aDescontar->reduce(fn (string $s, $m) => bcadd($s, (string) $m->valor, 2), '0.00');
                if ($linha && ! $substituir) {
                    $res['ignorados']++;

                    continue;
                }
                if ($linha) {
                    // somar às multas já lançadas na mesma linha (origem MULTA_ENTREGAVEL); senão a linha é substituída (como nos empréstimos)
                    $valor = $linha->origem === MultaEntregavel::ORIGEM_LANCAMENTO ? bcadd((string) $linha->valor, $total, 2) : $total;
                    $linha->update(['valor' => $valor, 'horas' => null, 'origem' => MultaEntregavel::ORIGEM_LANCAMENTO]);
                    $res['actualizados']++;
                } else {
                    $linha = LinhaFolhaSalarial::create(['periodo_processamento_salarial_id' => $p->id, 'colaborador_id' => $colaborador, 'infotipo_salarial_id' => $rubrica->id,
                        'valor' => $total, 'origem' => MultaEntregavel::ORIGEM_LANCAMENTO]);
                    $res['lancados']++;
                }
                foreach ($aDescontar as $m) {
                    $m->forceFill(['estado' => 'DESCONTADA', 'periodo_processamento_salarial_id' => $p->id, 'linha_folha_salarial_id' => $linha->id, 'descontada_em' => now()])->save();
                }
                $res['total'] = bcadd($res['total'], $total, 2);
            }
            $this->auditoria->registar('RH', 'Importar multas de entregáveis no processamento', "{$p->mes_ano}: {$res['lancados']} lançados, {$res['actualizados']} actualizados, total {$res['total']}",
                'periodos_processamento_salarial', $p->id);

            return $res;
        });
    }

    /** Desfaz o desconto do período: retira as linhas e repõe as multas para APLICADA. */
    public function anularDoPeriodo(PeriodoProcessamentoSalarial $p): int
    {
        return DB::transaction(function () use ($p) {
            $p = PeriodoProcessamentoSalarial::query()->lockForUpdate()->findOrFail($p->id);
            if ($p->estado !== 'ABERTO') {
                throw new ErroNegocio("O processamento de {$p->mes_ano} não está aberto: reabra-o primeiro.", 'PERIODO_ESTADO_INVALIDO', 422);
            }
            $linhas = MultaEntregavel::query()->where('periodo_processamento_salarial_id', $p->id)->where('estado', 'DESCONTADA')->pluck('linha_folha_salarial_id')->filter()->unique()->all();
            $n = self::libertarMultas($linhas, $p->id);
            LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->whereKey($linhas ?: [0])->delete();
            $this->auditoria->registar('RH', 'Anular multas de entregáveis do processamento', "{$p->mes_ano}: {$n} multa(s) reposta(s)", 'periodos_processamento_salarial', $p->id);

            return $n;
        });
    }

    /**
     * As multas descontadas por estes lançamentos voltam a APLICADA (o lançamento foi retirado do processamento). Chamado por
     * ServicoFolhaSalarial ao retirar lançamentos ou o período, como libertarPrestacoes nos empréstimos.
     */
    public static function libertarMultas(array $linhas, ?int $periodo = null): int
    {
        $ms = MultaEntregavel::query()->where('estado', 'DESCONTADA')->where(fn ($q) => $q->whereIn('linha_folha_salarial_id', $linhas ?: [0])
            ->when($periodo, fn ($q) => $q->orWhere(fn ($q) => $q->where('periodo_processamento_salarial_id', $periodo)->whereNull('linha_folha_salarial_id'))))->get();
        $ms->each(fn ($m) => $m->forceFill(['estado' => 'APLICADA', 'linha_folha_salarial_id' => null, 'periodo_processamento_salarial_id' => null, 'descontada_em' => null])->save());

        return $ms->count();
    }
}
