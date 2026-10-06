<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Services\Integracoes\PowerBI\ServicoFeedBI;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Power BI (decisão 25, M-02): tokens de leitura por empresa e feed OData /api/bi/odata. */
final class PowerBIFeedTest extends TestCase
{
    private Empresa $empresa;

    private Empresa $outra;

    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->outra = $this->criarEmpresa();
        $this->admin = $this->sessao(['config_backup']);
        foreach ([[$this->empresa, '6211', 'D', 100], [$this->empresa, '7111', 'C', 250.5], [$this->empresa, '9111', 'D', 5], [$this->outra, '6211', 'D', 999]] as $i => [$e, $conta, $dc, $valor]) {
            DB::table('lancamentos_contabeis')->insert(['empresa_id' => $e->id, 'codigo_conta' => $conta, 'tipo_dc' => $dc, 'valor' => $valor,
                'data_documento' => '2026-0'.($i + 1).'-15', 'descricao' => "Linha {$i}", 'numero_documento' => "DOC{$i}"]);
        }
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function criarToken(array $dados = [], ?array $sessao = null): array
    {
        return $this->postJson('/api/sistema/bi/tokens', $dados + ['nome' => 'Power BI Direcção'], $sessao ?? $this->admin)->assertCreated()->json('dados');
    }

    #[Test]
    public function gestao_dos_tokens_exige_config_backup_e_nunca_devolve_o_valor_depois_de_criado(): void
    {
        $sem = $this->sessao(['config_moedas_view']);
        $this->getJson('/api/sistema/bi/tokens', $sem)->assertForbidden();
        $this->postJson('/api/sistema/bi/tokens', ['nome' => 'X'], $sem)->assertForbidden();

        $r = $this->criarToken(['conjuntos' => ['contabilidade']]);
        $this->assertStringStartsWith('erpbi_', $r['token']);
        $this->assertSame(hash('sha256', $r['token']), DB::table('tokens_bi')->value('hash_token'));
        $lista = $this->getJson('/api/sistema/bi/tokens', $this->admin)->assertOk()->assertJsonCount(1, 'dados.tokens')->assertJsonCount(8, 'dados.conjuntos');
        $this->assertStringNotContainsString($r['token'], $lista->getContent());
        $this->assertStringNotContainsString('hash_token', $lista->getContent());
        $this->postJson('/api/sistema/bi/tokens', ['nome' => 'Mau', 'conjuntos' => ['salarios_iban']], $this->admin)->assertStatus(422)->assertJsonPath('codigo', 'BI_CONJUNTO_INVALIDO');

        // a outra empresa não vê nem revoga os tokens desta
        $outroAdmin = $this->sessao(['config_backup'], $this->outra);
        $this->getJson('/api/sistema/bi/tokens', $outroAdmin)->assertOk()->assertJsonCount(0, 'dados.tokens');
        $this->postJson("/api/sistema/bi/tokens/{$r['registo']['id']}/revogar", [], $outroAdmin)->assertNotFound();

        $this->postJson("/api/sistema/bi/tokens/{$r['registo']['id']}/revogar", [], $this->admin)->assertOk()->assertJsonPath('dados.estado', 'REVOGADO');
        $this->get('/api/bi/odata/contabilidade', ['Authorization' => "Bearer {$r['token']}"])->assertStatus(401);
        $this->assertTrue(DB::table('logs_auditoria')->where('acao', 'Revogar token BI')->exists());
    }

    #[Test]
    public function feed_odata_autentica_pelo_token_e_le_so_a_empresa_do_token(): void
    {
        $this->get('/api/bi/odata')->assertStatus(401)->assertHeader('WWW-Authenticate')->assertJsonPath('error.code', 'Unauthorized');
        $this->get('/api/bi/odata', ['Authorization' => 'Bearer erpbi_falso'])->assertStatus(401);
        $token = $this->criarToken()['token'];
        $h = ['Authorization' => "Bearer {$token}"];

        $doc = $this->get('/api/bi/odata', $h)->assertOk()->assertHeader('OData-Version', '4.0');
        $this->assertSame(array_keys(ServicoFeedBI::conjuntos()), array_column($doc->json('value'), 'name'));

        $meta = $this->get('/api/bi/odata/$metadata', $h)->assertOk();
        $this->assertStringContainsString('application/xml', $meta->headers->get('Content-Type'));
        $xml = simplexml_load_string($meta->getContent());
        $this->assertNotFalse($xml, 'CSDL inválido');
        $this->assertStringContainsString('<EntityType Name="contabilidade"><Key><PropertyRef Name="linha"/></Key>', $meta->getContent());
        $this->assertStringContainsString('<Property Name="saldo" Type="Edm.Decimal"', $meta->getContent());
        $this->assertStringContainsString('Capabilities.FilterRestrictions', $meta->getContent());

        // contabilidade: só a empresa do token, sem a classe 9; autenticação Básica (palavra-passe = token)
        $basico = ['Authorization' => 'Basic '.base64_encode("bi:{$token}")];
        $r = $this->get('/api/bi/odata/contabilidade?$count=true', $basico)->assertOk();
        $this->assertSame(2, ($r->json()['@odata.count'] ?? null));
        $this->assertSame(['6211', '7111'], array_column($r->json('value'), 'conta'));
        $this->assertSame([100.0, -250.5], array_column($r->json('value'), 'saldo'));
        $this->assertSame([1, 2], array_column($r->json('value'), 'linha'));
        $this->assertSame('2026-01-15', $r->json('value.0.data'));
        $this->assertStringNotContainsString('999', json_encode($r->json('value')));

        // paginação e $select
        $p1 = $this->get('/api/bi/odata/contabilidade?$top=1&$select=conta,saldo', $h)->assertOk();
        $this->assertSame([['linha' => 1, 'conta' => '6211', 'saldo' => 100.0]], $p1->json('value'));
        $this->assertNull(($p1->json()['@odata.nextLink'] ?? null), '$top=1 pedido: não há página seguinte');
        $p2 = $this->get('/api/bi/odata/contabilidade?$skip=1', $h)->assertOk();
        $this->assertSame(['7111'], array_column($p2->json('value'), 'conta'));
        $this->assertSame(2, $p2->json('value.0.linha'));

        // período e operadores não suportados
        $this->assertCount(1, $this->get('/api/bi/odata/contabilidade?data_inicio=2026-02-01&data_fim=2026-12-31', $h)->assertOk()->json('value'));
        $this->get('/api/bi/odata/contabilidade?$filter=conta%20eq%20%276211%27', $h)->assertStatus(501)->assertJsonPath('error.code', 'NotImplemented');
        $this->get('/api/bi/odata/contabilidade?$select=senha', $h)->assertStatus(400);
        $this->get('/api/bi/odata/contabilidade?data_inicio=ontem', $h)->assertStatus(400);
        $this->get('/api/bi/odata/inexistente', $h)->assertStatus(404);

        // os 8 conjuntos respondem (SQL de cada um válido, mesmo sem dados)
        foreach (array_keys(ServicoFeedBI::conjuntos()) as $c) {
            $this->get("/api/bi/odata/{$c}?\$top=5", $h)->assertOk()->assertJsonStructure(['@odata.context', 'value']);
        }
    }

    #[Test]
    public function token_limitado_a_conjuntos_e_pagina_seguinte_do_servidor(): void
    {
        $token = $this->criarToken(['conjuntos' => ['vendas']])['token'];
        $h = ['Authorization' => "Bearer {$token}"];
        $this->get('/api/bi/odata/contabilidade', $h)->assertStatus(403);
        $this->assertSame(['vendas'], array_column($this->get('/api/bi/odata', $h)->json('value'), 'name'));
        $this->assertStringNotContainsString('EntityType Name="contabilidade"', $this->get('/api/bi/odata/$metadata', $h)->getContent());

        // página seguinte: com mais linhas do que o tamanho da página o servidor devolve @odata.nextLink
        $token2 = $this->criarToken()['token'];
        $linhas = [];
        for ($i = 0; $i < ServicoFeedBI::TAMANHO_PAGINA + 3; $i++) {
            $linhas[] = ['empresa_id' => $this->empresa->id, 'codigo_conta' => '6211', 'tipo_dc' => 'D', 'valor' => 1, 'data_documento' => '2025-06-01'];
        }
        foreach (array_chunk($linhas, 1000) as $bloco) {
            DB::table('lancamentos_contabeis')->insert($bloco);
        }
        $r = $this->get('/api/bi/odata/contabilidade', ['Authorization' => "Bearer {$token2}"])->assertOk();
        $this->assertCount(ServicoFeedBI::TAMANHO_PAGINA, $r->json('value'));
        $seguinte = ($r->json()['@odata.nextLink'] ?? null);
        $this->assertStringContainsString('%24skip='.ServicoFeedBI::TAMANHO_PAGINA, $seguinte);
        $r2 = $this->get(substr($seguinte, strpos($seguinte, '/api/')), ['Authorization' => "Bearer {$token2}"])->assertOk();
        $this->assertCount(5, $r2->json('value'));
        $this->assertNull(($r2->json()['@odata.nextLink'] ?? null));
    }
}
