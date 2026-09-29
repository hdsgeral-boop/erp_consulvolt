<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela contratos_trabalho (módulo RH). Legado: contracts · 89 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ContratoTrabalho.
 */
abstract class ContratoTrabalhoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'contratos_trabalho';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'colaborador_id', 'remuneracoes', 'dias_contrato_mes', 'data_inicio', 'data_fim', 'estado', 'horas_por_dia', 'codigo_moeda', 'produtividade',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'colaborador_id' => 'integer',
            'remuneracoes' => 'array',
            'dias_contrato_mes' => 'integer',
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'horas_por_dia' => 'decimal:3',
            'produtividade' => 'array',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }
}
