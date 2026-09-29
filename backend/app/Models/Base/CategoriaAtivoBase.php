<?php

namespace App\Models\Base;

use App\Models\AtivoImobilizado;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela categorias_ativos (módulo Activos). Legado: asset_categories · 11 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\CategoriaAtivo.
 */
abstract class CategoriaAtivoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'categorias_ativos';

    protected string $moduloAuditoria = 'Activos';

    protected $fillable = [
        'empresa_id', 'nome', 'taxa_anual', 'vida_util_padrao', 'conta_gasto', 'conta_amortizacao_acumulada', 'conta_venda', 'conta_perda', 'conta_ativo',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'taxa_anual' => 'decimal:4',
            'vida_util_padrao' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function ativosImobilizados(): HasMany
    {
        return $this->hasMany(AtivoImobilizado::class, 'categoria_ativo_id');
    }
}
