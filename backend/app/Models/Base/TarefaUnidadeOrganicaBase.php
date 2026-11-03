<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\EntregavelTarefaUnidade;
use App\Models\ModeloBase;
use App\Models\PostoTrabalho;
use App\Models\UnidadeOrganica;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela tarefas_unidade_organica (módulo Estrutura Orgânica, Fase J). Legado: org_unit_tasks.
 * Escrita à mão no formato das classes geradas: o código de negócio vai em App\Models\TarefaUnidadeOrganica.
 */
abstract class TarefaUnidadeOrganicaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'tarefas_unidade_organica';

    protected string $moduloAuditoria = 'Estrutura orgânica › Tarefas';

    protected $fillable = [
        'empresa_id', 'unidade_organica_id', 'codigo', 'nome', 'descricao', 'url_instrucoes', 'periodicidade', 'colaborador_responsavel_id', 'posto_trabalho_id', 'ativo', 'ordem', 'criado_por', 'atualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'unidade_organica_id' => 'integer',
            'colaborador_responsavel_id' => 'integer',
            'posto_trabalho_id' => 'integer',
            'ativo' => 'boolean',
            'ordem' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function unidadeOrganica(): BelongsTo
    {
        return $this->belongsTo(UnidadeOrganica::class, 'unidade_organica_id');
    }

    public function colaboradorResponsavel(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_responsavel_id');
    }

    public function postoTrabalho(): BelongsTo
    {
        return $this->belongsTo(PostoTrabalho::class, 'posto_trabalho_id');
    }

    public function entregaveis(): HasMany
    {
        return $this->hasMany(EntregavelTarefaUnidade::class, 'tarefa_unidade_id');
    }
}
