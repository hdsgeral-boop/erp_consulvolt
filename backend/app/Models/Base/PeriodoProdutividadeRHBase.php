<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\LinhaFolhaSalarial;
use App\Models\ModeloBase;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\RegistoProdutividadeRH;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela periodos_produtividade_rh (módulo RH). Legado: rh_prod_periods · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\PeriodoProdutividadeRH.
 */
abstract class PeriodoProdutividadeRHBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'periodos_produtividade_rh';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'mes', 'data_inicio', 'data_fim', 'observacoes', 'atualizado_por', 'estado', 'estado_original', 'criado_por', 'fechado_em', 'fechado_por', 'total_fecho', 'registos_fecho', 'lancado_em', 'lancado_por', 'periodo_processamento_salarial_id', 'reaberto_em', 'reaberto_por', 'motivo_reabertura',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'fechado_em' => 'datetime',
            'total_fecho' => 'decimal:2',
            'registos_fecho' => 'integer',
            'lancado_em' => 'datetime',
            'periodo_processamento_salarial_id' => 'integer',
            'reaberto_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }

    public function periodoProcessamentoSalarial(): BelongsTo
    {
        return $this->belongsTo(PeriodoProcessamentoSalarial::class, 'periodo_processamento_salarial_id');
    }

    public function linhasFolhaSalarial(): HasMany
    {
        return $this->hasMany(LinhaFolhaSalarial::class, 'periodo_produtividade_id');
    }

    public function registosProdutividadeRh(): HasMany
    {
        return $this->hasMany(RegistoProdutividadeRH::class, 'periodo_produtividade_id');
    }
}
