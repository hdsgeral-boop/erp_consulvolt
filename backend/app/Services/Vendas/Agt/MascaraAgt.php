<?php

namespace App\Services\Vendas\Agt;

/**
 * Máscara dos dados sensíveis antes de gravar pedidos/respostas no registo de comunicações (OWASP A02/A09):
 * assinaturas JWS e segredos ficam só com o início; NIF de clientes (dados pessoais) ficam parcialmente ocultos.
 * A autenticação Basic nunca é registada (vai no cabeçalho, que não se guarda).
 */
final class MascaraAgt
{
    private const SEGREDOS = '/(jws|signature|assinatura|password|palavra|passe|token|secret|segredo|chave|private|authorization)/i';

    private const PESSOAIS = ['customerTaxID', 'nif_cliente'];

    /** Campos com nomes parecidos que não são segredo. */
    private const PUBLICOS = ['signatureVersion'];

    public const MAX_BYTES = 20000;

    public static function aplicar(mixed $dados): mixed
    {
        if (! is_array($dados)) {
            return $dados;
        }
        $saida = [];
        foreach ($dados as $k => $v) {
            if (is_string($k) && ! in_array($k, self::PUBLICOS, true) && preg_match(self::SEGREDOS, $k) && is_scalar($v)) {
                $saida[$k] = self::ocultar((string) $v);
            } elseif (is_string($k) && in_array($k, self::PESSOAIS, true) && is_scalar($v)) {
                $saida[$k] = self::parcial((string) $v);
            } else {
                $saida[$k] = is_array($v) ? self::aplicar($v) : $v;
            }
        }

        return $saida;
    }

    /** Limita o tamanho guardado (as respostas da AGT podem trazer listas grandes). */
    public static function limitar(mixed $dados): ?array
    {
        if ($dados === null) {
            return null;
        }
        $json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            return ['nao_serializavel' => true];
        }
        if (strlen($json) <= self::MAX_BYTES) {
            return is_array($dados) ? $dados : ['valor' => $dados];
        }

        return ['truncado' => true, 'bytes' => strlen($json), 'inicio' => mb_strcut($json, 0, self::MAX_BYTES)];
    }

    public static function ocultar(string $v): string
    {
        return $v === '' ? '' : (mb_strlen($v) <= 12 ? '***' : mb_substr($v, 0, 12).'…['.mb_strlen($v).']');
    }

    public static function parcial(string $v): string
    {
        $n = mb_strlen($v);

        return $n <= 4 ? '***' : mb_substr($v, 0, 2).str_repeat('*', $n - 4).mb_substr($v, -2);
    }
}
