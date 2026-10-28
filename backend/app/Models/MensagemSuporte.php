<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Mensagem da conversa de um ticket de suporte (cliente <-> plataforma). */
class MensagemSuporte extends ModeloBase
{
    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = null;

    public const CLIENTE = 'CLIENTE';

    public const PLATAFORMA = 'PLATAFORMA';

    protected $table = 'mensagens_suporte';

    protected $fillable = ['ticket_id', 'autor_tipo', 'autor_nome', 'mensagem'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketSuporte::class, 'ticket_id');
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(AnexoSuporte::class, 'mensagem_id');
    }
}