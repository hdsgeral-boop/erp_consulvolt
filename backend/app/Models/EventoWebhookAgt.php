<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/** agt_webhook_eventos — notificações recebidas da AGT (único por empresa + nonce: anti-replay). */
class EventoWebhookAgt extends ModeloBase
{
    use PertenceEmpresa;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $table = 'agt_webhook_eventos';

    protected $fillable = ['empresa_id', 'nonce', 'carimbo', 'recebido_em', 'ip', 'corpo', 'estado', 'processado_em', 'resultado'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'corpo' => 'array', 'carimbo' => 'datetime', 'recebido_em' => 'datetime', 'processado_em' => 'datetime'];
    }
}
