<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\Empresa;
use App\Models\GrupoConsolidacao;
use App\Models\LancamentoContabil;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela execucoes_consolidacao (módulo Contabilidade). Legado: consolidation_runs · 4 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ExecucaoConsolidacao.
 */
abstract class ExecucaoConsolidacaoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'execucoes_consolidacao';

    protected string $moduloAuditoria = 'Contabilidade';

    protected $fillable = [
        'empresa_id', 'grupo_consolidacao_id', 'empresa_holding_id', 'data_execucao', 'executado_em', 'data_fim', 'codigo_moeda', 'estado', 'nome_utilizador', 'totais',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'grupo_consolidacao_id' => 'integer',
            'empresa_holding_id' => 'integer',
            'data_execucao' => 'date',
            'executado_em' => 'datetime',
            'data_fim' => 'date',
            'totais' => 'array',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function grupoConsolidacao(): BelongsTo
    {
        return $this->belongsTo(GrupoConsolidacao::class, 'grupo_consolidacao_id');
    }

    public function empresaHolding(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_holding_id');
    }

    public function empresas(): HasMany
    {
        return $this->hasMany(Empresa::class, 'execucao_consolidacao_id');
    }

    public function lancamentosContabeis(): HasMany
    {
        return $this->hasMany(LancamentoContabil::class, 'execucao_consolidacao_id');
    }

    public function gruposConsolidacao(): HasMany
    {
        return $this->hasMany(GrupoConsolidacao::class, 'ultima_execucao_id');
    }
}
