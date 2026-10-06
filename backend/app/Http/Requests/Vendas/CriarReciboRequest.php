<?php

namespace App\Http\Requests\Vendas;

use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/vendas/recibos — recibo de cliente que liquida uma ou mais facturas. */
final class CriarReciboRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // permissão verificada no controller (catálogo do legado)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $daEmpresa = fn (string $tabela) => Rule::exists($tabela, 'id')->where('empresa_id', $empresa);

        return [
            'cliente_id' => ['required', 'integer', $daEmpresa('terceiros')],
            'data' => ['required', 'date_format:Y-m-d'],
            'codigo_conta' => ['required', 'string', 'max:20'],
            'meio_pagamento' => ['nullable', Rule::in(['NUMERARIO', 'TPA', 'TRANSFERENCIA', 'CONTA_CORRENTE'])],
            'referencia_pagamento' => ['nullable', 'string', 'max:50'],
            'contabilizar' => ['nullable', 'boolean'],
            // M-18: recibo de adiantamento (sem facturas; alocado depois em /recibos/{id}/alocar)
            'tipo_recibo' => ['nullable', Rule::in(['NORMAL', 'ADIANTAMENTO'])],
            'montante' => ['required_if:tipo_recibo,ADIANTAMENTO', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'observacoes' => ['nullable', 'string', 'max:255'],
            'alocacoes' => ['required_unless:tipo_recibo,ADIANTAMENTO', 'prohibited_if:tipo_recibo,ADIANTAMENTO', 'array', 'min:1', 'max:200'],
            'alocacoes.*.venda_id' => ['required', 'integer', $daEmpresa('vendas')],
            'alocacoes.*.montante' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'unidade_negocio_id' => ['nullable', 'integer', $daEmpresa('unidades_negocio')],
            'centro_custo_id' => ['nullable', 'integer', $daEmpresa('centros_custo')],
            'projeto_id' => ['nullable', 'integer', $daEmpresa('projetos')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'cliente_id' => 'cliente', 'codigo_conta' => 'conta de caixa/banco', 'alocacoes' => 'facturas a liquidar',
            'alocacoes.*.venda_id' => 'factura', 'alocacoes.*.montante' => 'montante',
        ];
    }
}
