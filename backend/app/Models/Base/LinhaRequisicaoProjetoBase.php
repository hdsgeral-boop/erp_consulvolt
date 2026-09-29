<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\RequisicaoMaterialProjeto;
use App\Models\TarefaProjeto;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela linhas_requisicao_projeto (módulo Projectos). Legado: project_requisition_lines · 10 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\LinhaRequisicaoProjeto.
 */
abstract class LinhaRequisicaoProjetoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'linhas_requisicao_projeto';

    protected string $moduloAuditoria = 'Projectos';

    protected $fillable = [
        'empresa_id', 'requisicao_material_projeto_id', 'tarefa_projeto_id', 'rubrica', 'descricao', 'quantidade', 'estado',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'requisicao_material_projeto_id' => 'integer',
            'tarefa_projeto_id' => 'integer',
            'quantidade' => 'decimal:3',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function requisicaoMaterialProjeto(): BelongsTo
    {
        return $this->belongsTo(RequisicaoMaterialProjeto::class, 'requisicao_material_projeto_id');
    }

    public function tarefaProjeto(): BelongsTo
    {
        return $this->belongsTo(TarefaProjeto::class, 'tarefa_projeto_id');
    }
}
