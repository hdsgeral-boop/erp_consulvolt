<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/**
 * negocios_imobiliarios — negócio de compra ou venda de um imóvel ou fracção: processo por fases (negociação, promessa, escritura, registo,
 * concluído), com os impostos da transmissão fixados na escritura, aprovação, encargos e pagamentos. A conclusão da compra cria o bem em Activos; a
 * da venda factura, marca as fracções vendidas e apura o resultado, e o abate do bem é um passo à parte (ServicoNegociosImobiliarios).
 */
class NegocioImobiliario extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    public const TIPOS = ['COMPRA', 'VENDA'];

    /** Fases por ordem (a de CANCELADO é 0: fora do avanço). */
    public const FASES = ['NEGOCIACAO' => 1, 'PROMESSA' => 2, 'ESCRITURA' => 3, 'REGISTADO' => 4, 'CONCLUIDO' => 5, 'CANCELADO' => 0];

    public const ESTADOS = ['NEGOCIACAO', 'PROMESSA', 'ESCRITURA', 'REGISTADO', 'CONCLUIDO', 'CANCELADO'];

    public const FECHADOS = ['CONCLUIDO', 'CANCELADO'];

    protected $table = 'negocios_imobiliarios';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = ['empresa_id', 'numero', 'tipo', 'estado', 'imovel_id', 'fracao_id', 'terceiro_id', 'preco', 'vpt', 'sinal', 'finalidade', 'isencao', 'data_promessa', 'data_escritura',
        'data_registo', 'notario', 'base', 'base_e_vpt', 'ip_taxa', 'ip_valor', 'selo_pct', 'selo_valor', 'escritura_valor', 'total_impostos', 'impostos_ano', 'impostos_fixados_em',
        'aprovado_por', 'aprovado_em', 'motivo_cancelamento', 'cancelado_em', 'contratos_destino', 'contratos_tratados_em', 'concluido_em', 'custo_aquisicao', 'ativo_id',
        'valor_contabilistico', 'mais_valia', 'resultado_contabilistico', 'quota', 'abate_parcial', 'venda_id', 'abate_id', 'lancamento_compra', 'lancamento_venda', 'abatido_em',
        'notas', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'imovel_id' => 'integer', 'fracao_id' => 'integer', 'terceiro_id' => 'integer', 'ativo_id' => 'integer', 'venda_id' => 'integer', 'abate_id' => 'integer',
            'preco' => 'float', 'vpt' => 'float', 'sinal' => 'float', 'base' => 'float', 'ip_taxa' => 'float', 'ip_valor' => 'float', 'selo_pct' => 'float', 'selo_valor' => 'float',
            'escritura_valor' => 'float', 'total_impostos' => 'float', 'custo_aquisicao' => 'float', 'valor_contabilistico' => 'float', 'mais_valia' => 'float', 'resultado_contabilistico' => 'float',
            'quota' => 'float', 'base_e_vpt' => 'boolean', 'abate_parcial' => 'boolean', 'impostos_ano' => 'integer', 'data_promessa' => 'date:Y-m-d', 'data_escritura' => 'date:Y-m-d',
            'data_registo' => 'date:Y-m-d', 'concluido_em' => 'date:Y-m-d', 'impostos_fixados_em' => 'date:Y-m-d', 'aprovado_em' => 'datetime', 'cancelado_em' => 'datetime',
            'contratos_tratados_em' => 'datetime', 'abatido_em' => 'datetime'];
    }

    public function fechado(): bool
    {
        return in_array($this->estado, self::FECHADOS, true);
    }

    public function ordem(): int
    {
        return self::FASES[$this->estado] ?? 0;
    }
}
