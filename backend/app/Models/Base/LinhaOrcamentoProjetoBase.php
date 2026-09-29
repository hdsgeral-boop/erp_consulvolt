<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\MembroEquipaProjeto;
use App\Models\ModeloBase;
use App\Models\NoOrganigramaProjeto;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela linhas_orcamento_projeto (módulo Projectos). Legado: project_budget_lines · 13 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\LinhaOrcamentoProjeto.
 */
abstract class LinhaOrcamentoProjetoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'linhas_orcamento_projeto';

    protected string $moduloAuditoria = 'Projectos';

    protected $fillable = [
        'empresa_id', 'projeto_id', 'tarefa_projeto_id', 'rubrica', 'montante', 'numero_conta', 'no_organigrama_projeto_id', 'membro_equipa_projeto_id',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'projeto_id' => 'integer',
            'tarefa_projeto_id' => 'integer',
            'montante' => 'decimal:2',
            'no_organigrama_projeto_id' => 'integer',
            'membro_equipa_projeto_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function tarefaProjeto(): BelongsTo
    {
        return $this->belongsTo(TarefaProjeto::class, 'tarefa_projeto_id');
    }

    public function noOrganigramaProjeto(): BelongsTo
    {
        return $this->belongsTo(NoOrganigramaProjeto::class, 'no_organigrama_projeto_id');
    }

    public function membroEquipaProjeto(): BelongsTo
    {
        return $this->belongsTo(MembroEquipaProjeto::class, 'membro_equipa_projeto_id');
    }
}
