<?php

namespace App\Http\Requests\Vendas;

use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/vendas/documentos — emissão de FT, FR, NC, OR, PF ou NE (regras de negócio em ServicoDocumentosVenda). */
final class EmitirDocumentoRequest extends FormRequest
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
            'tipo_documento' => ['required', Rule::in(['FT', 'FR', 'NC', 'OR', 'PF', 'NE', 'GR'])],   // a GD só se gera a partir de uma GR
            'cliente_id' => ['required', 'integer', Rule::exists('terceiros', 'id')->where('empresa_id', $empresa)->whereNull('eliminado_em')],
            'data_emissao' => ['required', 'date_format:Y-m-d'],
            'linhas' => ['required', 'array', 'min:1', 'max:500'],
            'linhas.*.produto_id' => ['required', 'integer', Rule::exists('produtos', 'id')->where('empresa_id', $empresa)->whereNull('eliminado_em')],
            'linhas.*.quantidade' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999'],
            'linhas.*.preco_unitario' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'linhas.*.descricao' => ['nullable', 'string', 'max:1000'],
            'linhas.*.observacoes' => ['nullable', 'string', 'max:2000'],
            'venda_origem_id' => ['required_if:tipo_documento,NC', 'nullable', 'integer', $daEmpresa('vendas')],
            'motivo_nota_credito' => ['required_if:tipo_documento,NC', 'nullable', 'string', 'max:200'],
            'conta_disponibilidade' => ['required_if:tipo_documento,FR', 'nullable', 'string', 'max:20'],
            'meio_pagamento' => ['nullable', Rule::in(['NUMERARIO', 'TPA', 'TRANSFERENCIA', 'CONTA_CORRENTE'])],
            'referencia_pagamento' => ['nullable', 'string', 'max:50'],
            'modo_pagamento' => ['nullable', Rule::in(['PRONTO', 'PRAZO', 'MARCOS'])],
            'plano_pagamentos' => ['nullable', 'array', 'max:60'],
            'plano_pagamentos.*.percentagem' => ['required', 'numeric', 'gt:0', 'max:100'],
            'plano_pagamentos.*.data' => ['nullable', 'date_format:Y-m-d'],
            'plano_pagamentos.*.descricao' => ['nullable', 'string', 'max:200'],
            'dias_validade' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'valido_ate' => ['nullable', 'date_format:Y-m-d'],
            'condicoes_pagamento' => ['nullable', 'string', 'max:2000'],
            'observacoes' => ['nullable', 'string', 'max:4000'],
            'codigo_moeda' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'taxa_cambio' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
            'unidade_negocio_id' => ['nullable', 'integer', $daEmpresa('unidades_negocio')],
            'centro_custo_id' => ['nullable', 'integer', $daEmpresa('centros_custo')],
            'projeto_id' => ['nullable', 'integer', $daEmpresa('projetos')],
            'armazem_id' => ['nullable', 'integer', Rule::exists('armazens', 'id')->where('empresa_id', $empresa)->whereNull('eliminado_em')],
            'devolucao_mercadoria' => ['nullable', 'boolean'],   // NC: a mercadoria volta ao stock
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'tipo_documento' => 'tipo de documento', 'cliente_id' => 'cliente', 'data_emissao' => 'data de emissão', 'linhas' => 'linhas do documento',
            'linhas.*.produto_id' => 'produto', 'linhas.*.quantidade' => 'quantidade', 'linhas.*.preco_unitario' => 'preço unitário',
            'venda_origem_id' => 'factura de referência', 'motivo_nota_credito' => 'motivo da nota de crédito',
            'conta_disponibilidade' => 'conta de caixa/banco', 'plano_pagamentos' => 'plano de pagamentos',
        ];
    }
}
