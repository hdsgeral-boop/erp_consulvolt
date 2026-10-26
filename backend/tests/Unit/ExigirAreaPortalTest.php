<?php

namespace Tests\Unit;

use App\Exceptions\ErroNegocio;
use App\Http\Middleware\ExigirAreaPortal;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Isolamento do Portal do Colaborador por subdomínio: em portal.<base>, só passam as rotas da lista branca (fail
 * closed) — qualquer outra, mesmo que o utilizador tivesse permissão, é recusada. Fora dessa área, não há efeito.
 */
final class ExigirAreaPortalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.dominio.base' => 'meuerp.online']);
    }

    private function pedidoComRota(string $host, ?string $nomeRota): Request
    {
        $pedido = Request::create("http://{$host}/qualquer");
        $rota = new Route('GET', 'qualquer', []);
        if ($nomeRota !== null) {
            $rota->name($nomeRota);
        }
        $pedido->setRouteResolver(fn () => $rota);

        return $pedido;
    }

    private function passou(Request $pedido): bool
    {
        $chegou = false;
        (new ExigirAreaPortal())->handle($pedido, function () use (&$chegou) {
            $chegou = true;

            return new \Symfony\Component\HttpFoundation\Response();
        });

        return $chegou;
    }

    #[Test]
    public function fora_da_area_portal_deixa_passar_qualquer_rota(): void
    {
        $this->assertTrue($this->passou($this->pedidoComRota('acme.meuerp.online', 'crm.contactos.index')));
        $this->assertTrue($this->passou($this->pedidoComRota('localhost', 'crm.contactos.index')));
    }

    #[Test]
    public function na_area_portal_as_rotas_da_lista_branca_passam(): void
    {
        foreach (['portal.acesso', 'rh.portal.resumo', 'rh.portal.compras.aprovacoes', 'rh.portal.projetos.tarefas',
            'autenticacao.sair', 'autenticacao.eu', 'sistema.empresas.index', 'sistema.menu', 'sistema.identidade'] as $nome) {
            $this->assertTrue($this->passou($this->pedidoComRota('portal.meuerp.online', $nome)), "Rota '{$nome}' devia passar no portal.");
        }
    }

    #[Test]
    public function na_area_portal_qualquer_outra_rota_e_recusada(): void
    {
        foreach (['crm.contactos.index', 'vendas.documentos.store', 'plataforma.clientes.index', 'suporte.tickets.index', 'sistema.logs.index'] as $nome) {
            try {
                $this->passou($this->pedidoComRota('portal.meuerp.online', $nome));
                $this->fail("A rota '{$nome}' devia ter sido recusada na área do portal.");
            } catch (ErroNegocio $e) {
                $this->assertSame('AREA_RESERVADA_PORTAL', $e->codigo);
                $this->assertSame(403, $e->estadoHttp);
            }
        }
    }
}
