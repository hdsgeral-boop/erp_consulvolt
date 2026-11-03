<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ExecucaoEntregavel;
use App\Models\ImpedimentoEntregavel;
use App\Models\ItemProdutividadeRH;
use App\Models\ModeloBase;
use App\Models\TarefaUnidadeOrganica;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela entregaveis_tarefa_unidade (módulo Estrutura Orgânica, Fase J). Legado: org_task_deliverables.
 * Escrita à mão no formato das classes geradas: o código de negócio vai em App\Models\EntregavelTarefaUnidade.
 */
abstract class EntregavelTarefaUnidadeBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'entregaveis_tarefa_unidade';

    protected string $moduloAuditoria = 'Estrutura orgânica › Tarefas';

    protected $fillable = [
        'empresa_id', 'tarefa_unidade_id', 'nome', 'descricao', 'metrica', 'unidade', 'meta', 'item_produtividade_id', 'colaborador_id', 'origem_dados', 'crm_filtro', 'prazo_dia', 'prazo_mes', 'paga_fora_prazo', 'alerta', 'multa_valor', 'multa_descricao', 'avaliacao', 'peso_avaliacao', 'ativo', 'ordem', 'copiado_de_id', 'criado_por', 'atualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'tarefa_unidade_id' => 'integer',
            'meta' => 'decimal:4',
            'item_produtividade_id' => 'integer',
            'colaborador_id' => 'integer',
            'crm_filtro' => 'array',
            'prazo_dia' => 'integer',
            'prazo_mes' => 'integer',
            'paga_fora_prazo' => 'boolean',
            'alerta' => 'boolean',
            'multa_valor' => 'decimal:2',
            'avaliacao' => 'boolean',
            'peso_avaliacao' => 'decimal:2',
            'ativo' => 'boolean',
            'ordem' => 'integer',
            'copiado_de_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(TarefaUnidadeOrganica::class, 'tarefa_unidade_id');
    }

    public function itemProdutividade(): BelongsTo
    {
        return $this->belongsTo(ItemProdutividadeRH::class, 'item_produtividade_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    public function execucoes(): HasMany
    {
        return $this->hasMany(ExecucaoEntregavel::class, 'entregavel_id');
    }

    public function impedimentos(): HasMany
    {
        return $this->hasMany(ImpedimentoEntregavel::class, 'entregavel_id');
    }
}
