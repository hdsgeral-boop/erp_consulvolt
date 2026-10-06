<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\ModeloBase;

/**
 * Tabela cambios_bai_pendentes (módulo Sistema). Tabela nova do desenho.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\CambioBAIPendente.
 */
abstract class CambioBAIPendenteBase extends ModeloBase
{
    use Auditavel;

    protected $table = 'cambios_bai_pendentes';

    protected string $moduloAuditoria = 'Sistema';

    protected $fillable = [
        'execucao_cambio_bai_id', 'data_cotacao', 'codigo_moeda', 'nome_moeda', 'taxa_compra', 'taxa_venda', 'taxa_media', 'ultima_taxa', 'ultima_data', 'ultima_fonte', 'variacao', 'alerta', 'estado', 'decidido_por_id', 'decidido_por', 'decidido_em', 'motivo',
    ];

    protected function casts(): array
    {
        return [
            'execucao_cambio_bai_id' => 'integer',
            'data_cotacao' => 'date',
            'taxa_compra' => 'decimal:6',
            'taxa_venda' => 'decimal:6',
            'taxa_media' => 'decimal:6',
            'ultima_taxa' => 'decimal:6',
            'ultima_data' => 'date',
            'variacao' => 'decimal:2',
            'alerta' => 'boolean',
            'decidido_por_id' => 'integer',
            'decidido_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
