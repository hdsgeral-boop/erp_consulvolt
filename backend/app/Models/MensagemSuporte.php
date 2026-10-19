<?php

namespace App\Models;

/** Mensagem da conversa de um ticket de suporte (cliente <-> plataforma). */
class MensagemSuporte extends ModeloBase
{
    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = null;

    public const CLIENTE = 'CLIENTE';

    public const PLATAFORMA = 'PLATAFORMA';

    protected $table = 'mensagens_suporte';

    protected $fillable = ['ticket_id', 'autor_tipo', 'autor_nome', 'mensagem'];
}