<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Models\PecaLavandaria;
use App\Models\PedidoLavandaria;
use App\Models\Produto;
use App\Models\ReclamacaoLavandaria;
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
use Illuminate\Validation\Rule;

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

    public function guardarDefinicoes(Request $r): JsonResponse
    {
        $this->exigir('lav_tabelas');
        $d = $r->validate(['taxa_armazenagem_ativa' => ['sometimes', 'boolean'], 'dias_armazenagem_gratis' => ['sometimes', 'integer', 'min:0'],
            'percentagem_armazenagem_dia' => ['sometimes', 'numeric', 'min:0'], 'percentagem_adiantamento' => ['sometimes', 'numeric', 'between:0,100'],
            'percentagem_urgencia' => ['sometimes', 'numeric', 'min:0'], 'fator_prazo_urgencia' => ['sometimes', 'numeric', 'between:0.1,1'],
            'valor_taxa_recolha' => ['sometimes', 'numeric', 'min:0'], 'valor_taxa_entrega' => ['sometimes', 'numeric', 'min:0'], 'dias_reclamacao' => ['sometimes', 'integer', 'min:0'],
            'conta_extras' => ['sometimes', 'nullable', 'string', 'max:20'], 'taxa_extras' => ['sometimes', 'numeric', 'min:0'],
            'conta_compensacao' => ['sometimes', 'nullable', 'string', 'max:20'], 'faturar_no_adiantamento' => ['sometimes', 'boolean']]);

        return RespostaApi::sucesso($this->config->guardar($d), 'Definições da lavandaria gravadas.');
    }

    public function pecas(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(PecaLavandaria::query()->when(! $r->boolean('incluir_inativas'), fn ($q) => $q->where(fn ($q) => $q->whereNull('ativo')->orWhere('ativo', true)))
            ->orderBy('nome')->get(), 'Tabela de peças.');
    }

    public function guardarPeca(Request $r, ?int $peca = null): JsonResponse
    {
        $this->exigir('lav_tabelas');
        $d = $r->validate(['nome' => [$peca ? 'sometimes' : 'required', 'string', 'max:255'], 'tecido' => ['nullable', 'string', 'max:255'], 'cor' => ['nullable', 'string', 'max:255'],
            'unidade' => ['sometimes', Rule::in(RegrasLavandaria::UNIDADES)], 'preco' => ['sometimes', 'numeric', 'min:0'], 'ativo' => ['sometimes', 'boolean'],
            'precos_servico' => ['sometimes', 'array'], 'precos_servico.*.produto_id' => ['required', 'integer'], 'precos_servico.*.preco' => ['nullable', 'numeric', 'min:0']]);
        $p = $peca ? PecaLavandaria::query()->findOrFail($peca) : null;
        $res = $this->tabelas->guardarPeca($d, $p);

        return $p ? RespostaApi::sucesso($res, 'Peça actualizada.') : RespostaApi::criado($res, 'Peça criada.');
    }

    public function servicos(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(Produto::query()->whereIn('lavandaria_grupo', RegrasLavandaria::GRUPOS)
            ->when(! $r->boolean('incluir_inativos'), fn ($q) => $q->where(fn ($q) => $q->whereNull('lavandaria_ativa')->orWhere('lavandaria_ativa', true)))
            ->orderBy('lavandaria_grupo')->orderBy('nome')
            ->get(['id', 'codigo', 'nome', 'lavandaria_grupo', 'codigo_conta', 'taxa_imposto', 'lavandaria_dias_entrega', 'lavandaria_requer_orcamento', 'lavandaria_ativa']), 'Tabela de serviços.');
    }

    public function guardarServico(Request $r, ?int $servico = null): JsonResponse
    {
        $this->exigir('lav_tabelas');
        $d = $r->validate(['nome' => [$servico ? 'sometimes' : 'required', 'string', 'max:255'], 'grupo' => [$servico ? 'sometimes' : 'required', Rule::in(RegrasLavandaria::GRUPOS)],
            'codigo_conta' => [$servico ? 'sometimes' : 'required', 'string', 'max:20'], 'taxa_imposto' => ['sometimes', 'numeric', 'min:0'],
            'dias_entrega' => ['sometimes', 'integer', 'min:0'], 'requer_orcamento' => ['sometimes', 'boolean'], 'ativa' => ['sometimes', 'boolean'],
            'codigo_isencao_fe' => ['sometimes', 'nullable', 'string', 'max:10'], 'conta_iva_liquidado' => ['sometimes', 'nullable', 'string', 'max:20']]);
        $s = $servico ? Produto::query()->findOrFail($servico) : null;
        $res = $this->tabelas->guardarServico($d, $s);

        return $s ? RespostaApi::sucesso($res, 'Serviço actualizado.') : RespostaApi::criado($res, 'Serviço criado.');
    }

    // ───────────── Ordens ─────────────

    public function ordens(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['estado' => ['nullable', 'string', 'max:20'], 'texto' => ['nullable', 'string', 'max:100'], 'terminal_pos_id' => ['nullable', 'integer'],
            'responsavel' => ['nullable', 'string', 'max:20']]);

        return RespostaApi::sucesso($this->ordens->listar($f), 'Ordens de serviço.');
    }

    public function ordem(int $ordem): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->ordens->detalhe($ordem), 'Ordem de serviço.');
    }

    public function registar(Request $r, int $sessao): JsonResponse
    {
        $this->exigir('lav_ordens');
        $d = $r->validate([
            'cliente_id' => ['required', 'integer'], 'modo_faturacao' => ['nullable', 'in:RECEPCAO,ENTREGA'], 'urgente' => ['nullable', 'boolean'],
            'observacoes' => ['nullable', 'string', 'max:2000'], 'valor' => ['nullable', 'numeric', 'min:0'],
            'itens' => ['required', 'array', 'min:1'], 'itens.*.peca_id' => ['required', 'integer'], 'itens.*.produto_id' => ['required', 'integer'],
            'itens.*.quantidade' => ['required', 'numeric', 'gt:0'], 'itens.*.numero_pecas' => ['nullable', 'integer', 'min:1'], 'itens.*.preco' => ['nullable', 'numeric', 'min:0'],
            'itens.*.cor' => ['nullable', 'string', 'max:100'], 'itens.*.tecido' => ['nullable', 'string', 'max:100'], 'itens.*.descricao_peca' => ['nullable', 'string', 'max:255'],
            'itens.*.estado_entrada' => ['nullable', 'string', 'max:60'], 'itens.*.notas_entrada' => ['nullable', 'string', 'max:1000'],
            'itens.*.valor_declarado' => ['nullable', 'numeric', 'min:0'],
            'recolha' => ['nullable', 'array'], 'recolha.ativa' => ['nullable', 'boolean'], 'recolha.morada' => ['nullable', 'string', 'max:500'], 'recolha.data' => ['nullable', 'date'],
            'recolha.taxa' => ['nullable', 'numeric', 'min:0'],
            'entrega' => ['nullable', 'array'], 'entrega.ativa' => ['nullable', 'boolean'], 'entrega.morada' => ['nullable', 'string', 'max:500'], 'entrega.data' => ['nullable', 'date'],
            'entrega.taxa' => ['nullable', 'numeric', 'min:0'],
        ] + self::PAGAMENTOS);
        $res = $this->ordens->registar($sessao, $d);

        return RespostaApi::criado($res, "Ordem {$res['pedido']->numero_encomenda} registada.");
    }

    public function accaoLinhas(Request $r, int $ordem): JsonResponse
    {
        $this->exigir('lav_ordens');
        $d = $r->validate(['accao' => ['required', 'in:INICIAR,PRONTA'], 'linha_id' => ['nullable', 'integer'], 'executado_por' => ['nullable', 'string', 'max:255']]);

        return RespostaApi::sucesso($this->ordens->accaoLinhas($ordem, isset($d['linha_id']) ? (int) $d['linha_id'] : null, $d['accao'], $d['executado_por'] ?? null),
            'Estado das peças actualizado.');
    }

    public function orcamentos(Request $r): JsonResponse
    {
        $this->exigir('lav_ordens');
        $d = $r->validate(['decisao' => ['required', 'in:APROVAR,RECUSAR'], 'canal' => ['nullable', 'in:Presencial,Telefone,WhatsApp,E-mail'], 'nota' => ['nullable', 'string', 'max:500'],
            'linhas' => ['required', 'array', 'min:1'], 'linhas.*.pedido_id' => ['required', 'integer'], 'linhas.*.linha_id' => ['required', 'integer'],
            'linhas.*.valor' => ['nullable', 'numeric', 'min:0']]);
        $res = $this->ordens->decidirOrcamentos($d['decisao'], $d['linhas'], $d['canal'] ?? 'Presencial', $d['nota'] ?? null);

        return RespostaApi::sucesso($res, "{$res['tratados']} orçamento(s) ".($d['decisao'] === 'APROVAR' ? 'aprovado(s).' : 'recusado(s).'));
    }

    public function alterarEstado(Request $r): JsonResponse
    {
        $d = $r->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer'], 'estado' => ['required', 'in:EM_EXECUCAO,PRONTA,RECEBIDA,ANULADA'],
            'colaborador_id' => ['nullable', 'integer'], 'motivo' => ['nullable', 'string', 'max:500']]);
        $d['estado'] === 'ANULADA' ? $this->exigir('lav_anular') : $this->exigir('lav_ordens');   // no legado anular por aqui bastava lav_ordens
        $res = $this->ordens->alterarEstado(array_map('intval', $d['ids']), $d['estado'], isset($d['colaborador_id']) ? (int) $d['colaborador_id'] : null, $d['motivo'] ?? null);

        return RespostaApi::sucesso($res, "{$res['alteradas']} ordem(ns) alterada(s).");
    }

    public function atribuir(Request $r): JsonResponse
    {
        $this->exigir('lav_ordens');
        $d = $r->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer'], 'colaborador_id' => ['nullable', 'integer'], 'retirar' => ['nullable', 'boolean'],
            'data' => ['nullable', 'date_format:Y-m-d'], 'aplicar' => ['nullable', 'in:PENDENTES,NENHUM'], 'nota' => ['nullable', 'string', 'max:500']]);
        $res = $this->ordens->atribuir(array_map('intval', $d['ids']), isset($d['colaborador_id']) ? (int) $d['colaborador_id'] : null, (bool) ($d['retirar'] ?? false),
            $d['data'] ?? null, $d['aplicar'] ?? 'PENDENTES', $d['nota'] ?? null);

        return RespostaApi::sucesso($res, "{$res['alteradas']} ordem(ns) actualizada(s).");
    }

    public function anular(Request $r, int $ordem): JsonResponse
    {
        $this->exigir('lav_anular');
        $d = $r->validate(['motivo' => ['required', 'string', 'min:3', 'max:500']]);

        return RespostaApi::sucesso($this->ordens->anular($ordem, $d['motivo']), 'Ordem anulada.');
    }

    public function material(Request $r, int $ordem, int $linha): JsonResponse
    {
        $this->exigir('lav_ordens');
        $d = $r->validate(['produto_id' => ['required', 'integer'], 'quantidade' => ['required', 'numeric', 'gt:0']]);

        return RespostaApi::sucesso($this->ordens->consumirMaterial($ordem, $linha, (int) $d['produto_id'], (string) $d['quantidade']), 'Material abatido ao stock.');
    }

    public function registarReclamacao(Request $r, int $ordem, int $linha): JsonResponse
    {
        $this->exigir('lav_ordens');
        $d = $r->validate(['descricao' => ['required', 'string', 'max:2000'], 'valor_declarado' => ['nullable', 'numeric', 'min:0']]);

        return RespostaApi::criado($this->reclamacoes->registar($ordem, $linha, $d['descricao'], $d['valor_declarado'] ?? 0), 'Dano/reclamação registado.');
    }

    // ───────────── Dinheiro (sessão aberta) ─────────────

    public function receber(Request $r, int $sessao, int $ordem): JsonResponse
    {
        $this->exigir('lav_receber');
        $d = $r->validate(['valor' => ['required', 'numeric', 'gt:0'], 'pagamentos' => ['required', 'array', 'min:1']] + self::PAGAMENTOS);

        return RespostaApi::criado($this->caixa->receber($sessao, $ordem, RegrasLavandaria::dinheiro($d['valor']), $d['pagamentos']), 'Recebimento registado.');
    }

    public function faturar(int $sessao, int $ordem): JsonResponse
    {
        $this->exigir('lav_ordens');

        return RespostaApi::criado($this->caixa->faturarPendente($sessao, $ordem), 'Factura emitida.');
    }

    public function simularEntrega(Request $r, int $ordem): JsonResponse
    {
        $this->exigir(...self::VER);
        $d = $r->validate(['linhas' => ['required', 'array', 'min:1'], 'linhas.*' => ['integer']]);

        return RespostaApi::sucesso($this->caixa->simularEntrega(PedidoLavandaria::query()->findOrFail($ordem), array_map('intval', $d['linhas'])), 'Simulação da entrega.');
    }

    public function entregar(Request $r, int $sessao, int $ordem): JsonResponse
    {
        $this->exigir('lav_ordens');
        $d = $r->validate(['linhas' => ['required', 'array', 'min:1'], 'linhas.*' => ['integer'], 'valor' => ['nullable', 'numeric', 'min:0']] + self::PAGAMENTOS);

        return RespostaApi::sucesso($this->caixa->entregar($sessao, $ordem, $d['linhas'], RegrasLavandaria::dinheiro($d['valor'] ?? 0), $d['pagamentos'] ?? []), 'Entrega registada.');
    }

    public function anularRecibo(Request $r, int $pagamento): JsonResponse
    {
        $this->exigir('lav_anular');
        $d = $r->validate(['motivo' => ['required', 'string', 'min:3', 'max:500']]);

        return RespostaApi::sucesso($this->caixa->anularRecibo($pagamento, $d['motivo']), 'Recibo anulado.');
    }

    // ───────────── Danos e reclamações ─────────────

    public function reclamacoesLista(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['estado' => ['nullable', Rule::in(ServicoReclamacoesLavandaria::ESTADOS)]]);

        return RespostaApi::sucesso(ReclamacaoLavandaria::query()->when($f['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))->orderByDesc('criado_em')->orderByDesc('id')->get(),
            'Danos e reclamações.');
    }

    public function decidirReclamacao(Request $r, int $reclamacao): JsonResponse
    {
        $this->exigir('lav_dano_decidir');
        $d = $r->validate(['decisao' => ['required', 'in:COMPROVADO,APROVADA,RECUSADA'], 'referencia_comprovativo' => ['nullable', 'string', 'max:255'],
            'data_comprovativo' => ['nullable', 'date_format:Y-m-d'], 'valor_comprovativo' => ['nullable', 'numeric', 'min:0'], 'nota_decisao' => ['nullable', 'string', 'max:255']]);

        return RespostaApi::sucesso($this->reclamacoes->decidir($reclamacao, $d['decisao'], $d['referencia_comprovativo'] ?? null, $d['data_comprovativo'] ?? null,
            $d['valor_comprovativo'] ?? 0, $d['nota_decisao'] ?? null), 'Decisão registada.');
    }

    public function pagarReclamacao(Request $r, int $reclamacao): JsonResponse
    {
        $this->exigir('lav_dano_pagar');
        $d = $r->validate(['data' => ['required', 'date_format:Y-m-d'], 'conta_financeira' => ['required', 'string', 'max:20']]);

        return RespostaApi::sucesso($this->reclamacoes->pagar($reclamacao, $d['data'], $d['conta_financeira']), 'Pagamento da indemnização criado na Tesouraria.');
    }

    // ───────────── Relatório ─────────────

    public function relatorioPeriodo(Request $r): JsonResponse
    {
        $this->exigir('pos_lavandaria_view', 'pos_relatorios_view', 'lav_ordens');
        $d = $r->validate(['de' => ['required', 'date_format:Y-m-d'], 'ate' => ['required', 'date_format:Y-m-d'], 'terminal_pos_id' => ['nullable', 'integer']]);

        return RespostaApi::sucesso($this->relatorio->gerar($d['de'], $d['ate'], isset($d['terminal_pos_id']) ? (int) $d['terminal_pos_id'] : null), 'Relatório da lavandaria.');
    }
}
