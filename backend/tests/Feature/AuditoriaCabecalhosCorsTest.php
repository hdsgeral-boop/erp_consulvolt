<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Auditoria 2026-11 (f): CORS sem «*» com credenciais e cabeçalhos de segurança do nginx de produção. */
final class AuditoriaCabecalhosCorsTest extends TestCase
{
    #[Test]
    public function cors_nunca_combina_origem_curinga_com_credenciais(): void
    {
        $cors = config('cors');
        $this->assertNotContains('*', $cors['allowed_origins'], 'allowed_origins «*» com supports_credentials');
        $this->assertSame([], $cors['allowed_origins_patterns']);
        $this->assertNotContains('*', $cors['allowed_methods']);
        $this->assertContains('X-Empresa-Id', $cors['allowed_headers']);
    }

    #[Test]
    public function origem_estranha_nao_recebe_cabecalhos_cors(): void
    {
        $r = $this->call('OPTIONS', '/api/saude', [], [], [], ['HTTP_ORIGIN' => 'https://atacante.exemplo', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET']);
        $this->assertNotSame('https://atacante.exemplo', $r->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $r->headers->get('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function nginx_de_producao_envia_os_cabecalhos_de_seguranca(): void
    {
        $f = base_path('../docker/nginx/producao/cabecalhos-seguranca.conf');
        if (! is_file($f)) {
            $this->markTestSkipped('ficheiro de nginx fora do contentor');
        }
        $c = (string) file_get_contents($f);
        foreach (['Content-Security-Policy', 'X-Content-Type-Options "nosniff"', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy', 'frame-ancestors', "object-src 'none'", "script-src 'self'"] as $esperado) {
            $this->assertStringContainsString($esperado, $c, "falta {$esperado}");
        }
        $this->assertStringNotContainsString("'unsafe-eval'", $c);
        $this->assertStringContainsString('https://*.r2.dev', $c, 'CSP tem de continuar a permitir imagens do R2');
    }
}
