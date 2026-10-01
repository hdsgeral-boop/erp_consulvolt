<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\CotacaoCompra;
use App\Models\EncomendaCompra;
use App\Models\FaturaCompra;
use App\Models\ItemCompra;
use App\Models\PedidoCompra;
use App\Models\RececaoCompra;
use App\Services\Compras\RelacoesNomes;
use App\Services\Compras\ServicoConfigCompras;
use App\Services\Compras\ServicoContabilizacaoCompras;
use App\Services\Compras\ServicoDeliberacaoCompras;
use App\Services\Compras\ServicoFaturasCompra;
use App\Services\Compras\ServicoProcessoCompras;
use App\Services\Compras\ServicoRececoesCompra;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * /api/compras — ecrãs compras_pedidos, compras_prospeccao, compras_encomendas, compras_rececoes (+ armazem_rececoes)
 * e compras_faturacao do legado (js/ui_compras_v2.js). Permissões: catálogo do legado; segregação de funções
 * pelas tarefas (criar ≠ aprovar, avaliar ≠ adjudicar, registar recepção ≠ validar no armazém).
 */
final class ComprasController extends Controller
{
    public function __construct(
        private readonly ServicoProcessoCompras $processo,
        private readonly ServicoDeliberacaoCompras $deliberacao,
        private readonly ServicoRececoesCompra $rececoes,
        private readonly ServicoFaturasCompra $faturas,
        private readonly ServicoContabilizacaoCompras $contabilizacao,
        private readonly ServicoConfigCompras $config,
    ) {}

    // ─────────── Pedidos ───────────

    public function pedidos(Request $r): JsonResponse
    {
        $this->exigir('compras_pedidos_view');

        return $this->listar($r, PedidoCompra::query(), 'data', ['numero_pedido', 'nome_requerente']);
    }

    public function pedido(int $id): JsonResponse
    {
        $this->exigir('compras_pedidos_view');
        $p = PedidoCompra::query()->findOrFail($id);

        return RespostaApi::sucesso($this->doc($p, 'pedido_compra_id') + ['valor_estimado' => $this->deliberacao->valorEstimado($p)], 'Pedido obtido com sucesso.');
    }

    public function criarPedido(Request $r): JsonResponse
    {
        $this->exigir('compras_ped_criar');
        $d = $r->validate([
            'nome_requerente' => ['required', 'string', 'max:255'], 'data' => ['nullable', 'date_format:Y-m-d'], 'descricao' => ['nullable', 'string', 'max:2000'],
            'data_entrega' => ['nullable', 'date_format:Y-m-d'], 'observacoes' => ['nullable', 'string', 'max:4000'],
            'linhas' => ['required', 'array', 'min:1', 'max:500'], 'linhas.*.produto_id' => ['required', 'integer', $this->daEmpresa('produtos')],
            'linhas.*.quantidade' => ['required', 'numeric', 'gt:0'], 'linhas.*.preco_unitario' => ['nullable', 'numeric', 'min:0'],
            'linhas.*.descricao' => ['nullable', 'string', 'max:1000'],
        ] + $this->dimensoes());
        $p = $this->processo->criarPedido($d);

        return RespostaApi::criado($this->doc($p, 'pedido_compra_id'), "Pedido {$p->numero_pedido} criado e enviado para deliberação.");
    }

    public function decidirPedido(Request $r, int $id): JsonResponse
    {
        $this->exigir('compras_ped_aprovar', 'compras_ped_aprovar_n2', 'compras_ped_aprovar_n3', 'compras_ped_aprovar_n4');
        $d = $r->validate(['decisao' => ['required', Rule::in(['APROVAR', 'RECUSAR'])], 'nota' => ['nullable', 'string', 'max:1000']]);
        $p = $this->deliberacao->decidir(PedidoCompra::query()->findOrFail($id), $d['decisao'] === 'APROVAR', $d['nota'] ?? null);

        return RespostaApi::sucesso($this->doc($p, 'pedido_compra_id'), $p->estado === 'APROVADO' ? 'Pedido aprovado.' : ($p->estado === 'REJEITADO' ? 'Pedido recusado.' : 'Etapa aprovada.'));
    }

    public function anularPedido(Request $r, int $id): JsonResponse
    {
        $this->exigir('compras_ped_eliminar');
        $p = $this->processo->anularPedido(PedidoCompra::query()->findOrFail($id), $this->motivo($r));

        return RespostaApi::sucesso($this->doc($p, 'pedido_compra_id'), "Pedido {$p->numero_pedido} anulado.");
    }

    public function compararPropostas(int $id): JsonResponse
    {
        $this->exigir('compras_prospeccao_view');

        return RespostaApi::sucesso($this->processo->comparar(PedidoCompra::query()->findOrFail($id)), 'Comparação das propostas.');
    }

    public function escaloes(): JsonResponse
    {
        $this->exigir('compras_pedidos_view', 'compras_deliberacao_config');

        return RespostaApi::sucesso($this->deliberacao->escaloes(), 'Escalões de deliberação.');
    }

    public function definirEscaloes(Request $r): JsonResponse
    {
        $this->exigir('compras_deliberacao_config');
        $d = $r->validate(['niveis' => ['required', 'array', 'min:1', 'max:4'], 'niveis.*.nome' => ['required', 'string', 'max:100'], 'niveis.*.limite' => ['nullable', 'numeric', 'gt:0']]);

        return RespostaApi::sucesso($this->deliberacao->definirEscaloes($d['niveis']), 'Escalões de deliberação gravados.');
    }

    // ─────────── Propostas ───────────

    public function propostas(Request $r): JsonResponse
    {
        $this->exigir('compras_prospeccao_view');

        return $this->listar($r, CotacaoCompra::query()->with(RelacoesNomes::fornecedor())
            ->when($r->integer('pedido_compra_id'), fn ($q, $v) => $q->where('pedido_compra_id', $v)), 'data', ['numero_proposta', 'referencia']);
    }

    public function proposta(int $id): JsonResponse
    {
        $this->exigir('compras_prospeccao_view');

        return RespostaApi::sucesso($this->doc(CotacaoCompra::query()->findOrFail($id), 'cotacao_compra_id'), 'Proposta obtida com sucesso.');
    }

    public function criarProposta(Request $r): JsonResponse
    {
        $this->exigir('compras_new_proposal');
        $d = $r->validate([
            'pedido_compra_id' => ['required', 'integer', $this->daEmpresa('pedidos_compra')], 'fornecedor_id' => ['required', 'integer', $this->daEmpresa('terceiros')],
            'referencia' => ['required', 'string', 'max:50'], 'data' => ['nullable', 'date_format:Y-m-d'], 'data_entrega' => ['nullable', 'date_format:Y-m-d'],
            'codigo_moeda' => ['nullable', 'regex:/^[A-Z]{3}$/'], 'taxa_cambio' => ['nullable', 'numeric', 'gt:0'],
            'linhas' => ['required', 'array', 'min:1'], 'linhas.*.item_pedido_id' => ['required', 'integer'],
            'linhas.*.preco_unitario' => ['required', 'numeric', 'min:0'], 'linhas.*.taxa_imposto' => ['nullable', 'numeric', 'between:0,100'],
        ]);
        $c = $this->processo->criarProposta($d);

        return RespostaApi::criado($this->doc($c, 'cotacao_compra_id'), "Proposta {$c->numero_proposta} registada.");
    }

    public function proporAdjudicacao(int $id): JsonResponse
    {
        $this->exigir('compras_evaluate');

        return RespostaApi::sucesso($this->doc($this->processo->propor(CotacaoCompra::query()->findOrFail($id)), 'cotacao_compra_id'), 'Proposta enviada para adjudicação.');
    }

    public function cancelarProposta(int $id): JsonResponse
    {
        $this->exigir('compras_evaluate');

        return RespostaApi::sucesso($this->doc($this->processo->cancelarProposta(CotacaoCompra::query()->findOrFail($id)), 'cotacao_compra_id'), 'Proposta de adjudicação cancelada.');
    }

    public function adjudicar(Request $r, int $id): JsonResponse
    {
        $this->exigir('compras_adjudicate');
        $d = $r->validate(['data' => ['nullable', 'date_format:Y-m-d']]);
        $e = $this->processo->adjudicar(CotacaoCompra::query()->findOrFail($id), $d['data'] ?? null);

        return RespostaApi::criado($this->doc($e, 'encomenda_compra_id'), "Proposta adjudicada: encomenda {$e->numero_encomenda} gerada.");
    }

    public function anularProposta(int $id): JsonResponse
    {
        $this->exigir('compras_prop_eliminar');

        return RespostaApi::sucesso($this->doc($this->processo->anularProposta(CotacaoCompra::query()->findOrFail($id)), 'cotacao_compra_id'), 'Proposta anulada.');
    }

    // ─────────── Encomendas ───────────

    public function encomendas(Request $r): JsonResponse
    {
        $this->exigir('compras_encomendas_view', 'compras_rececoes_view', 'armazem_rececoes_view', 'compras_faturacao_view');

        return $this->listar($r, EncomendaCompra::query()->with(RelacoesNomes::fornecedor())
            ->when($r->integer('fornecedor_id'), fn ($q, $v) => $q->where('fornecedor_id', $v)), 'data', ['numero_encomenda']);
    }

    public function encomenda(int $id): JsonResponse
    {
        $this->exigir('compras_encomendas_view', 'compras_rececoes_view', 'armazem_rececoes_view', 'compras_faturacao_view');

        return RespostaApi::sucesso($this->doc(EncomendaCompra::query()->findOrFail($id), 'encomenda_compra_id'), 'Encomenda obtida com sucesso.');
    }

    public function anularEncomenda(Request $r, int $id): JsonResponse
    {
        $this->exigir('compras_enc_eliminar');
        $e = $this->processo->anularEncomenda(EncomendaCompra::query()->findOrFail($id), $this->motivo($r));

        return RespostaApi::sucesso($this->doc($e, 'encomenda_compra_id'), "Encomenda {$e->numero_encomenda} anulada.");
    }

    // ─────────── Recepções ───────────

    public function rececoes(Request $r): JsonResponse
    {
        $this->exigir('compras_rececoes_view', 'armazem_rececoes_view');

        return $this->listar($r, RececaoCompra::query()->when($r->integer('encomenda_compra_id'), fn ($q, $v) => $q->where('encomenda_compra_id', $v)),
            'data', ['numero_rececao', 'numero_entrega']);
    }

    public function rececao(int $id): JsonResponse
    {
        $this->exigir('compras_rececoes_view', 'armazem_rececoes_view');
        $rec = RececaoCompra::query()->findOrFail($id);

        return RespostaApi::sucesso($rec->toArray() + ['linhas' => $rec->itensGuiaSaida()->with(RelacoesNomes::produto())->orderBy('id')->get()->toArray()],
            'Recepção obtida com sucesso.');
    }

    public function registarRececao(Request $r, int $encomenda): JsonResponse
    {
        $this->exigir('compras_rec_registar');
        $d = $r->validate(['numero_entrega' => ['required', 'string', 'max:50'], 'data' => ['required', 'date_format:Y-m-d'],
            'linhas' => ['required', 'array', 'min:1'], 'linhas.*.item_encomenda_id' => ['required', 'integer'], 'linhas.*.quantidade' => ['required', 'numeric', 'min:0']],
            [], ['numero_entrega' => 'n.º da guia do fornecedor']);
        $rec = $this->rececoes->registar(EncomendaCompra::query()->findOrFail($encomenda), $d);

        return RespostaApi::criado($rec->toArray(), "Recepção {$rec->numero_rececao} registada (por validar no armazém).");
    }

    public function validarRececao(Request $r, int $id): JsonResponse
    {
        $this->exigir('armazem_validar');
        $d = $r->validate(['armazem_id' => ['nullable', 'integer', $this->daEmpresa('armazens')]]);
        $rec = $this->rececoes->validar(RececaoCompra::query()->findOrFail($id), $d['armazem_id'] ?? null);

        return RespostaApi::sucesso($rec->toArray(), "Recepção {$rec->numero_rececao} validada: stock e contabilidade actualizados.");
    }

    public function reverterRececao(Request $r, int $id): JsonResponse
    {
        $this->exigir('armazem_rec_anular');
        $rec = $this->rececoes->reverterValidacao(RececaoCompra::query()->findOrFail($id), $this->motivo($r));

        return RespostaApi::sucesso($rec->toArray(), "Validação da recepção {$rec->numero_rececao} revertida (estorno registado).");
    }

    public function anularRececao(Request $r, int $id): JsonResponse
    {
        $this->exigir('armazem_rec_anular');
        $rec = $this->rececoes->anular(RececaoCompra::query()->findOrFail($id), $this->motivo($r));

        return RespostaApi::sucesso($rec->toArray(), "Recepção {$rec->numero_rececao} anulada.");
    }

    // ─────────── Facturas ───────────

    public function faturasLista(Request $r): JsonResponse
    {
        $this->exigir('compras_faturacao_view');

        return $this->listar($r, FaturaCompra::query()->with(RelacoesNomes::fornecedor())
            ->when($r->integer('fornecedor_id'), fn ($q, $v) => $q->where('fornecedor_id', $v))
            ->when($r->integer('encomenda_compra_id'), fn ($q, $v) => $q->where('encomenda_compra_id', $v))
            ->when($r->boolean('por_contabilizar'), fn ($q) => $q->where('contabilizado', false)), 'data', ['numero_fatura']);
    }

    public function fatura(int $id): JsonResponse
    {
        $this->exigir('compras_faturacao_view');

        return RespostaApi::sucesso($this->doc(FaturaCompra::query()->findOrFail($id), 'fatura_compra_id'), 'Factura obtida com sucesso.');
    }

    public function faturarEncomenda(Request $r, int $encomenda): JsonResponse
    {
        $this->exigir('compras_fact_registar');
        $d = $r->validate($this->regrasFatura() + ['linhas.*.item_encomenda_id' => ['required', 'integer'], 'linhas.*.quantidade' => ['required', 'numeric', 'min:0'],
            'linhas.*.taxa_imposto' => ['nullable', 'numeric', 'between:0,100']]);
        $f = $this->faturas->registarDaEncomenda(EncomendaCompra::query()->findOrFail($encomenda), $d);

        return RespostaApi::criado($this->doc($f, 'fatura_compra_id'), "Factura {$f->numero_fatura} registada.");
    }

    public function criarFaturaDireta(Request $r): JsonResponse
    {
        $this->exigir('compras_fact_registar');
        $d = $r->validate($this->regrasFatura() + ['fornecedor_id' => ['required', 'integer', $this->daEmpresa('terceiros')],
            'codigo_moeda' => ['nullable', 'regex:/^[A-Z]{3}$/'], 'linhas.*.produto_id' => ['required', 'integer', $this->daEmpresa('produtos')],
            'linhas.*.quantidade' => ['required', 'numeric', 'gt:0'], 'linhas.*.preco_unitario' => ['required', 'numeric', 'min:0'],
            'linhas.*.taxa_imposto' => ['nullable', 'numeric', 'between:0,100'], 'linhas.*.descricao' => ['nullable', 'string', 'max:1000']] + $this->dimensoes());
        $f = $this->faturas->registarDireta($d);

        return RespostaApi::criado($this->doc($f, 'fatura_compra_id'), "Factura {$f->numero_fatura} registada.");
    }

    public function anularFatura(Request $r, int $id): JsonResponse
    {
        $this->exigir('compras_fact_eliminar');
        $f = $this->faturas->anular(FaturaCompra::query()->findOrFail($id), $this->motivo($r));

        return RespostaApi::sucesso($this->doc($f, 'fatura_compra_id'), "Factura {$f->numero_fatura} anulada.");
    }

    public function contabilizarFatura(int $id): JsonResponse
    {
        $this->exigir('compras_fact_contabilizar');
        $f = $this->contabilizacao->contabilizar(FaturaCompra::query()->findOrFail($id));

        return RespostaApi::sucesso($this->doc($f, 'fatura_compra_id'), "Factura {$f->numero_fatura} contabilizada (lançamento {$f->numero_lan_contabilizacao}).");
    }

    public function descontabilizarFatura(Request $r, int $id): JsonResponse
    {
        $this->exigir('compras_descontab');
        $f = $this->contabilizacao->descontabilizar(FaturaCompra::query()->findOrFail($id), $this->motivo($r));

        return RespostaApi::sucesso($this->doc($f, 'fatura_compra_id'), "Factura {$f->numero_fatura} descontabilizada (estorno registado).");
    }

    // ─────────── Configuração ───────────

    public function contas(): JsonResponse
    {
        $this->exigir('compras_faturacao_view', 'compras_fact_contabilizar');

        return RespostaApi::sucesso($this->config->todas(), 'Contas de compras.');
    }

    public function definirContas(Request $r): JsonResponse
    {
        $this->exigir('compras_fact_contabilizar');
        $regras = ['contas' => ['required', 'array']];
        foreach (array_keys(ServicoConfigCompras::CHAVES) as $k) {
            $regras["contas.{$k}"] = ['nullable', 'string', 'max:20'];
        }
        $this->config->definir($r->validate($regras)['contas']);

        return RespostaApi::sucesso($this->config->todas(), 'Contas de compras actualizadas.');
    }

    // ─────────── Auxiliares ───────────

    /**
     * Listagem paginada (RespostaApi::paginado — metadados.paginacao, ADR-064) com os filtros comuns: estado, período e
     * `pesquisa` (ilike, sem curingas do utilizador) nas colunas indicadas.
     *
     * @param  list<string>  $colunasPesquisa
     */
    private function listar(Request $r, Builder $q, string $data, array $colunasPesquisa = []): JsonResponse
    {
        $f = $r->validate(['estado' => ['nullable', 'string', 'max:30'], 'data_inicio' => ['nullable', 'date_format:Y-m-d'], 'data_fim' => ['nullable', 'date_format:Y-m-d'],
            'pesquisa' => ['nullable', 'string', 'max:100'], 'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);
        $pesquisa = trim((string) ($f['pesquisa'] ?? ''));
        $pagina = $q->when($f['estado'] ?? null, fn ($x, $v) => $x->where('estado', $v))
            ->when($f['data_inicio'] ?? null, fn ($x, $v) => $x->where($data, '>=', $v))
            ->when($f['data_fim'] ?? null, fn ($x, $v) => $x->where($data, '<', date('Y-m-d', strtotime("{$v} +1 day"))))
            ->when($pesquisa !== '' && $colunasPesquisa, function ($x) use ($pesquisa, $colunasPesquisa) {
                $termo = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $pesquisa).'%';
                $x->where(function ($w) use ($termo, $colunasPesquisa) {
                    foreach ($colunasPesquisa as $coluna) {
                        $w->orWhere($coluna, 'ilike', $termo);
                    }
                });
            })
            ->orderByDesc($data)->orderByDesc('id')->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1));

        return RespostaApi::paginado($pagina, null, 'Lista obtida com sucesso.');
    }

    /** Documento com as linhas; `fornecedor` {id,nome,nif} quando o documento o tem e `produto` {id,codigo,nome} nas linhas. */
    private function doc(Model $m, string $fk): array
    {
        $m->refresh();
        if (method_exists($m, 'fornecedor')) {
            $m->load(RelacoesNomes::fornecedor());
        }

        return $m->toArray() + ['linhas' => ItemCompra::query()->where($fk, $m->getKey())->with(RelacoesNomes::produto())->orderBy('id')->get()->toArray()];
    }

    private function motivo(Request $r): string
    {
        return $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']], [], ['motivo' => 'motivo'])['motivo'];
    }

    private function regrasFatura(): array
    {
        return ['numero_fatura' => ['required', 'string', 'max:50'], 'data' => ['required', 'date_format:Y-m-d'], 'data_vencimento' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:data'],
            'taxa_cambio' => ['nullable', 'numeric', 'gt:0'], 'linhas' => ['required', 'array', 'min:1', 'max:500']];
    }

    private function dimensoes(): array
    {
        return ['unidade_negocio_id' => ['nullable', 'integer', $this->daEmpresa('unidades_negocio')], 'centro_custo_id' => ['nullable', 'integer', $this->daEmpresa('centros_custo')],
            'projeto_id' => ['nullable', 'integer', $this->daEmpresa('projetos')]];
    }

    private function daEmpresa(string $tabela): Exists
    {
        return Rule::exists($tabela, 'id')->where('empresa_id', app(ContextoEmpresa::class)->obrigatorio());
    }
}
