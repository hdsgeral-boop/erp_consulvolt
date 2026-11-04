<?php

namespace App\Support\Autenticacao;

/**
 * Do User-Agent só se guarda o mínimo: a família do navegador e do sistema operativo («Chrome em Windows») e um hash
 * dessa família (vinculação do token ao dispositivo). Versões ficam de fora de propósito: uma actualização automática
 * do navegador não pode terminar a sessão. O texto completo do User-Agent não é guardado.
 */
final class AgenteUtilizador
{
    public static function navegador(?string $ua): string
    {
        $ua = (string) $ua;

        return match (true) {
            $ua === '' => 'Desconhecido',
            (bool) preg_match('~Edg(e|A|iOS)?/~', $ua) => 'Edge',
            (bool) preg_match('~OPR/|Opera~', $ua) => 'Opera',
            (bool) preg_match('~Firefox/|FxiOS/~', $ua) => 'Firefox',
            (bool) preg_match('~Chrome/|CriOS/|Chromium/~', $ua) => 'Chrome',
            (bool) preg_match('~Safari/~', $ua) => 'Safari',
            (bool) preg_match('~curl|Symfony|Postman|python|axios|node|Go-http|okhttp|Java~i', $ua) => 'Cliente API',
            default => 'Navegador',
        };
    }

    public static function sistema(?string $ua): string
    {
        $ua = (string) $ua;

        return match (true) {
            (bool) preg_match('~Windows~', $ua) => 'Windows',
            (bool) preg_match('~Android~', $ua) => 'Android',
            (bool) preg_match('~iPhone|iPad|iPod~', $ua) => 'iOS',
            (bool) preg_match('~Macintosh|Mac OS X~', $ua) => 'macOS',
            (bool) preg_match('~CrOS~', $ua) => 'ChromeOS',
            (bool) preg_match('~Linux|X11~', $ua) => 'Linux',
            default => 'sistema desconhecido',
        };
    }

    public static function rotulo(?string $ua): string
    {
        return self::navegador($ua).' em '.self::sistema($ua);
    }

    public static function assinatura(?string $ua): string
    {
        return hash('sha256', self::navegador($ua).'|'.self::sistema($ua));
    }

    /** IP aproximado para mostrar ao utilizador: IPv4 sem o último octeto; IPv6 só o prefixo /48. */
    public static function ipAproximado(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $o = explode('.', $ip);

            return $o[0].'.'.$o[1].'.'.$o[2].'.x';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            $h = array_map(fn ($x) => dechex($x), array_values(unpack('n3', substr($bin, 0, 6))));

            return implode(':', $h).'::/48';
        }

        return null;
    }
}
