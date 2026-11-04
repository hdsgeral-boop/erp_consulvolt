<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Support\Tenancy\DominioPedido;
use Illuminate\Http\Request;
use Tests\TestCase;

/** Caddy (on_demand_tls) só pede certificados para nomes do sistema; DOMINIO_PLATAFORMA configurável. */
class DominioPermitidoTest extends TestCase
{
    private function perguntar(string $dominio): int
    {
        return $this->get('/api/sistema/dominio-permitido?domain='.urlencode($dominio))->getStatusCode();
    }

    public function test_so_nomes_do_sistema_sao_permitidos(): void
    {
        config(['erp.dominio.base' => 'meuerp.test', 'erp.dominio.prefixo_plataforma' => 'gestao-admin']);
        Tenant::query()->create(['nome' => 'Cliente DP', 'slug' => 'cliente-dp']);

        $this->assertSame(200, $this->perguntar('meuerp.test'));
        $this->assertSame(200, $this->perguntar('www.meuerp.test'));
        $this->assertSame(200, $this->perguntar('gestao-admin.meuerp.test'));
        $this->assertSame(200, $this->perguntar('portal.meuerp.test'), 'a área do portal do colaborador também é do sistema');
        $this->assertSame(200, $this->perguntar('cliente-dp.meuerp.test'));
        $this->assertSame(200, $this->perguntar('CLIENTE-DP.MEUERP.TEST'));

        $this->assertSame(404, $this->perguntar('inventado.meuerp.test'));
        $this->assertSame(404, $this->perguntar('a.b.meuerp.test'));
        $this->assertSame(404, $this->perguntar('cliente-dp.outro.test'));
        $this->assertSame(404, $this->perguntar(''));
    }

    public function test_sem_dominio_base_nada_e_permitido(): void
    {
        config(['erp.dominio.base' => '']);

        $this->assertSame(404, $this->perguntar('meuerp.test'));
    }

    public function test_prefixo_da_plataforma_por_omissao_com_ponto_tambem_e_aceite(): void
    {
        config(['erp.dominio.base' => 'meuerp.test', 'erp.dominio.prefixo_plataforma' => 'admin.erp']);

        $this->assertSame(200, $this->perguntar('admin.erp.meuerp.test'));
    }

    public function test_dominio_pedido_usa_o_prefixo_configurado(): void
    {
        config(['erp.dominio.base' => 'meuerp.test', 'erp.dominio.prefixo_plataforma' => 'gestao-admin']);

        $this->assertSame(DominioPedido::PLATAFORMA, DominioPedido::de(Request::create('https://gestao-admin.meuerp.test/'))->area);
        $this->assertSame(DominioPedido::DESCONHECIDA, DominioPedido::de(Request::create('https://admin.erp.meuerp.test/'))->area);
    }
}
