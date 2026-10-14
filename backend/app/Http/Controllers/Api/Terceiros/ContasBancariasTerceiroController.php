<?php

namespace App\Http\Controllers\Api\Terceiros;

use App\Http\Controllers\Controller;
use App\Models\Banco;
use App\Models\ContaBancariaTerceiro;
use App\Models\Terceiro;
use App\Services\Terceiros\ServicoContasBancariasTerceiros;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/terceiros/{terceiro}/contas-bancarias — contas bancárias dos terceiros (Fase A, Versão Actual: ui_aux.js:920-1005).
 * Consulta com as permissões de consulta dos terceiros; gestão com as de gestão das fichas (aux_gerir nas Tabelas
 * auxiliares, vendas_clientes_gerir / compras_forn_gerir nas fichas de clientes e fornecedores).
 * Os modelos filtram a empresa activa: um id de outra empresa dá 404.
 */
final class ContasBancariasTerceiroController extends Controller
{
    private const VER = ['tabelas_aux_view', 'vendas_clientes_view', 'compras_fornecedores_view', 'teso_gestao_pagamentos_view', 'lancamentos_view'];

    private const GERIR = ['aux_gerir', 'vendas_clientes_gerir', 'compras_forn_gerir'];

    public function __construct(private readonly ServicoContasBancariasTerceiros $servico) {}

    public function index(int $terceiro): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->servico->listar(Terceiro::query()->findOrFail($terceiro)), 'Contas bancárias obtidas com sucesso.');
    }

    public function store(Request $r, int $terceiro): JsonResponse
    {
        $this->exigir(...self::GERIR);
        $d = $this->validar($r);

        return RespostaApi::criado($this->servico->criar(Terceiro::query()->findOrFail($terceiro), $d), 'Conta bancária adicionada.');
    }

    public function update(Request $r, int $terceiro, int $conta): JsonResponse
    {
        $this->exigir(...self::GERIR);
        $d = $this->validar($r);
        $t = Terceiro::query()->findOrFail($terceiro);

        return RespostaApi::sucesso($this->servico->atualizar($t, $this->conta($t, $conta), $d), 'Conta bancária actualizada.');
    }

    public function predefinir(int $terceiro, int $conta): JsonResponse
    {
        $this->exigir(...self::GERIR);
        $t = Terceiro::query()->findOrFail($terceiro);

        return RespostaApi::sucesso($this->servico->predefinir($t, $this->conta($t, $conta)), 'Conta bancária predefinida.');
    }

    public function destroy(int $terceiro, int $conta): JsonResponse
    {
        $this->exigir(...self::GERIR);
        $t = Terceiro::query()->findOrFail($terceiro);
        $this->servico->eliminar($t, $this->conta($t, $conta));

        return RespostaApi::sucesso(null, 'Conta bancária eliminada.');
    }

    /** GET /terceiros/bancos — nomes dos bancos registados na empresa (sugestões do campo «Banco», ui_aux.js:935-942). */
    public function bancos(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(Banco::query()->ordenarPorNome()->get(['id', 'nome', 'codigo']), 'Bancos obtidos com sucesso.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $r): array
    {
        return $r->validate([
            'banco_id' => ['nullable', 'integer', Rule::exists('bancos', 'id')->whereNull('eliminado_em')],
            'banco' => ['nullable', 'string', 'max:150'],
            'numero_conta' => ['nullable', 'string', 'max:50'],
            'iban' => ['required', 'string', 'max:50'],
            'titular' => ['nullable', 'string', 'max:150'],
            'codigo_moeda' => ['nullable', 'string', Rule::exists('moedas', 'codigo')->whereNull('eliminado_em')],
            'predefinida' => ['nullable', 'boolean'],
        ], ['iban.required' => 'O IBAN é obrigatório — é ele que o banco usa para transferir.'],
            ['banco_id' => 'banco', 'numero_conta' => 'n.º de conta', 'codigo_moeda' => 'moeda']);
    }

    private function conta(Terceiro $t, int $id): ContaBancariaTerceiro
    {
        return ContaBancariaTerceiro::query()->where('terceiro_id', $t->id)->findOrFail($id);
    }
}
