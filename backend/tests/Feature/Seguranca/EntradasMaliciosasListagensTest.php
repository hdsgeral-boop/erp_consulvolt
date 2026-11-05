<?php

namespace Tests\Feature\Seguranca;

use App\Models\Utilizador;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route as Rotas;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-083 — varrimento GERADO a partir de `route:list`: TODAS as rotas GET autenticadas com empresa activa (listagens,
 * pesquisas, relatórios) recebem, com um super-administrador (que passa as permissões e chega mesmo ao controlador),
 *  1. payloads de injecção evidentes em q, pesquisa, filtro_x, ordenar_x: têm de dar 422 (filtro global), nunca 200/500;
 *  2. caracteres especiais que o filtro deixa passar (aspas, %, _, \, ;) e parâmetros de paginação/ordenação absurdos:
 *     nunca 500 — o controlador/serviço usa bindings e listas brancas, e nenhum erro interno pode vazar.
 * Uma rota nova entra automaticamente neste varrimento.
 */
final class EntradasMaliciosasListagensTest extends TestCase
{
    /** Payloads bloqueados pelo filtro global. */
    private const BLOQUEADOS = [
        "' OR 1=1 --",
        "x' UNION SELECT nome, palavra_passe FROM utilizadores--",
        'x; DROP TABLE utilizadores',
        '1;select pg_sleep(10)',
        '../../../etc/passwd',
        '<script>alert(1)</script>',
    ];

    /** Texto estranho mas não evidentemente malicioso: tem de ser tratado como texto literal. */
    private const ESTRANHOS = ["'", '"', '\\', '%', '_', '%_%', "a'b", ';', ')', '(', '`', 'ç~ñ', "\u{202E}abc", '0', '-1', '1e999', 'null', 'true', '[]', '{}'];

    /** Rotas que chamam um serviço externo (o teste proíbe pedidos HTTP reais: daria 500 por causa do próprio teste). */
    private const CHAMAM_SERVICO_EXTERNO = ['api/sistema/cambios/bai'];

    /** @return list<array{string, string}> [uri concreta, id] das rotas GET com empresa */
    private function rotasGet(): array
    {
        $candidatos = ['999999', 'x', 'produtos', 'colaboradores', 'clientes', 'contabilidade', 'vendas', 'ABC'];
        $saida = [];
        foreach (Rotas::getRoutes()->getRoutes() as $r) {
            /** @var Route $r */
            if (! in_array('GET', $r->methods(), true) || ! str_starts_with($r->uri(), 'api/') || ! in_array('empresa', $r->gatherMiddleware(), true)) {
                continue;
            }
            if (in_array($r->uri(), self::CHAMAM_SERVICO_EXTERNO, true)) {
                continue;
            }
            $uri = $r->uri();
            $ok = true;
            foreach ($r->parameterNames() as $p) {
                $regex = $r->wheres[$p] ?? null;
                $valor = $regex === null ? '999999' : null;
                foreach ($regex === null ? [] : $candidatos as $c) {
                    if (preg_match('#^(?:'.$regex.')$#u', $c)) {
                        $valor = $c;
                        break;
                    }
                }
                if ($valor === null) {
                    $ok = false;
                    break;
                }
                $uri = preg_replace('#\{'.preg_quote($p, '#').'\??\}#', $valor, $uri);
            }
            if ($ok) {
                $saida[] = ['/'.$uri, 'GET '.$r->uri()];
            }
        }

        return $saida;
    }

    private function cenario(): array
    {
        config(['erp.api.pedidos_por_minuto' => 1_000_000]);
        Http::preventStrayRequests();
        $empresa = $this->criarEmpresa(['nif' => '5417006577']);
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    #[Test]
    public function injeccoes_evidentes_em_pesquisa_filtro_e_ordenacao_dao_422_em_todas_as_listagens(): void
    {
        $h = $this->cenario();
        $rotas = $this->rotasGet();
        $this->assertGreaterThan(150, count($rotas), 'o varrimento tem de cobrir as rotas GET da API');
        $falhas = [];
        foreach ($rotas as $i => [$uri, $id]) {
            $payload = self::BLOQUEADOS[$i % count(self::BLOQUEADOS)];
            $param = ['q', 'pesquisa', 'filtro_nome', 'ordenar', 'search', 'sort'][$i % 6];
            $estado = $this->getJson($uri.'?'.http_build_query([$param => $payload]), $h)->getStatusCode();
            if ($estado !== 422) {
                $falhas[] = "{$id} ({$param}) -> {$estado}";
            }
        }
        $this->assertSame([], $falhas, 'Rotas que deixaram passar uma injecção evidente');
    }

    #[Test]
    public function texto_estranho_e_parametros_absurdos_nunca_dao_500_em_nenhuma_listagem(): void
    {
        $h = $this->cenario();
        $rotas = $this->rotasGet();
        $erros = [];
        foreach ($rotas as $i => [$uri, $id]) {
            $estranho = self::ESTRANHOS[$i % count(self::ESTRANHOS)];
            $consultas = [
                ['q' => $estranho, 'pesquisa' => $estranho, 'search' => $estranho, 'filtro_nome' => $estranho, 'termo' => $estranho],
                ['ordenar' => 'coluna_que_nao_existe', 'sort' => 'zzz', 'direcao' => 'sideways', 'order' => 'x', 'ordenar_por' => 'id; --x'],
                ['per_page' => '999999999', 'limite' => '-1', 'pagina' => 'abc', 'page' => '-5', 'limit' => '99999999999999999999', 'por_pagina' => '0'],
                ['filtro' => ['a' => ['b' => $estranho]], 'q' => ['x']],
            ];
            foreach ($consultas as $q) {
                $estado = $this->getJson($uri.'?'.http_build_query($q), $h)->getStatusCode();
                if ($estado >= 500) {
                    $erros[] = "{$id} ".json_encode(array_keys($q))." -> {$estado}";
                }
            }
        }
        $this->assertSame([], $erros, 'Rotas que rebentam (5xx) com entradas estranhas');
    }

    #[Test]
    public function pesquisa_com_aspas_e_sinais_nao_altera_os_resultados_da_listagem(): void
    {
        $h = $this->cenario();
        // «x' OR '1'='1» como texto literal (sem espaços à volta do OR para fugir ao filtro? não: é recusado) — e um
        // termo inexistente com aspas devolve o mesmo vazio que um termo inexistente simples.
        $a = $this->getJson('/api/terceiros?'.http_build_query(['q' => "zzzzinexistente'"]), $h);
        $b = $this->getJson('/api/terceiros?'.http_build_query(['q' => 'zzzzinexistente']), $h);
        $this->assertSame($b->status(), $a->status());
        $this->assertSame(200, $a->status());
        $this->assertSame($b->json('dados'), $a->json('dados'));
        $this->getJson('/api/terceiros?'.http_build_query(['q' => "x' OR '1'='1"]), $h)->assertStatus(422);
    }
}
