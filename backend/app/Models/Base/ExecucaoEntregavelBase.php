<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\EntregavelTarefaUnidade;
use App\Models\ModeloBase;
use App\Models\PeriodoProdutividadeRH;
use App\Models\TarefaUnidadeOrganica;
use App\Models\UnidadeOrganica;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela execucoes_entregavel (módulo Estrutura Orgânica, Fase J). Legado: org_task_executions («entregas»).
 * Escrita à mão no formato das classes geradas: o código de negócio vai em App\Models\ExecucaoEntregavel.
 */
abstract class ExecucaoEntregavelBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'execucoes_entregavel';

    protected string $moduloAuditoria = 'Estrutura orgânica › Tarefas';

    protected $fillable = [
        'empresa_id', 'entregavel_id', 'tarefa_unidade_id', 'unidade_organica_id', 'colaborador_id', 'quantidade', 'data', 'observacoes', 'url_evidencia', 'registado_por', 'registado_por_colaborador_id', 'origem', 'origem_chave', 'validacao', 'validacao_motivo', 'validada_por', 'validada_por_colaborador_id', 'validada_em', 'validada_como', 'devolucao_anterior', 'periodo_produtividade_id', 'convertida_em', 'convertida_por', 'criado_por', 'atualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'entregavel_id' => 'integer',
            'tarefa_unidade_id' => 'integer',
            'unidade_organica_id' => 'integer',
            'colaborador_id' => 'integer',
            'quantidade' => 'decimal:4',
            'data' => 'date:Y-m-d',
            'registado_por_colaborador_id' => 'integer',
            'validada_por_colaborador_id' => 'integer',
            'validada_em' => 'datetime',
            'devolucao_anterior' => 'array',
            'periodo_produtividade_id' => 'integer',
            'convertida_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function entregavel(): BelongsTo
    {
        return $this->belongsTo(EntregavelTarefaUnidade::class, 'entregavel_id');
    }

    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(TarefaUnidadeOrganica::class, 'tarefa_unidade_id');
    }

    public function unidadeOrganica(): BelongsTo
    {
        return $this->belongsTo(UnidadeOrganica::class, 'unidade_organica_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    public function periodoProdutividade(): BelongsTo
    {
        return $this->belongsTo(PeriodoProdutividadeRH::class, 'periodo_produtividade_id');
    }
}
