<?php

namespace Tests\Feature\Desempenho;

use App\Models\Utilizador;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route as Rotas;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-085 / docs/DESEMPENHO_BACKEND_2026-11.md — guarda de desempenho GERADA de `route:list`:
 *  1. todas as rotas GET com empresa activa (listagens, relatórios, fichas) respondem com um NÚMERO DE CONSULTAS limitado
 *     (custo fixo do pedido: autenticação, empresa, permissões) e sem a MESMA consulta repetida (consulta duplicada);
 *  2. nas listagens principais, o número de consultas NÃO cresce com o número de linhas (N+1): mede com poucos e com muitos
 *     registos e exige o mesmo número de consultas.
 * `Model::shouldBeStrict` (AppServiceProvider) já faz o lazy loading falhar fora de produção; este teste apanha o resto
 * (consultas em ciclo feitas com `DB::` ou `->find()` por linha).
 */
final class ConsultasPorEndpointTest extends TestCase
{
    /** Máximo de consultas por pedido GET (medido: o pior caso actual está bem abaixo; ver o relatório). */
    private const MAXIMO_CONSULTAS = 60;

    /** A mesma consulta (SQL + valores) mais do que isto vezes num pedido é uma consulta duplicada. */
    private const MAXIMO_REPETICOES = 6;

    /**
     * Tectos próprios (medidos) das rotas que, por desenho, agregam muitos dados num só pedido. O custo é FIXO (não cresce com
     * as linhas) — são pedidos explícitos e raros, não polling. Um aumento destes números é uma regressão a justificar.
     */
    private const TECTOS_PROPRIOS = [
        'GET api/sistema/copias/exportar' => 450,      // exportação completa da empresa: percorre todas as tabelas (medido: 425)
        'GET api/gestao/relatorios/resumo' => 125,     // resumo executivo de todos os módulos, 2 períodos (medido: 114)
        'GET api/gestao/relatorios/todos' => 125,      // relatório de gestão completo (medido: 115)
    ];

    private const CHAMAM_SERVICO_EXTERNO = ['api/sistema/cambios/bai'];

    /** @return list<array{string, string}> */
    private function rotasGet(): array
    {
        $saida = [];
        foreach (Rotas::getRoutes()->getRoutes() as $r) {
            /** @var Route $r */
            if (! in_array('GET', $r->methods(), true) || ! str_starts_with($r->uri(), 'api/') || ! in_array('empresa', $r->gatherMiddleware(), true)) {
                continue;
            }
            if (in_array($r->uri(), self::CHAMAM_SERVICO_EXTERNO, true) || $r->parameterNames() !== []) {
                continue;   // só as listagens/relatórios sem parâmetro de recurso (as fichas dão 404 sem dados)
            }
            $saida[] = ['/'.$r->uri(), 'GET '.$r->uri()];
        }

        return $saida;
    }

    private function cenario(): array
    {
        config(['erp.api.pedidos_por_minuto' => 1_000_000]);
        Http::preventStrayRequests();
        $empresa = $this->criarEmpresa(['nif' => '5417006588']);
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    /** @return array{estado: int, consultas: int, repetida: array{sql: string, vezes: int}|null} */
    private function medir(string $uri, array $h): array
    {
        foreach (DB::getConnections() as $c) {
            $c->flushQueryLog();
            $c->enableQueryLog();
        }
        $estado = $this->getJson($uri, $h)->getStatusCode();
        $contagem = [];
        $total = 0;
        foreach (DB::getConnections() as $c) {
            foreach ($c->getQueryLog() as $q) {
                $total++;
                $chave = $q['query'].'|'.json_encode($q['bindings']);
                $contagem[$chave] = ($contagem[$chave] ?? 0) + 1;
            }
            $c->disableQueryLog();
        }
        arsort($contagem);
        $maisRepetida = $contagem === [] ? null : ['sql' => (string) array_key_first($contagem), 'vezes' => (int) reset($contagem)];

        return ['estado' => $estado, 'consultas' => $total, 'repetida' => $maisRepetida];
    }

    #[Test]
    public function nenhuma_listagem_excede_o_limite_de_consultas_nem_repete_a_mesma_consulta(): void
    {
        $h = $this->cenario();
        $rotas = $this->rotasGet();
        $this->assertGreaterThan(100, count($rotas));
        $this->getJson('/api/sistema/menu', $h);   // aquece caches de permissões/menu: mede-se o custo corrente, não o primeiro pedido

        $medidas = [];
        $excesso = [];
        $duplicadas = [];
        foreach ($rotas as [$uri, $id]) {
            $m = $this->medir($uri, $h);
            if ($m['estado'] >= 500) {
                continue;   // os 5xx por falta de dados/serviços externos não são deste teste (cobertos por EntradasMaliciosasListagensTest)
            }
            $medidas[$id] = $m['consultas'];
            if ($m['consultas'] > (self::TECTOS_PROPRIOS[$id] ?? self::MAXIMO_CONSULTAS)) {
                $excesso[] = "{$id}: {$m['consultas']} consultas";
            }
            if ($m['repetida'] && $m['repetida']['vezes'] > self::MAXIMO_REPETICOES) {
                $duplicadas[] = "{$id}: {$m['repetida']['vezes']}× ".mb_substr($m['repetida']['sql'], 0, 160);
            }
        }

        if (getenv('ERP_MEDIR_DESEMPENHO')) {
            arsort($medidas);
            file_put_contents(storage_path('app/desempenho_consultas.json'), json_encode(['rotas' => count($medidas), 'por_rota' => $medidas, 'excesso' => $excesso, 'duplicadas' => $duplicadas], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $this->assertSame([], $excesso, 'Rotas com demasiadas consultas por pedido (> '.self::MAXIMO_CONSULTAS.')');
        $this->assertSame([], $duplicadas, 'Rotas que repetem a MESMA consulta (> '.self::MAXIMO_REPETICOES.'×) no mesmo pedido');
    }

    #[Test]
    public function as_listagens_principais_nao_fazem_n_mais_1_o_numero_de_consultas_nao_cresce_com_as_linhas(): void
    {
        $h = $this->cenario();
        $empresaId = (int) $h['X-Empresa-Id'];
        $listagens = ['/api/sistema/utilizadores?por_pagina=100', '/api/sistema/perfis', '/api/sistema/gestao-empresas', '/api/sistema/empresas'];

        // poucos registos, mas já com todas as relações preenchidas (perfil, empresas): senão as consultas de carregamento
        // antecipado nem chegam a correr e a comparação seria enganadora
        $perfil0 = $this->criarPerfil(['_v2' => true, 'lancamentos_view' => true], 'Perfil base');
        foreach ([1, 2] as $_) {
            $this->criarUtilizador(['perfil_utilizador_id' => $perfil0->id])->empresas()->attach($empresaId);
        }
        $this->criarEmpresa();
        foreach ($listagens as $uri) {
            $this->getJson($uri, $h);   // aquece
        }
        $antes = [];
        foreach ($listagens as $uri) {
            $m = $this->medir($uri, $h);
            $this->assertLessThan(500, $m['estado'], $uri);
            $antes[$uri] = $m['consultas'];
        }

        // muitos registos: 25 utilizadores (cada um com perfil e empresa), 15 perfis, 10 empresas
        $perfis = [];
        for ($i = 0; $i < 15; $i++) {
            $perfis[] = $this->criarPerfil(['_v2' => true, 'lancamentos_view' => true], "Perfil carga {$i}");
        }
        for ($i = 0; $i < 25; $i++) {
            $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfis[$i % 15]->id]);
            $u->empresas()->attach($empresaId);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->criarEmpresa();
        }

        $depois = [];
        foreach ($listagens as $uri) {
            $m = $this->medir($uri, $h);
            $this->assertLessThan(500, $m['estado'], $uri);
            $depois[$uri] = $m['consultas'];
        }

        foreach ($listagens as $uri) {
            $this->assertLessThanOrEqual($antes[$uri] + 3, $depois[$uri], "{$uri}: {$antes[$uri]} consultas com poucas linhas, {$depois[$uri]} com muitas — N+1");
        }
    }

    #[Test]
    public function toda_a_listagem_paginada_tem_tecto_de_linhas_por_pagina(): void
    {
        $h = $this->cenario();
        $acima = [];
        $paginadas = 0;
        foreach ($this->rotasGet() as [$uri, $id]) {
            $r = $this->getJson($uri.'?'.http_build_query(['por_pagina' => 999999, 'per_page' => 999999, 'limite' => 999999]), $h);
            if ($r->getStatusCode() === 422 && $r->baseResponse instanceof JsonResponse) {
                $paginadas++;   // recusou o valor absurdo na validação (max:N): tem tecto

                continue;
            }
            if ($r->getStatusCode() !== 200 || ! $r->baseResponse instanceof JsonResponse) {
                continue;   // ficheiros (exportações) e erros não têm paginação
            }
            $por = $r->json('metadados.paginacao.por_pagina');
            if ($por !== null) {
                $paginadas++;
                if ((int) $por > 1000) {
                    $acima[] = "{$id}: por_pagina={$por}";
                }
            }
        }
        $this->assertGreaterThan(20, $paginadas, 'o varrimento tem de apanhar as listagens paginadas');
        $this->assertSame([], $acima, 'Listagens sem tecto de paginação (> 1000 linhas por página)');
    }
}
