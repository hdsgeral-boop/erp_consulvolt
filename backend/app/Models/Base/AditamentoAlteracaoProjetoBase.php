<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\Projeto;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela aditamentos_alteracoes_projeto (módulo Projectos). Legado: project_change_orders · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\AditamentoAlteracaoProjeto.
 */
abstract class AditamentoAlteracaoProjetoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'aditamentos_alteracoes_projeto';

    protected string $moduloAuditoria = 'Projectos';

    protected $fillable = [
        'empresa_id', 'projeto_id', 'descricao', 'montante', 'estado',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'projeto_id' => 'integer',
            'montante' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }
}
