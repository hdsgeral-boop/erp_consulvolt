<?php

namespace App\Services\Imobiliario;

use Illuminate\Support\Facades\Auth;

/** Quem faz a acção (nome para os campos `criado_por`/`atualizado_por` e id para os trilhos); «sistema» fora de um pedido autenticado. */
final class Autor
{
    public static function nome(): string
    {
        return mb_substr((string) (Auth::user()?->nomeApresentacao() ?? 'sistema'), 0, 100);
    }

    public static function id(): ?int
    {
        return Auth::id() ? (int) Auth::id() : null;
    }
}
