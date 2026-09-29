<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\MapeamentoContabilRH;
use App\Models\MapeamentoContabilSistemaRH;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela tipos_organizacao_rh (módulo RH). Legado: org_types · 28 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\TipoOrganizacaoRH.
 */
abstract class TipoOrganizacaoRHBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'tipos_organizacao_rh';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'nome',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function colaboradores(): HasMany
    {
        return $this->hasMany(Colaborador::class, 'tipo_organizacao_id');
    }

    public function mapeamentosContabeisRh(): HasMany
    {
        return $this->hasMany(MapeamentoContabilRH::class, 'tipo_organizacao_id');
    }

    public function mapeamentosContabeisSistemaRh(): HasMany
    {
        return $this->hasMany(MapeamentoContabilSistemaRH::class, 'tipo_organizacao_id');
    }
}
