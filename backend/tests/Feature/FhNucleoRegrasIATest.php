<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Services\Integracoes\IA\Regras\AvaliadorExpressoes;
use App\Services\Integracoes\IA\Regras\ErroExpressao;
use Illuminate\Http\Client\Request as PedidoHttp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase H (Versão Actual): Assistente IA › Regras em ficheiro JSON (ficheiros_regras_ia). Importação validada por esquema,
 * versões, permissões ia_assistente_view / ia_regras / ia_lancar (antes da validação), isolamento por empresa, motor interno
 * (cálculo no servidor) e IA externa que só extrai valores (cliente HTTP falso). Nada é gravado no diário. Dados fictícios.
 */
final class FhNucleoRegrasIATest extends TestCase
{
    private const URL_IA = 'https://api.anthropic.com/v1/messages';

    private const BASE = '/api/contabilidade/assistente/ficheiros-regras';

    private Empresa $empresa;

    private array $gestor;

    private array $leitor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['assistente_ia.chave' => 'chave-ficticia-de-teste']);
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa Fictícia Regras, Lda', 'nif' => '5000999111']);
        $e = $this->empresa->id;
        DB::table('plano_contas')->insert([
            ['empresa_id' => $e, 'codigo' => '43.1', 'descricao' => 'Caixa fictícia', 'tipo' => 'M'],
            ['empresa_id' => $e, 'codigo' => '43.2', 'descricao' => 'Banco fictício', 'tipo' => 'M'],
            ['empresa_id' => $e, 'codigo' => '71.1', 'descricao' => 'Vendas', 'tipo' => 'M'],
            ['empresa_id' => $e, 'codigo' => '34.5', 'descricao' => 'IVA liquidado', 'tipo' => 'T'],
        ]);
        DB::table('diarios_contabeis')->insert(['empresa_id' => $e, 'codigo' => '01', 'nome' => 'Vendas']);
        $this->gestor = $this->sessao(['ia_assistente_view', 'ia_regras', 'ia_lancar']);
        $this->leitor = $this->sessao(['ia_assistente_view']);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    /** Regra fictícia no formato do legado (modelo do iaRegraModelo). */
    private static function regra(array $alterar = []): array
    {
        return array_replace_recursive([
            'rule_name' => 'fecho_caixa_ficticio',
            'description' => 'Fecho de caixa diário (teste)',
            'entity' => ['company' => 'Empresa Fictícia', 'unit' => 'Loja 1', 'vat_rate' => 0.14],
            'principles' => ['Não inventar valores'],
            'source' => ['document' => 'Fecho de caixa'],
            'payment_mapping' => [
                ['method' => ['Numerário', 'Dinheiro'], 'account_code' => '43.1', 'type_dc' => 'D'],
                ['method' => ['Multibanco', 'TPA'], 'account_code' => '43.2', 'type_dc' => 'D'],
            ],
            'revenue' => ['vendas_gross' => 'total_vendas_com_iva', 'net' => 'round(gross / 1.14, 2)', 'vat' => 'gross - net'],
            'entry' => ['reference' => 'FC/AA/MM/DD', 'omit_zero_lines' => true, 'journal_code' => '01', 'lines' => [
                ['order' => 1, 'account_code' => '43.1', 'type_dc' => 'D', 'description' => 'Dinheiro', 'note_statement' => '10', 'note_cash_flow' => '111', 'value' => 'total_numerario'],
                ['order' => 2, 'account_code' => '43.2', 'type_dc' => 'D', 'description' => 'Multibanco', 'value' => 'total_multibanco'],
                ['order' => 3, 'account_code' => '71.1', 'type_dc' => 'C', 'description' => 'Vendas', 'value' => 'vendas_net'],
                ['order' => 4, 'account_code' => '34.5', 'type_dc' => 'C', 'description' => 'IVA liquidado', 'value' => 'vendas_vat'],
            ]],
            'validations' => [
                ['rule' => 'soma_metodos_pagamento == total_vendas_com_iva'],
                ['rule' => 'total_debito == total_credito', 'tolerance' => 0],
                ['rule' => 'note_cash_flow 111 apenas na conta 43.1'],
                ['rule' => 'lançamento registado no diário 01; número atribuído pelo sistema'],
            ],
        ], $alterar);
    }

    private function importar(array $regra, array $s, array $extra = [], string $nome = 'fecho.json')
    {
        $f = UploadedFile::fake()->createWithContent($nome, json_encode($regra, JSON_UNESCAPED_UNICODE));

        return $this->post(self::BASE, ['ficheiro' => $f] + $extra, $s + ['Accept' => 'application/json']);
    }

    #[Test]
    public function avaliador_so_aceita_aritmetica_e_as_funcoes_da_lista_branca(): void
    {
        $this->assertSame(14.0, AvaliadorExpressoes::avaliar('2 + 3 * 4', []));
        $this->assertSame(2.0, AvaliadorExpressoes::avaliar('max(1,2)', []));            // no legado lia-se max(1.2)
        $this->assertSame(3.0, AvaliadorExpressoes::avaliar('1,5 * 2', []));
        $this->assertSame(877.19, AvaliadorExpressoes::avaliar('round(g / 1.14, 2)', ['g' => 1000]));
        $this->assertSame(6.0, AvaliadorExpressoes::avaliar('sum(1, 2, abs(-3))', []));
        $this->assertTrue(AvaliadorExpressoes::eExpressao('total_a - (b * 2)'));
        $this->assertFalse(AvaliadorExpressoes::eExpressao('soma das bebidas e dos pratos'));
        $this->assertFalse(AvaliadorExpressoes::eExpressao('system("ls")'));
        foreach (['exec(1)', '1 / 0', 'x + 1', str_repeat('(', 60).'1'.str_repeat(')', 60), '2 ** 3', '$a'] as $mau) {
            try {
                AvaliadorExpressoes::avaliar($mau, []);
                $this->fail("Devia recusar: {$mau}");
            } catch (ErroExpressao) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function permissoes_sao_verificadas_antes_da_validacao_e_por_empresa(): void
    {
        $sem = $this->sessao(['lancamentos_view']);
        $this->getJson(self::BASE, $sem)->assertForbidden();
        $this->postJson(self::BASE, [], $sem)->assertForbidden();             // 403 e não 422: a permissão vem antes
        $this->postJson(self::BASE, [], $this->leitor)->assertForbidden();
        $this->postJson(self::BASE.'/1/calcular', [], $this->leitor)->assertForbidden();
        $this->getJson(self::BASE, $this->leitor)->assertOk()->assertJsonPath('dados', []);

        $id = $this->importar(self::regra(), $this->gestor)->assertCreated()->json('dados.id');
        $this->patchJson(self::BASE."/{$id}", ['ativo' => false], $this->leitor)->assertForbidden();
        $this->deleteJson(self::BASE."/{$id}", [], $this->leitor)->assertForbidden();

        // outra empresa não vê, não altera e não calcula a regra
        $outra = $this->criarEmpresa(['nome' => 'Outra Fictícia', 'nif' => '5000999222']);
        $s2 = $this->sessao(['ia_assistente_view', 'ia_regras', 'ia_lancar'], $outra);
        $this->getJson(self::BASE, $s2)->assertOk()->assertJsonPath('dados', []);
        $this->getJson(self::BASE."/{$id}", $s2)->assertNotFound();
        $this->patchJson(self::BASE."/{$id}", ['ativo' => false], $s2)->assertNotFound();
        $this->postJson(self::BASE."/{$id}/calcular", ['dias' => [['data' => '2026-10-14', 'valores' => []]]], $s2)->assertNotFound();
        $this->deleteJson(self::BASE."/{$id}", [], $s2)->assertNotFound();
        $this->assertSame(1, DB::table('ficheiros_regras_ia')->count());
    }

    #[Test]
    public function importa_valida_o_esquema_versiona_e_exporta_o_original(): void
    {
        $r = $this->importar(self::regra() + ['notas_internas' => 'x'], $this->gestor)->assertCreated()
            ->assertJsonPath('dados.versao', 1)->assertJsonPath('dados.motor', 'AMBOS')->assertJsonPath('dados.ativo', true)->json('dados');
        $this->assertStringContainsString('notas_internas', $r['avisos'][0]);
        $this->assertSame('fecho_caixa_ficticio', DB::table('ficheiros_regras_ia')->value('nome_regra'));

        // mesma rule_name: só substitui com confirmação, e a versão sobe
        $this->importar(self::regra(['description' => 'v2']), $this->gestor)->assertStatus(409)->assertJsonPath('codigo', 'IA_REGRA_EXISTE');
        $this->importar(self::regra(['description' => 'v2']), $this->gestor, ['substituir' => '1'])->assertOk()->assertJsonPath('dados.versao', 2);
        $this->assertSame(1, DB::table('ficheiros_regras_ia')->count());
        $this->assertTrue(DB::table('logs_auditoria')->where('acao', 'Importar regra')->exists());

        $id = $r['id'];
        $exp = $this->getJson(self::BASE."/{$id}/exportar", $this->leitor)->assertOk()->json('dados');
        $this->assertSame('fecho.json', $exp['ficheiro']);
        $this->assertSame('v2', json_decode($exp['conteudo'], true)['description']);

        // ficheiros recusados (nada é gravado)
        $maus = [
            ['{ isto não é json', 'não é um JSON válido'],
            ['[1, 2]', 'objecto JSON'],
            [json_encode(['rule_name' => 'x']), 'entry'],
            [json_encode(self::regra(['entry' => ['lines' => [['account_code' => '43.1', 'type_dc' => 'X', 'value' => '1']]]])), 'type_dc'],
            [json_encode(array_replace(self::regra(), ['entry' => ['lines' => [['account_code' => '43.1', 'type_dc' => 'D', 'value' => 'soma das vendas do dia']]]])), 'expressão'],
            [json_encode(array_replace(self::regra(), ['entry' => ['lines' => [['account_code' => '43.1', 'type_dc' => 'D', 'value' => 'shell_exec(1)']]]])), 'expressão'],
        ];
        foreach ($maus as [$conteudo, $texto]) {
            $res = $this->postJson(self::BASE, ['conteudo' => $conteudo, 'ficheiro' => 'mau.json'], $this->gestor)->assertStatus(422);
            $this->assertStringContainsString($texto, (string) $res->json('mensagem'));
        }
        $grande = UploadedFile::fake()->createWithContent('grande.json', '{"a":"'.str_repeat('x', 270000).'"}');
        $this->post(self::BASE, ['ficheiro' => $grande], $this->gestor + ['Accept' => 'application/json'])->assertStatus(422);
        $txt = UploadedFile::fake()->createWithContent('regra.txt', json_encode(self::regra(['rule_name' => 'outra'])));
        $this->post(self::BASE, ['ficheiro' => $txt], $this->gestor + ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(1, DB::table('ficheiros_regras_ia')->count());

        // modelo descarregável é ele próprio válido
        $modelo = $this->getJson(self::BASE.'/modelo', $this->leitor)->assertOk()->json('dados.conteudo');
        $this->postJson(self::BASE, ['conteudo' => $modelo, 'ficheiro' => 'modelo.json'], $this->gestor)->assertCreated();
    }

    #[Test]
    public function lista_e_detalhe_mostram_contas_em_falta_e_os_valores_a_pedir(): void
    {
        $id = $this->importar(self::regra(['entry' => ['lines' => [3 => ['account_code' => '99.9']]]]), $this->gestor)->assertCreated()->json('dados.id');
        $l = $this->getJson(self::BASE, $this->leitor)->assertOk()->json('dados.0');
        $this->assertSame(['99.9'], $l['contas_em_falta']);
        $this->assertSame(4, $l['linhas']);
        $this->assertNull($l['problema']);

        $d = $this->getJson(self::BASE."/{$id}", $this->leitor)->assertOk()->json('dados');
        $this->assertSame(['total_numerario', 'total_multibanco', 'total_vendas_com_iva'], array_column($d['entradas'], 'nome'));
        $this->assertSame(['vendas_gross', 'vendas_net', 'vendas_vat'], $d['calculadas']);
        // a validação escrita por extenso não gera campos («lançamento», «registado», …)
        $this->assertNotContains('registado', array_column($d['entradas'], 'nome'));

        // regra migrada com o formato do ficheiro real (document_number.format, receita em prosa): fictícia
        $real = self::regra(['rule_name' => 'hotel_ficticio', 'revenue' => ['food_gross' => 'soma das bebidas e pratos', 'rooms_gross' => 'total_vendas_com_iva - food_gross',
            'net' => 'round(gross / 1.07, 2)', 'vat' => 'gross - net', 'flag_if_printed_net_differs' => true]]);
        unset($real['entry']['reference']);
        $real['entry']['document_number'] = ['format' => 'FC/AA/MM/DD', 'subtract_days' => 0];
        $real['entry']['lines'][2]['value'] = 'food_net + rooms_net';
        $real['entry']['lines'][3]['value'] = 'food_vat + rooms_vat';
        $id2 = $this->importar($real, $this->gestor, [], 'hotel.json')->assertCreated()->json('dados.id');
        $d2 = $this->getJson(self::BASE."/{$id2}", $this->leitor)->assertOk()->json('dados');
        $food = collect($d2['entradas'])->firstWhere('nome', 'food_gross');
        $this->assertSame('soma das bebidas e pratos', $food['ajuda']);
        $c = $this->postJson(self::BASE."/{$id2}/calcular", ['dias' => [['data' => '2026-09-22', 'valores' => [
            'total_numerario' => 1070, 'total_multibanco' => 1070, 'total_vendas_com_iva' => 2140, 'food_gross' => 1070]]]], $this->gestor)->assertOk()->json('dados.dias.0');
        $this->assertSame('FC/26/09/22', $c['referencia']);
        $this->assertSame([], $c['erros']);
        $this->assertSame(['2140.00', '2140.00'], [$c['total_debito'], $c['total_credito']]);
    }

    #[Test]
    public function motor_interno_calcula_valida_e_propoe_sem_gravar(): void
    {
        $id = $this->importar(self::regra(), $this->gestor)->assertCreated()->json('dados.id');
        $r = $this->postJson(self::BASE."/{$id}/calcular", ['dias' => [
            ['data' => '2026-10-14', 'valores' => ['total_numerario' => 800, 'total_multibanco' => 340, 'total_vendas_com_iva' => 1140],
                'outros' => [['metodo' => 'Cheque', 'valor' => 50]], 'ilegivel' => 'talão 3', 'net_impresso' => 999],
            ['data' => '2026-10-15', 'valores' => ['total_numerario' => 800, 'total_multibanco' => 340, 'total_vendas_com_iva' => 1200]],
        ]], $this->gestor)->assertOk()->assertJsonPath('dados.propostas_validas', 1)->json('dados');

        $dia = $r['dias'][0];
        $this->assertSame('FC/26/10/14', $dia['referencia']);
        $this->assertSame([['43.1', 'D', 800.0], ['43.2', 'D', 340.0], ['71.1', 'C', 1000.0], ['34.5', 'C', 140.0]],
            array_map(fn ($l) => [$l['codigo_conta'], $l['tipo_dc'], (float) $l['valor']], $dia['linhas']));
        $this->assertSame([true, true, true, null], array_column($dia['validacoes'], 'ok'));
        $this->assertSame([], $dia['erros']);
        $pontos = implode(' | ', $dia['pontos']);
        foreach (['Cheque', 'talão 3', 'difere do calculado'] as $t) {
            $this->assertStringContainsString($t, $pontos);
        }
        $p = $dia['proposta'];
        $this->assertSame(['REGRA_FICHEIRO', true, '1140.00', 'FC/26/10/14', '2026-10-14'], [$p['origem'], $p['equilibrado'], $p['debito'], $p['numero_documento'], $p['data_documento']]);
        $this->assertSame(DB::table('diarios_contabeis')->value('id'), $p['diario_id']);
        $this->assertSame('111', $p['linhas'][0]['nota_fluxo']);
        $this->assertStringContainsString('totalizadora', implode(' ', $p['avisos']));   // 34.5 é T neste plano fictício

        // segundo dia: métodos ≠ total e D ≠ C → erros, sem proposta
        $mau = $r['dias'][1];
        $this->assertNull($mau['proposta']);
        $this->assertStringContainsString('não fecha', implode(' ', $mau['erros']));
        $this->assertFalse($mau['validacoes'][0]['ok']);

        $this->assertSame(0, DB::table('lancamentos_contabeis')->count());
        $this->assertSame('REGRAS', DB::table('utilizacoes_assistente_ia')->value('motor'));

        // inactiva ou só para a IA externa: o motor interno recusa
        $this->patchJson(self::BASE."/{$id}", ['motor' => 'EXTERNA'], $this->gestor)->assertOk()->assertJsonPath('dados.motor', 'EXTERNA');
        $this->postJson(self::BASE."/{$id}/calcular", ['dias' => [['data' => '2026-10-14']]], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'IA_REGRA_MOTOR');
        $this->patchJson(self::BASE."/{$id}", ['motor' => 'AMBOS', 'ativo' => false], $this->gestor)->assertOk();
        $this->postJson(self::BASE."/{$id}/calcular", ['dias' => [['data' => '2026-10-14']]], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'IA_REGRA_INACTIVA');
        $this->patchJson(self::BASE."/{$id}", ['motor' => 'QUALQUER'], $this->gestor)->assertStatus(422);
        $this->postJson(self::BASE."/{$id}/calcular", ['dias' => [['data' => '14/10/2026', 'valores' => ['x' => 'abc']]]], $this->gestor)->assertStatus(422);

        $this->deleteJson(self::BASE."/{$id}", [], $this->gestor)->assertOk();
        $this->assertSame(0, DB::table('ficheiros_regras_ia')->count());
    }

    #[Test]
    public function ia_externa_so_extrai_valores_e_o_calculo_e_local_com_a_chave_no_servidor(): void
    {
        $id = $this->importar(self::regra(), $this->gestor)->assertCreated()->json('dados.id');
        $pdf = fn () => UploadedFile::fake()->createWithContent('fecho.pdf', "%PDF-1.4\nfecho ficticio\n%%EOF");
        Http::fake([self::URL_IA => Http::response(['id' => 'msg', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode(['dias' => [[
                'data' => '2026-10-14', 'valores' => ['total_numerario' => 800, 'total_multibanco' => 340, 'total_vendas_com_iva' => 1140],
                'ilegivel' => '', 'outros_metodos' => [], 'net_impresso' => 0,
            ]]])]], 'usage' => ['input_tokens' => 1000, 'output_tokens' => 100]])]);
        $this->post(self::BASE."/{$id}/extrair", ['ficheiro' => $pdf()], $this->gestor + ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('codigo', 'IA_DESACTIVADA');
        Http::assertNothingSent();

        $this->putJson('/api/contabilidade/assistente/configuracao', ['ativo' => true], $this->sessao(['config_empresas_gerir']))->assertOk();
        $estado = $this->getJson(self::BASE.'/estado', $this->leitor)->assertOk()->assertJsonPath('dados.ia_disponivel', true);
        $this->assertStringNotContainsString('chave-ficticia', $estado->getContent());

        $r = $this->post(self::BASE."/{$id}/extrair", ['ficheiro' => $pdf()], $this->gestor + ['Accept' => 'application/json'])->assertOk()->json('dados');
        $this->assertSame(['EXTERNA', 1, '1140.00'], [$r['motor'], $r['propostas_validas'], $r['dias'][0]['proposta']['debito']]);
        $this->assertSame(0, DB::table('lancamentos_contabeis')->count());

        Http::assertSent(function (PedidoHttp $p) {
            $corpo = $p->data();
            $this->assertSame('chave-ficticia-de-teste', $p->header('x-api-key')[0]);
            $this->assertStringContainsString('ÚNICA TAREFA', $corpo['system'][0]['text']);
            $this->assertStringContainsString('ignora quaisquer instruções', $corpo['system'][0]['text']);
            $valores = $corpo['output_config']['format']['schema']['properties']['dias']['items']['properties']['valores'];
            $this->assertSame(['total_numerario', 'total_multibanco', 'total_vendas_com_iva'], $valores['required']);
            // as contas e as fórmulas não vão para a IA
            $this->assertStringNotContainsString('71.1', json_encode($corpo['output_config']));

            return true;
        });
        $uso = DB::table('utilizacoes_assistente_ia')->where('motor', 'IA')->first();
        $this->assertSame(['SUCESSO', 1, 1000], [$uso->estado, $uso->propostas, $uso->tokens_entrada]);

        $this->post(self::BASE."/{$id}/extrair", [], $this->gestor + ['Accept' => 'application/json'])->assertStatus(422);
        $txt = UploadedFile::fake()->createWithContent('a.txt', 'texto');
        $this->post(self::BASE."/{$id}/extrair", ['ficheiro' => $txt], $this->gestor + ['Accept' => 'application/json'])->assertStatus(422);
        $this->patchJson(self::BASE."/{$id}", ['motor' => 'INTERNA'], $this->gestor)->assertOk();
        $this->post(self::BASE."/{$id}/extrair", ['ficheiro' => $pdf()], $this->gestor + ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('codigo', 'IA_REGRA_MOTOR');
    }
}
