<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Http\Requests\POS\Lavandaria\AccaoLinhasRequest;
use App\Http\Requests\POS\Lavandaria\AlterarEstadoRequest;
use App\Http\Requests\POS\Lavandaria\AnularReciboRequest;
use App\Http\Requests\POS\Lavandaria\AnularRequest;
use App\Http\Requests\POS\Lavandaria\AtribuirRequest;
use App\Http\Requests\POS\Lavandaria\ColaboradoresRequest;
use App\Http\Requests\POS\Lavandaria\DecidirReclamacaoRequest;
use App\Http\Requests\POS\Lavandaria\EntregarRequest;
use App\Http\Requests\POS\Lavandaria\GuardarDefinicoesRequest;
use App\Http\Requests\POS\Lavandaria\GuardarPecaRequest;
use App\Http\Requests\POS\Lavandaria\GuardarServicoRequest;
use App\Http\Requests\POS\Lavandaria\MaterialRequest;
use App\Http\Requests\POS\Lavandaria\OrcamentosRequest;
use App\Http\Requests\POS\Lavandaria\OrdensRequest;
use App\Http\Requests\POS\Lavandaria\PagarReclamacaoRequest;
use App\Http\Requests\POS\Lavandaria\ReceberRequest;
use App\Http\Requests\POS\Lavandaria\ReclamacoesListaRequest;
use App\Http\Requests\POS\Lavandaria\RegistarReclamacaoRequest;
use App\Http\Requests\POS\Lavandaria\RegistarRequest;
use App\Http\Requests\POS\Lavandaria\RelatorioPeriodoRequest;
use App\Http\Requests\POS\Lavandaria\ServicosRequest;
use App\Http\Requests\POS\Lavandaria\SimularEntregaRequest;
use App\Http\Resources\POS\PecaLavandariaResource;
use App\Http\Resources\POS\ReclamacaoLavandariaResource;
use App\Models\Colaborador;
use App\Models\PecaLavandaria;
use App\Models\PedidoLavandaria;
use App\Models\Produto;
use App\Models\ReclamacaoLavandaria;
use App\Models\TerminalPOS;
use App\Services\POS\Lavandaria\RegrasLavandaria;
use App\Services\POS\Lavandaria\ServicoCaixaLavandaria;
use App\Services\POS\Lavandaria\ServicoConfigLavandaria;
use App\Services\POS\Lavandaria\ServicoOrdensLavandaria;
use App\Services\POS\Lavandaria\ServicoReclamacoesLavandaria;
use App\Services\POS\Lavandaria\ServicoRelatorioLavandaria;
use App\Services\POS\Lavandaria\ServicoTabelasLavandaria;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/pos/lavandaria — definições, peças e serviços, ordens de serviço (recepção, execução, orçamentos, atribuição, entrega),
 * recebimentos, danos e reclamações e relatório (js/lavandaria.js). Permissões do catálogo (permissoes.js:251-258):
 * lav_ordens, lav_receber, lav_anular, lav_tabelas, lav_dano_decidir, lav_dano_pagar; consulta com pos_lavandaria_view ou qualquer lav_*.
 */
final class LavandariaController extends Controller
{
    private const VER = ['pos_lavandaria_view', 'lav_ordens', 'lav_receber', 'lav_anular', 'lav_tabelas', 'lav_dano_decidir', 'lav_dano_pagar'];

    private const PAGAMENTOS = ['pagamentos' => ['nullable', 'array'], 'pagamentos.*.meio_id' => ['required', 'string', 'max:40'],
        'pagamentos.*.valor' => ['required', 'numeric', 'gt:0'], 'pagamentos.*.referencia' => ['nullable', 'string', 'max:100']];

    public function __construct(
        private readonly ServicoConfigLavandaria $config,
        private readonly ServicoTabelasLavandaria $tabelas,
        private readonly ServicoOrdensLavandaria $ordens,
        private readonly ServicoCaixaLavandaria $caixa,
        private readonly ServicoReclamacoesLavandaria $reclamacoes,
        private readonly ServicoRelatorioLavandaria $relatorio,
    ) {}

    // ───────────── Definições e tabelas ─────────────

    public function definicoes(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->config->obter() + ['estados_entrada' => RegrasLavandaria::ESTADOS_ENTRADA], 'Definições da lavandaria.');
    }

    public function guardarDefinicoes(GuardarDefinicoesRequest $r): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->config->guardar($d), 'Definições da lavandaria gravadas.');
    }

    public function pecas(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(PecaLavandariaResource::collection(PecaLavandaria::query()->when(! $r->boolean('incluir_inativas'), fn ($q) => $q->where(fn ($q) => $q->whereNull('ativo')->orWhere('ativo', true)))
            ->ordenarPorNome()->get()), 'Tabela de peças.');
    }

    public function guardarPeca(GuardarPecaRequest $r, ?int $peca = null): JsonResponse
    {
        $d = $r->validated();
        $p = $peca ? PecaLavandaria::query()->findOrFail($peca) : null;
        $res = $this->tabelas->guardarPeca($d, $p);

        return $p ? RespostaApi::sucesso($res, 'Peça actualizada.') : RespostaApi::criado($res, 'Peça criada.');
    }

    /** ?terminal_pos_id= filtra para os serviços configurados nesse terminal (null no terminal = sem restrição). */
    public function servicos(ServicosRequest $r): JsonResponse
    {
        $f = $r->validated();
        $ids = isset($f['terminal_pos_id']) ? TerminalPOS::query()->findOrFail($f['terminal_pos_id'])->lavandaria_servicos_ids : null;

        return RespostaApi::sucesso(Produto::query()->whereIn('lavandaria_grupo', RegrasLavandaria::GRUPOS)
            ->when(! $r->boolean('incluir_inativos'), fn ($q) => $q->where(fn ($q) => $q->whereNull('lavandaria_ativa')->orWhere('lavandaria_ativa', true)))
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('lavandaria_grupo')->ordenarPorNome()
            ->get(['id', 'codigo', 'nome', 'lavandaria_grupo', 'codigo_conta', 'taxa_imposto', 'lavandaria_dias_entrega', 'lavandaria_requer_orcamento', 'lavandaria_ativa']), 'Tabela de serviços.');
    }

    public function guardarServico(GuardarServicoRequest $r, ?int $servico = null): JsonResponse
    {
        $d = $r->validated();
        $s = $servico ? Produto::query()->findOrFail($servico) : null;
        $res = $this->tabelas->guardarServico($d, $s);

        return $s ? RespostaApi::sucesso($res, 'Serviço actualizado.') : RespostaApi::criado($res, 'Serviço criado.');
    }

    // ───────────── Ordens ─────────────

    public function ordens(OrdensRequest $r): JsonResponse
    {
        $f = $r->validated();

        return RespostaApi::sucesso($this->ordens->listar($f), 'Ordens de serviço.');
    }

    /**
     * GET /colaboradores — só id e nome (e se está activo), para atribuir ordens e filtrar por responsável sem as
     * permissões do RH (ADR-064). Por omissão só os não inactivos; ?todos=1 inclui os inactivos (ordens antigas).
     */
    public function colaboradores(ColaboradoresRequest $r): JsonResponse
    {
        $f = $r->validated();

        return RespostaApi::sucesso(Colaborador::query()->when(! ($f['todos'] ?? false), fn ($q) => $q->where(fn ($x) => $x->whereNull('estado')->orWhere('estado', '<>', 'INACTIVO')))
            ->ordenarPorNome('nome_completo')->get(['id', 'nome_completo', 'estado'])
            ->map(fn ($c) => ['id' => $c->id, 'nome' => $c->nome_completo, 'activo' => $c->estado !== 'INACTIVO'])->values(), 'Colaboradores.');
    }

    public function ordem(int $ordem): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->ordens->detalhe($ordem), 'Ordem de serviço.');
    }

    public function registar(RegistarRequest $r, int $sessao): JsonResponse
    {
        $d = $r->validated();
        $res = $this->ordens->registar($sessao, $d);

        return RespostaApi::criado($res, "Ordem {$res['pedido']->numero_encomenda} registada.");
    }

    public function accaoLinhas(AccaoLinhasRequest $r, int $ordem): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->ordens->accaoLinhas($ordem, isset($d['linha_id']) ? (int) $d['linha_id'] : null, $d['accao'], $d['executado_por'] ?? null),
            'Estado das peças actualizado.');
    }

    public function orcamentos(OrcamentosRequest $r): JsonResponse
    {
        $d = $r->validated();
        $res = $this->ordens->decidirOrcamentos($d['decisao'], $d['linhas'], $d['canal'] ?? 'Presencial', $d['nota'] ?? null);

        return RespostaApi::sucesso($res, "{$res['tratados']} orçamento(s) ".($d['decisao'] === 'APROVAR' ? 'aprovado(s).' : 'recusado(s).'));
    }

    public function alterarEstado(AlterarEstadoRequest $r): JsonResponse
    {
        $d = $r->validated();
        $d['estado'] === 'ANULADA' ? $this->exigir('lav_anular') : $this->exigir('lav_ordens');   // no legado anular por aqui bastava lav_ordens
        $res = $this->ordens->alterarEstado(array_map('intval', $d['ids']), $d['estado'], isset($d['colaborador_id']) ? (int) $d['colaborador_id'] : null, $d['motivo'] ?? null);

        return RespostaApi::sucesso($res, "{$res['alteradas']} ordem(ns) alterada(s).");
    }

    public function atribuir(AtribuirRequest $r): JsonResponse
    {
        $d = $r->validated();
        $res = $this->ordens->atribuir(array_map('intval', $d['ids']), isset($d['colaborador_id']) ? (int) $d['colaborador_id'] : null, (bool) ($d['retirar'] ?? false),
            $d['data'] ?? null, $d['aplicar'] ?? 'PENDENTES', $d['nota'] ?? null);

        return RespostaApi::sucesso($res, "{$res['alteradas']} ordem(ns) actualizada(s).");
    }

    public function anular(AnularRequest $r, int $ordem): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->ordens->anular($ordem, $d['motivo']), 'Ordem anulada.');
    }

    public function material(MaterialRequest $r, int $ordem, int $linha): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->ordens->consumirMaterial($ordem, $linha, (int) $d['produto_id'], (string) $d['quantidade']), 'Material abatido ao stock.');
    }

    public function registarReclamacao(RegistarReclamacaoRequest $r, int $ordem, int $linha): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::criado($this->reclamacoes->registar($ordem, $linha, $d['descricao'], $d['valor_declarado'] ?? 0), 'Dano/reclamação registado.');
    }

    // ───────────── Dinheiro (sessão aberta) ─────────────

    public function receber(ReceberRequest $r, int $sessao, int $ordem): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::criado($this->caixa->receber($sessao, $ordem, RegrasLavandaria::dinheiro($d['valor']), $d['pagamentos']), 'Recebimento registado.');
    }

    public function faturar(int $sessao, int $ordem): JsonResponse
    {
        $this->exigir('lav_ordens');

        return RespostaApi::criado($this->caixa->faturarPendente($sessao, $ordem), 'Factura emitida.');
    }

    public function simularEntrega(SimularEntregaRequest $r, int $ordem): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->caixa->simularEntrega(PedidoLavandaria::query()->findOrFail($ordem), array_map('intval', $d['linhas'])), 'Simulação da entrega.');
    }

    public function entregar(EntregarRequest $r, int $sessao, int $ordem): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->caixa->entregar($sessao, $ordem, $d['linhas'], RegrasLavandaria::dinheiro($d['valor'] ?? 0), $d['pagamentos'] ?? []), 'Entrega registada.');
    }

    public function anularRecibo(AnularReciboRequest $r, int $pagamento): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->caixa->anularRecibo($pagamento, $d['motivo']), 'Recibo anulado.');
    }

    // ───────────── Danos e reclamações ─────────────

    public function reclamacoesLista(ReclamacoesListaRequest $r): JsonResponse
    {
        $f = $r->validated();

        return RespostaApi::sucesso(ReclamacaoLavandariaResource::collection(ReclamacaoLavandaria::query()->when($f['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))->orderByDesc('criado_em')->orderByDesc('id')->get()),
            'Danos e reclamações.');
    }

    public function decidirReclamacao(DecidirReclamacaoRequest $r, int $reclamacao): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->reclamacoes->decidir($reclamacao, $d['decisao'], $d['referencia_comprovativo'] ?? null, $d['data_comprovativo'] ?? null,
            $d['valor_comprovativo'] ?? 0, $d['nota_decisao'] ?? null), 'Decisão registada.');
    }

    public function pagarReclamacao(PagarReclamacaoRequest $r, int $reclamacao): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->reclamacoes->pagar($reclamacao, $d['data'], $d['conta_financeira']), 'Pagamento da indemnização criado na Tesouraria.');
    }

    // ───────────── Relatório ─────────────

    public function relatorioPeriodo(RelatorioPeriodoRequest $r): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->relatorio->gerar($d['de'], $d['ate'], isset($d['terminal_pos_id']) ? (int) $d['terminal_pos_id'] : null), 'Relatório da lavandaria.');
    }
}
