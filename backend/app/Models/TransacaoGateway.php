<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transação de pagamento via gateway para subscrições de licenças.
 */
class TransacaoGateway extends ModeloBase
{
    public const ESTADO_PENDENTE = 'PENDENTE';
    public const ESTADO_PAGO = 'PAGO';
    public const ESTADO_CANCELADO = 'CANCELADO';
    public const ESTADO_FALHOU = 'FALHOU';
    public const ESTADO_EXPIRADA = 'EXPIRADA';

    public const GATEWAY_REFERENCIA = 'REFERENCIA';
    public const GATEWAY_EXPRESS = 'EXPRESS';
    public const GATEWAY_MANUAL = 'MANUAL';

    public const CREATED_AT = 'criado_em';
    public const UPDATED_AT = 'atualizado_em';

    protected $table = 'transacoes_gateway';

    protected $fillable = [
        'subscricao_id',
        'gateway',
        'referencia_externa',
        'entidade',
        'referencia',
        'telemovel',
        'valor',
        'moeda',
        'estado',
        'dados_pedido',
        'dados_resposta',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'dados_pedido' => 'array',
            'dados_resposta' => 'array',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function subscricao(): BelongsTo
    {
        return $this->belongsTo(SubscricaoEmpresa::class, 'subscricao_id');
    }
}
