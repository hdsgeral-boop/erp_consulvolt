<?php

namespace App\Support\Seguranca;

use App\Exceptions\ErroNegocio;

/**
 * OWASP A10 (SSRF) — validação de um URL de SAÍDA indicado por um utilizador (hoje: o relógio biométrico, que está
 * legitimamente na rede local da empresa, por isso as redes privadas 10/172.16/192.168 são aceites).
 *
 * Recusa: esquemas ≠ http/https; credenciais no URL; anfitriões numéricos não canónicos (2852039166, 0x7f.1, 0177.0.0.1
 * — o curl interpretá-los-ia como 169.254.169.254 ou 127.0.0.1); loopback, «localhost», 0.0.0.0, link-local
 * (169.254/16 = metadados da nuvem, fe80::/10), multicast, IPv6 único-local de metadados (fd00:ec2::254) e IPv4
 * mapeado em IPv6; os nomes dos serviços internos do Docker (redis, postgres, app, worker, scheduler, web) e as portas
 * dos serviços internos (5432, 6379, 9000, 11211). Os IP resolvidos são verificados TODOS e devolvidos para fixar a
 * ligação (CURLOPT_RESOLVE) — sem isto, um DNS malicioso podia responder um IP público na verificação e 169.254.x no
 * pedido (DNS rebinding). Os redireccionamentos têm de estar desligados no cliente HTTP.
 */
final class GuardaUrlSaida
{
    private const SERVICOS_INTERNOS = ['localhost', 'redis', 'postgres', 'app', 'worker', 'scheduler', 'web', 'nginx', 'metadata', 'metadata.google.internal'];

    private const PORTAS_INTERNAS = [5432, 6379, 9000, 11211];

    /**
     * @return array{host: string, porta: int, ips: list<string>, resolver: list<string>} resolver = valores para CURLOPT_RESOLVE
     *
     * @throws ErroNegocio URL_SAIDA_RECUSADA (422)
     */
    public static function validar(string $url, string $codigo = 'URL_SAIDA_RECUSADA'): array
    {
        $p = parse_url(trim($url));
        $esquema = strtolower((string) ($p['scheme'] ?? ''));
        $host = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
        if (! in_array($esquema, ['http', 'https'], true) || $host === '' || isset($p['user']) || isset($p['pass'])) {
            self::recusar($codigo, 'só endereços http(s) sem credenciais');
        }
        $porta = (int) ($p['port'] ?? ($esquema === 'https' ? 443 : 80));
        if (in_array($porta, self::PORTAS_INTERNAS, true)) {
            self::recusar($codigo, "porta {$porta} reservada a serviços internos");
        }
        if (in_array($host, self::SERVICOS_INTERNOS, true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            self::recusar($codigo, 'anfitrião interno do servidor');
        }
        $eIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        // só dígitos/pontos/x mas não é um IPv4 canónico: forma numérica ambígua (decimal, octal, hexadecimal)
        if (! $eIp && preg_match('/^[0-9.x]+$/i', $host) && preg_match('/^(0x[0-9a-f]+|[0-9]+)(\.(0x[0-9a-f]+|[0-9]+))*$/i', $host)) {
            self::recusar($codigo, 'endereço numérico não canónico');
        }
        $ips = $eIp ? [$host] : self::resolver($host);
        foreach ($ips as $ip) {
            if (self::ipProibido($ip)) {
                self::recusar($codigo, 'endereço local, de metadados ou reservado');
            }
        }

        return ['host' => $host, 'porta' => $porta, 'ips' => $ips, 'resolver' => $eIp ? [] : array_map(fn ($ip) => "{$host}:{$porta}:{$ip}", $ips)];
    }

    public static function ipProibido(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return true;
        }
        if (strlen($bin) === 16) {
            $prefixo = substr($bin, 0, 12);
            // IPv4 mapeado (::ffff:169.254.169.254) ou NAT64 (64:ff9b::a9fe:a9fe): verifica o IPv4 embutido
            if ($prefixo === str_repeat("\0", 10)."\xff\xff" || $prefixo === "\x00\x64\xff\x9b".str_repeat("\0", 8)) {
                return self::ipProibido((string) inet_ntop(substr($bin, 12)));
            }
            if ($prefixo === str_repeat("\0", 12)) {
                return true;   // ::, ::1 e IPv4 compatível (obsoleto)
            }
            $b0 = ord($bin[0]);
            $b1 = ord($bin[1]);

            return ($b0 === 0xFE && ($b1 & 0xC0) === 0x80)   // fe80::/10 link-local
                || $b0 === 0xFF                                // multicast
                || strtolower((string) inet_ntop($bin)) === 'fd00:ec2::254';   // metadados AWS em IPv6
        }
        $o = array_map('intval', explode('.', $ip));

        return $o[0] === 127 || $o[0] === 0 || ($o[0] === 169 && $o[1] === 254) || $o[0] >= 224
            || ($o[0] === 100 && $o[1] >= 64 && $o[1] <= 127 && $o[2] === 100 && $o[3] === 200);   // metadados Alibaba (100.100.100.200)
    }

    /** @return list<string> */
    private static function resolver(string $host): array
    {
        $ips = [];
        foreach ([DNS_A, DNS_AAAA] as $tipo) {
            foreach (@dns_get_record($host, $tipo) ?: [] as $r) {
                $ips[] = (string) ($r['ip'] ?? $r['ipv6'] ?? '');
            }
        }
        if (! $ips && ($v4 = @gethostbynamel($host))) {
            $ips = $v4;
        }

        return array_values(array_unique(array_filter($ips)));
    }

    private static function recusar(string $codigo, string $motivo): never
    {
        throw new ErroNegocio("Endereço recusado por segurança: {$motivo}.", $codigo, 422);
    }
}
