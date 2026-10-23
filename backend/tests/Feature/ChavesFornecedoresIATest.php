<?php

namespace Tests\Feature;

use App\Exceptions\ErroNegocio;
use App\Services\IA\Agente\ServicoConfiguracaoIA;
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
