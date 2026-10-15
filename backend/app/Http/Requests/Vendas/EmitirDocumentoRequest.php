<?php

namespace App\Http\Requests\Vendas;

use App\Services\Reparticao\ServicoReparticao;
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
            // Fase C (linha_un_cc.js): unidade/centro de custo da linha (vazio = o do cabeçalho)
            'linhas.*.unidade_negocio_id' => ['nullable', 'integer', $daEmpresa('unidades_negocio')],
            'linhas.*.centro_custo_id' => ['nullable', 'integer', $daEmpresa('centros_custo')],
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
            'oportunidade_crm_id' => ['nullable', 'integer', $daEmpresa('oportunidades_venda_crm')],   // CRM (ADR-054): liga o documento à oportunidade
            'armazem_id' => ['nullable', 'integer', Rule::exists('armazens', 'id')->where('empresa_id', $empresa)->whereNull('eliminado_em')],
            'devolucao_mercadoria' => ['nullable', 'boolean'],   // NC: a mercadoria volta ao stock
            // paridade com o formulário do legado (RELATORIO 4.2.9–4.2.12)
            'baixar_stock' => ['nullable', 'boolean'],                                   // «Baixar Stock Autom.» (FT/FR)
            'guias_saida' => ['nullable', 'array', 'max:50'],                            // guias de saída do armazém facturadas
            'guias_saida.*' => ['integer', 'distinct', $daEmpresa('guias_saida')],
            'contas_pagamento' => ['nullable', 'array', 'max:10'],                       // «Adicionar Conta» (meios de pagamento da Tesouraria)
            'contas_pagamento.*' => ['integer', 'distinct', Rule::exists('meios_pagamento', 'id')->where('empresa_id', $empresa)->whereNull('eliminado_em')],
            'ocultar_meios_pagamento' => ['nullable', 'boolean'],                        // «Ocultar quadro de Meios de Pagamento na impressão»
        ] + ServicoReparticao::regras();   // Fase C: repartição do documento por várias unidades de negócio (alocacao_un.js)
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'tipo_documento' => 'tipo de documento', 'cliente_id' => 'cliente', 'data_emissao' => 'data de emissão', 'linhas' => 'linhas do documento',
            'linhas.*.produto_id' => 'produto', 'linhas.*.quantidade' => 'quantidade', 'linhas.*.preco_unitario' => 'preço unitário',
            'venda_origem_id' => 'factura de referência', 'motivo_nota_credito' => 'motivo da nota de crédito',
            'guias_saida' => 'guias de saída', 'guias_saida.*' => 'guia de saída', 'contas_pagamento' => 'contas dos meios de pagamento',
            'contas_pagamento.*' => 'meio de pagamento',
            'conta_disponibilidade' => 'conta de caixa/banco', 'plano_pagamentos' => 'plano de pagamentos',
        ];
    }
}
