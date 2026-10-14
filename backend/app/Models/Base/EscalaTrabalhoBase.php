<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela escalas_trabalho (módulo RH). Legado: rh_schedules · 21 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\EscalaTrabalho.
 */
abstract class EscalaTrabalhoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'escalas_trabalho';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'colaborador_id', 'dia', 'entrada', 'saida', 'intervalo_minutos', 'tolerancia_min', 'folga', 'prioritaria', 'origem', 'nota', 'criado_por', 'periodos', 'saida_intervalo', 'entrada_intervalo', 'atualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'colaborador_id' => 'integer',
            'dia' => 'date',
            'intervalo_minutos' => 'integer',
            'tolerancia_min' => 'integer',
            'folga' => 'boolean',
            'prioritaria' => 'boolean',
            'periodos' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }
}
