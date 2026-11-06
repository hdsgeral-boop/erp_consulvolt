<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;

/** Normalização de entradas do cadastro imobiliário (txt, r2, limparDocumentos e normalizarUrl do legado). */
final class Normaliza
{
    public static function texto(mixed $v): ?string
    {
        $t = trim((string) ($v ?? ''));

        return $t === '' ? null : $t;
    }

    /** Número com 2 casas (meio para cima); vazio/inválido dá 0 ou null conforme `$anulavel`. */
    public static function r2(mixed $v, bool $anulavel = false): ?float
    {
        if ($v === null || $v === '') {
            return $anulavel ? null : 0.0;
        }

        return is_numeric($v) ? round((float) $v, 2) : ($anulavel ? null : 0.0);
    }

    public static function inteiro(mixed $v, bool $anulavel = false): ?int
    {
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return $anulavel ? null : 0;
        }

        return (int) round((float) $v);
    }

    /** Só endereços http(s); sem esquema prefixa https://. Vazio devolve ''. */
    public static function url(mixed $valor, string $campo): string
    {
        $v = trim((string) ($valor ?? ''));
        if ($v === '') {
            return '';
        }
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $v) !== 1) {
            $v = 'https://'.$v;
        }
        preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $v, $esquema);
        if (! in_array(strtolower($esquema[1] ?? ''), ['http', 'https'], true)) {
            throw new ErroNegocio("{$campo}: só se aceitam endereços http:// ou https://.", 'URL_INVALIDO');
        }
        $p = parse_url($v);
        if ($p === false || ! isset($p['host']) || $p['host'] === '' || preg_match('/\s/', $v) === 1) {
            throw new ErroNegocio("{$campo}: «".trim((string) $valor).'» não é um endereço válido. Exemplo: https://…', 'URL_INVALIDO');
        }

        return $v;
    }

    /**
     * Documentos ligados (links): tipo da lista, nome, url e data; as entradas sem url são descartadas.
     *
     * @param  array<int, mixed>|null  $docs
     * @return list<array{tipo: string, nome: string, url: string, data: string}>
     */
    public static function documentos(?array $docs): array
    {
        $saida = [];
        foreach ($docs ?? [] as $d) {
            if (! is_array($d)) {
                continue;
            }
            $url = self::url($d['url'] ?? '', 'Documento');
            if ($url === '') {
                continue;
            }
            $tipo = (string) ($d['tipo'] ?? '');
            $saida[] = ['tipo' => isset(CatalogoImobiliario::TIPOS_DOCUMENTO[$tipo]) ? $tipo : 'OUTRO', 'nome' => mb_substr(trim((string) ($d['nome'] ?? '')), 0, 200),
                'url' => $url, 'data' => mb_substr(trim((string) ($d['data'] ?? '')), 0, 10)];
        }

        return $saida;
    }
}
