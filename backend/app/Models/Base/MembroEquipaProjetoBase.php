<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\EquipaProjeto;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\ModeloBase;
use App\Models\NoOrganigramaProjeto;
use App\Models\Terceiro;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela membros_equipa_projeto (módulo Projectos). Legado: project_team_members · 29 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\MembroEquipaProjeto.
 */
abstract class MembroEquipaProjetoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'membros_equipa_projeto';

    protected string $moduloAuditoria = 'Projectos';

    protected $fillable = [
        'empresa_id', 'equipa_projeto_id', 'colaborador_id', 'terceiro_id', 'nome_externo', 'papel', 'horas_alocadas', 'valor_contrato', 'no_organigrama_projeto_id',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'equipa_projeto_id' => 'integer',
            'colaborador_id' => 'integer',
            'terceiro_id' => 'integer',
            'horas_alocadas' => 'decimal:3',
            'valor_contrato' => 'decimal:2',
            'no_organigrama_projeto_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function equipaProjeto(): BelongsTo
    {
        return $this->belongsTo(EquipaProjeto::class, 'equipa_projeto_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    public function terceiro(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'terceiro_id');
    }

    public function noOrganigramaProjeto(): BelongsTo
    {
        return $this->belongsTo(NoOrganigramaProjeto::class, 'no_organigrama_projeto_id');
    }

    public function linhasOrcamentoProjeto(): HasMany
    {
        return $this->hasMany(LinhaOrcamentoProjeto::class, 'membro_equipa_projeto_id');
    }

    public function nosOrganigramaProjeto(): HasMany
    {
        return $this->hasMany(NoOrganigramaProjeto::class, 'membro_responsavel_id');
    }
}
