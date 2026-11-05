<?php

namespace Tests\Feature\Seguranca;

use App\Support\Seguranca\DetectorInjecao;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** ADR-083: o filtro global de entrada recusa injecções evidentes nos parâmetros de pesquisa SEM bloquear texto legítimo. */
final class SanitizarEntradaTest extends TestCase
{
    public static function maliciosos(): array
    {
        return [
            'tautologia' => ["' OR 1=1 --"],
            'tautologia aspas' => ["x' or 'a'='a"],
            'union' => ["x' UNION SELECT nome, palavra_passe FROM utilizadores--"],
            'union sem aspas' => ['1 UNION ALL SELECT null,null'],
            'empilhada' => ['x; DROP TABLE utilizadores'],
            'empilhada select' => ['a;select pg_sleep(5)'],
            'pg_sleep' => ['x||pg_sleep(10)'],
            'comentario' => ["admin'--"],
            'bloco comentario' => ['x/**/or/**/1=1'],
            'schema' => ['x and (select 1 from information_schema.tables)=1'],
            'boolean cego' => ['1 and 1=1'],
            'copy' => ['x; copy utilizadores to program'],
            'traversal' => ['../../../etc/passwd'],
            'traversal windows' => ['..\..\windows\win.ini'],
            'traversal codificado' => ['%2e%2e%2fetc'],
            'null byte' => ["abc\0def"],
            'null byte codificado' => ['abc%00def'],
            'controlo' => ["abc\x07def"],
            'xss script' => ['<script>alert(1)</script>'],
            'xss js' => ['javascript:alert(1)'],
            'xss evento' => ['" onerror=alert(1) x="'],
            'nosql' => ['{"$ne": null}'],
            'ssti' => ['{{7*7}}'],
            'ssti expr' => ['${jndi:ldap://x/a}'],
            'subshell' => ['$(cat /etc/passwd)'],
            'esquema' => ['php://filter/convert.base64-encode/resource=index'],
            'longo' => [str_repeat('a', 301)],
        ];
    }

    public static function legitimos(): array
    {
        return [
            ["O'Brien"], ["D'Avila"], ["N'Dalu & Filhos, Lda"], ['select'], ['Select Hotel Luanda'], ['union'], ['Union Jack Lda'],
            ['Pedro and Maria'], ['Pedro or Maria'], ['A-B--C'], ['Fatura -- Janeiro'], ['FT 2026/000123'], ['5417006511'], ['joão.silva@exemplo.ao'],
            ['Câmara Municipal de Luanda (CML)'], ['100% algodão'], ['café_expresso'], ['Rua 1.º de Maio, n.º 5'], ['Descrição; com ponto e vírgula'],
            ['drop'], ['update de preços'], ['delete'], ['1 = 1'], ['a=b'], ['#123'], ['C# developer'], ['Tom\'s Café'], ['SELECT * é um produto?'], ['insert'],
            ['Cat 5e cabo'], ['ls'], ['Água & Gás'], ['日本語'], [str_repeat('a', 300)],
        ];
    }

    #[Test]
    #[DataProvider('maliciosos')]
    public function recusa_padroes_de_injecao_evidentes(string $payload): void
    {
        $this->assertNotNull(DetectorInjecao::motivo(DetectorInjecao::normalizar($payload)), "devia recusar: {$payload}");
    }

    #[Test]
    #[DataProvider('legitimos')]
    public function nao_bloqueia_texto_legitimo(string $texto): void
    {
        $this->assertNull(DetectorInjecao::motivo(DetectorInjecao::normalizar($texto)), "falso positivo: {$texto}");
    }

    #[Test]
    #[DataProvider('maliciosos')]
    public function o_middleware_devolve_422_em_pesquisa_filtro_e_ordenacao(string $payload): void
    {
        // rota pública: o filtro corre antes de qualquer autenticação (nunca chega ao controlador)
        foreach (['q', 'pesquisa', 'search', 'filtro_estado', 'filtro', 'ordenar', 'ordenar_por', 'sort'] as $param) {
            $this->getJson('/api/sistema/planos-publicos?'.http_build_query([$param => $payload]))->assertStatus(422)->assertJsonPath('codigo', 'VALIDACAO')->assertJsonPath("erros.{$param}.0", fn ($m) => is_string($m));
        }
        $this->postJson('/api/autenticacao/recuperar-password', ['q' => $payload, 'email' => 'x@y.ao'])->assertStatus(422);
    }

    #[Test]
    public function listas_e_operadores_em_parametros_escalares_dao_422_e_nunca_500(): void
    {
        $this->getJson('/api/sistema/planos-publicos?q[]=a&q[]=b')->assertStatus(422);
        $this->getJson('/api/sistema/planos-publicos?q[$ne]=1')->assertStatus(422);
        $this->getJson('/api/sistema/planos-publicos?filtro[$where]=1')->assertStatus(422);
        $this->getJson('/api/sistema/planos-publicos?filtro[a][b][c][d][e][f]=1')->assertStatus(422);
        $this->getJson('/api/sistema/planos-publicos?sort[]=nome')->assertStatus(422);
    }

    #[Test]
    public function parametros_fora_da_lista_nao_sao_inspeccionados_e_texto_normal_passa(): void
    {
        $this->getJson('/api/sistema/planos-publicos?nome='.urlencode("' OR 1=1 --"))->assertOk();
        $this->getJson('/api/sistema/planos-publicos?q='.urlencode("O'Brien"))->assertOk();
        $this->getJson('/api/sistema/planos-publicos?q='.urlencode('Select Hotel'))->assertOk();
    }

    #[Test]
    public function normaliza_unicode_e_remove_caracteres_invisiveis(): void
    {
        $this->assertSame('abc', DetectorInjecao::normalizar("a\u{200B}b\u{FEFF}c"));
        $this->assertSame("\u{00E9}", DetectorInjecao::normalizar("e\u{0301}"));
    }
}
