<?php

namespace App\Models\Base;

use App\Models\AtivoImobilizado;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\Projeto;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela afetacoes_ativos_projeto (módulo Projectos). Legado: project_asset_allocations · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\AfetacaoAtivoProjeto.
 */
abstract class AfetacaoAtivoProjetoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'afetacoes_ativos_projeto';

    protected string $moduloAuditoria = 'Projectos';

    protected $fillable = [
        'empresa_id', 'projeto_id', 'ativo_imobilizado_id', 'data_inicio', 'data_fim',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'projeto_id' => 'integer',
            'ativo_imobilizado_id' => 'integer',
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function ativoImobilizado(): BelongsTo
    {
        return $this->belongsTo(AtivoImobilizado::class, 'ativo_imobilizado_id');
    }
}
