<?php

namespace App\Support\Email;

use App\Models\Tenant;
use App\Models\Utilizador;
use App\Services\Autenticacao\ServicoAutenticacao;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envio dos e-mails de conta (EmailBase, em fila): sem endereço não faz nada; uma falha (SMTP, fila) fica no log e
 * nunca anula a operação que o originou (criar um utilizador não pode falhar por causa do correio).
 */
final class EnviarEmail
{
    public static function para(?string $email, Mailable $mensagem): void
    {
        $email = trim((string) $email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        try {
            Mail::to($email)->send($mensagem);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Endereço onde o utilizador entra (https://<cliente>.<domínio>, ou o APP_URL sem domínio configurado). */
    public static function enderecoDe(Utilizador $u): string
    {
        return app(ServicoAutenticacao::class)->enderecoDoUtilizador($u) ?? rtrim((string) config('app.url'), '/');
    }

    /** Nome do cliente (tenant) do utilizador, para os textos dos e-mails. */
    public static function nomeClienteDe(Utilizador $u): string
    {
        return ($u->tenant_id ? Tenant::query()->whereKey($u->tenant_id)->value('nome') : null) ?: 'a sua empresa';
    }
}
