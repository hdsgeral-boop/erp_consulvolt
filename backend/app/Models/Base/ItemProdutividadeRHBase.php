<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\InfotipoSalarial;
use App\Models\ModeloBase;
use App\Models\RegistoProdutividadeRH;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela itens_produtividade_rh (módulo RH). Legado: rh_prod_items · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ItemProdutividadeRH.
 */
abstract class ItemProdutividadeRHBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'itens_produtividade_rh';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'codigo', 'descricao', 'metrica', 'unidade', 'preco_unitario', 'infotipo_salarial_id', 'minimo', 'maximo', 'ativo', 'atualizado_por', 'criado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'preco_unitario' => 'decimal:2',
            'infotipo_salarial_id' => 'integer',
            'ativo' => 'boolean',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }

    public function infotipoSalarial(): BelongsTo
    {
        return $this->belongsTo(InfotipoSalarial::class, 'infotipo_salarial_id');
    }

    public function registosProdutividadeRh(): HasMany
    {
        return $this->hasMany(RegistoProdutividadeRH::class, 'item_produtividade_id');
    }
}
