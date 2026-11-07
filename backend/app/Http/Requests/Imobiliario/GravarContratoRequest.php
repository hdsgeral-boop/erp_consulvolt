<?php

namespace App\Http\Requests\Imobiliario;

use App\Http\Requests\POS\PedidoPOS;
use App\Services\Imobiliario\CatalogoContratos;
use Illuminate\Validation\Rule;

/**
 * Criar (POST) e editar (PUT) um contrato de arrendamento. Permissão imo_contrato_editar, verificada antes da validação. O estado nunca
 * vem do pedido (muda por activar, suspender, terminar…) e a caução recebida não se grava aqui (ledger). As regras de negócio
 * (conflitos de datas, subarrendamento, contas) são do serviço.
 */
final class GravarContratoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['imo_contrato_editar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $dinheiro = ['nullable', 'numeric', 'min:0', 'max:9999999999999999'];

        return [
            'tipo' => ['nullable', Rule::in(array_keys(CatalogoContratos::TIPOS))], 'ambito' => ['nullable', Rule::in(array_keys(CatalogoContratos::AMBITOS))],
            'fracao_id' => ['nullable', 'integer', 'min:1'], 'imovel_id' => ['nullable', 'integer', 'min:1'],
            'fracoes_ids' => ['nullable', 'array', 'max:500'], 'fracoes_ids.*' => ['integer', 'min:1'],
            'terceiro_id' => ['nullable', 'integer', 'min:1'], 'contrato_pai_id' => ['nullable', 'integer', 'min:1'], 'permite_sub' => ['nullable', 'boolean'],
            'finalidade_fiscal' => ['nullable', Rule::in(array_keys(CatalogoContratos::FINALIDADES_FISCAIS))],
            'inicio' => ['nullable', 'date_format:Y-m-d'], 'fim' => ['nullable', 'date_format:Y-m-d'],
            'periodicidade' => ['nullable', Rule::in(array_keys(CatalogoContratos::PERIODICIDADES))], 'antecipada' => ['nullable', 'boolean'],
            'dia_vencimento' => ['nullable', 'integer', 'min:1', 'max:31'], 'renda' => $dinheiro, 'pro_rata' => ['nullable', 'boolean'],
            'actualizacao' => ['nullable', Rule::in(array_keys(CatalogoContratos::ACTUALIZACOES))], 'actualizacao_valor' => $dinheiro,
            'actualizacao_meses' => ['nullable', 'integer', 'min:1', 'max:120'], 'caucao' => $dinheiro, 'retencao_ip' => ['nullable', 'boolean'],
            'isencao_ip' => ['nullable', Rule::in(array_keys(CatalogoContratos::ISENCOES))], 'selo_encargo' => ['nullable', Rule::in(array_keys(CatalogoContratos::SELO_ENCARGO))],
            'doc_tipo' => ['nullable', Rule::in(array_keys(CatalogoContratos::DOC_TIPOS))], 'renovacao' => ['nullable', Rule::in(array_keys(CatalogoContratos::RENOVACOES))],
            'renovacao_meses' => ['nullable', 'integer', 'min:1', 'max:120'], 'aviso_dias' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'conta_custo' => ['nullable', 'string', 'max:20'], 'conta_retencao' => ['nullable', 'string', 'max:20'], 'conta_financeira' => ['nullable', 'string', 'max:20'],
            'mora_mensal' => ['nullable', 'numeric', 'min:0', 'max:100'], 'registo_agt_data' => ['nullable', 'date_format:Y-m-d'],
            'outra_parte_morada' => ['nullable', 'string', 'max:300'], 'outra_parte_doc' => ['nullable', 'string', 'max:100'], 'outra_parte_contacto' => ['nullable', 'string', 'max:150'],
            'notas' => ['nullable', 'string', 'max:5000'],
            'encargos' => ['nullable', 'array', 'max:100'], 'encargos.*.designacao' => ['nullable', 'string', 'max:150'], 'encargos.*.valor' => $dinheiro,
            'encargos.*.incluido_na_renda' => ['nullable', 'boolean'], 'encargos.*.origem' => ['nullable', Rule::in(['MANUAL', 'PARQUE'])], 'encargos.*.fracao_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['inicio.date_format' => 'A data de início tem de ter o formato AAAA-MM-DD.', 'fim.date_format' => 'A data de fim tem de ter o formato AAAA-MM-DD.',
            'registo_agt_data.date_format' => 'A data de entrega na Repartição tem de ter o formato AAAA-MM-DD.', 'renda.numeric' => 'A renda tem de ser um número.',
            'tipo.in' => 'Tipo de contrato desconhecido.', 'ambito.in' => 'Âmbito desconhecido.'];
    }
}
