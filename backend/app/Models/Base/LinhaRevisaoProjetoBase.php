<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\RevisaoMensalProjeto;
use App\Models\TarefaProjeto;
use App\Models\Terceiro;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela linhas_revisao_projeto (módulo Projectos). Legado: project_review_lines · 55 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\LinhaRevisaoProjeto.
 */
abstract class LinhaRevisaoProjetoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'linhas_revisao_projeto';

    protected string $moduloAuditoria = 'Projectos';

    protected $fillable = [
        'empresa_id', 'revisao_mensal_projeto_id', 'tipo', 'tipo_original', 'terceiro_id', 'tarefa_projeto_id', 'percentagem_anterior', 'percentagem_atual', 'valor_calculado', 'documento_gerado_id', 'colaborador_id',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'revisao_mensal_projeto_id' => 'integer',
            'terceiro_id' => 'integer',
            'tarefa_projeto_id' => 'integer',
            'percentagem_anterior' => 'decimal:4',
            'percentagem_atual' => 'decimal:4',
            'valor_calculado' => 'decimal:2',
            'colaborador_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function revisaoMensalProjeto(): BelongsTo
    {
        return $this->belongsTo(RevisaoMensalProjeto::class, 'revisao_mensal_projeto_id');
    }

    public function terceiro(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'terceiro_id');
    }

    public function tarefaProjeto(): BelongsTo
    {
        return $this->belongsTo(TarefaProjeto::class, 'tarefa_projeto_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }
}
