<?php

namespace Tests\Feature;

use App\Models\Empresa;
use Illuminate\Http\Client\Request as PedidoHttp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Assistente IA para lançamentos (decisão 26, M-03): desligado por omissão, só propõe, dados minimizados, regras internas. Cliente HTTP falso. */
final class AssistenteIATest extends TestCase
{
    private const URL = 'https://api.anthropic.com/v1/messages';

    private Empresa $empresa;

    private array $contabilista;

    protected function setUp(): void
    {
        parent::setUp();
        config(['assistente_ia.chave' => 'chave-de-teste-nao-real']);
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa Sigilosa, Lda', 'nif' => '5999888777', 'regras_ia' => 'Combustível vai sempre para a conta 6243.']);
        $e = $this->empresa->id;
        DB::table('plano_contas')->insert([
            ['empresa_id' => $e, 'codigo' => '6243', 'descricao' => 'Combustíveis', 'tipo' => 'M'],
            ['empresa_id' => $e, 'codigo' => '4511', 'descricao' => 'Caixa sede', 'tipo' => 'M'],
            ['empresa_id' => $e, 'codigo' => '3452', 'descricao' => 'IVA dedutível', 'tipo' => 'M'],
            ['empresa_id' => $e, 'codigo' => '62', 'descricao' => 'Fornecimentos e serviços', 'tipo' => 'T'],
        ]);
        DB::table('diarios_contabeis')->insert(['empresa_id' => $e, 'codigo' => 'CX', 'nome' => 'Caixa']);
        DB::table('terceiros')->insert(['empresa_id' => $e, 'nome' => 'Cliente Confidencial', 'nif' => '5000111222']);
        $this->contabilista = $this->sessao(['lancamentos_view', 'lancamentos_post', 'aux_gerir']);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function respostaClaude(array $dados, string $stop = 'end_turn'): array
    {
        return ['id' => 'msg_teste', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => $stop,
            'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => json_encode($dados)]],
            'usage' => ['input_tokens' => 1200, 'cache_read_input_tokens' => 800, 'output_tokens' => 300]];
    }

    #[Test]
    public function desligado_por_omissao_sem_chave_explica_como_activar_e_activar_exige_permissao(): void
    {
        Http::fake();
        $this->getJson('/api/contabilidade/assistente', $this->contabilista)->assertOk()->assertJsonPath('dados.ativo', false)
            ->assertJsonPath('dados.configurado', true)->assertJsonPath('dados.modelo', 'claude-opus-5-5');
        $this->postJson('/api/contabilidade/assistente/propor', ['texto' => 'Pagamento de combustível 10 000 Kz', 'motor' => 'ia'], $this->contabilista)
            ->assertStatus(422)->assertJsonPath('codigo', 'IA_DESACTIVADA');
        $this->putJson('/api/contabilidade/assistente/configuracao', ['ativo' => true], $this->contabilista)->assertForbidden();

        $admin = $this->sessao(['config_empresas_gerir']);
        $this->putJson('/api/contabilidade/assistente/configuracao', ['ativo' => true], $admin)->assertOk()->assertJsonPath('dados.ativo', true);
        $this->assertTrue(DB::table('logs_auditoria')->where('acao', 'Activar assistente IA')->exists());

        config(['assistente_ia.chave' => null]);
        $this->getJson('/api/contabilidade/assistente', $this->contabilista)->assertJsonPath('dados.configurado', false);
        $this->postJson('/api/contabilidade/assistente/propor', ['texto' => 'Combustível', 'motor' => 'ia'], $this->contabilista)
            ->assertStatus(422)->assertJsonPath('codigo', 'IA_SEM_CHAVE');
        Http::assertNothingSent();
    }

    #[Test]
    public function propoe_com_claude_valida_as_contas_e_nao_grava_nada_com_dados_minimizados(): void
    {
        $this->putJson('/api/contabilidade/assistente/configuracao', ['ativo' => true], $this->sessao(['config_empresas_gerir']))->assertOk();
        Http::fake([self::URL => Http::response($this->respostaClaude(['observacoes' => '', 'propostas' => [[
            'diario_codigo' => 'CX', 'data_documento' => '2026-10-05', 'numero_documento' => 'FT 12', 'descricao' => 'Combustível viatura',
            'justificacao' => 'Despesa de combustível paga em numerário.',
            'linhas' => [
                ['codigo_conta' => '6243', 'tipo_dc' => 'D', 'valor' => 8771.93, 'descricao' => 'Combustível'],
                ['codigo_conta' => '3452', 'tipo_dc' => 'D', 'valor' => 1228.07, 'descricao' => 'IVA'],
                ['codigo_conta' => '4511', 'tipo_dc' => 'C', 'valor' => 10000, 'descricao' => 'Pagamento'],
            ],
        ], [
            'diario_codigo' => 'ZZ', 'data_documento' => 'ontem', 'numero_documento' => '', 'descricao' => 'Mal formada', 'justificacao' => '',
            'linhas' => [['codigo_conta' => '62', 'tipo_dc' => 'D', 'valor' => 5, 'descricao' => ''], ['codigo_conta' => '9999', 'tipo_dc' => 'C', 'valor' => 4, 'descricao' => '']],
        ]]]))]);

        $pdf = UploadedFile::fake()->createWithContent('factura.pdf', "%PDF-1.4\nfactura de teste\n%%EOF");
        $r = $this->post('/api/contabilidade/assistente/propor', ['texto' => 'Factura de combustível em anexo', 'ficheiro' => $pdf], $this->contabilista + ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('dados.motor', 'IA')->assertJsonCount(2, 'dados.propostas');
        $boa = $r->json('dados.propostas.0');
        $this->assertSame([true, '10000.00', [], 'FT 12', '2026-10-05'], [$boa['equilibrado'], $boa['debito'], $boa['avisos'], $boa['numero_documento'], $boa['data_documento']]);
        $this->assertSame(DB::table('diarios_contabeis')->value('id'), $boa['diario_id']);
        $this->assertSame('Combustíveis', $boa['linhas'][0]['descricao_conta']);
        $ma = $r->json('dados.propostas.1');
        $this->assertFalse($ma['equilibrado']);
        $this->assertNull($ma['diario_id']);
        $avisos = implode(' | ', $ma['avisos']);
        foreach (['diário ZZ', 'totalizadora', '9999 não existe', 'não estão equilibrados', 'data de hoje'] as $t) {
            $this->assertStringContainsString($t, $avisos);
        }

        // nada gravado; utilização registada com tokens e custo (sem o conteúdo)
        $this->assertSame(0, DB::table('lancamentos_contabeis')->count());
        $uso = DB::table('utilizacoes_assistente_ia')->first();
        $this->assertSame(['IA', 'SUCESSO', 2, 2000, 300, 'application/pdf'], [$uso->motor, $uso->estado, $uso->propostas, $uso->tokens_entrada, $uso->tokens_saida, $uso->tipo_ficheiro]);
        $this->assertEqualsWithDelta(2000 / 1e6 * 4 + 300 / 1e6 * 20, (float) $uso->custo_estimado_usd, 1e-6);

        Http::assertSent(function (PedidoHttp $p) {
            $corpo = $p->data();
            $sistema = $corpo['system'][0]['text'];
            $this->assertSame(['chave-de-teste-nao-real', '2023-06-01', 'server-side-fallback-2026-07-01'], [$p->header('x-api-key')[0], $p->header('anthropic-version')[0], $p->header('anthropic-beta')[0]]);
            $this->assertSame('claude-opus-5-5', $corpo['model']);
            $this->assertSame('json_schema', $corpo['output_config']['format']['type']);
            $this->assertSame('default', $corpo['fallbacks']);
            $this->assertArrayNotHasKey('thinking', $corpo);
            $this->assertSame('document', $corpo['messages'][0]['content'][0]['type']);
            $this->assertSame('application/pdf', $corpo['messages'][0]['content'][0]['source']['media_type']);
            // minimização: contas de movimento e diários sim; nome/NIF da empresa, terceiros e contas totalizadoras não
            $this->assertStringContainsString('6243: Combustíveis', $sistema);
            $this->assertStringContainsString('CX: Caixa', $sistema);
            $this->assertStringContainsString('Combustível vai sempre para a conta 6243', $sistema);
            foreach (['Empresa Sigilosa', '5999888777', 'Cliente Confidencial', '5000111222', '62: Fornecimentos'] as $proibido) {
                $this->assertStringNotContainsString($proibido, json_encode($corpo, JSON_UNESCAPED_UNICODE));
            }

            return true;
        });
    }

    #[Test]
    public function recusa_e_falhas_do_fornecedor_dao_erro_claro_e_ficam_registadas(): void
    {
        $this->putJson('/api/contabilidade/assistente/configuracao', ['ativo' => true], $this->sessao(['config_empresas_gerir']))->assertOk();
        Http::fake([self::URL => Http::sequence()
            ->push($this->respostaClaude(['propostas' => [], 'observacoes' => ''], 'refusal'))
            ->push(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401)]);
        $this->postJson('/api/contabilidade/assistente/propor', ['texto' => 'Algo', 'motor' => 'ia'], $this->contabilista)->assertStatus(422)->assertJsonPath('codigo', 'IA_RECUSA');
        $this->postJson('/api/contabilidade/assistente/propor', ['texto' => 'Algo', 'motor' => 'ia'], $this->contabilista)->assertStatus(502)->assertJsonPath('codigo', 'IA_CHAVE_INVALIDA');
        $this->assertSame(['RECUSA', 'FALHA'], DB::table('utilizacoes_assistente_ia')->orderBy('id')->pluck('estado')->all());
        $this->postJson('/api/contabilidade/assistente/propor', ['texto' => ''], $this->contabilista)->assertStatus(422)->assertJsonPath('codigo', 'IA_SEM_CONTEUDO');
    }

    #[Test]
    public function regras_internas_crud_e_motor_local_sem_envio_a_terceiros(): void
    {
        Http::fake();
        $leitor = $this->sessao(['lancamentos_post']);
        $this->postJson('/api/contabilidade/assistente/regras', ['nome' => 'X', 'palavras_chave' => 'a', 'modelo' => '[]'], $leitor)->assertForbidden();
        $this->postJson('/api/contabilidade/assistente/regras', ['nome' => 'Mau', 'palavras_chave' => 'gasóleo', 'modelo' => '{"x":1}'], $this->contabilista)
            ->assertStatus(422)->assertJsonPath('codigo', 'IA_REGRA_INVALIDA');
        $id = $this->postJson('/api/contabilidade/assistente/regras', ['nome' => 'Combustível', 'palavras_chave' => 'gasóleo, combustível',
            'modelo' => [['account_code' => '6243', 'type_dc' => 'D'], ['account_code' => '3452', 'type_dc' => 'D', 'percent' => 14], ['codigo_conta' => '4511', 'tipo_dc' => 'C']]],
            $this->contabilista)->assertCreated()->json('dados.id');
        $this->getJson('/api/contabilidade/assistente/regras', $leitor)->assertOk()->assertJsonCount(1, 'dados');

        // o motor local é tentado primeiro (auto), mesmo com a IA desligada; valor = maior montante, datas ignoradas
        $r = $this->postJson('/api/contabilidade/assistente/propor', ['texto' => 'Abastecimento de Gasóleo em 05/10/2026: 25 000,00 Kz'], $leitor)
            ->assertOk()->assertJsonPath('dados.motor', 'REGRAS')->assertJsonPath('dados.regra', 'Combustível');
        $this->assertSame([25000.0, 3500.0, 25000.0], array_column($r->json('dados.propostas.0.linhas'), 'valor'));
        $this->postJson('/api/contabilidade/assistente/propor', ['texto' => 'Renda do escritório 100 000', 'motor' => 'regras'], $leitor)
            ->assertStatus(422)->assertJsonPath('codigo', 'IA_SEM_REGRA');

        $this->putJson("/api/contabilidade/assistente/regras/{$id}", ['nome' => 'Combustível', 'palavras_chave' => 'diesel',
            'modelo' => '[{"account_code":"6243","type_dc":"D"},{"account_code":"4511","type_dc":"C"}]'], $this->contabilista)->assertOk()->assertJsonPath('dados.palavras_chave', 'diesel');
        $this->deleteJson("/api/contabilidade/assistente/regras/{$id}", [], $this->contabilista)->assertOk();
        $this->assertSame(0, DB::table('regras_internas_ia')->count());
        Http::assertNothingSent();
    }
}
