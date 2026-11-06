<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/**
 * taxas_imobiliario — taxas fiscais do módulo imobiliário por empresa e ano. São DADOS EDITÁVEIS marcados «por validar»:
 * o ERP não afirma a conformidade legal dos valores de arranque (confirmação do contabilista/jurista, fora do sistema).
 */
class TaxaImobiliario extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    /** Colunas das taxas, pela ordem do ecrã. */
    public const CAMPOS = ['ip_taxa', 'ip_base', 'selo_habitacao', 'selo_outro', 'iva_arrendamento', 'iva_alojamento', 'mora_mensal', 'prazo_registo', 'ip_transmissao',
        'selo_transmissao', 'escritura_fixo', 'isencao_ate', 'isencao_metade_ate', 'det_escalao1_ate', 'det_escalao1_pct', 'det_escalao2_ate', 'det_escalao2_fixo',
        'det_escalao3_pct', 'det_terreno_pct', 'det_agravamento_pct', 'det_agravamento_meses'];

    protected $table = 'taxas_imobiliario';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = [...self::CAMPOS, 'empresa_id', 'ano', 'estado_validacao', 'validada_por', 'validada_em', 'validacao_nota', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return array_fill_keys(self::CAMPOS, 'float') + ['empresa_id' => 'integer', 'ano' => 'integer', 'validada_em' => 'datetime'];
    }
}
