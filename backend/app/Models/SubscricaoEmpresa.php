<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** subscricoes_empresa — pedido de renovação/compra de licença de uma empresa (ADR-074). */
class SubscricaoEmpresa extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    public const PENDENTE = 'PENDENTE';

    public const PAGO = 'PAGO';

    public const CANCELADO = 'CANCELADO';

    protected $table = 'subscricoes_empresa';

    protected string $moduloAuditoria = 'Sistema/Licença';

    protected $fillable = [
        'empresa_id', 'plano_id', 'meses', 'valor', 'moeda', 'estado', 'referencia', 'periodo_inicio', 'periodo_fim',
        'pago_em', 'pago_por', 'metodo', 'notas', 'criado_por',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2', 'meses' => 'integer', 'periodo_inicio' => 'date', 'periodo_fim' => 'date', 'pago_em' => 'datetime',
        ];
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(PlanoLicenca::class, 'plano_id');
    }
}