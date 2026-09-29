<?php

namespace App\Models\Base;

use App\Models\CargoFuncao;
use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\PostoTrabalho;
use App\Models\UnidadeOrganica;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela postos_trabalho (módulo RH). Legado: org_positions · 9 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\PostoTrabalho.
 */
abstract class PostoTrabalhoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'postos_trabalho';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'unidade_organica_id', 'cargo_funcao_id', 'titulo', 'vagas', 'posto_superior_id', 'responsabilidades', 'chefia', 'ordem',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'unidade_organica_id' => 'integer',
            'cargo_funcao_id' => 'integer',
            'vagas' => 'integer',
            'posto_superior_id' => 'integer',
            'chefia' => 'integer',
            'ordem' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function unidadeOrganica(): BelongsTo
    {
        return $this->belongsTo(UnidadeOrganica::class, 'unidade_organica_id');
    }

    public function cargoFuncao(): BelongsTo
    {
        return $this->belongsTo(CargoFuncao::class, 'cargo_funcao_id');
    }

    public function postoSuperior(): BelongsTo
    {
        return $this->belongsTo(PostoTrabalho::class, 'posto_superior_id');
    }

    public function colaboradores(): HasMany
    {
        return $this->hasMany(Colaborador::class, 'posto_trabalho_id');
    }

    public function postosTrabalhoPorPostoSuperior(): HasMany
    {
        return $this->hasMany(PostoTrabalho::class, 'posto_superior_id');
    }
}
