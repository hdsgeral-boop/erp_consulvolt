<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** trustProxies seguro (só IP e protocolo, só de redes privadas, nunca o host) e ETag/304 do CacheCondicional. */
class ProxyECacheCondicionalTest extends TestCase
{
    private function eco(array $cabecalhos, string $remoto): array
    {
        Route::get('/_teste/eco', fn (Request $r) => response()->json(['host' => $r->getHost(), 'ip' => $r->ip(), 'https' => $r->isSecure()]));

        return $this->call('GET', '/_teste/eco', [], [], [], $this->transformHeadersToServerVars($cabecalhos) + ['REMOTE_ADDR' => $remoto])->json();
    }

    public function test_proxy_privado_define_ip_e_protocolo_mas_nunca_o_host(): void
    {
        $r = $this->eco(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'admin.forjado.exemplo'], '172.18.0.5');

        $this->assertSame('203.0.113.9', $r['ip']);
        $this->assertTrue($r['https']);
        $this->assertNotSame('admin.forjado.exemplo', $r['host']);
    }

    public function test_pedido_directo_de_ip_publico_ignora_os_cabecalhos_de_encaminhamento(): void
    {
        $r = $this->eco(['X-Forwarded-For' => '10.9.9.9', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'admin.forjado.exemplo'], '198.51.100.7');

        $this->assertSame('198.51.100.7', $r['ip']);
        $this->assertFalse($r['https']);
        $this->assertNotSame('admin.forjado.exemplo', $r['host']);
    }

    public function test_logotipo_de_login_tem_etag_e_responde_304_sem_corpo(): void
    {
        $primeira = $this->getJson('/api/sistema/logotipo-login')->assertOk();
        $etag = $primeira->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertStringContainsString('public', (string) $primeira->headers->get('Cache-Control'));

        $segunda = $this->getJson('/api/sistema/logotipo-login', ['If-None-Match' => $etag]);
        $segunda->assertStatus(304);
        $this->assertSame('', $segunda->getContent());
    }
}
