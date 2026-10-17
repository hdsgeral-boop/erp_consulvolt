<?php

namespace App\Services\Ativos;

use App\Exceptions\ErroNegocio;
use App\Models\AtivoImobilizado;
use App\Models\EncomendaCompra;
use App\Models\FaturaCompra;
use App\Models\ItemCompra;
use App\Models\ItemOrdemManutencao;
use App\Models\LinhaRequisicaoManutencao;
use App\Models\OrdemManutencaoAtivo;
use App\Models\PedidoCompra;
use App\Models\PlanoManutencaoAtivo;
use App\Models\Produto;
use App\Models\RegistoManutencaoAtivo;
use App\Models\RequisicaoManutencao;
use App\Models\StockArmazem;
use App\Services\Compras\ServicoProcessoCompras;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Ordens de manutenção, necessidades e requisição (Fase F, Versão Actual — js/activos_manutencao.js:219-457, 568-583;
 * UI activos_manutencao_ui.js:320-688). Regras do legado mantidas:
 *   - ordem OM AAAA/NNNN sobre um activo, manual ou gerada pelo plano; prioridade; estados PLANEADA → AGUARDA_MATERIAL →
 *     EM_CURSO → CONCLUIDA | CANCELADA; checklist de tarefas; necessidades MATERIAL/SERVICO;
 *   - «Gerar ordens dos planos»: plano activo vencido ou a vencer, uma ordem por activo alvo (ALTA se vencido); um plano
 *     com ordem aberta para o mesmo activo não gera outra (gerarOrdensDosPlanos :271-303);
 *   - conclusão: exige o trabalho efectuado, avisa das necessidades por receber (pode forçar), regista horas, mão-de-obra,
 *     serviços e leitura; escreve o histórico do activo (registos_manutencao_ativos) e avança o plano a partir da execução
 *     real (TEMPO: data de fim + intervalo; CONTADOR: leitura + intervalo) — concluirOrdem :319-376;
 *   - cancelamento com motivo; as requisições e os pedidos já emitidos não se apagam (cancelarOrdem :378-391);
 *   - repartição das necessidades: SERVIÇO (ou artigo que não movimenta stock) → pedido de compra; MATERIAL → armazém até
 *     ao stock, a falta a compras; no máximo uma requisição e um pedido de compra por accionamento; o pedido herda CC e UN
 *     do activo; a ordem PLANEADA passa a AGUARDA_MATERIAL (planoDeRequisicao/requisitar :396-516);
 *   - custo dos serviços sugerido pelas facturas de compra ligadas (ou, sem factura, Σ qtd × preço do pedido) — :627-642.
 * Melhorias: validação no servidor; linha sem artigo não vai a compras (o pedido de compra exige artigo) e fica por pedir
 * com o motivo; requisição e pedido criados na mesma transacção que o estado das necessidades; tudo auditado.
 * A manutenção não gera lançamento contabilístico (o material sai pelo stock e os serviços pelo circuito de compras).
 * Gestão de tarefas (r3_activos — boas práticas de CMMS):
 *   - cada tarefa tem id estável, texto, obrigatória/opcional e quem/quando a concluiu; o histórico da ordem regista cada
 *     conclusão e reabertura (e iniciar, concluir, reabrir e cancelar a ordem);
 *   - progresso «N de M» e situação derivada: SEM_TAREFAS | POR_INICIAR | EM_CURSO | PRONTA (todas as obrigatórias feitas);
 *     marcar a primeira tarefa feita passa a ordem PLANEADA/AGUARDA_MATERIAL a EM_CURSO (o trabalho começou);
 *   - a ordem fica pendente enquanto houver tarefas obrigatórias por fazer: o servidor recusa concluí-la (TAREFAS_PENDENTES);
 *     as opcionais por fazer ficam registadas na conclusão;
 *   - reabrir uma tarefa de uma ordem concluída reabre a ordem (com motivo): volta a EM_CURSO, sai do histórico do activo e
 *     o plano volta ao estado anterior à conclusão (se entretanto não tiver avançado por outra ordem);
 *   - acrescentar/retirar tarefas só com a ordem aberta; uma tarefa feita não se retira (reabre-se primeiro).
 */
final class ServicoOrdensManutencao
{
    public function __construct(
        private readonly NumeracaoManutencao $numeracao,
        private readonly ServicoPlanosManutencao $planos,
        private readonly ServicoProcessoCompras $compras,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    // ───────────── Consulta ─────────────

    public function listar(array $f): array
    {
        $filtro = $f['filtro'] ?? 'ABERTAS';
        $q = OrdemManutencaoAtivo::query()->with(['ativoImobilizado' => fn ($q) => $q->withTrashed()->select('id', 'codigo', 'descricao'), 'planoManutencao:id,codigo,nome'])
            ->when($filtro === 'ABERTAS', fn ($q) => $q->whereIn('estado', OrdemManutencaoAtivo::ABERTAS))
            ->when(in_array($filtro, OrdemManutencaoAtivo::ESTADOS, true), fn ($q) => $q->where('estado', $filtro))
            ->when($f['ativo_imobilizado_id'] ?? null, fn ($q, $v) => $q->where('ativo_imobilizado_id', $v))
            ->when($f['pesquisa'] ?? null, function ($q, $p) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], $p).'%';
                $q->where(fn ($x) => $x->where('numero', 'ilike', $termo)->orWhere('descricao', 'ilike', $termo)->orWhere('responsavel', 'ilike', $termo)
                    ->orWhereHas('ativoImobilizado', fn ($a) => $a->withTrashed()->where(fn ($y) => $y->where('codigo', 'ilike', $termo)->orWhere('descricao', 'ilike', $termo))));
            })
            ->orderByDesc('data_prevista')->orderByDesc('id');
        /** @var LengthAwarePaginator $pagina */
        $pagina = $q->paginate((int) ($f['por_pagina'] ?? 100), ['*'], 'pagina', (int) ($f['pagina'] ?? 1));
        $hoje = now()->toDateString();
        $pagina->setCollection($pagina->getCollection()->map(fn (OrdemManutencaoAtivo $o) => array_merge($o->toArray(), [
            'tarefas' => $t = self::tarefas($o->tarefas), 'progresso' => self::progresso($t),
            'custo_total' => $this->custoTotal($o),
            'em_atraso' => in_array($o->estado, OrdemManutencaoAtivo::ABERTAS, true) && $o->data_prevista && $o->data_prevista->toDateString() < $hoje,
        ])));
        $contas = OrdemManutencaoAtivo::query()->selectRaw('estado, COUNT(*) AS n')->groupBy('estado')->pluck('n', 'estado');
        $atrasadas = OrdemManutencaoAtivo::query()->whereIn('estado', OrdemManutencaoAtivo::ABERTAS)->whereDate('data_prevista', '<', $hoje)->count();

        return ['pagina' => $pagina, 'contagens' => [
            'ABERTAS' => (int) collect(OrdemManutencaoAtivo::ABERTAS)->sum(fn ($e) => $contas[$e] ?? 0),
            'CONCLUIDA' => (int) ($contas['CONCLUIDA'] ?? 0), 'CANCELADA' => (int) ($contas['CANCELADA'] ?? 0),
            'TODAS' => (int) $contas->sum(), 'EM_ATRASO' => $atrasadas,
        ]];
    }

    /** Detalhe da ordem (detalheOrdem, UI :425-528): necessidades, requisições, pedidos de compra e custos. */
    public function detalhe(OrdemManutencaoAtivo $o): array
    {
        $o->load(['ativoImobilizado' => fn ($q) => $q->withTrashed()->select('id', 'codigo', 'descricao', 'centro_custo_id', 'unidade_negocio_id'), 'planoManutencao:id,codigo,nome,periodicidade']);
        $itens = ItemOrdemManutencao::query()->with('produto:id,codigo,nome,movimenta_stock')->where('ordem_manutencao_id', $o->id)->orderBy('id')->get();
        $pedidos = PedidoCompra::query()->whereIn('id', $itens->pluck('pedido_compra_id')->filter()->unique())->get(['id', 'numero_pedido', 'estado', 'data']);

        $tarefas = self::tarefas($o->tarefas);

        return array_merge($o->toArray(), [
            'tarefas' => $tarefas, 'progresso' => self::progresso($tarefas), 'historico' => array_values((array) ($o->historico ?? [])),
            'custo_total' => $this->custoTotal($o),
            'itens' => $itens->toArray(),
            'por_pedir' => $itens->where('estado', 'POR_PEDIR')->count(),
            'requisicoes' => RequisicaoManutencao::query()->where('ordem_manutencao_id', $o->id)->orderBy('id')->get(['id', 'numero', 'estado', 'data', 'armazem_id'])->toArray(),
            'pedidos_compra' => $pedidos->toArray(),
            'custo_servicos_sugerido' => $this->custoServicosSugerido($o),
        ]);
    }

    // ───────────── Criação ─────────────

    /**
     * @param  array{ativo_imobilizado_id: int, plano_manutencao_id?: ?int, tipo: string, prioridade?: ?string, data_prevista: string, descricao?: ?string,
     *               responsavel?: ?string, tarefas?: list<string|array{texto: string}>, itens?: list<array{tipo?: string, produto_id?: ?int, descricao?: ?string, quantidade?: mixed}>}  $d
     */
    public function criar(array $d): OrdemManutencaoAtivo
    {
        $ativo = AtivoImobilizado::query()->find($d['ativo_imobilizado_id'] ?? 0) ?? throw new ErroNegocio('Escolha o activo da ordem de manutenção.', 'DADOS_INVALIDOS', 422);
        if ($ativo->estado === AtivoImobilizado::ESTADO_ABATIDO) {
            throw new ErroNegocio("O activo {$ativo->codigo} está abatido.", 'ATIVO_ABATIDO', 422);
        }
        if (! in_array($d['tipo'] ?? null, PlanoManutencaoAtivo::TIPOS, true)) {
            throw new ErroNegocio('Escolha o tipo de manutenção.', 'DADOS_INVALIDOS', 422);
        }
        if (empty($d['data_prevista'])) {
            throw new ErroNegocio('Indique a data prevista.', 'DADOS_INVALIDOS', 422);
        }
        $descricao = trim((string) ($d['descricao'] ?? ''));
        if ($descricao === '') {
            throw new ErroNegocio('Descreva o trabalho a fazer.', 'DADOS_INVALIDOS', 422);
        }
        $itens = $this->normalizarItens($d['itens'] ?? []);

        return DB::transaction(function () use ($d, $ativo, $descricao, $itens) {
            $o = OrdemManutencaoAtivo::create([
                'numero' => $this->numeracao->proximo('OM'), 'ativo_imobilizado_id' => $ativo->id, 'plano_manutencao_id' => $d['plano_manutencao_id'] ?? null,
                'tipo' => $d['tipo'], 'prioridade' => in_array($d['prioridade'] ?? null, OrdemManutencaoAtivo::PRIORIDADES, true) ? $d['prioridade'] : 'NORMAL',
                'estado' => OrdemManutencaoAtivo::PLANEADA, 'data_prevista' => substr((string) $d['data_prevista'], 0, 10), 'descricao' => $descricao,
                'responsavel' => trim((string) ($d['responsavel'] ?? '')) ?: null,
                'tarefas' => array_map(fn ($t) => array_merge($t, ['feito' => false, 'feito_por' => null, 'feito_em' => null]), self::tarefas($d['tarefas'] ?? [], true)),
                'horas' => 0, 'custo_mao_obra' => 0, 'custo_materiais' => 0, 'custo_servicos' => 0, 'criado_por' => Auth::user()?->nome_utilizador,
            ]);
            foreach ($itens as $i) {
                ItemOrdemManutencao::create($i + ['ordem_manutencao_id' => $o->id, 'quantidade_recebida' => 0, 'estado' => 'POR_PEDIR', 'custo' => 0]);
            }

            return $o->refresh();
        });
    }

    /**
     * Abre ordens a partir dos planos (gerarOrdensDosPlanos :271-303 e «Ordem» do plano, UI :861-876). Sem plano indicado,
     * percorre os planos activos vencidos ou a vencer; com plano, abre-o já (prioridade NORMAL). Nunca duplica uma ordem
     * aberta do mesmo plano para o mesmo activo.
     *
     * @return array{criadas: list<string>, saltadas: list<string>}
     */
    public function gerarDosPlanos(?PlanoManutencaoAtivo $so = null): array
    {
        return DB::transaction(function () use ($so) {
            $planos = $so ? collect([$so]) : PlanoManutencaoAtivo::query()->where(fn ($q) => $q->whereNull('ativo')->orWhere('ativo', true))->orderBy('id')->get();
            $abertas = OrdemManutencaoAtivo::query()->whereIn('estado', OrdemManutencaoAtivo::ABERTAS)->whereNotNull('plano_manutencao_id')
                ->get(['plano_manutencao_id', 'ativo_imobilizado_id'])->map(fn ($o) => "{$o->plano_manutencao_id}:{$o->ativo_imobilizado_id}")->flip();
            $criadas = [];
            $saltadas = [];
            foreach ($planos as $p) {
                $s = ServicoPlanosManutencao::situacao($p);
                if (! $so && ! in_array($s['chave'], ['VENCIDO', 'A_VENCER'], true)) {
                    continue;
                }
                if ($so && $p->ativo === false) {
                    throw new ErroNegocio('O plano está inactivo: reactive-o para abrir ordens.', 'PLANO_INACTIVO', 422);
                }
                $alvos = $this->planos->alvos($p)->filter(fn ($a) => $a->estado !== AtivoImobilizado::ESTADO_ABATIDO);
                if ($alvos->isEmpty()) {
                    $saltadas[] = "{$p->nome}: o plano não tem activos (categoria vazia ou activo abatido).";

                    continue;
                }
                $per = (array) $p->periodicidade;
                foreach ($alvos as $a) {
                    if (isset($abertas["{$p->id}:{$a->id}"])) {
                        $saltadas[] = "{$p->nome} / ".($a->codigo ?: $a->id).': já tem ordem aberta.';

                        continue;
                    }
                    $o = $this->criar([
                        'ativo_imobilizado_id' => $a->id, 'plano_manutencao_id' => $p->id, 'tipo' => $p->tipo,
                        'prioridade' => ! $so && $s['chave'] === 'VENCIDO' ? 'ALTA' : 'NORMAL',
                        'data_prevista' => ($per['modo'] ?? 'TEMPO') === 'TEMPO' ? ($p->proxima_data?->toDateString() ?? now()->toDateString()) : now()->toDateString(),
                        'descricao' => implode(' — ', array_filter([$p->nome, $p->instrucoes])), 'responsavel' => $p->responsavel,
                        'tarefas' => (array) ($p->tarefas ?? []), 'itens' => (array) ($p->itens ?? []),
                    ]);
                    $abertas["{$p->id}:{$a->id}"] = true;
                    $criadas[] = trim("{$o->numero} — {$a->codigo} {$a->descricao}");
                }
            }
            if ($so && ! $criadas) {
                throw new ErroNegocio($saltadas[0] ?? 'Nada a abrir.', 'NADA_A_GERAR', 422, ['saltadas' => $saltadas]);
            }
            $this->auditoria->registar('Activos', 'Gerar ordens dos planos', count($criadas).' criada(s)', 'ordens_manutencao_ativos');

            return ['criadas' => $criadas, 'saltadas' => $saltadas];
        });
    }

    // ───────────── Execução ─────────────

    /** Iniciar (iniciar-ordem, UI :517): PLANEADA ou AGUARDA_MATERIAL → EM_CURSO. */
    public function iniciar(OrdemManutencaoAtivo $o): OrdemManutencaoAtivo
    {
        return DB::transaction(function () use ($o) {
            $o = $this->aberta($o);
            if ($o->estado === OrdemManutencaoAtivo::EM_CURSO) {
                throw new ErroNegocio('A ordem já está em curso.', 'ORDEM_EM_CURSO', 422);
            }
            $o->update(['estado' => OrdemManutencaoAtivo::EM_CURSO, 'data_inicio' => $o->data_inicio ?? now()->toDateString(),
                'historico' => $this->comEvento($o, 'ORDEM_INICIADA')]);

            return $o->refresh();
        });
    }

    /**
     * Marca as tarefas feitas pela ordem da lista (od-tarefa, UI :504-508) — compatível com o pedido antigo; cada mudança
     * fica com quem/quando e no histórico. @param  list<bool>  $feitas  pela ordem das tarefas
     */
    public function marcarTarefas(OrdemManutencaoAtivo $o, array $feitas): OrdemManutencaoAtivo
    {
        return DB::transaction(function () use ($o, $feitas) {
            $o = $this->aberta($o);
            $tarefas = self::tarefas($o->tarefas);
            $alteradas = [];
            foreach ($tarefas as $k => $t) {
                if (array_key_exists($k, $feitas) && (bool) $feitas[$k] !== $t['feito']) {
                    $alteradas[] = $t['id'];
                }
            }
            $this->aplicarMarcacoes($o, $tarefas, array_fill_keys($alteradas, null));

            return $o->refresh();
        });
    }

    /**
     * Conclui ou reabre uma tarefa (r3_activos). Numa ordem aberta só muda a tarefa; numa ordem CONCLUÍDA, reabrir a tarefa
     * reabre a ordem — exige motivo. Ordem cancelada não aceita.
     */
    public function marcarTarefa(OrdemManutencaoAtivo $o, string $tarefaId, bool $feito, ?string $motivo = null): OrdemManutencaoAtivo
    {
        return DB::transaction(function () use ($o, $tarefaId, $feito, $motivo) {
            $o = OrdemManutencaoAtivo::query()->lockForUpdate()->findOrFail($o->id);
            if ($o->estado === OrdemManutencaoAtivo::CANCELADA) {
                throw new ErroNegocio('A ordem está cancelada.', 'ORDEM_FECHADA', 422);
            }
            $tarefas = self::tarefas($o->tarefas);
            $k = $this->indiceTarefa($tarefas, $tarefaId);
            if ($tarefas[$k]['feito'] === $feito) {
                return $o;
            }
            $motivo = trim((string) $motivo) !== '' ? mb_substr(trim((string) $motivo), 0, 1000) : null;
            if ($o->estado === OrdemManutencaoAtivo::CONCLUIDA) {
                if ($feito) {
                    throw new ErroNegocio('A ordem já está concluída.', 'ORDEM_FECHADA', 422);
                }
                if ($motivo === null) {
                    throw new ErroNegocio('Indique porque reabre a tarefa: a ordem concluída volta a ficar em curso.', 'MOTIVO_EM_FALTA', 422);
                }
                $this->reabrirOrdem($o, $motivo, $tarefas[$k]);
            }
            $this->aplicarMarcacoes($o, $tarefas, [$tarefaId => $motivo]);

            return $o->refresh();
        });
    }

    /** Acrescenta uma tarefa a uma ordem aberta (r3_activos). */
    public function acrescentarTarefa(OrdemManutencaoAtivo $o, string $texto, bool $obrigatoria = true): OrdemManutencaoAtivo
    {
        $texto = mb_substr(trim($texto), 0, 500);
        if ($texto === '') {
            throw new ErroNegocio('Descreva a tarefa.', 'DADOS_INVALIDOS', 422);
        }

        return DB::transaction(function () use ($o, $texto, $obrigatoria) {
            $o = $this->aberta($o);
            $tarefas = self::tarefas($o->tarefas);
            if (count($tarefas) >= 200) {
                throw new ErroNegocio('A ordem já tem o máximo de 200 tarefas.', 'DADOS_INVALIDOS', 422);
            }
            $nova = ['id' => self::novoId($tarefas), 'texto' => $texto, 'obrigatoria' => $obrigatoria, 'feito' => false, 'feito_por' => null, 'feito_em' => null];
            $tarefas[] = $nova;
            $o->update(['tarefas' => $tarefas, 'historico' => $this->comEvento($o, 'TAREFA_ACRESCENTADA', $nova)]);

            return $o->refresh();
        });
    }

    /** Retira uma tarefa ainda por fazer de uma ordem aberta (r3_activos); uma tarefa feita reabre-se primeiro. */
    public function retirarTarefa(OrdemManutencaoAtivo $o, string $tarefaId): OrdemManutencaoAtivo
    {
        return DB::transaction(function () use ($o, $tarefaId) {
            $o = $this->aberta($o);
            $tarefas = self::tarefas($o->tarefas);
            $k = $this->indiceTarefa($tarefas, $tarefaId);
            if ($tarefas[$k]['feito']) {
                throw new ErroNegocio('A tarefa já está feita: reabra-a antes de a retirar.', 'TAREFA_FEITA', 422);
            }
            $retirada = $tarefas[$k];
            array_splice($tarefas, $k, 1);
            $o->update(['tarefas' => $tarefas, 'historico' => $this->comEvento($o, 'TAREFA_RETIRADA', $retirada)]);

            return $o->refresh();
        });
    }

    /** Acrescentar necessidade (formularioItem, UI :530-566). */
    public function acrescentarItem(OrdemManutencaoAtivo $o, array $d): ItemOrdemManutencao
    {
        return DB::transaction(function () use ($o, $d) {
            $o = $this->aberta($o);
            if (empty($d['produto_id'])) {
                throw new ErroNegocio('Escolha o artigo ou serviço.', 'DADOS_INVALIDOS', 422);
            }
            $item = $this->normalizarItens([$d])[0];

            return ItemOrdemManutencao::create($item + ['ordem_manutencao_id' => $o->id, 'quantidade_recebida' => 0, 'estado' => 'POR_PEDIR', 'custo' => 0]);
        });
    }

    /** Retirar uma necessidade ainda por pedir (apagar-item, UI :514). */
    public function retirarItem(ItemOrdemManutencao $item): void
    {
        DB::transaction(function () use ($item) {
            $item = ItemOrdemManutencao::query()->lockForUpdate()->findOrFail($item->id);
            $this->aberta(OrdemManutencaoAtivo::query()->findOrFail($item->ordem_manutencao_id));
            if ($item->estado !== 'POR_PEDIR') {
                throw new ErroNegocio('Só se retiram necessidades ainda por pedir (as requisitadas ou em compra cancelam-se na requisição ou no pedido).', 'ITEM_JA_PEDIDO', 422);
            }
            $item->delete();
        });
    }

    /**
     * Conclusão (concluirOrdem :319-376 e formularioConclusao, UI :641-688).
     *
     * @param  array{resolucao: string, data_fim?: ?string, horas?: mixed, custo_mao_obra?: mixed, custo_servicos?: mixed, leitura_contador?: mixed, tarefas?: ?list<bool>, forcar?: ?bool}  $d
     */
    public function concluir(OrdemManutencaoAtivo $o, array $d): OrdemManutencaoAtivo
    {
        $resolucao = trim((string) ($d['resolucao'] ?? ''));
        if ($resolucao === '') {
            throw new ErroNegocio('Escreva o que foi feito na manutenção.', 'DADOS_INVALIDOS', 422);
        }
        foreach (['horas', 'custo_mao_obra', 'custo_servicos', 'leitura_contador'] as $c) {
            if (isset($d[$c]) && $d[$c] !== '' && (float) $d[$c] < 0) {
                throw new ErroNegocio("O campo {$c} não pode ser negativo.", 'DADOS_INVALIDOS', 422);
            }
        }

        return DB::transaction(function () use ($o, $d, $resolucao) {
            $o = OrdemManutencaoAtivo::query()->lockForUpdate()->findOrFail($o->id);
            if ($o->estado === OrdemManutencaoAtivo::CONCLUIDA) {
                throw new ErroNegocio('A ordem já está concluída.', 'ORDEM_FECHADA', 422);
            }
            if ($o->estado === OrdemManutencaoAtivo::CANCELADA) {
                throw new ErroNegocio('A ordem está cancelada e não pode ser concluída.', 'ORDEM_FECHADA', 422);
            }
            $dataFim = substr((string) ($d['data_fim'] ?? '') ?: now()->toDateString(), 0, 10);
            if ($o->data_inicio && $dataFim < $o->data_inicio->toDateString()) {
                throw new ErroNegocio('A data de conclusão não pode ser anterior à data de início.', 'DADOS_INVALIDOS', 422);
            }
            // r3_activos: as marcações enviadas com a conclusão contam (com quem/quando); com obrigatórias por fazer a ordem
            // fica pendente — o «forcar» só dispensa as necessidades por receber, nunca as tarefas
            $tarefas = self::tarefas($o->tarefas);
            if (isset($d['tarefas']) && is_array($d['tarefas'])) {
                $alteradas = [];
                foreach ($tarefas as $k => $t) {
                    if (array_key_exists($k, $d['tarefas']) && (bool) $d['tarefas'][$k] !== $t['feito']) {
                        $alteradas[] = $t['id'];
                    }
                }
                if ($alteradas) {
                    $this->aplicarMarcacoes($o, $tarefas, array_fill_keys($alteradas, null));
                    $o->refresh();
                    $tarefas = self::tarefas($o->tarefas);
                }
            }
            $prog = self::progresso($tarefas);
            if ($prog['obrigatorias_por_fazer'] > 0) {
                $falta = array_values(array_map(fn ($t) => $t['texto'], array_filter($tarefas, fn ($t) => $t['obrigatoria'] && ! $t['feito'])));
                throw new ErroNegocio("A ordem tem {$prog['obrigatorias_por_fazer']} tarefa(s) obrigatória(s) por concluir ({$prog['feitas']} de {$prog['total']} feitas): "
                    .'só se conclui quando todas estiverem terminadas.', 'TAREFAS_PENDENTES', 422, ['tarefas' => $falta, 'progresso' => $prog]);
            }
            $pendentes = ItemOrdemManutencao::query()->where('ordem_manutencao_id', $o->id)->whereIn('estado', ['REQUISITADO', 'EM_COMPRA'])->count();
            if ($pendentes && empty($d['forcar'])) {
                throw new ErroNegocio("A ordem tem {$pendentes} necessidade(s) por receber (requisição ou pedido de compra). Conclua mesmo assim?", 'NECESSIDADES_PENDENTES', 409,
                    ['pendentes' => $pendentes]);
            }
            $leitura = isset($d['leitura_contador']) && $d['leitura_contador'] !== '' && $d['leitura_contador'] !== null ? CalculadoraAmortizacoes::d($d['leitura_contador']) : null;
            $alt = [
                'estado' => OrdemManutencaoAtivo::CONCLUIDA, 'resolucao' => mb_substr($resolucao, 0, 5000), 'data_fim' => $dataFim,
                'data_inicio' => $o->data_inicio?->toDateString() ?? $dataFim, 'horas' => number_format((float) ($d['horas'] ?? 0), 3, '.', ''),
                'custo_mao_obra' => CalculadoraAmortizacoes::d($d['custo_mao_obra'] ?? 0),
                'custo_servicos' => array_key_exists('custo_servicos', $d) && $d['custo_servicos'] !== null ? CalculadoraAmortizacoes::d($d['custo_servicos']) : CalculadoraAmortizacoes::d($o->custo_servicos),
                'leitura_contador' => $leitura, 'concluido_em' => now(), 'concluido_por' => Auth::user()?->nome_utilizador,
            ];
            $opcionais = $prog['opcionais_por_fazer'];
            $alt['tarefas'] = $tarefas;
            $alt['historico'] = $this->comEvento($o, 'ORDEM_CONCLUIDA', null, $opcionais ? "{$opcionais} tarefa(s) opcional(is) por fazer" : null);
            // estado do plano antes de avançar: repõe-se se a ordem for reaberta
            $planoAntes = $o->plano_manutencao_id ? PlanoManutencaoAtivo::query()->find($o->plano_manutencao_id) : null;
            $alt['plano_antes'] = $planoAntes ? ['ultima_data' => $planoAntes->ultima_data?->toDateString(), 'proxima_data' => $planoAntes->proxima_data?->toDateString(),
                'execucoes' => (int) $planoAntes->execucoes, 'contador_atual' => $planoAntes->contador_atual, 'proximo_contador' => $planoAntes->proximo_contador] : null;
            $o->update($alt);
            $o->refresh();

            // histórico do activo: continua a ser registos_manutencao_ativos, que é o que a ficha do activo lê (:352-358)
            RegistoManutencaoAtivo::create([
                'ativo_imobilizado_id' => $o->ativo_imobilizado_id, 'tipo' => $o->tipo === 'CORRECTIVA' ? 'CORRECTIVA' : 'PREVENTIVA', 'tipo_original' => $o->tipo,
                'data' => $dataFim, 'descricao' => $o->descricao ?: $o->numero, 'resolucao' => $o->resolucao, 'custo' => $this->custoTotal($o),
                'estado' => RegistoManutencaoAtivo::CONCLUIDA, 'data_execucao' => $dataFim, 'ordem_manutencao_id' => $o->id, 'numero_ordem' => $o->numero,
            ]);

            // plano: a próxima vez conta-se a partir da execução real, não da data teórica (:360-373)
            if ($o->plano_manutencao_id) {
                $p = PlanoManutencaoAtivo::query()->lockForUpdate()->find($o->plano_manutencao_id);
                $per = (array) ($p?->periodicidade ?? []);
                if ($p && $per) {
                    $altPlano = ['ultima_data' => $dataFim, 'execucoes' => (int) $p->execucoes + 1];
                    if (($per['modo'] ?? 'TEMPO') === 'TEMPO') {
                        $altPlano['proxima_data'] = ServicoPlanosManutencao::somar($dataFim, (int) ($per['intervalo'] ?? 1), (string) ($per['unidade'] ?? '')) ?? $p->proxima_data;
                    } elseif ($leitura !== null) {
                        $altPlano['contador_atual'] = $leitura;
                        $altPlano['proximo_contador'] = bcadd($leitura, (string) (int) ($per['intervalo'] ?? 0), 2);
                    }
                    $p->update($altPlano);
                }
            }

            return $o;
        });
    }

    /** Cancelamento com motivo (cancelarOrdem :378-391). Devolve quantas necessidades já tinham sido pedidas (não se anulam). */
    public function cancelar(OrdemManutencaoAtivo $o, string $motivo): array
    {
        if (trim($motivo) === '') {
            throw new ErroNegocio('Indique o motivo do cancelamento.', 'DADOS_INVALIDOS', 422);
        }

        return DB::transaction(function () use ($o, $motivo) {
            $o = OrdemManutencaoAtivo::query()->lockForUpdate()->findOrFail($o->id);
            if ($o->estado === OrdemManutencaoAtivo::CONCLUIDA) {
                throw new ErroNegocio('Uma ordem concluída não se cancela.', 'ORDEM_FECHADA', 422);
            }
            if ($o->estado === OrdemManutencaoAtivo::CANCELADA) {
                throw new ErroNegocio('A ordem já está cancelada.', 'ORDEM_FECHADA', 422);
            }
            $pedidos = ItemOrdemManutencao::query()->where('ordem_manutencao_id', $o->id)->whereIn('estado', ['REQUISITADO', 'EM_COMPRA'])->count();
            $o->update(['estado' => OrdemManutencaoAtivo::CANCELADA, 'motivo_cancelamento' => mb_substr(trim($motivo), 0, 1000), 'cancelado_em' => now(),
                'cancelado_por' => Auth::user()?->nome_utilizador, 'historico' => $this->comEvento($o, 'ORDEM_CANCELADA', null, mb_substr(trim($motivo), 0, 1000))]);

            return ['ordem' => $o->refresh(), 'pedidos_emitidos' => $pedidos];
        });
    }

    // ───────────── Necessidades → requisição / pedido de compra ─────────────

    /** Pré-visualização linha a linha (planoDeRequisicao :415-438): para onde vai e porquê. */
    public function planoDeRequisicao(OrdemManutencaoAtivo $o, ?int $armazemId): array
    {
        $itens = ItemOrdemManutencao::query()->with('produto')->where('ordem_manutencao_id', $o->id)->where('estado', 'POR_PEDIR')->orderBy('id')->get();
        $linhas = [];
        foreach ($itens as $i) {
            $prod = $i->produto;
            $qtd = bccomp((string) $i->quantidade, '0', 3) > 0 ? number_format((float) $i->quantidade, 3, '.', '') : '1.000';
            $nome = $prod ? trim(($prod->codigo ? "{$prod->codigo} — " : '').$prod->nome) : ($i->descricao ?: 'Linha sem artigo');
            $base = ['item_id' => $i->id, 'nome' => $nome, 'tipo' => $i->tipo, 'produto_id' => $i->produto_id, 'quantidade' => $qtd];
            if (! $prod) {
                // o pedido de compra exige um artigo: a linha fica por pedir até se indicar um artigo (serviço)
                $linhas[] = $base + ['stock' => null, 'para_armazem' => '0.000', 'para_compra' => '0.000', 'bloqueada' => true,
                    'porque' => 'Linha sem artigo: indique um artigo (serviço) para a pedir a compras'];

                continue;
            }
            if ($i->tipo === 'SERVICO' || ! $prod->movimenta_stock) {
                $linhas[] = $base + ['stock' => null, 'para_armazem' => '0.000', 'para_compra' => $qtd, 'bloqueada' => false, 'porque' => 'Serviço ou artigo que não é de armazém'];

                continue;
            }
            $stock = $this->stockDisponivel($prod, $armazemId);
            $paraArmazem = bccomp($stock, '0', 3) <= 0 ? '0.000' : (bccomp($stock, $qtd, 3) < 0 ? $stock : $qtd);
            $paraCompra = bcsub($qtd, $paraArmazem, 3);
            $linhas[] = $base + ['stock' => $stock, 'para_armazem' => $paraArmazem, 'para_compra' => $paraCompra, 'bloqueada' => false,
                'porque' => bccomp($paraCompra, '0', 3) <= 0 ? 'Material com stock suficiente'
                    : (bccomp($paraArmazem, '0', 3) > 0 ? 'Stock só dá para '.rtrim(rtrim($paraArmazem, '0'), '.').' — a falta vai a compras' : 'Material sem stock')];
        }

        return $linhas;
    }

    /**
     * Executa a repartição (requisitar :441-516): no máximo uma requisição ao armazém e um pedido de compra.
     *
     * @return array{requisicao_id: ?int, requisicao_numero: ?string, pedido_compra_id: ?int, pedido_compra_numero: ?string, armazem: int, compra: int}
     */
    public function requisitar(OrdemManutencaoAtivo $o, ?int $armazemId, ?string $requerente = null): array
    {
        return DB::transaction(function () use ($o, $armazemId, $requerente) {
            $o = OrdemManutencaoAtivo::query()->lockForUpdate()->findOrFail($o->id);
            if (! in_array($o->estado, OrdemManutencaoAtivo::ABERTAS, true)) {
                throw new ErroNegocio('A ordem já está fechada.', 'ORDEM_FECHADA', 422);
            }
            $plano = array_values(array_filter($this->planoDeRequisicao($o, $armazemId),
                fn ($l) => bccomp($l['para_armazem'], '0', 3) > 0 || bccomp($l['para_compra'], '0', 3) > 0));
            if (! $plano) {
                throw new ErroNegocio('Não há necessidades por pedir nesta ordem.', 'SEM_NECESSIDADES', 422);
            }
            $paraArmazem = array_values(array_filter($plano, fn ($l) => bccomp($l['para_armazem'], '0', 3) > 0));
            if ($paraArmazem && ! $armazemId) {
                throw new ErroNegocio('Escolha o armazém de onde sai o material.', 'ARMAZEM_EM_FALTA', 422);
            }
            $ativo = AtivoImobilizado::withTrashed()->find($o->ativo_imobilizado_id);
            $refAtivo = $ativo ? trim("{$ativo->codigo} {$ativo->descricao}") : "activo {$o->ativo_imobilizado_id}";
            $requerente = trim((string) $requerente) ?: ($o->responsavel ?: (Auth::user()?->nome_utilizador ?? 'Manutenção'));
            $hoje = now()->toDateString();
            $req = null;
            $pedido = null;

            // 1) requisição ao armazém — material que há em casa
            if ($paraArmazem) {
                $req = RequisicaoManutencao::create(['numero' => $this->numeracao->proximo('RQ'), 'ordem_manutencao_id' => $o->id, 'ativo_imobilizado_id' => $o->ativo_imobilizado_id,
                    'armazem_id' => $armazemId, 'requerente' => mb_substr($requerente, 0, 150), 'data' => $hoje, 'estado' => RequisicaoManutencao::PENDENTE,
                    'criado_por' => Auth::user()?->nome_utilizador]);
                foreach ($paraArmazem as $l) {
                    LinhaRequisicaoManutencao::create(['requisicao_manutencao_id' => $req->id, 'item_ordem_manutencao_id' => $l['item_id'], 'produto_id' => $l['produto_id'],
                        'descricao' => $l['nome'], 'quantidade' => $l['para_armazem'], 'quantidade_entregue' => 0, 'estado' => 'PENDENTE', 'custo' => 0]);
                }
            }

            // 2) pedido de compra — serviços e o material que falta, pelo circuito de Compras (deliberação incluída)
            $paraCompra = array_values(array_filter($plano, fn ($l) => bccomp($l['para_compra'], '0', 3) > 0));
            if ($paraCompra) {
                $pedido = $this->compras->criarPedido([
                    'nome_requerente' => mb_substr("{$requerente} (Manutenção {$o->numero})", 0, 255), 'data' => $hoje,
                    'descricao' => mb_substr("Manutenção {$o->numero} — {$refAtivo}", 0, 255), 'data_entrega' => $o->data_prevista?->toDateString() ?? $hoje,
                    'observacoes' => implode("\n", array_filter(["Gerado pela ordem de manutenção {$o->numero}.", $o->descricao ? "Trabalho: {$o->descricao}" : null])),
                    'centro_custo_id' => $ativo?->centro_custo_id, 'unidade_negocio_id' => $ativo?->unidade_negocio_id,
                    'linhas' => array_map(fn ($l) => ['produto_id' => $l['produto_id'], 'quantidade' => $l['para_compra'], 'descricao' => $l['nome']], $paraCompra),
                ]);
            }

            // 3) estado de cada necessidade — uma linha pode ir aos dois sítios
            foreach ($plano as $l) {
                $armazem = bccomp($l['para_armazem'], '0', 3) > 0;
                $compra = bccomp($l['para_compra'], '0', 3) > 0;
                ItemOrdemManutencao::query()->whereKey($l['item_id'])->first()?->update([
                    'estado' => $armazem ? 'REQUISITADO' : 'EM_COMPRA', 'destino' => $armazem && $compra ? 'AMBOS' : ($armazem ? 'ARMAZEM' : 'COMPRA'),
                    'requisicao_manutencao_id' => $armazem ? $req?->id : null, 'pedido_compra_id' => $compra ? $pedido?->id : null,
                    'quantidade_armazem' => $l['para_armazem'], 'quantidade_compra' => $l['para_compra'],
                ]);
            }
            if ($o->estado === OrdemManutencaoAtivo::PLANEADA) {
                $o->update(['estado' => OrdemManutencaoAtivo::AGUARDA_MATERIAL]);
            }
            $this->auditoria->registar('Activos', 'Requisitar necessidades', "{$o->numero}: ".count($paraArmazem).' ao armazém, '.count($paraCompra).' a compras',
                'ordens_manutencao_ativos', $o->id);

            return ['requisicao_id' => $req?->id, 'requisicao_numero' => $req?->numero, 'pedido_compra_id' => $pedido?->id, 'pedido_compra_numero' => $pedido?->numero_pedido,
                'armazem' => count($paraArmazem), 'compra' => count($paraCompra)];
        });
    }

    /**
     * Custo dos serviços sugerido (custoServicosDaOrdem :627-642): Σ das facturas de compra (não anuladas) das encomendas
     * dos pedidos da ordem; sem factura, Σ quantidade × preço das linhas desses pedidos.
     */
    public function custoServicosSugerido(OrdemManutencaoAtivo $o): string
    {
        $pedidos = ItemOrdemManutencao::query()->where('ordem_manutencao_id', $o->id)->whereNotNull('pedido_compra_id')->distinct()->pluck('pedido_compra_id');
        if ($pedidos->isEmpty()) {
            return '0.00';
        }
        $facturado = (string) FaturaCompra::query()->whereIn('encomenda_compra_id', EncomendaCompra::query()->whereIn('pedido_compra_id', $pedidos)->select('id'))
            ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADA'))->sum('montante_total');
        if (bccomp($facturado, '0', 2) > 0) {
            return CalculadoraAmortizacoes::d($facturado);
        }

        return CalculadoraAmortizacoes::d(ItemCompra::query()->where('tipo_documento_origem', 'PEDIDO')->whereIn('pedido_compra_id', $pedidos)
            ->selectRaw('COALESCE(SUM(quantidade * COALESCE(preco_unitario, 0)), 0) AS t')->value('t'));
    }

    public function custoTotal(OrdemManutencaoAtivo $o): string
    {
        return bcadd(bcadd(CalculadoraAmortizacoes::d($o->custo_materiais), CalculadoraAmortizacoes::d($o->custo_servicos), 2), CalculadoraAmortizacoes::d($o->custo_mao_obra), 2);
    }

    /** Stock disponível (stockDisponivel :402-412): do armazém escolhido; sem armazém, Σ dos armazéns; em último caso o total do artigo. */
    public function stockDisponivel(Produto $p, ?int $armazemId): string
    {
        if ($armazemId) {
            return number_format((float) StockArmazem::query()->where('armazem_id', $armazemId)->where('produto_id', $p->id)->value('quantidade_stock'), 3, '.', '');
        }
        $linhas = StockArmazem::query()->where('produto_id', $p->id);
        if ($linhas->exists()) {
            return number_format((float) $linhas->sum('quantidade_stock'), 3, '.', '');
        }

        return number_format((float) $p->quantidade_stock, 3, '.', '');
    }

    // ───────────── Tarefas (r3_activos) ─────────────

    /**
     * Normaliza a checklist: aceita o formato antigo ({texto, feito} ou texto simples) e o novo. Sem id, a tarefa recebe
     * «t<n>» pela posição (estável até ser gravada); sem «obrigatoria», é obrigatória. Com $nova os ids vindos do pedido
     * são ignorados (criação).
     *
     * @return list<array{id: string, texto: string, obrigatoria: bool, feito: bool, feito_por: ?string, feito_em: ?string}>
     */
    public static function tarefas(mixed $lista, bool $nova = false): array
    {
        $res = [];
        $usados = [];
        foreach (array_values(is_array($lista) ? $lista : []) as $k => $t) {
            $texto = mb_substr(trim((string) (is_array($t) ? ($t['texto'] ?? '') : $t)), 0, 500);
            if ($texto === '') {
                continue;
            }
            $id = is_array($t) && ! $nova && isset($t['id']) && preg_match('/^[a-z0-9]{1,20}$/', (string) $t['id']) ? (string) $t['id'] : 't'.($k + 1);
            while (isset($usados[$id])) {
                $id = 't'.($k + 1).'x'.count($usados);
            }
            $usados[$id] = true;
            $feito = ! $nova && is_array($t) && (bool) ($t['feito'] ?? false);
            $res[] = ['id' => $id, 'texto' => $texto, 'obrigatoria' => ! is_array($t) || ! array_key_exists('obrigatoria', $t) || (bool) $t['obrigatoria'],
                'feito' => $feito, 'feito_por' => $feito ? ($t['feito_por'] ?? null) : null, 'feito_em' => $feito ? ($t['feito_em'] ?? null) : null];
        }

        return $res;
    }

    /**
     * Progresso «N de M» e situação derivada da checklist.
     *
     * @return array{total: int, feitas: int, obrigatorias: int, obrigatorias_por_fazer: int, opcionais_por_fazer: int, percentagem: int, situacao: string}
     */
    public static function progresso(array $tarefas): array
    {
        $total = count($tarefas);
        $feitas = count(array_filter($tarefas, fn ($t) => $t['feito']));
        $obrigatorias = count(array_filter($tarefas, fn ($t) => $t['obrigatoria']));
        $obrigPorFazer = count(array_filter($tarefas, fn ($t) => $t['obrigatoria'] && ! $t['feito']));

        return ['total' => $total, 'feitas' => $feitas, 'obrigatorias' => $obrigatorias, 'obrigatorias_por_fazer' => $obrigPorFazer,
            'opcionais_por_fazer' => $total - $feitas - $obrigPorFazer, 'percentagem' => $total ? (int) floor($feitas * 100 / $total) : 0,
            'situacao' => $total === 0 ? 'SEM_TAREFAS' : ($obrigPorFazer === 0 ? 'PRONTA' : ($feitas === 0 ? 'POR_INICIAR' : 'EM_CURSO'))];
    }

    /**
     * Inverte as tarefas indicadas (id => motivo), com quem/quando e um evento por tarefa; a primeira tarefa feita numa ordem
     * PLANEADA/AGUARDA_MATERIAL passa-a a EM_CURSO. Corre dentro da transacção do chamador, com a ordem bloqueada.
     *
     * @param  array<string, ?string>  $ids
     */
    private function aplicarMarcacoes(OrdemManutencaoAtivo $o, array $tarefas, array $ids): void
    {
        if (! $ids) {
            return;
        }
        $quem = Auth::user()?->nome_utilizador;
        $historico = array_values((array) ($o->historico ?? []));
        $algumaFeita = false;
        foreach ($tarefas as $k => $t) {
            if (! array_key_exists($t['id'], $ids)) {
                continue;
            }
            $feito = ! $t['feito'];
            $tarefas[$k] = array_merge($t, ['feito' => $feito, 'feito_por' => $feito ? $quem : null, 'feito_em' => $feito ? now()->toIso8601String() : null]);
            $historico[] = $this->evento($feito ? 'TAREFA_CONCLUIDA' : 'TAREFA_REABERTA', $t, $ids[$t['id']]);
            $algumaFeita = $algumaFeita || $feito;
        }
        $alt = ['tarefas' => $tarefas, 'historico' => array_slice($historico, -500)];
        if ($algumaFeita && in_array($o->estado, [OrdemManutencaoAtivo::PLANEADA, OrdemManutencaoAtivo::AGUARDA_MATERIAL], true)) {
            $alt += ['estado' => OrdemManutencaoAtivo::EM_CURSO, 'data_inicio' => $o->data_inicio?->toDateString() ?? now()->toDateString()];
        }
        $o->update($alt);
    }

    /**
     * Reabre uma ordem concluída (ao reabrir uma tarefa): EM_CURSO, sem data de fim; sai do histórico do activo e o plano
     * volta ao estado anterior à conclusão se ainda for o desta ordem (não avançou por outra ordem entretanto).
     */
    private function reabrirOrdem(OrdemManutencaoAtivo $o, string $motivo, array $tarefa): void
    {
        RegistoManutencaoAtivo::query()->where('ordem_manutencao_id', $o->id)->get()->each->delete();
        $antes = (array) ($o->plano_antes ?? []);
        if ($o->plano_manutencao_id && $antes) {
            $p = PlanoManutencaoAtivo::query()->lockForUpdate()->find($o->plano_manutencao_id);
            if ($p && $p->ultima_data?->toDateString() === $o->data_fim?->toDateString() && (int) $p->execucoes === (int) ($antes['execucoes'] ?? 0) + 1) {
                $p->update(['ultima_data' => $antes['ultima_data'] ?? null, 'proxima_data' => $antes['proxima_data'] ?? null, 'execucoes' => (int) ($antes['execucoes'] ?? 0),
                    'contador_atual' => $antes['contador_atual'] ?? $p->contador_atual, 'proximo_contador' => $antes['proximo_contador'] ?? $p->proximo_contador]);
            }
        }
        $o->update(['estado' => OrdemManutencaoAtivo::EM_CURSO, 'data_fim' => null, 'concluido_em' => null, 'concluido_por' => null, 'plano_antes' => null,
            'reaberta_em' => now(), 'reaberta_por' => Auth::user()?->nome_utilizador, 'historico' => $this->comEvento($o, 'ORDEM_REABERTA', $tarefa, $motivo)]);
        $this->auditoria->registar('Activos', 'Reabrir ordem de manutenção', "{$o->numero}: tarefa «{$tarefa['texto']}» reaberta — {$motivo}", 'ordens_manutencao_ativos', $o->id);
        $o->refresh();
    }

    /** @param  list<array{id: string}>  $tarefas */
    private function indiceTarefa(array $tarefas, string $id): int
    {
        foreach ($tarefas as $k => $t) {
            if ($t['id'] === $id) {
                return $k;
            }
        }
        throw new ErroNegocio('Tarefa não encontrada nesta ordem.', 'TAREFA_INEXISTENTE', 404);
    }

    /** @param  list<array{id: string}>  $tarefas */
    private static function novoId(array $tarefas): string
    {
        $usados = array_flip(array_column($tarefas, 'id'));
        do {
            $id = 'k'.bin2hex(random_bytes(4));
        } while (isset($usados[$id]));

        return $id;
    }

    private function evento(string $accao, ?array $tarefa = null, ?string $motivo = null): array
    {
        return array_filter(['em' => now()->toIso8601String(), 'por' => Auth::user()?->nome_utilizador, 'accao' => $accao,
            'tarefa' => $tarefa['id'] ?? null, 'texto' => $tarefa['texto'] ?? null, 'motivo' => $motivo], fn ($v) => $v !== null);
    }

    /** Histórico da ordem com mais um evento (guarda os últimos 500). */
    private function comEvento(OrdemManutencaoAtivo $o, string $accao, ?array $tarefa = null, ?string $motivo = null): array
    {
        return array_slice([...array_values((array) ($o->historico ?? [])), $this->evento($accao, $tarefa, $motivo)], -500);
    }

    // ───────────── Auxiliares ─────────────

    private function aberta(OrdemManutencaoAtivo $o): OrdemManutencaoAtivo
    {
        $o = OrdemManutencaoAtivo::query()->lockForUpdate()->findOrFail($o->id);
        if (! in_array($o->estado, OrdemManutencaoAtivo::ABERTAS, true)) {
            throw new ErroNegocio($o->estado === OrdemManutencaoAtivo::CONCLUIDA ? 'A ordem já está concluída.' : 'A ordem está cancelada.', 'ORDEM_FECHADA', 422);
        }

        return $o;
    }

    /** @return list<array{tipo: string, produto_id: ?int, descricao: ?string, quantidade: string}> */
    private function normalizarItens(array $itens): array
    {
        $produtos = Produto::query()->whereIn('id', array_filter(array_map(fn ($i) => (int) ($i['produto_id'] ?? 0), $itens)))->get()->keyBy('id');
        $res = [];
        foreach (array_values($itens) as $n => $i) {
            $prodId = ! empty($i['produto_id']) ? (int) $i['produto_id'] : null;
            $descricao = trim((string) ($i['descricao'] ?? ''));
            if (! $prodId && $descricao === '') {
                continue;
            }
            $prod = $prodId ? ($produtos[$prodId] ?? throw new ErroNegocio('Necessidade '.($n + 1).': artigo inexistente.', 'DADOS_INVALIDOS', 422)) : null;
            $q = (float) ($i['quantidade'] ?? 0);
            if (isset($i['quantidade']) && $i['quantidade'] !== '' && $q <= 0) {
                throw new ErroNegocio('Necessidade '.($n + 1).': indique a quantidade.', 'DADOS_INVALIDOS', 422);
            }
            $res[] = ['tipo' => ($i['tipo'] ?? '') === 'SERVICO' ? 'SERVICO' : 'MATERIAL', 'produto_id' => $prodId,
                'descricao' => mb_substr($descricao !== '' ? $descricao : trim(($prod?->codigo ? "{$prod->codigo} — " : '').($prod?->nome ?? '')), 0, 2000),
                'quantidade' => number_format($q > 0 ? $q : 1, 3, '.', '')];
        }

        return $res;
    }
}
