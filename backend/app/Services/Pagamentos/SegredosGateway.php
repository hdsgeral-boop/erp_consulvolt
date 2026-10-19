<?php

namespace App\Services\Pagamentos;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Leitura/escrita das configurações dos gateways, com os segredos cifrados em repouso (prefixo `enc:` + Crypt).
 * Valores antigos em texto simples continuam legíveis até a migração de dados os cifrar.
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

    public static function cifrar(string $valor): string
    {
        return $valor === '' || str_starts_with($valor, self::PREFIXO) ? $valor : self::PREFIXO.Crypt::encryptString($valor);
    }

    public static function decifrar(?string $valor): string
    {
        if ($valor === null || $valor === '' || ! str_starts_with($valor, self::PREFIXO)) {
            return (string) $valor;
        }
        try {
            return Crypt::decryptString(substr($valor, strlen(self::PREFIXO)));
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
