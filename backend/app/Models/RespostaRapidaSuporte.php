<?php

namespace App\Models;

/** Resposta pré-definida ("macro") para a equipa de suporte inserir rapidamente numa conversa. Catálogo partilhado. */
class RespostaRapidaSuporte extends ModeloBase
{
    protected $table = 'respostas_rapidas_suporte';

    protected $fillable = ['titulo', 'texto'];
}
