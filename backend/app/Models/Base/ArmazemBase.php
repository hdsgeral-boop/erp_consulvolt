<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\GuiaSaida;
use App\Models\ModeloBase;
use App\Models\MovimentoInventario;
use App\Models\RececaoCompra;
use App\Models\SessaoInventario;
use App\Models\StockArmazem;
use App\Models\TerminalPOS;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela armazens (módulo Logística). Legado: warehouses · 6 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\Armazem.
 */
abstract class ArmazemBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'armazens';

    protected string $moduloAuditoria = 'Logística';

    protected $fillable = [
        'empresa_id', 'nome', 'localizacao', 'codigo', 'predefinido',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'predefinido' => 'boolean',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function rececoesCompra(): HasMany
    {
        return $this->hasMany(RececaoCompra::class, 'armazem_id');
    }

    public function stockArmazem(): HasMany
    {
        return $this->hasMany(StockArmazem::class, 'armazem_id');
    }

    public function movimentosInventarioPorArmazem(): HasMany
    {
        return $this->hasMany(MovimentoInventario::class, 'armazem_id');
    }

    public function movimentosInventarioPorArmazemContraparte(): HasMany
    {
        return $this->hasMany(MovimentoInventario::class, 'armazem_contraparte_id');
    }

    public function guiasSaida(): HasMany
    {
        return $this->hasMany(GuiaSaida::class, 'armazem_id');
    }

    public function sessoesInventario(): HasMany
    {
        return $this->hasMany(SessaoInventario::class, 'armazem_id');
    }

    public function terminaisPos(): HasMany
    {
        return $this->hasMany(TerminalPOS::class, 'armazem_id');
    }
}
