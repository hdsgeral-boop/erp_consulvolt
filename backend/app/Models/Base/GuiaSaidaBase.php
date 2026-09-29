<?php

namespace App\Models\Base;

use App\Models\Armazem;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ItemGuiaSaida;
use App\Models\ModeloBase;
use App\Models\Projeto;
use App\Models\Terceiro;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela guias_saida (módulo Logística). Legado: delivery_notes · 11 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\GuiaSaida.
 */
abstract class GuiaSaidaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'guias_saida';

    protected string $moduloAuditoria = 'Logística';

    protected $fillable = [
        'empresa_id', 'numero_documento', 'data', 'tipo', 'terceiro_id', 'armazem_id', 'area_rececao', 'estado', 'venda_relacionada_id', 'contabilizado', 'projeto_id', 'codigo_projeto',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'data' => 'date',
            'terceiro_id' => 'integer',
            'armazem_id' => 'integer',
            'venda_relacionada_id' => 'integer',
            'contabilizado' => 'boolean',
            'projeto_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function terceiro(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'terceiro_id');
    }

    public function armazem(): BelongsTo
    {
        return $this->belongsTo(Armazem::class, 'armazem_id');
    }

    public function vendaRelacionada(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'venda_relacionada_id');
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function itensGuiaSaida(): HasMany
    {
        return $this->hasMany(ItemGuiaSaida::class, 'guia_saida_id');
    }
}
