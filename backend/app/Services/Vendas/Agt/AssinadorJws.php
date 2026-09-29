<?php

namespace App\Services\Vendas\Agt;

use OpenSSLAsymmetricKey;
use RuntimeException;

/** JWS compacto RS256 (cabeçalho.conteúdo.assinatura), como o serviço intermédio do legado (servidor.js:263-268). */
final class AssinadorJws
{
    public static function assinar(array $conteudo, OpenSSLAsymmetricKey $chave, string $typ = 'JWT'): string
    {
        $cabecalho = self::b64u(json_encode(['alg' => 'RS256', 'typ' => $typ], JSON_UNESCAPED_SLASHES));
        // JSON sem espaços nem quebras de linha; números como números (os totais têm de coincidir com o documento)
        $corpo = self::b64u(json_encode($conteudo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        if (! openssl_sign("{$cabecalho}.{$corpo}", $assinatura, $chave, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Falha a assinar o pedido (JWS).');
        }

        return "{$cabecalho}.{$corpo}.".self::b64u($assinatura);
    }

    public static function b64u(string $v): string
    {
        return rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    }
}
