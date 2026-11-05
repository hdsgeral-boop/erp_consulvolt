<?php

namespace App\Services\Pagamentos;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Leitura/escrita das configurações dos gateways, com os segredos cifrados em repouso (prefixo `enc:` + Crypt, chave APP_KEY).
 *
 * Compatibilidade: um payload do Crypt sem o prefixo (legado da produção, ex.: `ia_fornecedores.chave`) também se decifra; valores antigos em texto simples continuam legíveis até a migração de dados os cifrar, e sem APP_KEY
 * (ex.: ambiente por configurar) não se cifra nem se quebra — o valor guarda-se/lê-se tal como está. Atenção: mudar a APP_KEY
 * sem a manter em APP_PREVIOUS_KEYS torna os segredos já cifrados ilegíveis (devolvem vazio e o gateway fica por configurar).
 */
final class SegredosGateway
{
    public const PREFIXO = 'enc:';

    /** Chaves de `configuracoes_gateway` que contêm segredos. */
    public const CHAVES_SECRETAS = ['ref_chave_api', 'mcx_chave_api', 'ref_webhook_segredo', 'mcx_webhook_segredo'];

    public static function eSecreta(string $chave): bool
    {
        return in_array($chave, self::CHAVES_SECRETAS, true);
    }

    /** Há APP_KEY configurada (sem ela o Crypt lança excepção). */
    public static function podeCifrar(): bool
    {
        return trim((string) config('app.key')) !== '';
    }

    public static function cifrar(string $valor): string
    {
        if ($valor === '' || str_starts_with($valor, self::PREFIXO) || ! self::podeCifrar()) {
            return $valor;
        }

        if (self::pareceUmPayloadCifrado($valor)) {
            return self::PREFIXO.$valor;   // payload legado já cifrado (sem prefixo): só se normaliza, não se cifra duas vezes
        }

        return self::PREFIXO.Crypt::encryptString($valor);
    }

    public static function decifrar(?string $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }
        if (str_starts_with($valor, self::PREFIXO)) {
            return self::decifrarPayload(substr($valor, strlen(self::PREFIXO)));
        }
        // Legado da produção: payload do Crypt gravado SEM o prefixo. Só se trata como cifrado se tiver a forma de um
        // payload do Laravel; se não decifrar devolve vazio (nunca o texto cifrado). Texto simples passa tal como está.
        if (self::pareceUmPayloadCifrado($valor)) {
            return self::decifrarPayload($valor);
        }

        return $valor;
    }

    /** base64 de um JSON com `iv`, `value` e `mac` (CBC) ou `tag` (GCM) (começa por `eyJ`) — a forma de `Crypt::encryptString`. */
    public static function pareceUmPayloadCifrado(string $valor): bool
    {
        if (! str_starts_with($valor, 'eyJ')) {
            return false;
        }
        $json = base64_decode($valor, true);
        $dados = $json === false ? null : json_decode($json, true);

        return is_array($dados) && isset($dados['iv'], $dados['value']) && (isset($dados['mac']) || isset($dados['tag']));
    }

    private static function decifrarPayload(string $payload): string
    {
        if (! self::podeCifrar()) {
            return '';
        }
        try {
            return Crypt::decryptString($payload);
        } catch (DecryptException) {
            return '';
        }
    }

    /** @return array<string, string> configurações com os segredos já decifrados (uso interno, nunca devolver na API). */
    public static function configuracoes(): array
    {
        $linhas = DB::table('configuracoes_gateway')->pluck('valor', 'chave')->all();
        foreach ($linhas as $chave => $valor) {
            if (self::eSecreta((string) $chave)) {
                $linhas[$chave] = self::decifrar($valor);
            }
        }

        return $linhas;
    }

    /** Grava uma configuração, cifrando-a se for segredo. */
    public static function gravar(string $chave, string $valor): void
    {
        $guardar = self::eSecreta($chave) ? self::cifrar($valor) : $valor;
        DB::table('configuracoes_gateway')->updateOrInsert(['chave' => $chave], ['valor' => $guardar, 'atualizado_em' => now()]);
    }
}
