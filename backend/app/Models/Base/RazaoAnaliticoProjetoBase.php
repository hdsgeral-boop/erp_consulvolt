<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\LancamentoContabil;
use App\Models\ModeloBase;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela razao_analitico_projetos (módulo Projectos). Legado: project_ledger · 30 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\RazaoAnaliticoProjeto.
 */
abstract class RazaoAnaliticoProjetoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'razao_analitico_projetos';

    protected string $moduloAuditoria = 'Projectos';

    protected $fillable = [
        'empresa_id', 'projeto_id', 'tarefa_projeto_id', 'rubrica', 'modulo_origem', 'tipo_documento_origem', 'tipo_documento_origem_original', 'natureza', 'natureza_original', 'data', 'valor', 'montante', 'documento_origem_id', 'lancamento_contabil_id', 'descricao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'projeto_id' => 'integer',
            'tarefa_projeto_id' => 'integer',
            'data' => 'date',
            'valor' => 'decimal:2',
            'montante' => 'decimal:2',
            'lancamento_contabil_id' => 'integer',
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

    public function lancamentoContabil(): BelongsTo
    {
        return $this->belongsTo(LancamentoContabil::class, 'lancamento_contabil_id');
    }
}
