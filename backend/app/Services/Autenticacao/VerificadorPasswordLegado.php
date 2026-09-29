<?php

namespace App\Services\Autenticacao;

/**
 * Verifica palavras-passe com o hash do ERP legado (js/data/senhas.js):
 *   PBKDF2-HMAC-SHA256 · iterações no sufixo de `algoritmo` ("PBKDF2-SHA256-120000") · 32 bytes
 *   · salt e hash em Base64 standard · comparação em tempo constante.
 *
 * Usado apenas até ao primeiro login de cada utilizador migrado; a seguir é feito re-hash para Argon2id.
 */
final class VerificadorPasswordLegado
{
    public function verificar(string $palavraPasse, string $hashBase64, string $saltBase64, ?string $algoritmo): bool
    {
        $iteracoes = $this->iteracoes($algoritmo);
        $salt = base64_decode($saltBase64, true);
        $esperado = base64_decode($hashBase64, true);

        if ($iteracoes === null || $salt === false || $esperado === false || $esperado === '') {
            return false;
        }

        $calculado = hash_pbkdf2('sha256', $palavraPasse, $salt, $iteracoes, strlen($esperado), true);

        return hash_equals($esperado, $calculado);
    }

    /** Iterações a partir de "PBKDF2-SHA256-<n>"; algoritmo vazio assume o valor por omissão do legado. */
    private function iteracoes(?string $algoritmo): ?int
    {
        $config = config('erp.password.legado');

        if ($algoritmo === null || $algoritmo === '') {
            return $config['iteracoes_por_omissao'];
        }

        if (! str_starts_with($algoritmo, $config['prefixo_algoritmo'])) {
            return null;
        }

        $n = substr($algoritmo, strlen($config['prefixo_algoritmo']));

        return ctype_digit($n) && (int) $n > 0 ? (int) $n : null;
    }
}
