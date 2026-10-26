<?php

namespace Tests\Unit;

use App\Models\Utilizador;
use App\Support\Tenancy\DominioPedido;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Áreas por domínio: portal.<base> é um endereço partilhado por todos os clientes (isolamento por rota em ExigirAreaPortal, não aqui). */
final class DominioPedidoTest extends TestCase
{
    private function pedido(string $host): Request
    {
        return Request::create("http://{$host}/qualquer");
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.dominio.base' => 'meuerp.online', 'erp.dominio.plataforma_tenant_id' => 1,
            'erp.dominio.slugs_reservados' => ['admin', 'erp', 'www', 'api', 'app', 'mail', 'ftp', 'suporte', 'static', 'site', 'teste', 'portal']]);
    }

    #[Test]
    public function portal_e_uma_area_propria_partilhada_por_todos_os_clientes(): void
    {
        $d = DominioPedido::de($this->pedido('portal.meuerp.online'));

        $this->assertSame(DominioPedido::PORTAL, $d->area);
        $this->assertTrue($d->permite((new Utilizador())->forceFill(['tenant_id' => 1])));
        $this->assertTrue($d->permite((new Utilizador())->forceFill(['tenant_id' => 99])));
    }

    #[Test]
    public function portal_nao_cai_em_desconhecida_apesar_de_estar_nos_slugs_reservados(): void
    {
        // «portal» está em slugs_reservados (segunda linha de defesa), mas a área PORTAL é verificada primeiro.
        $this->assertContains('portal', config('erp.dominio.slugs_reservados'));
        $this->assertSame(DominioPedido::PORTAL, DominioPedido::de($this->pedido('portal.meuerp.online'))->area);
    }

    #[Test]
    public function subdominios_existentes_continuam_a_resolver_para_as_mesmas_areas(): void
    {
        $this->assertSame(DominioPedido::SITE, DominioPedido::de($this->pedido('meuerp.online'))->area);
        $this->assertSame(DominioPedido::PLATAFORMA, DominioPedido::de($this->pedido('admin.erp.meuerp.online'))->area);
        $this->assertSame(DominioPedido::CLIENTE, DominioPedido::de($this->pedido('acme.meuerp.online'))->area);
        $this->assertSame(DominioPedido::DESCONHECIDA, DominioPedido::de($this->pedido('suporte.meuerp.online'))->area);
        $this->assertSame(DominioPedido::LIVRE, DominioPedido::de($this->pedido('localhost'))->area);
    }

    #[Test]
    public function host_de_dois_niveis_dentro_do_proprio_portal_nao_e_a_area_do_portal(): void
    {
        // portal.acme.meuerp.online: prefixo "portal.acme" contém um ponto → desconhecida, nunca PORTAL nem CLIENTE.
        $d = DominioPedido::de($this->pedido('portal.acme.meuerp.online'));

        $this->assertSame(DominioPedido::DESCONHECIDA, $d->area);
    }
}
