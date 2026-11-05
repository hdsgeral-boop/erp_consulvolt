<?php

namespace App\Services\Autenticacao;

/**
 * TOTP (RFC 6238, sobre HOTP — RFC 4226): implementação própria, sem dependências externas (evita a instabilidade
 * observada ao tentar `composer require` uma biblioteca de terceiros neste ambiente — o resolver de dependências
 * tocou em pacotes não relacionados). Compatível com Google Authenticator, Authy, 1Password, etc.: segredo em
 * Base32, HMAC-SHA1, 6 dígitos, passo de 30 segundos — os parâmetros universais destas apps.
 */
final class ServicoTotp
{
    private const ALFABETO_BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const DIGITOS = 6;

    private const PASSO_SEGUNDOS = 30;

    /** Segredo aleatório de 160 bits (20 bytes), codificado em Base32 — o tamanho recomendado pela RFC 4226 §4. */
    public function gerarSegredo(): string
    {
        return self::base32Codificar(random_bytes(20));
    }

    /** URI `otpauth://` para gerar o QR code que uma app de autenticação lê (Google Authenticator, Authy, …). */
    public function uriProvisionamento(string $segredo, string $conta, string $emissor = 'Consulvolt'): string
    {
        return 'otpauth://totp/'.rawurlencode($emissor).':'.rawurlencode($conta)
            .'?secret='.$segredo.'&issuer='.rawurlencode($emissor).'&digits='.self::DIGITOS.'&period='.self::PASSO_SEGUNDOS;
    }

    /**
     * Verifica um código de 6 dígitos contra o segredo, com tolerância de ±1 passo (±30s) para desvios de relógio
     * do dispositivo — a prática universal destas apps. Comparação em tempo constante (hash_equals).
     */
    public function verificar(string $segredo, string $codigo, int $janela = 1): bool
    {
        $codigo = trim($codigo);
        if (! ctype_digit($codigo) || strlen($codigo) !== self::DIGITOS) {
            return false;
        }

        $contadorAtual = intdiv(time(), self::PASSO_SEGUNDOS);
        for ($desvio = -$janela; $desvio <= $janela; $desvio++) {
            if (hash_equals($this->gerarCodigo($segredo, $contadorAtual + $desvio), $codigo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Como `verificar`, mas devolve o passo (contador) que coincidiu, ou null. Serve para tornar o código de USO ÚNICO:
     * quem o recebe regista o passo já gasto (ServicoReautenticacao) e recusa a repetição dentro da janela
     * (OWASP ASVS 2.8.4: um OTP não pode ser reutilizado).
     */
    public function passoValido(string $segredo, string $codigo, int $janela = 1): ?int
    {
        $codigo = trim($codigo);
        if (! ctype_digit($codigo) || strlen($codigo) !== self::DIGITOS) {
            return null;
        }

        $contadorAtual = intdiv(time(), self::PASSO_SEGUNDOS);
        $achado = null;
        for ($desvio = -$janela; $desvio <= $janela; $desvio++) {   // sem retorno antecipado: tempo independente do desvio
            if (hash_equals($this->gerarCodigo($segredo, $contadorAtual + $desvio), $codigo)) {
                $achado = $contadorAtual + $desvio;
            }
        }

        return $achado;
    }

    /** HOTP (RFC 4226 §5.3) para um contador dado — a mesma função usada para TOTP (RFC 6238 §4) com contador = floor(tempo/passo). */
    private function gerarCodigo(string $segredoBase32, int $contador): string
    {
        $chave = self::base32Decodificar($segredoBase32);
        $dados = pack('N*', 0, $contador);   // contador de 64 bits, big-endian (metade alta sempre 0 até ao ano 2106)
        $hash = hash_hmac('sha1', $dados, $chave, true);

        $deslocamento = ord($hash[19]) & 0x0F;
        $binario = ((ord($hash[$deslocamento]) & 0x7F) << 24)
            | ((ord($hash[$deslocamento + 1]) & 0xFF) << 16)
            | ((ord($hash[$deslocamento + 2]) & 0xFF) << 8)
            | (ord($hash[$deslocamento + 3]) & 0xFF);

        return str_pad((string) ($binario % (10 ** self::DIGITOS)), self::DIGITOS, '0', STR_PAD_LEFT);
    }

    private static function base32Codificar(string $bruto): string
    {
        $bits = '';
        foreach (str_split($bruto) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $saida = '';
        foreach (str_split($bits, 5) as $grupo) {
            $grupo = str_pad($grupo, 5, '0', STR_PAD_RIGHT);
            $saida .= self::ALFABETO_BASE32[bindec($grupo)];
        }

        return $saida;
    }

    private static function base32Decodificar(string $codificado): string
    {
        $bits = '';
        foreach (str_split(strtoupper($codificado)) as $char) {
            $posicao = strpos(self::ALFABETO_BASE32, $char);
            if ($posicao === false) {
                continue;   // ignora separadores/espaços que o utilizador possa ter colado
            }
            $bits .= str_pad(decbin($posicao), 5, '0', STR_PAD_LEFT);
        }
        $bruto = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bruto .= chr(bindec($byte));
            }
        }

        return $bruto;
    }
}
