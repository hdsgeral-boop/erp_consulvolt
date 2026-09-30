<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela configuracoes_lavandaria (módulo POS). Legado: lav_settings · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ConfiguracaoLavandaria.
 */
abstract class ConfiguracaoLavandariaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'configuracoes_lavandaria';

    protected string $moduloAuditoria = 'POS';

    protected $fillable = [
        'empresa_id', 'taxa_armazenagem_ativa', 'dias_armazenagem_gratis', 'percentagem_armazenagem_dia', 'percentagem_adiantamento', 'percentagem_urgencia', 'fator_prazo_urgencia', 'valor_taxa_recolha', 'valor_taxa_entrega', 'dias_reclamacao', 'conta_extras', 'taxa_extras', 'conta_compensacao', 'faturar_no_adiantamento', 'atualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'taxa_armazenagem_ativa' => 'boolean',
            'dias_armazenagem_gratis' => 'integer',
            'percentagem_armazenagem_dia' => 'decimal:4',
            'percentagem_adiantamento' => 'decimal:4',
            'percentagem_urgencia' => 'decimal:4',
            'fator_prazo_urgencia' => 'decimal:4',
            'valor_taxa_recolha' => 'decimal:4',
            'valor_taxa_entrega' => 'decimal:4',
            'dias_reclamacao' => 'integer',
            'taxa_extras' => 'decimal:4',
            'faturar_no_adiantamento' => 'boolean',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }
}
