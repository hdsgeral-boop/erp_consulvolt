<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\ContratoFornecedor;
use App\Models\EncomendaCompra;
use App\Models\MarcoContratoFornecedor;
use App\Services\Compras\RelacoesNomes;
use App\Services\Compras\ServicoContratosFornecedores;
use App\Services\Compras\ServicoEncomendasClientes;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/compras/contratos e /api/compras/encomendas-clientes — ecrãs compras_contratos e compras_encomendas_clientes do legado. */
final class ContratosComprasController extends Controller
{
    public function __construct(
        private readonly ServicoContratosFornecedores $contratos,
        private readonly ServicoEncomendasClientes $encomendasClientes,
    ) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir('compras_contratos_view');
        $this->contratos->expirar();
        $f = $r->validate(['fornecedor_id' => ['nullable', 'integer'], 'estado' => ['nullable', Rule::in(['ATIVO', 'EXPIRADO', 'CANCELADO'])],
            'pesquisa' => ['nullable', 'string', 'max:100'], 'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);
        $pesquisa = trim((string) ($f['pesquisa'] ?? ''));

        return RespostaApi::paginado(ContratoFornecedor::query()->with(RelacoesNomes::fornecedor())
            ->when($f['fornecedor_id'] ?? null, fn ($q, $v) => $q->where('fornecedor_id', $v))
            ->when($f['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when($pesquisa !== '', fn ($q) => $q->where('referencia', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $pesquisa).'%'))
            ->orderByDesc('data_inicio')->orderByDesc('id')->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1)),
            null, 'Contratos de fornecedores.');
    }

    public function show(int $id): JsonResponse
    {
        $this->exigir('compras_contratos_view');

        return RespostaApi::sucesso($this->contratos->resumo(ContratoFornecedor::query()->findOrFail($id)), 'Contrato obtido com sucesso.');
    }

    public function gravar(Request $r, ?int $id = null): JsonResponse
    {
        $this->exigir('compras_contratos_gerir');
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $d = $r->validate(['fornecedor_id' => ['required', 'integer', Rule::exists('terceiros', 'id')->where('empresa_id', $empresa)],
            'referencia' => ['required', 'string', 'max:50'], 'descricao' => ['nullable', 'string', 'max:4000'], 'data_inicio' => ['nullable', 'date_format:Y-m-d'],
            'data_fim' => ['nullable', 'date_format:Y-m-d'], 'valor_total' => ['required', 'numeric', 'min:0', 'max:9999999999999.99']]);
        $c = $this->contratos->gravar($d, $id ? ContratoFornecedor::query()->findOrFail($id) : null);

        return $id ? RespostaApi::sucesso($this->contratos->resumo($c), 'Contrato actualizado.') : RespostaApi::criado($this->contratos->resumo($c), 'Contrato criado.');
    }

    public function associar(Request $r, int $id): JsonResponse
    {
        $this->exigir('compras_contratos_gerir', 'compras_enc_criar');
        $d = $r->validate(['encomendas' => ['required', 'array', 'min:1'], 'encomendas.*' => ['integer'], 'mover' => ['nullable', 'boolean']]);
        $res = $this->contratos->associarEncomendas(ContratoFornecedor::query()->findOrFail($id), $d['encomendas'], (bool) ($d['mover'] ?? false));

        return RespostaApi::sucesso($res, $res['consumo']['excedido'] ? 'Encomendas associadas — atenção: o valor encomendado excede o contratado.' : 'Encomendas associadas.');
    }

    public function desassociar(int $id, int $encomenda): JsonResponse
    {
        $this->exigir('compras_contratos_gerir', 'compras_enc_criar');

        return RespostaApi::sucesso($this->contratos->desassociarEncomenda(ContratoFornecedor::query()->findOrFail($id), EncomendaCompra::query()->findOrFail($encomenda)),
            'Encomenda retirada do contrato.');
    }

    public function gravarMarco(Request $r, int $id, ?int $marco = null): JsonResponse
    {
        $this->exigir('compras_contratos_gerir');
        $d = $r->validate(['titulo' => ['required', 'string', 'max:255'], 'data_prevista' => ['nullable', 'date_format:Y-m-d'], 'montante' => ['required', 'numeric', 'gt:0']]);
        $c = ContratoFornecedor::query()->findOrFail($id);
        $this->contratos->gravarMarco($c, $d, $marco ? MarcoContratoFornecedor::query()->where('contrato_fornecedor_id', $id)->findOrFail($marco) : null);

        return RespostaApi::sucesso($this->contratos->resumo($c), 'Marco gravado.');
    }

    public function eliminarMarco(int $id, int $marco): JsonResponse
    {
        $this->exigir('compras_contratos_gerir');
        $this->contratos->eliminarMarco(MarcoContratoFornecedor::query()->where('contrato_fornecedor_id', $id)->findOrFail($marco));

        return RespostaApi::sucesso($this->contratos->resumo(ContratoFornecedor::query()->findOrFail($id)), 'Marco eliminado.');
    }

    public function faturarMarco(Request $r, int $id, int $marco): JsonResponse
    {
        $this->exigir('compras_contratos_gerir');
        $d = $r->validate(['fatura_compra_id' => ['nullable', 'integer', Rule::exists('faturas_compra', 'id')->where('empresa_id', app(ContextoEmpresa::class)->obrigatorio())]]);
        $this->contratos->ligarFatura(MarcoContratoFornecedor::query()->where('contrato_fornecedor_id', $id)->findOrFail($marco), $d['fatura_compra_id'] ?? null);

        return RespostaApi::sucesso($this->contratos->resumo(ContratoFornecedor::query()->findOrFail($id)), 'Marco actualizado.');
    }

    public function cancelar(Request $r, int $id): JsonResponse
    {
        $this->exigir('compras_contratos_cancelar');
        $d = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return RespostaApi::sucesso($this->contratos->cancelar(ContratoFornecedor::query()->findOrFail($id), $d['motivo']), 'Contrato cancelado.');
    }

    public function encomendasClientes(): JsonResponse
    {
        $this->exigir('compras_encomendas_clientes_view');

        return RespostaApi::sucesso($this->encomendasClientes->listar(), 'Encomendas de clientes.');
    }

    public function gerarPedido(Request $r): JsonResponse
    {
        $this->exigir('compras_gerar_pedidos');
        $d = $r->validate(['itens' => ['required', 'array', 'min:1', 'max:500'], 'itens.*' => ['integer'], 'nome_requerente' => ['nullable', 'string', 'max:255'],
            'descricao' => ['nullable', 'string', 'max:2000']]);
        $p = $this->encomendasClientes->gerarPedido($d['itens'], $d['nome_requerente'] ?? null, $d['descricao'] ?? null);

        return RespostaApi::criado($p, "Pedido {$p->numero_pedido} criado e enviado para deliberação.");
    }
}
