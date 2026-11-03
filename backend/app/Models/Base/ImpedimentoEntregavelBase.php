<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\EntregavelTarefaUnidade;
use App\Models\ModeloBase;
use App\Models\TarefaUnidadeOrganica;
use App\Models\UnidadeOrganica;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela impedimentos_entregavel (módulo Estrutura Orgânica, Fase J). Legado: org_task_impediments.
 * Escrita à mão no formato das classes geradas: o código de negócio vai em App\Models\ImpedimentoEntregavel.
 */
abstract class ImpedimentoEntregavelBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'impedimentos_entregavel';

    protected string $moduloAuditoria = 'Estrutura orgânica › Tarefas';

    protected $fillable = [
        'empresa_id', 'entregavel_id', 'tarefa_unidade_id', 'unidade_organica_id', 'colaborador_id', 'periodo_de', 'prazo', 'motivo', 'url_documento', 'estado', 'decisao_nota', 'decidido_por', 'decidido_por_colaborador_id', 'decidido_em', 'por_delegado_de', 'criado_por', 'atualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'entregavel_id' => 'integer',
            'tarefa_unidade_id' => 'integer',
            'unidade_organica_id' => 'integer',
            'colaborador_id' => 'integer',
            'periodo_de' => 'date:Y-m-d',
            'prazo' => 'date:Y-m-d',
            'decidido_por_colaborador_id' => 'integer',
            'decidido_em' => 'datetime',
            'por_delegado_de' => 'array',
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
}
