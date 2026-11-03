<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\EntregavelTarefaUnidade;
use App\Models\LinhaFolhaSalarial;
use App\Models\ModeloBase;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\TarefaUnidadeOrganica;
use App\Models\UnidadeOrganica;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela multas_entregavel (módulo Estrutura Orgânica, Fase K). Sem legado: o legado só mostrava o valor da multa.
 * Escrita à mão no formato das classes geradas: o código de negócio vai em App\Models\MultaEntregavel.
 */
abstract class MultaEntregavelBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'multas_entregavel';

    protected string $moduloAuditoria = 'Estrutura orgânica › Multas';

    protected $fillable = [
        'empresa_id', 'entregavel_id', 'tarefa_unidade_id', 'unidade_organica_id', 'colaborador_id', 'periodo_de', 'periodo_ate', 'limite', 'dias_atraso', 'valor', 'descricao', 'estado', 'decidido_por', 'decidido_por_colaborador_id', 'decidido_em', 'motivo_decisao', 'periodo_processamento_salarial_id', 'linha_folha_salarial_id', 'descontada_em', 'criado_por',
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
            'periodo_ate' => 'date:Y-m-d',
            'limite' => 'date:Y-m-d',
            'dias_atraso' => 'integer',
            'valor' => 'decimal:2',
            'decidido_por_colaborador_id' => 'integer',
            'decidido_em' => 'datetime',
            'periodo_processamento_salarial_id' => 'integer',
            'linha_folha_salarial_id' => 'integer',
            'descontada_em' => 'datetime',
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

    public function periodoProcessamento(): BelongsTo
    {
        return $this->belongsTo(PeriodoProcessamentoSalarial::class, 'periodo_processamento_salarial_id');
    }

    public function linhaFolha(): BelongsTo
    {
        return $this->belongsTo(LinhaFolhaSalarial::class, 'linha_folha_salarial_id');
    }
}
