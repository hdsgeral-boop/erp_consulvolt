<?php

namespace App\Models\Base;

use App\Models\AtivoImobilizado;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\Terceiro;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela abates_vendas_ativos (módulo Activos). Legado: asset_disposals · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\AbateVendaAtivo.
 */
abstract class AbateVendaAtivoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'abates_vendas_ativos';

    protected string $moduloAuditoria = 'Activos';

    protected $fillable = [
        'empresa_id', 'ativo_imobilizado_id', 'tipo', 'data', 'descricao', 'valor', 'terceiro_id', 'conta_terceiro', 'taxa_iva', 'valor_iva', 'conta_iva',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ativo_imobilizado_id' => 'integer',
            'data' => 'date',
            'valor' => 'decimal:2',
            'terceiro_id' => 'integer',
            'taxa_iva' => 'decimal:2',
            'valor_iva' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function ativoImobilizado(): BelongsTo
    {
        return $this->belongsTo(AtivoImobilizado::class, 'ativo_imobilizado_id');
    }

    public function terceiro(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'terceiro_id');
    }
}
