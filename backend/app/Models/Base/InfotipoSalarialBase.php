<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ItemProdutividadeRH;
use App\Models\LinhaFolhaSalarial;
use App\Models\MapeamentoContabilRH;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela infotipos_salariais (módulo RH). Legado: infotypes · 175 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\InfotipoSalarial.
 */
abstract class InfotipoSalarialBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'infotipos_salariais';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'tipo', 'nome', 'numero_inss', 'irt', 'base_horaria', 'calculo_horas',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'numero_inss' => 'boolean',
            'base_horaria' => 'boolean',
            'calculo_horas' => 'decimal:3',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function linhasFolhaSalarial(): HasMany
    {
        return $this->hasMany(LinhaFolhaSalarial::class, 'infotipo_salarial_id');
    }

    public function mapeamentosContabeisRh(): HasMany
    {
        return $this->hasMany(MapeamentoContabilRH::class, 'infotipo_salarial_id');
    }

    public function itensProdutividadeRh(): HasMany
    {
        return $this->hasMany(ItemProdutividadeRH::class, 'infotipo_salarial_id');
    }
}
