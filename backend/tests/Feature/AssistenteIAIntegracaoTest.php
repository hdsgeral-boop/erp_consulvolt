<?php

namespace Tests\Feature;

use App\Exceptions\ErroNegocio;
use App\Models\PlanoConta;
use App\Models\Tenant;
use App\Models\Utilizador;
use App\Services\IA\Agente\Fornecedores\AnthropicIA;
use App\Services\IA\Agente\ServicoConfiguracaoIA;
use App\Services\Integracoes\IA\ClienteClaude;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bloco B (ADR-081) — o que a produção não testava: permissões do chat e das ferramentas, confirmação obrigatória,
 * isolamento por cliente, rotas da plataforma (chave cifrada e nunca devolvida), fornecedor Anthropic por HTTP, limites.
 */
final class AssistenteIAIntegracaoTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::statement('RESET ROLE');
        DB::selectOne("SELECT set_config('app.tenant_id', '', false)");

        parent::tearDown();
    }

    private function ia(): ServicoConfiguracaoIA
    {
        return app(ServicoConfiguracaoIA::class);
    }

    /** @return array{0: array<string, mixed>, 1: int, 2: Utilizador} */
    private function sessaoComPermissoes(array $permissoes, bool $atribuir = true, ?int $empresaId = null): array
    {
        $e = $empresaId ? null : $this->criarEmpresa();
        $empresaId ??= $e->id;
        $u = $this->criarUtilizador(['acesso_todas_empresas' => true, 'perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + $permissoes, 'Perfil IA '.count($permissoes).uniqid())->id]);
        if ($atribuir) {
            $this->ia()->gravarFornecedor('openai', ['chave' => 'sk-teste-1234', 'modelos' => ['gpt-teste'], 'modelo_padrao' => 'gpt-teste', 'ativo' => true]);
            $this->ia()->atribuir((int) $u->tenant_id, ['fornecedor' => 'openai', 'modelo' => 'gpt-teste']);
        }

        return [$this->entrar($u) + ['X-Empresa-Id' => $empresaId], $empresaId, $u];
    }

    private static function texto(string $t): array
    {
        return ['output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $t, 'annotations' => []]]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]];
    }

    public function test_sem_a_permissao_do_chat_nada_funciona_e_o_estado_aparece_inactivo(): void
    {
        [$h] = $this->sessaoComPermissoes(['lancamentos_view' => true]);
        Http::fake();

        $this->getJson('/api/ia/estado', $h)->assertOk()->assertJsonPath('dados.ativo', false);
        $this->postJson('/api/ia/mensagens', ['texto' => 'Olá'], $h)->assertForbidden();
        $this->getJson('/api/ia/conversas', $h)->assertForbidden();
        $this->getJson('/api/ia/regras', $h)->assertForbidden();
        $this->postJson('/api/ia/accoes/1/decisao', ['estado' => 'REJEITADA'], $h)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_ferramenta_sem_permissao_nao_e_anunciada_e_e_recusada_se_o_modelo_a_pedir(): void
    {
        $e = $this->criarEmpresa();
        $diario = DB::table('diarios_contabeis')->insertGetId(['empresa_id' => $e->id, 'codigo' => '01', 'nome' => 'Geral']);
        app(ContextoEmpresa::class)->executarComo($e->id, function () {
            PlanoConta::create(['codigo' => '4311', 'descricao' => 'Depósitos', 'tipo' => 'M']);
            PlanoConta::create(['codigo' => '6221', 'descricao' => 'Material', 'tipo' => 'M']);
        });
        // vê lançamentos mas não os pode gravar (sem lancamentos_post) nem criar terceiros
        [$h, $empresa] = $this->sessaoComPermissoes(['ia_chat_view' => true, 'lancamentos_view' => true], true, $e->id);
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['output' => [['type' => 'function_call', 'call_id' => 'c1', 'name' => 'propor_lancamento', 'arguments' => json_encode([
                'diario_id' => $diario, 'data_documento' => '2026-10-20',
                'linhas' => [['codigo_conta' => '6221', 'tipo_dc' => 'D', 'valor' => 10], ['codigo_conta' => '4311', 'tipo_dc' => 'C', 'valor' => 10]]])]],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])
            ->push(self::texto('Não tenho permissão para propor lançamentos.')),
        ]);

        $r = $this->postJson('/api/ia/mensagens', ['texto' => 'Lança 10 Kz'], $h)->assertOk();
        $this->assertSame([], $r->json('dados.mensagens.1.extra.accoes'));
        $this->assertSame(0, DB::table('ia_accoes')->where('empresa_id', $empresa)->count(), 'nenhuma proposta criada sem a permissão');
        // o modelo nunca foi informado da existência das ferramentas de escrita
        Http::assertSent(function ($req) {
            $nomes = array_column($req->data()['tools'] ?? [], 'name');

            return in_array('consultar_plano_contas', $nomes, true) && ! in_array('propor_lancamento', $nomes, true) && ! in_array('propor_terceiro', $nomes, true);
        });
        // o 2.º pedido levou o erro da ferramenta indisponível
        Http::assertSent(fn ($req) => collect($req->data()['input'] ?? [])->contains(fn ($i) => ($i['type'] ?? '') === 'function_call_output' && str_contains($i['output'], 'indispon')));
        $this->assertSame(0, DB::table('lancamentos_contabeis')->where('empresa_id', $empresa)->count());
    }

    public function test_acoes_da_ia_desligadas_na_atribuicao_retiram_as_ferramentas_de_escrita(): void
    {
        [$h, , $u] = $this->sessaoComPermissoes(['ia_chat_view' => true, 'lancamentos_view' => true, 'lancamentos_post' => true, 'aux_gerir' => true]);
        $this->ia()->atribuir((int) $u->tenant_id, ['fornecedor' => 'openai', 'modelo' => 'gpt-teste', 'accoes_erp' => false, 'pesquisa_web' => false]);
        Http::fake(['api.openai.com/*' => Http::response(self::texto('ok'))]);

        $this->postJson('/api/ia/mensagens', ['texto' => 'Olá'], $h)->assertOk();
        Http::assertSent(function ($req) {
            $nomes = array_column($req->data()['tools'] ?? [], 'name');

            return ! in_array('propor_lancamento', $nomes, true) && ! in_array('propor_terceiro', $nomes, true) && ! in_array('web_search', array_column($req->data()['tools'] ?? [], 'type'), true);
        });
    }

    public function test_proposta_de_terceiro_exige_a_permissao_do_papel_e_nada_e_gravado(): void
    {
        [$h, $empresa] = $this->sessaoComPermissoes(['ia_chat_view' => true, 'vendas_clientes_gerir' => true]);
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['output' => [['type' => 'function_call', 'call_id' => 'c1', 'name' => 'propor_terceiro', 'arguments' => '{"papel":"FORNECEDOR","nome":"Fornecedor X"}']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])
            ->push(['output' => [['type' => 'function_call', 'call_id' => 'c2', 'name' => 'propor_terceiro', 'arguments' => '{"papel":"CLIENTE","nome":"Cliente Y","email":"y@exemplo.ao"}']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])
            ->push(self::texto('Proposta pronta.')),
        ]);

        $r = $this->postJson('/api/ia/mensagens', ['texto' => 'Cria o fornecedor X e o cliente Y'], $h)->assertOk();
        $accoes = $r->json('dados.mensagens.1.extra.accoes');
        $this->assertCount(1, $accoes, 'só a proposta do papel permitido');
        $this->assertSame(['terceiro', 'PENDENTE', 'POST', '/terceiros'], [$accoes[0]['tipo'], $accoes[0]['estado'], $accoes[0]['metodo'], $accoes[0]['url']]);
        $this->assertSame('CLIENTE', $accoes[0]['dados']['papel']);
        $this->assertSame(0, DB::table('terceiros')->where('empresa_id', $empresa)->count(), 'a IA nunca grava: só o ecrã, depois de o utilizador confirmar');
    }

    public function test_so_o_dono_da_proposta_a_decide_e_a_decisao_fica_auditada(): void
    {
        [$h, $empresa, $u] = $this->sessaoComPermissoes(['ia_chat_view' => true, 'vendas_clientes_gerir' => true]);
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['output' => [['type' => 'function_call', 'call_id' => 'c1', 'name' => 'propor_terceiro', 'arguments' => '{"papel":"CLIENTE","nome":"Cliente Z"}']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])
            ->push(self::texto('Pronto.')),
        ]);
        $id = $this->postJson('/api/ia/mensagens', ['texto' => 'Cria o cliente Z'], $h)->json('dados.mensagens.1.extra.accoes.0.id');

        [$hOutro] = $this->sessaoComPermissoes(['ia_chat_view' => true], false, $empresa);
        $this->postJson("/api/ia/accoes/{$id}/decisao", ['estado' => 'EXECUTADA'], $hOutro)->assertNotFound();
        $this->assertSame('PENDENTE', DB::table('ia_accoes')->where('id', $id)->value('estado'));

        $this->postJson("/api/ia/accoes/{$id}/decisao", ['estado' => 'REJEITADA'], $h)->assertOk();
        $this->assertTrue(DB::table('logs_auditoria')->where('acao', 'Decisão sobre proposta da IA')->where('utilizador_id', $u->id)->exists());
        $this->postJson("/api/ia/accoes/{$id}/decisao", ['estado' => 'INVENTADO'], $h)->assertStatus(422);
    }

    public function test_conversas_sao_privadas_do_utilizador_e_da_empresa(): void
    {
        [$h, $empresa] = $this->sessaoComPermissoes(['ia_chat_view' => true]);
        Http::fake(['api.openai.com/*' => Http::response(self::texto('Olá!'))]);
        $id = $this->postJson('/api/ia/mensagens', ['texto' => 'Segredo do utilizador A'], $h)->json('dados.conversa.id');

        [$hB] = $this->sessaoComPermissoes(['ia_chat_view' => true], false, $empresa);
        $this->getJson('/api/ia/conversas', $hB)->assertOk()->assertJsonCount(0, 'dados');
        $this->getJson("/api/ia/conversas/{$id}/mensagens", $hB)->assertNotFound();
        $this->putJson("/api/ia/conversas/{$id}", ['titulo' => 'x'], $hB)->assertNotFound();
        $this->deleteJson("/api/ia/conversas/{$id}", [], $hB)->assertNotFound();
        $this->postJson('/api/ia/mensagens', ['texto' => 'continuar', 'conversa_id' => $id], $hB)->assertNotFound();

        $this->putJson("/api/ia/conversas/{$id}", ['titulo' => 'Renomeada'], $h)->assertOk();
        $this->deleteJson("/api/ia/conversas/{$id}", [], $h)->assertOk();
        $this->getJson('/api/ia/conversas', $h)->assertOk()->assertJsonCount(0, 'dados');
    }

    public function test_atribuicao_e_regras_sao_isoladas_por_cliente_e_as_regras_exigem_permissao(): void
    {
        $outro = Tenant::query()->create(['nome' => 'Outro cliente IA', 'slug' => 'outro-ia', 'estado' => 'ATIVO']);
        [$h, , $u] = $this->sessaoComPermissoes(['ia_chat_view' => true, 'ia_chat_regras' => true]);
        [$hSemRegras] = $this->sessaoComPermissoes(['ia_chat_view' => true], false, (int) DB::table('empresas')->value('id'));

        $this->putJson('/api/ia/regras', ['regras' => 'Só em kwanzas.'], $h)->assertOk();
        $this->putJson('/api/ia/regras', ['regras' => 'x'], $hSemRegras)->assertForbidden();
        $this->assertTrue(DB::table('logs_auditoria')->where('acao', 'Regras da IA do cliente')->exists());
        DB::table('ia_regras_cliente')->insert(['tenant_id' => $outro->id, 'regras' => 'REGRA SECRETA DO OUTRO', 'atualizado_em' => now()]);
        $this->getJson('/api/ia/regras', $h)->assertOk()->assertJsonPath('dados.regras', 'Só em kwanzas.');

        $this->assertSame((int) $u->tenant_id, 1);
        $this->assertNull($this->ia()->doCliente($outro->id), 'o outro cliente não tem atribuição');
        $this->assertNotNull($this->ia()->doCliente(1));
        // atribuição inactiva ou fornecedor desactivado = IA inactiva
        $this->ia()->atribuir($outro->id, ['fornecedor' => 'openai', 'modelo' => 'gpt-teste']);
        $this->assertNotNull($this->ia()->doCliente($outro->id));
        $this->ia()->atribuir($outro->id, ['fornecedor' => 'openai', 'modelo' => 'gpt-teste', 'ativo' => false]);
        $this->assertNull($this->ia()->doCliente($outro->id));
        $this->ia()->gravarFornecedor('openai', ['ativo' => false]);
        $this->assertNull($this->ia()->doCliente(1));
    }

    public function test_regras_do_cliente_estao_sob_rls_real(): void
    {
        $outro = Tenant::query()->create(['nome' => 'RLS IA', 'slug' => 'rls-ia', 'estado' => 'ATIVO']);
        DB::table('ia_regras_cliente')->insert([['tenant_id' => 1, 'regras' => 'A'], ['tenant_id' => $outro->id, 'regras' => 'B']]);
        config(['erp.saas.ativo' => true]);

        DB::statement('SET ROLE erp_app');
        $this->assertSame(0, DB::table('ia_regras_cliente')->count(), 'sem cliente definido, nada se vê');
        app(ContextoTenant::class)->definir(1);
        $this->assertSame(['A'], DB::table('ia_regras_cliente')->pluck('regras')->all());
        app(ContextoTenant::class)->definir($outro->id);
        $this->assertSame(['B'], DB::table('ia_regras_cliente')->pluck('regras')->all());
        $this->assertSame(0, DB::table('ia_conversas')->count());
    }

    public function test_quota_esgotada_bloqueia_e_o_consumo_e_do_cliente(): void
    {
        [$h, , $u] = $this->sessaoComPermissoes(['ia_chat_view' => true]);
        $this->ia()->atribuir((int) $u->tenant_id, ['fornecedor' => 'openai', 'modelo' => 'gpt-teste', 'quota_tokens_mes' => 1000]);
        Http::fake(['api.openai.com/*' => Http::response(['output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'ok', 'annotations' => []]]]], 'usage' => ['input_tokens' => 900, 'output_tokens' => 200]])]);

        $this->postJson('/api/ia/mensagens', ['texto' => 'a'], $h)->assertOk();
        $this->getJson('/api/ia/estado', $h)->assertJsonPath('dados.tokens_usados_mes', 1100)->assertJsonPath('dados.quota_tokens_mes', 1000);
        $this->postJson('/api/ia/mensagens', ['texto' => 'b'], $h)->assertStatus(429)->assertJsonPath('codigo', 'IA_QUOTA_ATINGIDA');
        Http::assertSentCount(1);

        // o consumo de outro cliente não conta para esta quota
        $outro = Tenant::query()->create(['nome' => 'Outro', 'slug' => 'outro-q', 'estado' => 'ATIVO']);
        $linha = collect($this->ia()->atribuicoes())->firstWhere('tenant_id', $outro->id);
        $this->assertSame(0, $linha['tokens_mes']);
    }

    public function test_limite_de_pedidos_do_chat(): void
    {
        config(['erp.api.ia_por_minuto' => 2]);
        [$h] = $this->sessaoComPermissoes(['ia_chat_view' => true]);
        Http::fake(['api.openai.com/*' => Http::response(self::texto('ok'))]);

        $this->postJson('/api/ia/mensagens', ['texto' => '1'], $h)->assertOk();
        $this->postJson('/api/ia/mensagens', ['texto' => '2'], $h)->assertOk();
        $this->postJson('/api/ia/mensagens', ['texto' => '3'], $h)->assertStatus(429);
    }

    public function test_erro_do_fornecedor_no_chat_nao_revela_o_motivo_nem_a_chave(): void
    {
        [$h] = $this->sessaoComPermissoes(['ia_chat_view' => true]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided: sk-teste-1234']], 401)]);

        $r = $this->postJson('/api/ia/mensagens', ['texto' => 'Olá'], $h)->assertStatus(502);
        $this->assertStringNotContainsString('sk-teste', $r->getContent());
        $this->assertStringNotContainsString('Incorrect', $r->getContent());
        $this->assertSame(0, DB::table('ia_consumo')->count());
    }

    public function test_plataforma_grava_chave_cifrada_nunca_a_devolve_e_atribui_a_clientes(): void
    {
        $empresa = $this->criarEmpresa();
        $super = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));
        $comum = $this->entrar($this->criarUtilizador(['acesso_todas_empresas' => true]));
        $cliente = Tenant::query()->create(['nome' => 'Cliente IA', 'slug' => 'cliente-ia', 'estado' => 'ATIVO']);
        config(['erp.saas.ativo' => true]);

        $this->getJson('/api/plataforma/ia/fornecedores', $comum)->assertForbidden();
        $this->putJson('/api/plataforma/ia/fornecedores/openai', ['chave' => 'x'], $comum)->assertForbidden();

        $r = $this->putJson('/api/plataforma/ia/fornecedores/openai', ['chave' => 'sk-super-secreta-9999', 'modelos' => ['gpt-5-mini'], 'modelo_padrao' => 'gpt-5-mini', 'ativo' => true], $super)->assertOk();
        $this->assertStringNotContainsString('super-secreta', $r->getContent());
        $this->assertStringNotContainsString('9999', $r->getContent());
        $this->assertTrue(collect($r->json('dados'))->firstWhere('fornecedor', 'openai')['configurado']);
        $this->assertStringNotContainsString('super-secreta', $this->getJson('/api/plataforma/ia/fornecedores', $super)->getContent());
        $this->assertStringStartsWith('enc:', (string) DB::table('ia_fornecedores')->where('fornecedor', 'openai')->value('chave'));
        $this->assertStringNotContainsString('super-secreta', (string) DB::table('logs_auditoria')->where('modulo', 'Plataforma')->pluck('detalhes')->implode(' '));

        $this->putJson("/api/plataforma/ia/atribuicoes/{$cliente->id}", ['fornecedor' => 'openai', 'modelo' => 'gpt-5-mini', 'quota_tokens_mes' => 50000], $super)->assertOk();
        $this->putJson("/api/plataforma/ia/atribuicoes/{$cliente->id}", ['fornecedor' => 'openai', 'modelo' => 'claude-x'], $super)->assertStatus(422)->assertJsonPath('codigo', 'IA_MODELO_INVALIDO');
        $this->putJson('/api/plataforma/ia/atribuicoes/99999', ['fornecedor' => 'openai'], $super)->assertStatus(404);
        $this->putJson("/api/plataforma/ia/atribuicoes/{$cliente->id}", ['fornecedor' => 'outro'], $super)->assertStatus(422);
        $linha = collect($this->getJson('/api/plataforma/ia/atribuicoes', $super)->assertOk()->json('dados'))->firstWhere('tenant_id', $cliente->id);
        $this->assertSame(['openai', 'gpt-5-mini', 50000], [$linha['fornecedor'], $linha['modelo'], $linha['quota_tokens_mes']]);
        $this->getJson('/api/plataforma/ia/consumo', $super)->assertOk()->assertJsonStructure(['dados' => ['meses', 'tokens_entrada', 'tokens_saida', 'custo_usd', 'pedidos', 'clientes_mes']]);

        $this->deleteJson("/api/plataforma/ia/atribuicoes/{$cliente->id}", [], $super)->assertOk();
        $this->assertNull($this->ia()->doCliente($cliente->id));
        $this->assertTrue(DB::table('logs_auditoria')->where('acao', 'Atribuir IA')->exists());
        $this->assertTrue($empresa->exists);
    }

    public function test_sem_app_key_a_chave_nao_e_guardada_em_claro(): void
    {
        config(['app.key' => '']);

        try {
            $this->ia()->gravarFornecedor('openai', ['chave' => 'sk-em-claro-1234']);
            $this->fail('devia recusar');
        } catch (ErroNegocio $e) {
            $this->assertSame('IA_SEM_CIFRA', $e->codigo);
        }
        $this->assertNull(DB::table('ia_fornecedores')->where('fornecedor', 'openai')->value('chave'));
    }

    public function test_anthropic_pelo_http_com_ferramentas_pesquisa_e_fallback(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => 'Vou consultar.'],
                ['type' => 'web_search_tool_result', 'content' => [['type' => 'web_search_result', 'title' => 'AGT', 'url' => 'https://agt.minfin.gov.ao']]],
                ['type' => 'tool_use', 'id' => 'tu1', 'name' => 'saldo_conta', 'input' => ['codigo' => '43']],
            ],
            'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 10, 'cache_read_input_tokens' => 5, 'output_tokens' => 7, 'server_tool_use' => ['web_search_requests' => 1]],
        ])]);

        $a = new AnthropicIA('sk-ant-teste');
        $r = $a->conversar('claude-opus-5-5', 'sistema', $a->mensagemUtilizador('Quanto há no banco?', []), [['nome' => 'saldo_conta', 'descricao' => 'd', 'parametros' => ['type' => 'object', 'properties' => new \stdClass]]], true);

        $this->assertSame('Vou consultar.', $r->texto);
        $this->assertSame([['id' => 'tu1', 'nome' => 'saldo_conta', 'args' => ['codigo' => '43']]], $r->chamadas);
        $this->assertSame([15, 7, 1], [$r->tokensEntrada, $r->tokensSaida, $r->pesquisas]);
        $this->assertSame('https://agt.minfin.gov.ao', $r->fontes[0]['url']);
        Http::assertSent(fn ($q) => $q->hasHeader('x-api-key', 'sk-ant-teste') && $q->hasHeader('anthropic-beta', 'server-side-fallback-2026-07-01')
            && $q['fallbacks'] === 'default' && $q['tools'][0]['input_schema']['type'] === 'object' && $q['tools'][1]['type'] === 'web_search_20260209');
        $this->assertSame('user', $a->resultados([['id' => 'tu1', 'nome' => 'x', 'conteudo' => '{}']])[0]['role']);
    }

    public function test_cliente_claude_continua_compativel_sem_argumentos_e_so_com_a_chave_do_ambiente(): void
    {
        config(['assistente_ia.chave' => 'chave-do-ambiente', 'assistente_ia.modelo' => 'claude-sonnet-5-5']);
        Http::fake(['api.anthropic.com/*' => Http::response(['model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => '{"a":1}']], 'usage' => ['input_tokens' => 3, 'output_tokens' => 2]])]);

        $claude = new ClienteClaude;   // construtor sem argumentos, como antes do Bloco B
        $this->assertTrue($claude->configurado());
        $this->assertSame(['a' => 1], $claude->extrair('s', [['type' => 'text', 'text' => 'x']], ['type' => 'object'])['dados']);
        Http::assertSent(fn ($q) => $q->hasHeader('x-api-key', 'chave-do-ambiente') && $q['model'] === 'claude-sonnet-5-5');
        $this->assertSame('servidor', $claude->definicao()['origem']);

        // a plataforma tem fornecedor mas o ambiente também: manda a plataforma
        $this->ia()->gravarFornecedor('anthropic', ['chave' => 'chave-da-plataforma', 'modelo_padrao' => 'claude-haiku-5-5', 'ativo' => true]);
        $this->assertSame('plataforma', $claude->definicao()['origem']);
    }
}
