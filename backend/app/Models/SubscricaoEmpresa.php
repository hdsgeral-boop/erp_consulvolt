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
        'ciclo_codigo', 'valor_bruto', 'desconto_ciclo_percentagem', 'valor_desconto_ciclo', 'valor_apos_ciclo',
        'cupom_id', 'cupom_codigo', 'desconto_cupom_tipo', 'desconto_cupom_valor', 'valor_desconto_cupom', 'venda_id',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2', 'meses' => 'integer', 'periodo_inicio' => 'date', 'periodo_fim' => 'date', 'pago_em' => 'datetime',
            'valor_bruto' => 'decimal:2', 'desconto_ciclo_percentagem' => 'decimal:2', 'valor_desconto_ciclo' => 'decimal:2',
            'valor_apos_ciclo' => 'decimal:2',
            'desconto_cupom_valor' => 'decimal:2', 'valor_desconto_cupom' => 'decimal:2',
        ];
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(PlanoLicenca::class, 'plano_id');
    }
}