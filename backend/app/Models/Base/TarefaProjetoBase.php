<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\FolhaHorasProjeto;
use App\Models\ItemCompra;
use App\Models\LancamentoContabil;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\LinhaRequisicaoProjeto;
use App\Models\LinhaRevisaoProjeto;
use App\Models\MarcoProjeto;
use App\Models\ModeloBase;
use App\Models\Projeto;
use App\Models\RazaoAnaliticoProjeto;
use App\Models\TarefaProjeto;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela tarefas_projeto (módulo Projectos). Legado: project_tasks · 51 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\TarefaProjeto.
 */
abstract class TarefaProjetoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'tarefas_projeto';

    protected string $moduloAuditoria = 'Projectos';

    protected $fillable = [
        'empresa_id', 'projeto_id', 'tarefa_pai_id', 'codigo', 'nome', 'data_inicio', 'data_fim', 'estado', 'estado_original', 'marco_projeto_id', 'atribuido_a_id', 'percentagem_execucao', 'valor_contrato', 'ordem',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'projeto_id' => 'integer',
            'tarefa_pai_id' => 'integer',
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'marco_projeto_id' => 'integer',
            'atribuido_a_id' => 'integer',
            'percentagem_execucao' => 'decimal:4',
            'valor_contrato' => 'decimal:2',
            'ordem' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function tarefaPai(): BelongsTo
    {
        return $this->belongsTo(TarefaProjeto::class, 'tarefa_pai_id');
    }

    public function marcoProjeto(): BelongsTo
    {
        return $this->belongsTo(MarcoProjeto::class, 'marco_projeto_id');
    }

    public function lancamentosContabeis(): HasMany
    {
        return $this->hasMany(LancamentoContabil::class, 'tarefa_projeto_id');
    }

    public function itensCompra(): HasMany
    {
        return $this->hasMany(ItemCompra::class, 'tarefa_projeto_id');
    }

    public function tarefasProjetoPorTarefaPai(): HasMany
    {
        return $this->hasMany(TarefaProjeto::class, 'tarefa_pai_id');
    }

    public function linhasRequisicaoProjeto(): HasMany
    {
        return $this->hasMany(LinhaRequisicaoProjeto::class, 'tarefa_projeto_id');
    }

    public function linhasOrcamentoProjeto(): HasMany
    {
        return $this->hasMany(LinhaOrcamentoProjeto::class, 'tarefa_projeto_id');
    }

    public function razaoAnaliticoProjetos(): HasMany
    {
        return $this->hasMany(RazaoAnaliticoProjeto::class, 'tarefa_projeto_id');
    }

    public function folhasHorasProjeto(): HasMany
    {
        return $this->hasMany(FolhaHorasProjeto::class, 'tarefa_projeto_id');
    }

    public function linhasRevisaoProjeto(): HasMany
    {
        return $this->hasMany(LinhaRevisaoProjeto::class, 'tarefa_projeto_id');
    }
}
