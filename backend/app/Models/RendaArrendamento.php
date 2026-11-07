<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/**
 * rendas_arrendamento — renda de um período de um contrato. UNIQUE (contrato, período): gerar nunca duplica. Facturada (lado
 * senhorio, `venda_id` único) ou paga (lado inquilino, documento de tesouraria único).
 */
class RendaArrendamento extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    public const ESTADOS = ['PREVISTA', 'APROVADA', 'FACTURADA', 'PAGA', 'ANULADA'];

    /** Estados em que a renda já é documento (fiscal ou de tesouraria): deixa de se alterar. */
    public const EM_DOCUMENTO = ['FACTURADA', 'PAGA'];

    protected $table = 'rendas_arrendamento';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = ['empresa_id', 'contrato_id', 'periodo', 'posicao', 'de', 'ate', 'vencimento', 'estado', 'renda', 'factor', 'encargos', 'desconto', 'extras',
        'extras_total', 'base', 'ip_base', 'ip_valor', 'ip_retido', 'selo_pct', 'selo_valor', 'selo_encargo', 'iva_taxa', 'iva_valor', 'total_facturar', 'total_receber',
        'custo_senhorio', 'venda_id', 'documento_tesouraria_id', 'numero_documento', 'data_facturada', 'data_pagamento', 'aprovada_por', 'aprovada_em',
        'retencao_lancamento', 'notas', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        $numeros = array_fill_keys(['renda', 'encargos', 'desconto', 'extras_total', 'base', 'ip_base', 'ip_valor', 'selo_valor', 'iva_valor', 'total_facturar',
            'total_receber', 'custo_senhorio', 'factor', 'selo_pct', 'iva_taxa'], 'float');

        return $numeros + ['empresa_id' => 'integer', 'contrato_id' => 'integer', 'venda_id' => 'integer', 'documento_tesouraria_id' => 'integer', 'ip_retido' => 'boolean',
            'extras' => 'array', 'de' => 'date:Y-m-d', 'ate' => 'date:Y-m-d', 'vencimento' => 'date:Y-m-d', 'data_facturada' => 'date:Y-m-d',
            'data_pagamento' => 'date:Y-m-d', 'aprovada_em' => 'datetime'];
    }

    public function emDocumento(): bool
    {
        return in_array($this->estado, self::EM_DOCUMENTO, true);
    }
}
