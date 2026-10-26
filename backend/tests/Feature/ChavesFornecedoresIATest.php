<?php

namespace Tests\Feature;

use App\Exceptions\ErroNegocio;
use App\Services\IA\Agente\ServicoConfiguracaoIA;
use App\Services\Integracoes\IA\ClienteClaude;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Chave de cada fornecedor de IA: gravar, reconhecer os modelos da conta e testar a ligação (pedidos simulados). */
final class ChavesFornecedoresIATest extends TestCase
{
    private function servico(): ServicoConfiguracaoIA
    {
        return app(ServicoConfiguracaoIA::class);
    }

    #[Test]
    public function gemini_reconhece_a_chave_lista_so_modelos_de_conversa_e_testa_a_ligacao(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models?*' => Http::response(['models' => [
                ['name' => 'models/gemini-3.5-flash', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/text-embedding-004', 'supportedGenerationMethods' => ['embedContent']],
            ]]),
            'generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]),
        ]);
        $this->assertSame(['gemini-3.5-flash'], $this->servico()->modelosDisponiveis('gemini', 'chave-gemini-teste'));
        Http::assertSent(fn ($r) => $r->hasHeader('x-goog-api-key', 'chave-gemini-teste'));

        $this->servico()->gravarFornecedor('gemini', ['chave' => 'chave-gemini-teste', 'modelos' => ['gemini-3.5-flash'], 'modelo_padrao' => 'gemini-3.5-flash', 'ativo' => true]);
        $this->servico()->testar('gemini');
        $g = collect($this->servico()->fornecedores())->firstWhere('fornecedor', 'gemini');
        $this->assertTrue($g['configurado']);
        $this->assertNotNull($g['testado_em']);
        $this->assertNull($g['ultimo_erro']);
    }

    #[Test]
    public function o_teste_mostra_o_motivo_real_do_fornecedor_e_o_chat_nao(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'API key not valid. Please pass a valid API key.']], 400)]);
        $this->servico()->gravarFornecedor('gemini', ['chave' => 'chave-errada', 'modelo_padrao' => 'gemini-3.5-flash']);
        try {
            $this->servico()->testar('gemini');
            $this->fail('devia falhar');
        } catch (ErroNegocio $e) {
            $this->assertStringContainsString('API key not valid', $e->getMessage());
        }
        $this->assertStringContainsString('API key not valid', (string) collect($this->servico()->fornecedores())->firstWhere('fornecedor', 'gemini')['ultimo_erro']);

        // no chat dos clientes a mensagem é genérica (sem o nome nem o texto do fornecedor)
        try {
            $this->servico()->cliente('gemini')->conversar('gemini-3.5-flash', 'sistema', [], [], false);
            $this->fail('devia falhar');
        } catch (ErroNegocio $e) {
            $this->assertStringNotContainsString('API key', $e->getMessage());
        }
    }

    #[Test]
    public function openai_reconhece_a_chave_e_testa_com_a_responses_api(): void
    {
        Http::fake([
            'api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-5.1'], ['id' => 'text-embedding-3-small'], ['id' => 'whisper-1']]]),
            'api.openai.com/v1/responses' => Http::response(['output' => [], 'usage' => ['input_tokens' => 5, 'output_tokens' => 2]]),
        ]);
        $this->assertSame(['gpt-5.1'], $this->servico()->modelosDisponiveis('openai', 'sk-teste'));
        $this->servico()->gravarFornecedor('openai', ['chave' => 'sk-teste', 'modelos' => ['gpt-5.1'], 'modelo_padrao' => 'gpt-5.1']);
        $this->servico()->testar('openai');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/responses') && $r->hasHeader('Authorization', 'Bearer sk-teste') && $r['model'] === 'gpt-5.1');
    }

    #[Test]
    public function nome_de_modelo_invalido_nao_e_gravado(): void
    {
        $this->expectException(ErroNegocio::class);
        $this->servico()->gravarFornecedor('gemini', ['chave' => 'x', 'modelo_padrao' => 'Gemini_API_Key_3']);
    }

    #[Test]
    public function o_assistente_de_contabilidade_segue_o_que_a_plataforma_define_e_so_depois_o_servidor(): void
    {
        $claude = app(ClienteClaude::class);
        $esquema = ['type' => 'object'];
        $anthropic = fn (string $t) => Http::response(['model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $t]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]);
        Http::fake([
            'api.anthropic.com/*' => $anthropic('{"origem":"anthropic"}'),
            'api.openai.com/v1/responses' => Http::response(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => "```json\n{\"origem\":\"openai\"}\n```"]]]], 'usage' => ['input_tokens' => 20, 'output_tokens' => 7]]),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Aqui está: {"origem":"gemini"} feito']]]]], 'usageMetadata' => ['promptTokenCount' => 30, 'candidatesTokenCount' => 9]]),
        ]);

        // sem nada na plataforma: só as variáveis do servidor (compatibilidade)
        config(['assistente_ia.chave' => null]);
        $this->assertFalse($claude->configurado());
        config(['assistente_ia.chave' => 'chave-do-servidor', 'assistente_ia.modelo' => 'claude-sonnet-5-5']);
        $this->assertSame('claude-sonnet-5-5', $claude->modelo());
        $this->assertSame('anthropic', $claude->extrair('s', [['type' => 'text', 'text' => 'x']], $esquema)['dados']['origem']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic.com') && $r->hasHeader('x-api-key', 'chave-do-servidor') && $r['model'] === 'claude-sonnet-5-5');

        // a plataforma passa a mandar: a chave e o modelo dela substituem os do servidor
        $this->servico()->gravarFornecedor('anthropic', ['chave' => 'chave-da-plataforma', 'modelos' => ['claude-haiku-5-5'], 'modelo_padrao' => 'claude-haiku-5-5', 'preco_entrada_mtok' => 1, 'preco_saida_mtok' => 5, 'ativo' => true]);
        $this->assertSame('claude-haiku-5-5', $claude->modelo());
        $claude->extrair('s', [['type' => 'text', 'text' => 'x']], $esquema);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic.com') && $r->hasHeader('x-api-key', 'chave-da-plataforma') && $r['model'] === 'claude-haiku-5-5');
        $this->assertEqualsWithDelta(1.0, $claude->custo(1_000_000, 0), 1e-9);

        // trocar o fornecedor na plataforma troca aqui (OpenAI), com o JSON lido mesmo dentro de um bloco markdown
        $this->servico()->gravarFornecedor('anthropic', ['ativo' => false]);
        $this->servico()->gravarFornecedor('openai', ['chave' => 'sk-plataforma', 'modelos' => ['gpt-5-mini'], 'modelo_padrao' => 'gpt-5-mini', 'ativo' => true]);
        $r = $claude->extrair('s', [['type' => 'text', 'text' => 'x'], ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => 'AAAA']]], $esquema);
        $this->assertSame(['origem' => 'openai'], $r['dados']);
        $this->assertSame(['gpt-5-mini', 20, 7], [$r['modelo'], $r['tokens_entrada'], $r['tokens_saida']]);
        Http::assertSent(fn ($q) => str_contains($q->url(), 'api.openai.com/v1/responses') && $q['model'] === 'gpt-5-mini' && str_contains(json_encode($q['input']), 'input_file'));

        // e para o Gemini, com o JSON no meio de texto
        $this->servico()->gravarFornecedor('openai', ['ativo' => false]);
        $this->servico()->gravarFornecedor('gemini', ['chave' => 'chave-gemini', 'modelos' => ['gemini-3.5-flash'], 'modelo_padrao' => 'gemini-3.5-flash', 'ativo' => true]);
        $this->assertSame('gemini', $claude->extrair('s', [['type' => 'text', 'text' => 'x']], $esquema)['dados']['origem']);
        Http::assertSent(fn ($q) => str_contains($q->url(), 'models/gemini-3.5-flash:generateContent'));

        // tudo desactivado na plataforma: volta às variáveis do servidor
        $this->servico()->gravarFornecedor('gemini', ['ativo' => false]);
        $this->assertSame('claude-sonnet-5-5', $claude->modelo());
    }

    #[Test]
    public function chave_recusada_e_reportada_sem_gravar_nada(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key']], 401)]);
        try {
            $this->servico()->modelosDisponiveis('openai', 'sk-errada');
            $this->fail('devia falhar');
        } catch (ErroNegocio $e) {
            $this->assertSame('IA_CHAVE_INVALIDA', $e->codigo ?? 'IA_CHAVE_INVALIDA');
            $this->assertStringContainsString('recusada', $e->getMessage());
        }
    }
}
