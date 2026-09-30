<?php

namespace App\Models\Base;

use App\Models\Armazem;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\LinhaSessaoInventario;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela sessoes_inventario (módulo Logística). Legado: inventory_sessions · 4 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\SessaoInventario.
 */
abstract class SessaoInventarioBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'sessoes_inventario';

    protected string $moduloAuditoria = 'Logística';

    protected $fillable = [
        'empresa_id', 'armazem_id', 'data', 'descricao', 'estado', 'estado_original', 'tipo', 'aprovado_por', 'aprovado_em', 'numero_lan_contabilizacao', 'motivo_anulacao', 'iniciado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'armazem_id' => 'integer',
            'data' => 'date',
            'aprovado_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function armazem(): BelongsTo
    {
        return $this->belongsTo(Armazem::class, 'armazem_id');
    }

    public function linhasSessaoInventario(): HasMany
    {
        return $this->hasMany(LinhaSessaoInventario::class, 'sessao_inventario_id');
    }
}
