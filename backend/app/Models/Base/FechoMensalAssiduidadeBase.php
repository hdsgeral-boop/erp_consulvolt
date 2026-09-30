<?php

namespace App\Models\Base;

use App\Models\AusenciaFaltaColaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\PeriodoProcessamentoSalarial;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela fechos_mensais_assiduidade (módulo RH). Legado: rh_attendance_closures · 2 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\FechoMensalAssiduidade.
 */
abstract class FechoMensalAssiduidadeBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'fechos_mensais_assiduidade';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'mes', 'estado', 'estado_original', 'dias_uteis', 'linhas', 'totais', 'fechado_em', 'fechado_por', 'lancado_em', 'lancado_por', 'periodo_processamento_salarial_id', 'apurado_ate', 'ausencias_geradas', 'reaberto_em', 'reaberto_por', 'motivo_reabertura', 'configuracao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'dias_uteis' => 'integer',
            'linhas' => 'array',
            'totais' => 'array',
            'fechado_em' => 'datetime',
            'lancado_em' => 'datetime',
            'periodo_processamento_salarial_id' => 'integer',
            'apurado_ate' => 'date',
            'ausencias_geradas' => 'integer',
            'reaberto_em' => 'datetime',
            'configuracao' => 'array',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function periodoProcessamentoSalarial(): BelongsTo
    {
        return $this->belongsTo(PeriodoProcessamentoSalarial::class, 'periodo_processamento_salarial_id');
    }

    public function ausenciasFaltasColaboradores(): HasMany
    {
        return $this->hasMany(AusenciaFaltaColaborador::class, 'fecho_mensal_assiduidade_id');
    }
}
