<?php

namespace Tests\Unit;

use App\Services\Autenticacao\ServicoTotp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fase 4 da extensão SaaS: valida a implementação própria do TOTP (RFC 6238) contra os vectores de teste OFICIAIS
 * do Apêndice B da RFC (SHA1, segredo ASCII "12345678901234567890") — garante compatibilidade real com apps de
 * autenticação (Google Authenticator, Authy, 1Password, …), não só com o próprio código.
 */
class ServicoTotpTest extends TestCase
{
    private function segredoDeTeste(): string
    {
        // O segredo ASCII dos vectores oficiais, convertido para Base32 (o formato que ServicoTotp espera).
        $totp = new ServicoTotp;
        $codificar = new \ReflectionMethod($totp, 'base32Codificar');

        return $codificar->invoke($totp, '12345678901234567890');
    }

    /** @return array<string, array{0: int, 1: string}> tempo unix => últimos 6 dígitos do TOTP de 8 dígitos (RFC 6238 Apêndice B, SHA1) */
    public static function vectoresRfc(): array
    {
        return [
            'T=59' => [59, '287082'],
            'T=1111111109' => [1111111109, '081804'],
            'T=1111111111' => [1111111111, '050471'],
            'T=1234567890' => [1234567890, '005924'],
            'T=2000000000' => [2000000000, '279037'],
        ];
    }

    #[DataProvider('vectoresRfc')]
    public function test_codigo_gerado_coincide_com_o_vector_oficial_da_rfc(int $tempoUnix, string $esperado): void
    {
        $totp = new ServicoTotp;
        $gerarCodigo = new \ReflectionMethod($totp, 'gerarCodigo');
        $contador = intdiv($tempoUnix, 30);

        $this->assertSame($esperado, $gerarCodigo->invoke($totp, $this->segredoDeTeste(), $contador));
    }

    public function test_verificar_aceita_o_codigo_actual_e_rejeita_um_codigo_errado(): void
    {
        $totp = new ServicoTotp;
        $segredo = $totp->gerarSegredo();
        $gerarCodigo = new \ReflectionMethod($totp, 'gerarCodigo');
        $codigoActual = $gerarCodigo->invoke($totp, $segredo, intdiv(time(), 30));

        $this->assertTrue($totp->verificar($segredo, $codigoActual));
        $this->assertFalse($totp->verificar($segredo, $codigoActual === '000000' ? '111111' : '000000'));
        $this->assertFalse($totp->verificar($segredo, '12345'));   // menos de 6 dígitos
        $this->assertFalse($totp->verificar($segredo, 'abcdef'));   // não numérico
    }

    public function test_verificar_aceita_a_janela_de_tolerancia_mas_nao_alem_dela(): void
    {
        $totp = new ServicoTotp;
        $segredo = $totp->gerarSegredo();
        $gerarCodigo = new \ReflectionMethod($totp, 'gerarCodigo');
        $contadorActual = intdiv(time(), 30);

        $this->assertTrue($totp->verificar($segredo, $gerarCodigo->invoke($totp, $segredo, $contadorActual - 1)));
        $this->assertTrue($totp->verificar($segredo, $gerarCodigo->invoke($totp, $segredo, $contadorActual + 1)));
        $this->assertFalse($totp->verificar($segredo, $gerarCodigo->invoke($totp, $segredo, $contadorActual - 2)));
    }

    public function test_segredo_gerado_e_valido_em_base32_e_uri_contem_os_parametros_esperados(): void
    {
        $totp = new ServicoTotp;
        $segredo = $totp->gerarSegredo();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $segredo);

        $uri = $totp->uriProvisionamento($segredo, 'operador@consulvolt.ao', 'Consulvolt');
        $this->assertStringStartsWith('otpauth://totp/Consulvolt:', $uri);
        $this->assertStringContainsString('secret='.$segredo, $uri);
        $this->assertStringContainsString('issuer=Consulvolt', $uri);
    }
}
