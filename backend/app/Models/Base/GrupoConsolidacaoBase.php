<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\Empresa;
use App\Models\ExecucaoConsolidacao;
use App\Models\MembroConsolidacao;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela grupos_consolidacao (módulo Contabilidade). Legado: consolidation_groups · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\GrupoConsolidacao.
 */
abstract class GrupoConsolidacaoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'grupos_consolidacao';

    protected string $moduloAuditoria = 'Contabilidade';

    protected $fillable = [
        'empresa_id', 'empresa_holding_id', 'nome', 'moeda_apresentacao', 'conta_reserva_cambial', 'eliminacao_ativa', 'prefixos_excluidos_eliminacao', 'conta_diferenca_eliminacao', 'ultima_execucao_id',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'empresa_holding_id' => 'integer',
            'eliminacao_ativa' => 'boolean',
            'ultima_execucao_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function empresaHolding(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_holding_id');
    }

    public function ultimaExecucao(): BelongsTo
    {
        return $this->belongsTo(ExecucaoConsolidacao::class, 'ultima_execucao_id');
    }

    public function membrosConsolidacao(): HasMany
    {
        return $this->hasMany(MembroConsolidacao::class, 'grupo_consolidacao_id');
    }

    public function execucoesConsolidacao(): HasMany
    {
        return $this->hasMany(ExecucaoConsolidacao::class, 'grupo_consolidacao_id');
    }
}
