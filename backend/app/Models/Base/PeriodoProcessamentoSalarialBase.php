<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\DocumentoTesouraria;
use App\Models\LinhaFolhaSalarial;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela periodos_processamento_salarial (módulo RH). Legado: payroll_periods · 47 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\PeriodoProcessamentoSalarial.
 */
abstract class PeriodoProcessamentoSalarialBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'periodos_processamento_salarial';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'mes_ano', 'estado', 'estado_original', 'contabilizado', 'fechado_em', 'fechado_por', 'validado_em', 'validado_por', 'numero_lan_contabilizacao', 'modo_calculo',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'contabilizado' => 'boolean',
            'fechado_em' => 'datetime',
            'validado_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function linhasFolhaSalarial(): HasMany
    {
        return $this->hasMany(LinhaFolhaSalarial::class, 'periodo_processamento_salarial_id');
    }

    public function documentosTesouraria(): HasMany
    {
        return $this->hasMany(DocumentoTesouraria::class, 'periodo_processamento_salarial_id');
    }
}
