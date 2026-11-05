<?php

namespace Tests\Feature\Seguranca;

use App\Models\Empresa;
use App\Models\Tenant;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route as Rotas;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-085 / OWASP API1 (BOLA) e API5 — acesso cruzado GERADO de `route:list`: com a sessão de um utilizador que só pertence à
 * empresa A, TODA a rota da API com empresa activa (GET, POST, PUT, DELETE) tem de recusar (403) o cabeçalho `X-Empresa-Id` de
 * (a) outra empresa do MESMO cliente e (b) uma empresa de OUTRO cliente (tenant), e de um id inexistente — antes de chegar ao
 * controlador, por isso sem ler nem escrever nada. O `tenant_id`/`empresa_id` que o frontend envia nunca é confiado.
 * Uma rota nova entra automaticamente no varrimento.
 */
final class AcessoCruzadoGeradoTest extends TestCase
{
    private Empresa $minha;

    private Empresa $mesmoCliente;

    private Empresa $outroCliente;

    private array $sessao;

    /** Rotas com restrições de parâmetro que nenhum valor genérico satisfaz (não se alcançam; contadas para não esconder buracos). */
    private int $inconclusivas = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.api.pedidos_por_minuto' => 1_000_000]);
        Http::preventStrayRequests();
        $this->minha = $this->criarEmpresa();
        $this->mesmoCliente = $this->criarEmpresa();
        $this->outroCliente = $this->criarEmpresa();
        // utilizador com acesso TOTAL às permissões do perfil mas só à empresa A: se a barreira de empresa falhar, entra
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['all' => true], 'Tudo')->id]);
        $u->empresas()->attach($this->minha->id);
        $this->sessao = $this->entrar($u);
    }

    /** @return list<array{string, string, string}> [método, uri concreta, rótulo] */
    private function rotas(): array
    {
        $saida = [];
        foreach (Rotas::getRoutes()->getRoutes() as $r) {
            /** @var Route $r */
            if (! str_starts_with($r->uri(), 'api/') || ! in_array('empresa', $r->gatherMiddleware(), true)) {
                continue;
            }
            $uri = $r->uri();
            foreach ($r->parameterNames() as $p) {
                $valor = $this->valorDe($r, $p);
                if ($valor === null) {
                    $this->inconclusivas++;

                    continue 2;
                }
                $uri = preg_replace('#\{'.preg_quote($p, '#').'\??\}#', $valor, $uri);
            }
            $metodo = array_values(array_diff($r->methods(), ['HEAD']))[0];
            $saida[] = [$metodo, '/'.$uri, $metodo.' '.$r->uri()];
        }

        return $saida;
    }

    private function valorDe(Route $r, string $parametro): ?string
    {
        $regex = $r->wheres[$parametro] ?? null;
        if ($regex === null) {
            return '1';
        }
        foreach (['1', 'x', 'produtos', 'clientes', 'vendas', 'ABC', 'a-b', 'resumo', '2026-01', '202601', '2026', 'receita', 'balanco', 'plano_contas', 'EUR', 'AOA', 'a1', '1-1', 'A', 'sim'] as $c) {
            if (preg_match('#^(?:'.$regex.')$#u', $c)) {
                return $c;
            }
        }

        return null;   // restrição que nenhuma candidata satisfaz: a rota não se consegue alcançar com um valor genérico
    }

    /** @param  list<array{string, string, string}>  $rotas */
    private function varrer(array $rotas, int $empresaId): array
    {
        $h = $this->sessao + ['X-Empresa-Id' => $empresaId];
        $falhas = [];
        foreach ($rotas as [$metodo, $uri, $rotulo]) {
            $estado = $this->json($metodo, $uri, [], $h)->getStatusCode();
            // Tudo tem de ser 403. O 429 (limitador próprio de rotas pesadas, atingido na 2.ª passagem) também recusa: não é fuga.
            if (! in_array($estado, [403, 429], true)) {
                $falhas[] = "{$rotulo} -> {$estado}";
            }
        }

        return $falhas;
    }

    #[Test]
    public function toda_a_rota_com_empresa_recusa_uma_empresa_do_mesmo_cliente_a_que_o_utilizador_nao_pertence(): void
    {
        $rotas = $this->rotas();
        $this->assertGreaterThan(600, count($rotas), 'o varrimento tem de cobrir a API toda');
        $this->assertLessThan(0.05 * (count($rotas) + $this->inconclusivas), $this->inconclusivas, 'demasiadas rotas inalcançáveis com valores genéricos');
        $this->assertSame([], $this->varrer($rotas, $this->mesmoCliente->id), 'rotas que aceitaram uma empresa alheia (mesmo cliente)');
    }

    #[Test]
    public function toda_a_rota_com_empresa_recusa_uma_empresa_de_outro_cliente_ou_inexistente(): void
    {
        config(['erp.saas.ativo' => true]);
        $outro = Tenant::query()->create(['nome' => 'Outro cliente', 'slug' => 'outro-cliente-cruzado']);
        DB::table('empresas')->where('id', $this->outroCliente->id)->update(['tenant_id' => $outro->id]);

        $rotas = $this->rotas();
        $this->assertSame([], $this->varrer($rotas, $this->outroCliente->id), 'rotas que aceitaram uma empresa de OUTRO cliente');
        // amostra com um id inexistente (1 rota em cada 5)
        $amostra = array_values(array_filter($rotas, fn ($_, $i) => $i % 5 === 0, ARRAY_FILTER_USE_BOTH));
        $this->assertSame([], $this->varrer($amostra, 99_999_999), 'rotas que aceitaram uma empresa inexistente');
    }

    #[Test]
    public function a_propria_empresa_continua_a_abrir_e_o_corpo_nao_escolhe_a_empresa(): void
    {
        // gestor de utilizadores SEM acesso total e só da empresa A
        $g = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'config_utilizadores_view' => true, 'config_util_gerir' => true], 'Gestor')->id]);
        $g->empresas()->attach($this->minha->id);
        $h = $this->entrar($g) + ['X-Empresa-Id' => $this->minha->id];

        $this->getJson('/api/sistema/menu', $h)->assertOk();
        // empresa_id/tenant_id na query não mudam a empresa do pedido (só vale o cabeçalho validado)
        $this->getJson('/api/sistema/utilizadores?empresa_id='.$this->mesmoCliente->id.'&tenant_id=999', $h)->assertOk();
        // ligar um utilizador a uma empresa a que o actor não acede é recusado, mesmo com tenant_id forjado no corpo
        $perfil = $this->criarPerfil(['_v2' => true, 'config_utilizadores_view' => true], 'Basico')->id;
        $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'cruzado', 'perfil_utilizador_id' => $perfil, 'empresas' => [['empresa_id' => $this->mesmoCliente->id]], 'tenant_id' => 999], $h)
            ->assertForbidden();
        $this->assertDatabaseMissing('utilizadores', ['nome_utilizador' => 'cruzado']);
    }
}
