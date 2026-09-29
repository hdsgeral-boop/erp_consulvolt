<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\MembroEquipaProjeto;
use App\Models\ModeloBase;
use App\Models\NoOrganigramaProjeto;
use App\Models\Projeto;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela nos_organigrama_projeto (módulo Projectos). Legado: project_org_nodes · 30 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\NoOrganigramaProjeto.
 */
abstract class NoOrganigramaProjetoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'nos_organigrama_projeto';

    protected string $moduloAuditoria = 'Projectos';

    protected $fillable = [
        'empresa_id', 'projeto_id', 'no_pai_id', 'titulo', 'area', 'descricao', 'vagas', 'membro_responsavel_id', 'ordem', 'cor', 'apoio', 'tarefas', 'disposicao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'projeto_id' => 'integer',
            'no_pai_id' => 'integer',
            'vagas' => 'integer',
            'membro_responsavel_id' => 'integer',
            'ordem' => 'integer',
            'apoio' => 'integer',
            'tarefas' => 'array',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function noPai(): BelongsTo
    {
        return $this->belongsTo(NoOrganigramaProjeto::class, 'no_pai_id');
    }

    public function membroResponsavel(): BelongsTo
    {
        return $this->belongsTo(MembroEquipaProjeto::class, 'membro_responsavel_id');
    }

    public function membrosEquipaProjeto(): HasMany
    {
        return $this->hasMany(MembroEquipaProjeto::class, 'no_organigrama_projeto_id');
    }

    public function linhasOrcamentoProjeto(): HasMany
    {
        return $this->hasMany(LinhaOrcamentoProjeto::class, 'no_organigrama_projeto_id');
    }

    public function nosOrganigramaProjetoPorNoPai(): HasMany
    {
        return $this->hasMany(NoOrganigramaProjeto::class, 'no_pai_id');
    }
}
