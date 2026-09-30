<?php

namespace App\Models\Base;

use App\Models\CentroCusto;
use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\PostoTrabalho;
use App\Models\UnidadeNegocio;
use App\Models\UnidadeOrganica;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela unidades_organicas (módulo RH). Legado: org_units · 16 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\UnidadeOrganica.
 */
abstract class UnidadeOrganicaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'unidades_organicas';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'codigo', 'nome', 'tipo', 'tipo_original', 'unidade_organica_pai_id', 'colaborador_responsavel_id', 'utilizador_responsavel', 'utilizadores', 'missao', 'atribuicoes', 'centro_custo_id', 'unidade_negocio_id', 'ordem', 'ativo', 'apoio', 'cor', 'atualizado_por', 'criado_por', 'disposicao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'unidade_organica_pai_id' => 'integer',
            'colaborador_responsavel_id' => 'integer',
            'utilizadores' => 'array',
            'centro_custo_id' => 'integer',
            'unidade_negocio_id' => 'integer',
            'ordem' => 'integer',
            'ativo' => 'boolean',
            'apoio' => 'integer',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function unidadeOrganicaPai(): BelongsTo
    {
        return $this->belongsTo(UnidadeOrganica::class, 'unidade_organica_pai_id');
    }

    public function colaboradorResponsavel(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_responsavel_id');
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id');
    }

    public function unidadeNegocio(): BelongsTo
    {
        return $this->belongsTo(UnidadeNegocio::class, 'unidade_negocio_id');
    }

    public function colaboradores(): HasMany
    {
        return $this->hasMany(Colaborador::class, 'unidade_organica_id');
    }

    public function unidadesOrganicasPorUnidadeOrganicaPai(): HasMany
    {
        return $this->hasMany(UnidadeOrganica::class, 'unidade_organica_pai_id');
    }

    public function postosTrabalho(): HasMany
    {
        return $this->hasMany(PostoTrabalho::class, 'unidade_organica_id');
    }
}
