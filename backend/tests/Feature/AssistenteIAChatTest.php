<?php

namespace Tests\Feature;

use App\Models\PlanoConta;
use App\Models\Utilizador;
use App\Services\IA\Agente\ServicoConfiguracaoIA;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Assistente IA (chat): configuração na plataforma (chave nunca devolvida), atribuição ao cliente, ciclo modelo →
 * ferramentas → resposta, propostas que só são gravadas com a confirmação do utilizador, quota e consumo.
 * O fornecedor é simulado (Http::fake da Responses API da OpenAI): nenhum pedido real.
 */
final class AssistenteIAChatTest extends TestCase
{
    private function preparar(): array
    {
        $e = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($e->id, function () {
            PlanoConta::create(['codigo' => '4311', 'descricao' => 'Depósitos à ordem', 'tipo' => 'M']);
            PlanoConta::create(['codigo' => '6221', 'descricao' => 'Material de escritório', 'tipo' => 'M']);
        });
        $diario = DB::table('diarios_contabeis')->insertGetId(['empresa_id' => $e->id, 'codigo' => '01', 'nome' => 'Diário geral']);
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);
        $ia = app(ServicoConfiguracaoIA::class);
        $ia->gravarFornecedor('openai', ['chave' => 'sk-teste-1234', 'modelos' => ['gpt-teste'], 'modelo_padrao' => 'gpt-teste', 'preco_entrada_mtok' => 1, 'preco_saida_mtok' => 2, 'ativo' => true]);
        $ia->atribuir((int) $u->tenant_id, ['fornecedor' => 'openai', 'modelo' => 'gpt-teste', 'quota_tokens_mes' => 100000]);

        return [$this->entrar($u) + ['X-Empresa-Id' => $e->id], $e->id, $diario];
    }

    private static function resposta(array $output, int $entrada = 100, int $saida = 20): array
    {
        return ['output' => $output, 'usage' => ['input_tokens' => $entrada, 'output_tokens' => $saida]];
    }

    public function test_chave_nunca_e_devolvida_e_so_os_ultimos_caracteres_aparecem(): void
    {
        app(ServicoConfiguracaoIA::class)->gravarFornecedor('anthropic', ['chave' => 'sk-ant-segredo-ABCD', 'ativo' => true]);
        $f = collect(app(ServicoConfiguracaoIA::class)->fornecedores())->firstWhere('fornecedor', 'anthropic');
        $this->assertTrue($f['configurado']);
        $this->assertSame('…ABCD', $f['chave_final']);
        $this->assertStringNotContainsString('segredo', (string) json_encode($f));
        $this->assertStringNotContainsString('segredo', (string) DB::table('ia_fornecedores')->where('fornecedor', 'anthropic')->value('chave'));
    }

    public function test_ciclo_com_ferramenta_e_proposta_de_lancamento_confirmada_pelo_utilizador(): void
    {
        [$h, $empresa, $diario] = $this->preparar();
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(self::resposta([['type' => 'function_call', 'call_id' => 'c1', 'name' => 'consultar_plano_contas', 'arguments' => '{"pesquisa":"62"}']]))
            ->push(self::resposta([['type' => 'function_call', 'call_id' => 'c2', 'name' => 'propor_lancamento', 'arguments' => json_encode([
                'diario_id' => $diario, 'data_documento' => '2026-10-20', 'descricao' => 'Compra de papel',
                'linhas' => [['codigo_conta' => '6221', 'tipo_dc' => 'D', 'valor' => 5000], ['codigo_conta' => '4311', 'tipo_dc' => 'C', 'valor' => 5000]]])]]))
            ->push(self::resposta([['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Preparei o lançamento para confirmar.', 'annotations' => []]]]])),
        ]);

        $r = $this->postJson('/api/ia/mensagens', ['texto' => 'Lança a compra de papel de 5000 Kz paga pelo banco'], $h)->assertOk();
        $msgs = $r->json('dados.mensagens');
        $this->assertSame(['utilizador', 'assistente'], array_column($msgs, 'papel'));
        $this->assertSame('Preparei o lançamento para confirmar.', $msgs[1]['texto']);
        $accao = $msgs[1]['extra']['accoes'][0];
        $this->assertSame(['lancamento', 'PENDENTE', 'POST', '/contabilidade/lancamentos'], [$accao['tipo'], $accao['estado'], $accao['metodo'], $accao['url']]);
        $this->assertSame(0, DB::table('lancamentos_contabeis')->where('empresa_id', $empresa)->count(), 'nada é gravado sem confirmação');

        // o 2.º pedido levou o resultado da ferramenta (function_call_output) com a conta encontrada
        Http::assertSent(fn ($req) => collect($req->data()['input'] ?? [])->contains(fn ($i) => ($i['type'] ?? '') === 'function_call_output' && str_contains($i['output'], '6221')));

        // o ecrã executa o endpoint normal e regista a decisão
        $this->postJson("/api/ia/accoes/{$accao['id']}/decisao", ['estado' => 'EXECUTADA', 'resultado' => ['mensagem' => 'Lançamento 1 gravado']], $h)
            ->assertOk()->assertJsonPath('dados.estado', 'EXECUTADA');
        $this->postJson("/api/ia/accoes/{$accao['id']}/decisao", ['estado' => 'REJEITADA'], $h)->assertStatus(422);

        $consumo = DB::table('ia_consumo')->first();
        $this->assertSame([300, 60], [(int) $consumo->tokens_entrada, (int) $consumo->tokens_saida]);
        $this->getJson('/api/ia/estado', $h)->assertOk()->assertJsonPath('dados.ativo', true)->assertJsonPath('dados.tokens_usados_mes', 360);
    }

    public function test_conversa_continua_com_o_historico_e_anexos_e_a_quota_bloqueia(): void
    {
        [$h] = $this->preparar();
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(self::resposta([['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Olá!', 'annotations' => []]]]]))
            ->push(self::resposta([['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Li o CSV.', 'annotations' => []]]]], 99000, 5000)),
        ]);
        $id = $this->postJson('/api/ia/mensagens', ['texto' => 'Olá'], $h)->assertOk()->json('dados.conversa.id');
        $csv = UploadedFile::fake()->createWithContent('vendas.csv', "produto;valor\nPapel;5000\n");
        $this->post('/api/ia/mensagens', ['texto' => 'Resume', 'conversa_id' => $id, 'anexos' => [$csv]], $h + ['Accept' => 'application/json'])->assertOk();
        Http::assertSent(fn ($req) => count($req->data()['input'] ?? []) >= 3 && str_contains(json_encode($req->data()), 'Papel;5000'));

        $this->getJson('/api/ia/conversas', $h)->assertOk()->assertJsonCount(1, 'dados');
        $this->getJson("/api/ia/conversas/{$id}/mensagens", $h)->assertOk()->assertJsonCount(4, 'dados');
        // quota de 100 000 tokens ultrapassada
        $this->postJson('/api/ia/mensagens', ['texto' => 'Mais', 'conversa_id' => $id], $h)->assertStatus(429)->assertJsonPath('codigo', 'IA_QUOTA_ATINGIDA');
    }

    public function test_sem_atribuicao_o_assistente_esta_inactivo(): void
    {
        $e = $this->criarEmpresa();
        $h = $this->entrar($this->criarUtilizador(['acesso_todas_empresas' => true])) + ['X-Empresa-Id' => $e->id];
        $this->getJson('/api/ia/estado', $h)->assertOk()->assertJsonPath('dados.ativo', false);
        $this->postJson('/api/ia/mensagens', ['texto' => 'Olá'], $h)->assertStatus(403)->assertJsonPath('codigo', 'IA_INATIVA');
    }
}
