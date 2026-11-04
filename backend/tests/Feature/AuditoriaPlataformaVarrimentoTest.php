<?php

namespace Tests\Feature;

use App\Models\PerfilUtilizador;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route as Rotas;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Auditoria 2026-11: um operador da plataforma SEM nenhuma chave (ou um agente de suporte) entra na área, mas cada
 * rota /api/plataforma/* e /api/suporte/* tem de recusar (403) — salvo as leituras listadas abaixo, de desenho.
 */
final class AuditoriaPlataformaVarrimentoTest extends TestCase
{
    /** Leituras abertas a qualquer operador por desenho (RbacPlataformaTest: «entra na área»). */
    private const ABERTAS_POR_DESENHO = [
        'GET api/plataforma/clientes' => 'lista de clientes (RbacPlataformaTest)',
        'GET api/plataforma/empresas' => 'selector de empresas',
        'GET api/plataforma/suporte' => 'tickets filtrados pelas categorias do perfil (sem chaves: lista vazia)',
        'GET api/plataforma/suporte/metricas' => 'métricas filtradas pelas categorias do perfil (sem chaves: zeros)',
        'GET api/plataforma/suporte/agentes' => 'selector de agentes (id e nome)',
        'GET api/plataforma/clientes/{cliente}' => 'ficha do cliente: 404 para um id inexistente',
    ];

    #[Test]
    public function operador_sem_chaves_e_recusado_em_toda_a_area_da_plataforma(): void
    {
        config(['erp.api.pedidos_por_minuto' => 100000, 'erp.saas.ativo' => true]);
        Http::preventStrayRequests();
        app(ContextoTenant::class)->definir(1);
        $perfil = PerfilUtilizador::create(['nome' => 'Operador vazio', 'permissoes' => ['_v2' => true], 'restrito_plataforma' => true]);
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_OPERADOR_PLATAFORMA, 'perfil_utilizador_id' => $perfil->id]);
        $h = $this->entrar($u);

        $abertas = [];
        foreach (Rotas::getRoutes()->getRoutes() as $r) {
            if (! str_starts_with($r->uri(), 'api/plataforma/')) {
                continue;
            }
            foreach (array_diff($r->methods(), ['HEAD', 'OPTIONS', 'PATCH']) as $metodo) {
                $id = "{$metodo} {$r->uri()}";
                if (isset(self::ABERTAS_POR_DESENHO[$id])) {
                    continue;
                }
                $uri = '/'.preg_replace('#\{[^}]+\}#', '999999', $r->uri());
                $estado = $this->json($metodo, $uri, [], $h)->status();
                if (! in_array($estado, [403, 404], true)) {
                    $abertas[] = "{$id} -> {$estado}";
                }
            }
        }
        $this->assertSame([], $abertas, 'Rotas da plataforma abertas a um operador sem chaves');
    }

    #[Test]
    public function operador_com_so_a_chave_de_clientes_nao_ganha_o_resto_e_cliente_com_acesso_total_nao_entra(): void
    {
        config(['erp.api.pedidos_por_minuto' => 100000, 'erp.saas.ativo' => true]);
        app(ContextoTenant::class)->definir(1);
        $perfil = PerfilUtilizador::create(['nome' => 'Operador clientes', 'permissoes' => ['_v2' => true, 'plataforma_clientes_gerir' => true], 'restrito_plataforma' => true]);
        $op = $this->criarUtilizador(['papel' => Utilizador::PAPEL_OPERADOR_PLATAFORMA, 'perfil_utilizador_id' => $perfil->id]);
        $h = $this->entrar($op);
        $proibidas = [
            ['POST', '/api/plataforma/planos'], ['PUT', '/api/plataforma/definicoes'], ['PUT', '/api/plataforma/saas/geral'],
            ['POST', '/api/plataforma/administradores'], ['POST', '/api/plataforma/operadores-plataforma'], ['POST', '/api/plataforma/agentes-suporte'],
            ['PUT', '/api/plataforma/ia/atribuicoes/1'], ['PUT', '/api/plataforma/ia/fornecedores/openai'], ['GET', '/api/plataforma/ia/consumo'],
            ['DELETE', '/api/plataforma/sessoes/1'], ['POST', '/api/plataforma/transferencias/1/confirmar'], ['GET', '/api/plataforma/administradores'],
        ];
        foreach ($proibidas as [$m, $u]) {
            $this->json($m, $u, [], $h)->assertForbidden();
        }

        // cliente com perfil de acesso total (all): nunca entra na área da plataforma nem no suporte
        $tot = PerfilUtilizador::create(['nome' => 'Total cliente', 'permissoes' => ['all' => true, '_v2' => true]]);
        $c = $this->criarUtilizador(['perfil_utilizador_id' => $tot->id]);
        $hc = $this->entrar($c);
        foreach (['/api/plataforma/clientes', '/api/plataforma/painel', '/api/plataforma/administradores', '/api/suporte/tickets'] as $u) {
            $this->getJson($u, $hc)->assertForbidden();
        }
    }
}
