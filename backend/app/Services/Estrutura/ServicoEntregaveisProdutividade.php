<?php

namespace App\Services\Estrutura;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\EntregavelTarefaUnidade;
use App\Models\ExecucaoEntregavel;
use App\Models\ItemProdutividadeRH;
use App\Models\PeriodoProdutividadeRH;
use App\Models\RegistoProdutividadeRH;
use App\Models\TarefaUnidadeOrganica;
use App\Services\RH\ServicoProdutividade;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Ponte entregáveis → produtividade (Fase J, ADR-078 — estrutura_tarefas.js:1011-1166). A remuneração não acontece na estrutura:
 * aqui juntam-se as execuções VALIDADAS por (entregável, colaborador) dentro da janela do período de produtividade ABERTO e
 * convertem-se em registos de produtividade (origem ESTRUTURA, chave idempotente `ORG|e<ent>|c<colab>|i<item>|p<per>` que soma ao
 * registo existente, `fonte` com até 500 execuções e 100 evidências). Exclusões listadas: já convertidas, não validadas, sem item,
 * fora do prazo em entregáveis que não pagam fora de prazo. A conversão torna os executores elegíveis (ENTREGAVEL) e marca as
 * execuções; anular limpa a marca e apaga os registos ESTRUTURA do período (só com o período aberto).
 */
final class ServicoEntregaveisProdutividade
{
    public function __construct(
        private readonly ServicoProdutividade $produtividade,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    public static function chave(int $entregavel, int $colaborador, int $item, int $periodo): string
    {
        return "ORG|e{$entregavel}|c{$colaborador}|i{$item}|p{$periodo}";
    }

    /**
     * Agrupa as execuções por (entregável, colaborador) e diz o que pode e o que não pode ser convertido. Não escreve nada.
     *
     * @param  list<int>|null  $unidadeIds
     * @return array<string, mixed>
     */
    public function analisar(PeriodoProdutividadeRH $p, ?array $unidadeIds = null): array
    {
        if ($p->estado !== 'ABERTO') {
            throw new ErroNegocio("A produtividade de {$p->mes} está fechada. Reabra-a para converter os entregáveis.", 'PERIODO_FECHADO', 422);
        }
        [$de, $ate] = [$p->data_inicio->toDateString(), $p->data_fim->toDateString()];
        $exs = ExecucaoEntregavel::query()->whereBetween('data', [$de, $ate])->when($unidadeIds, fn ($q, $u) => $q->whereIn('unidade_organica_id', $u))->orderBy('data')->orderBy('id')->get();
        $ents = EntregavelTarefaUnidade::query()->whereIn('id', $exs->pluck('entregavel_id')->unique()->all() ?: [0])->get()->keyBy('id');
        $tarefas = TarefaUnidadeOrganica::query()->whereIn('id', $ents->pluck('tarefa_unidade_id')->unique()->all() ?: [0])->get()->keyBy('id');
        $itens = ItemProdutividadeRH::query()->get()->keyBy('id');
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', $exs->pluck('colaborador_id')->unique()->all() ?: [0])->pluck('nome_completo', 'id');
        $actuais = RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p->id)->whereNotNull('origem_chave')->get()->keyBy('origem_chave');
        $eleg = $this->produtividade->elegiveis($p);

        $grupos = [];
        $excluidas = [];
        $excluir = function (ExecucaoEntregavel $x, string $motivo, string $codigo) use (&$excluidas, $ents, $nomes) {
            $k = "{$x->entregavel_id}|{$x->colaborador_id}|{$codigo}";
            $excluidas[$k] ??= ['entregavel_id' => (int) $x->entregavel_id, 'entregavel' => $ents[$x->entregavel_id]?->nome ?? '—', 'colaborador_id' => (int) $x->colaborador_id,
                'colaborador_nome' => $nomes[$x->colaborador_id] ?? "#{$x->colaborador_id}", 'codigo' => $codigo, 'motivo' => $motivo, 'quantidade' => 0.0, 'registos' => 0, 'execucoes' => []];
            $excluidas[$k]['quantidade'] = round($excluidas[$k]['quantidade'] + (float) $x->quantidade, 4);
            $excluidas[$k]['registos']++;
            $excluidas[$k]['execucoes'][] = (int) $x->id;
        };
        foreach ($exs as $x) {
            $e = $ents[$x->entregavel_id] ?? null;
            if (! $e || ! $e->ativo) {
                continue;
            }
            if ($x->convertida()) {
                $excluir($x, 'Já convertida em produtividade.', 'JA_CONVERTIDA');

                continue;
            }
            $per = (string) ($tarefas[$e->tarefa_unidade_id]?->periodicidade ?: 'MENSAL');
            if (! $e->paga_fora_prazo && ServicoEntregaveis::foraDePrazo($e, $per, $x->data->toDateString())) {
                $excluir($x, 'Entregue fora do prazo (limite '.ServicoEntregaveis::limiteDaEntrega($e, $per, $x->data->toDateString()).') num entregável que não paga fora de prazo.', 'FORA_DO_PRAZO');

                continue;
            }
            if ($x->validacao !== 'VALIDADA') {
                $excluir($x, 'Sem o visto do responsável ('.mb_strtolower(ExecucaoEntregavel::ROTULOS_VALIDACAO[$x->validacao] ?? $x->validacao).').', 'NAO_VALIDADA');

                continue;
            }
            if (! $e->item_produtividade_id || ! isset($itens[$e->item_produtividade_id])) {
                $excluir($x, 'O entregável não tem item de produtividade ligado.', 'SEM_ITEM');

                continue;
            }
            $k = "{$e->id}|{$x->colaborador_id}";
            $grupos[$k] ??= ['entregavel' => $e, 'colaborador_id' => (int) $x->colaborador_id, 'nome' => $nomes[$x->colaborador_id] ?? "#{$x->colaborador_id}", 'quantidade' => 0.0, 'registos' => 0,
                'ultima' => '', 'exec_ids' => [], 'evidencias' => [], 'sem_evidencia' => 0];
            $g = &$grupos[$k];
            $g['quantidade'] = round($g['quantidade'] + (float) $x->quantidade, 4);
            $g['registos']++;
            $g['exec_ids'][] = (int) $x->id;
            if (trim((string) $x->url_evidencia) !== '') {
                $g['evidencias'][] = (string) $x->url_evidencia;
            } else {
                $g['sem_evidencia']++;
            }
            $g['ultima'] = max($g['ultima'], $x->data->toDateString());
            unset($g);
        }

        $validas = [];
        $erros = [];
        $porElegibilizar = [];
        $total = '0.00';
        foreach ($grupos as $g) {
            $e = $g['entregavel'];
            $item = $itens[$e->item_produtividade_id];
            $data = min($g['ultima'] ?: $ate, now()->toDateString(), $ate);
            $data = max($data, $de);
            $chave = self::chave((int) $e->id, $g['colaborador_id'], (int) $item->id, (int) $p->id);
            $existente = $actuais->get($chave);
            $quantidade = $existente ? round((float) $existente->quantidade + $g['quantidade'], 4) : $g['quantidade'];
            $jaElegivel = isset($eleg[$g['colaborador_id']]['itens'][$item->id]);
            $preco = $jaElegivel ? (string) $eleg[$g['colaborador_id']]['itens'][$item->id]['preco'] : (string) $item->preco_unitario;
            $activo = Colaborador::query()->whereKey($g['colaborador_id'])->where('estado', 'ACTIVO')->exists();
            if (! $activo) {
                $erros[] = ['entregavel_id' => (int) $e->id, 'entregavel' => $e->nome, 'colaborador_id' => $g['colaborador_id'], 'colaborador_nome' => $g['nome'], 'quantidade' => $g['quantidade'],
                    'mensagem' => 'O colaborador não está activo: não é elegível para produtividade.'];

                continue;
            }
            if (! $item->ativo) {
                $erros[] = ['entregavel_id' => (int) $e->id, 'entregavel' => $e->nome, 'colaborador_id' => $g['colaborador_id'], 'colaborador_nome' => $g['nome'], 'quantidade' => $g['quantidade'],
                    'mensagem' => "O item {$item->codigo} está inactivo."];

                continue;
            }
            if ($item->metrica === 'OBJECTIVO' && $quantidade > 1000) {
                $erros[] = ['entregavel_id' => (int) $e->id, 'entregavel' => $e->nome, 'colaborador_id' => $g['colaborador_id'], 'colaborador_nome' => $g['nome'], 'quantidade' => $g['quantidade'],
                    'mensagem' => 'Quantidade inválida para a métrica de objectivo (máximo 1000 %).'];

                continue;
            }
            if (! $jaElegivel) {
                $porElegibilizar[] = ['colaborador_id' => $g['colaborador_id'], 'colaborador_nome' => $g['nome'], 'item_produtividade_id' => (int) $item->id, 'item_codigo' => $item->codigo, 'preco' => $preco];
            }
            $valor = ServicoProdutividade::valor($item, $quantidade, (float) $preco);
            $valorAntes = $existente ? ServicoProdutividade::valor($item, (float) $existente->quantidade, (float) $preco) : '0.00';
            $acrescimo = bcsub($valor, $valorAntes, 2);
            $total = bcadd($total, $acrescimo, 2);
            $validas[] = ['entregavel_id' => (int) $e->id, 'entregavel' => $e->nome, 'tarefa_unidade_id' => (int) $e->tarefa_unidade_id, 'colaborador_id' => $g['colaborador_id'], 'colaborador_nome' => $g['nome'],
                'item_produtividade_id' => (int) $item->id, 'item_codigo' => $item->codigo, 'quantidade' => $g['quantidade'], 'registos' => $g['registos'], 'data' => $data, 'preco' => $preco,
                'origem_chave' => $chave, 'registo_existente_id' => $existente?->id, 'quantidade_total' => $quantidade, 'valor' => $acrescimo, 'valor_total' => $valor,
                'ja_elegivel' => $jaElegivel, 'sem_evidencia' => $g['sem_evidencia'], 'exec_ids' => $g['exec_ids'], 'evidencias' => $g['evidencias'],
                'explicacao' => ServicoProdutividade::explicar($item, $quantidade, (float) $preco)];
        }

        return ['periodo' => $p->only(['id', 'mes', 'data_inicio', 'data_fim', 'estado']), 'validas' => $validas, 'excluidas' => array_values($excluidas), 'erros' => $erros,
            'por_elegibilizar' => $porElegibilizar, 'total' => $total, 'convertidas' => ExecucaoEntregavel::query()->where('periodo_produtividade_id', $p->id)->count()];
    }

    /**
     * Converte: torna os executores elegíveis (ENTREGAVEL), grava/actualiza os registos de produtividade (origem ESTRUTURA) e marca
     * as execuções. Exige rh_prod_registar. Reanalisa no servidor.
     *
     * @return array{criados: int, actualizados: int, marcadas: int, elegiveis: int, total: string}
     */
    public function converter(PeriodoProdutividadeRH $p, ?array $unidadeIds = null): array
    {
        if (! Gate::allows('rh_prod_registar')) {
            throw new ErroNegocio('Sem permissão para registar produtividade (tarefa «Registar produtividade» do perfil).', 'SEM_PERMISSAO', 403);
        }
        $analise = $this->analisar($p, $unidadeIds);
        if (! $analise['validas']) {
            throw new ErroNegocio('Não há entregáveis por converter.', 'NADA_A_CONVERTER', 422);
        }

        return DB::transaction(function () use ($p, $analise) {
            $r = ['criados' => 0, 'actualizados' => 0, 'marcadas' => 0, 'elegiveis' => 0, 'total' => $analise['total']];
            foreach ($analise['por_elegibilizar'] as $el) {
                if ($this->produtividade->tornarElegivel($el['colaborador_id'], $el['item_produtividade_id'], 'ENTREGAVEL', null, 'Executou o entregável da unidade')['novo']) {
                    $r['elegiveis']++;
                }
            }
            $user = Auth::user()?->nome_utilizador;
            foreach ($analise['validas'] as $v) {
                $existente = $v['registo_existente_id'] ? RegistoProdutividadeRH::query()->find($v['registo_existente_id']) : null;
                $e = EntregavelTarefaUnidade::query()->find($v['entregavel_id']);
                $fonteAnterior = (array) ($existente?->fonte ?? []);
                $fonte = ['origem' => 'ESTRUTURA', 'entregavel_id' => $v['entregavel_id'], 'tarefa_unidade_id' => $v['tarefa_unidade_id'], 'unidade' => $e?->unidade, 'metrica' => $e?->metrica,
                    'de' => $p->data_inicio->toDateString(), 'ate' => $p->data_fim->toDateString(),
                    'execucoes' => array_slice(array_values(array_unique(array_merge((array) ($fonteAnterior['execucoes'] ?? []), $v['exec_ids']))), 0, 500),
                    'evidencias' => array_slice(array_values(array_unique(array_merge((array) ($fonteAnterior['evidencias'] ?? []), $v['evidencias']))), 0, 100),
                    'sem_evidencia' => (int) ($fonteAnterior['sem_evidencia'] ?? 0) + $v['sem_evidencia']];
                $obs = "Entregável «{$v['entregavel']}» da tarefa da unidade: {$v['registos']} entrega(s) entre {$p->data_inicio->toDateString()} e {$p->data_fim->toDateString()}.";
                $reg = $this->produtividade->gravarRegisto($p, ['colaborador_id' => $v['colaborador_id'], 'item_produtividade_id' => $v['item_produtividade_id'], 'quantidade' => $v['quantidade_total'],
                    'data' => $existente?->data?->toDateString() ?? $v['data'], 'observacoes' => $obs], $existente, 'ESTRUTURA');
                $reg->forceFill(['origem_chave' => $v['origem_chave'], 'fonte' => $fonte])->save();
                $existente ? $r['actualizados']++ : $r['criados']++;
                $r['marcadas'] += ExecucaoEntregavel::query()->whereIn('id', $v['exec_ids'])->update(['periodo_produtividade_id' => $p->id, 'convertida_em' => now(), 'convertida_por' => $user]);
            }
            $this->auditoria->registar(ServicoEntregaveis::MODULO_AUDITORIA, 'Converter entregáveis em produtividade',
                "{$p->mes}: {$r['criados']} novo(s), {$r['actualizados']} actualizado(s), {$r['marcadas']} execução(ões)".($r['elegiveis'] ? ", {$r['elegiveis']} colaborador(es) tornado(s) elegível(eis)" : ''),
                'periodos_produtividade_rh', $p->id);

            return $r;
        });
    }

    /**
     * Devolve as execuções do período à condição de «por converter» e apaga os registos de produtividade ESTRUTURA do período.
     *
     * @return array{execucoes: int, registos: int}
     */
    public function anularConversao(PeriodoProdutividadeRH $p): array
    {
        if (! Gate::allows('rh_prod_registar')) {
            throw new ErroNegocio('Sem permissão para registar produtividade (tarefa «Registar produtividade» do perfil).', 'SEM_PERMISSAO', 403);
        }
        if ($p->estado !== 'ABERTO') {
            throw new ErroNegocio("A produtividade de {$p->mes} está fechada. Reabra-a para anular a conversão.", 'PERIODO_FECHADO', 422);
        }

        return DB::transaction(function () use ($p) {
            $n = ExecucaoEntregavel::query()->where('periodo_produtividade_id', $p->id)->update(['periodo_produtividade_id' => null, 'convertida_em' => null, 'convertida_por' => null]);
            $regs = RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p->id)->where('origem', 'ESTRUTURA')->get();
            foreach ($regs as $r) {
                $this->produtividade->eliminarRegisto($r);
            }
            $this->auditoria->registar(ServicoEntregaveis::MODULO_AUDITORIA, 'Anular conversão de entregáveis', "{$p->mes}: {$n} execução(ões), {$regs->count()} registo(s)", 'periodos_produtividade_rh', $p->id);

            return ['execucoes' => $n, 'registos' => $regs->count()];
        });
    }
}
