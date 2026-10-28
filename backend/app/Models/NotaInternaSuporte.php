<?php

namespace App\Models;

/** Nota interna de um ticket de suporte: só visível à equipa (agentes e plataforma), nunca ao cliente. */
class NotaInternaSuporte extends ModeloBase
{
    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = null;

    protected $table = 'notas_internas_suporte';

    protected $fillable = ['ticket_id', 'autor_nome', 'nota'];
}
