<?php

namespace Tests\Feature;

use App\Models\Banco;
use App\Models\Empresa;
use App\Models\EnderecoTerceiro;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Services\Terceiros\ContaTerceiro;
use App\Services\Terceiros\ServicoContasBancariasTerceiros;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase A (Versão Actual) — terceiros: contas por papel (contas_terceiro.js), endereço estruturado, contas bancárias
 * (ui_aux.js:920-1005) e integrações sem contas por defeito. Dados fictícios.
 */
final class FaTerceirosContasTest extends TestCase
{
    private Empresa $empresa;

    private array $h;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000099']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            DB::table('moedas')->insert([['codigo' => 'AOA', 'nome' => 'Kwanza'], ['codigo' => 'USD', 'nome' => 'Dólar']]);
            foreach ([['31', 'Clientes', 'T'], ['3111', 'Clientes nacionais', 'M'], ['3112', 'Clientes estrangeiros', 'M'], ['32', 'Fornecedores', 'T'],
                ['3211', 'Fornecedores nacionais', 'M'], ['3701', 'Outros credores', 'M'], ['3452', 'IVA liquidado', 'M'], ['3451', 'IVA dedutível', 'M'],
                ['611', 'Vendas', 'M'], ['752', 'Fornecimentos e serviços', 'M'], ['451', 'Depósitos à ordem', 'M']] as [$c, $d, $t]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => $t]);
            }
        });
        $this->h = $this->sessao(['aux_gerir', 'tabelas_aux_view', 'vendas_clientes_gerir', 'compras_forn_gerir', 'vendas_faturacao_view', 'vendas_fat_emitir',
            'vendas_fat_contabilizar', 'compras_faturacao_view', 'compras_fact_registar', 'compras_fact_contabilizar']);
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function naEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function aux(array $dados, ?int $id = null): TestResponse
    {
        $corpo = $dados + ['papel' => 'CLIENTE', 'substituir_tipo' => true, 'nome' => 'Entidade Fictícia', 'nif' => '5000000001'];

        return $id ? $this->putJson("/api/terceiros/{$id}", $corpo, $this->h) : $this->postJson('/api/terceiros', $corpo, $this->h);
    }

    #[Test]
    public function regras_de_leitura_e_classe_iguais_ao_legado(): void
    {
        $this->assertTrue(ContaTerceiro::classeCorrecta('31.1.1', 'CLIENTE'));
        $this->assertFalse(ContaTerceiro::classeCorrecta('3211', 'CLIENTE'));
        $this->assertTrue(ContaTerceiro::classeCorrecta('37.0.1', 'FORNECEDOR'));
        $this->assertTrue(ContaTerceiro::classeCorrecta('3711', 'FORNECEDOR'));   // 37.1: outros devedores, também aceite nos fornecedores
        $this->assertTrue(ContaTerceiro::classeCorrecta('37921', 'FORNECEDOR'));   // 37.92
        $this->assertFalse(ContaTerceiro::classeCorrecta('6111', 'FORNECEDOR'));
        $this->assertTrue(ContaTerceiro::classeCorrecta('37911', 'CLIENTE'));   // 37.91, também aceite nos clientes
        $this->assertTrue(ContaTerceiro::classeCorrecta('3721', 'CLIENTE'));   // 37.2
        $this->assertFalse(ContaTerceiro::classeCorrecta('3711', 'CLIENTE'));
        // a conta própria primeiro; o codigo_conta antigo só serve se for da classe do papel
        $this->assertSame('3112', ContaTerceiro::cliente(['conta_cliente' => '3112', 'codigo_conta' => '3111']));
        $this->assertSame('3111', ContaTerceiro::cliente(['codigo_conta' => '3111']));
        $this->assertSame('', ContaTerceiro::fornecedor(['codigo_conta' => '3111']));
        $this->assertSame('3701', ContaTerceiro::fornecedor(['codigo_conta' => '3701']));
        $this->assertSame('Cliente e Fornecedor', ContaTerceiro::rotuloTipo('CLIENTE_FORNECEDOR'));
        $this->assertTrue(ServicoContasBancariasTerceiros::ibanValido('AO30 0040 0000 1234 5678 9012 3'));
        $this->assertFalse(ServicoContasBancariasTerceiros::ibanValido('AO31004000001234567890123'));
        $this->assertTrue(ServicoContasBancariasTerceiros::ibanValido('PT50 0002 0123 1234 5678 9015 4'));
    }

    #[Test]
    public function tabelas_auxiliares_validam_as_contas_de_cada_papel(): void
    {
        $base = ['papel' => 'CLIENTE_FORNECEDOR'];
        $this->aux($base + ['conta_cliente' => '3111'])->assertStatus(422)->assertJsonPath('codigo', 'CONTA_FORNECEDOR_OBRIGATORIA');
        $this->aux($base + ['conta_fornecedor' => '3211'])->assertStatus(422)->assertJsonPath('codigo', 'CONTA_CLIENTE_OBRIGATORIA');
        $this->aux($base + ['conta_cliente' => '3211', 'conta_fornecedor' => '3211'])->assertStatus(422)->assertJsonPath('codigo', 'CONTA_CLASSE_ERRADA');
        $this->aux($base + ['conta_cliente' => '3111', 'conta_fornecedor' => '3111'])->assertStatus(422)->assertJsonPath('codigo', 'CONTA_CLASSE_ERRADA');
        $this->aux($base + ['conta_cliente' => '31', 'conta_fornecedor' => '3211'])->assertStatus(422)->assertJsonPath('codigo', 'CONTA_TOTALIZADORA');
        $this->aux($base + ['conta_cliente' => '3119', 'conta_fornecedor' => '3211'])->assertStatus(422)->assertJsonPath('codigo', 'CONTA_INEXISTENTE');

        $id = $this->aux($base + ['conta_cliente' => '3111', 'conta_fornecedor' => '3701'])->assertCreated()
            ->assertJsonPath('dados.tipo', 'CLIENTE_FORNECEDOR')->assertJsonPath('dados.rotulo_tipo', 'Cliente e Fornecedor')
            ->assertJsonPath('dados.conta_cliente', '3111')->assertJsonPath('dados.conta_fornecedor', '3701')->json('dados.id');

        // o tipo das Tabelas auxiliares substitui o anterior (passa a só fornecedor) e a conta de cliente fica gravada
        $this->aux(['papel' => 'FORNECEDOR'], $id)->assertOk()->assertJsonPath('dados.tipo', 'FORNECEDOR')->assertJsonPath('dados.conta_cliente', '3111');
        // colaborador não pede contas
        $this->aux(['papel' => 'COLABORADOR', 'nif' => '5000000002', 'nome' => 'Colaborador Fictício'])->assertCreated()->assertJsonPath('dados.tipo', 'COLABORADOR');

        // substituir o tipo / «Cliente e Fornecedor» só com aux_gerir
        $semAux = $this->sessao(['vendas_clientes_gerir', 'compras_forn_gerir']);
        $this->postJson('/api/terceiros', ['papel' => 'CLIENTE_FORNECEDOR', 'nome' => 'X', 'nif' => '5000000003', 'conta_cliente' => '3111', 'conta_fornecedor' => '3211'], $semAux)
            ->assertForbidden();
        $this->postJson('/api/terceiros', ['papel' => 'CLIENTE', 'substituir_tipo' => true, 'nome' => 'X', 'nif' => '5000000003', 'conta_cliente' => '3111'], $semAux)
            ->assertForbidden();
    }

    #[Test]
    public function ficha_de_cliente_acrescenta_o_papel_sem_apagar_a_conta_de_fornecedor(): void
    {
        $id = $this->postJson('/api/terceiros', ['papel' => 'FORNECEDOR', 'nome' => 'Fornecedor Fictício', 'nif' => '5400000001', 'conta_fornecedor' => '3211'], $this->h)
            ->assertCreated()->assertJsonPath('dados.conta_fornecedor', '3211')->assertJsonPath('dados.conta_cliente', null)->json('dados.id');
        // ficha antiga (codigo_conta) na ficha de cliente: é a conta de cliente; a de fornecedor mantém-se (era o erro do legado)
        $this->putJson("/api/terceiros/{$id}", ['papel' => 'CLIENTE', 'codigo_conta' => '3112'], $this->h)->assertOk()
            ->assertJsonPath('dados.tipo', 'CLIENTE_FORNECEDOR')->assertJsonPath('dados.conta_cliente', '3112')->assertJsonPath('dados.conta_fornecedor', '3211');
        $this->putJson("/api/terceiros/{$id}", ['papel' => 'CLIENTE', 'conta_cliente' => '3211'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_CLASSE_ERRADA');

        // registo antigo: só codigo_conta da classe 32 → serve de conta de fornecedor, não de cliente
        $antigo = $this->naEmpresa(fn () => Terceiro::create(['nome' => 'Antigo Fictício', 'nif' => '5400000002', 'tipo' => Terceiro::CLIENTE_FORNECEDOR, 'codigo_conta' => '3211']));
        $this->getJson('/api/terceiros?pesquisa=Antigo', $this->h)->assertJsonPath('dados.0.conta_fornecedor', '3211')->assertJsonPath('dados.0.conta_cliente', null);
        // gravar como cliente sem indicar a conta de cliente: falta (o codigo_conta 32 não é de cliente)
        $this->putJson("/api/terceiros/{$antigo->id}", ['papel' => 'CLIENTE'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_CLIENTE_OBRIGATORIA');
    }

    #[Test]
    public function endereco_estruturado_fica_ligado_pelo_nif_e_compoe_o_texto(): void
    {
        $morada = ['rua' => 'Rua Fictícia', 'numero_porta' => '12', 'bairro' => 'Bairro Teste', 'municipio' => 'Município Teste', 'provincia' => 'Província Teste', 'pais' => 'Angola'];
        $id = $this->aux(['conta_cliente' => '3111', 'morada' => $morada])->assertCreated()
            ->assertJsonPath('dados.endereco', 'Rua Fictícia, n.º 12, Bairro Teste, Município Teste, Província Teste, Angola')->json('dados.id');
        $this->getJson("/api/terceiros/{$id}", $this->h)->assertOk()->assertJsonPath('dados.morada.bairro', 'Bairro Teste')->assertJsonPath('dados.morada.numero_porta', '12');

        // muda o NIF: o endereço segue-o (não se duplica)
        $this->aux(['nif' => '5000000009', 'morada' => ['bairro' => 'Outro Bairro'] + $morada], $id)->assertOk();
        $this->naEmpresa(function () {
            $this->assertSame(1, EnderecoTerceiro::query()->count());
            $this->assertSame('5000000009', EnderecoTerceiro::query()->value('nif'));
            $this->assertSame('Outro Bairro', EnderecoTerceiro::query()->value('bairro'));
        });
    }

    #[Test]
    public function contas_bancarias_com_iban_valido_e_uma_so_predefinida(): void
    {
        $id = $this->aux(['papel' => 'FORNECEDOR', 'conta_fornecedor' => '3211'])->json('dados.id');
        $banco = $this->naEmpresa(fn () => Banco::create(['nome' => 'Banco Fictício', 'codigo' => '0099']));
        $url = "/api/terceiros/{$id}/contas-bancarias";

        $this->postJson($url, ['iban' => 'AO31004000001234567890123'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'IBAN_INVALIDO');
        $this->postJson($url, [], $this->h)->assertStatus(422);
        $c1 = $this->postJson($url, ['banco_id' => $banco->id, 'iban' => 'ao30 0040 0000 1234 5678 9012 3', 'titular' => 'Titular Fictício'], $this->h)->assertCreated()
            ->assertJsonPath('dados.predefinida', true)->assertJsonPath('dados.banco', 'Banco Fictício')->assertJsonPath('dados.iban', 'AO30004000001234567890123')->json('dados.id');
        $this->postJson($url, ['iban' => 'AO30004000001234567890123'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'IBAN_REPETIDO');
        $c2 = $this->postJson($url, ['banco' => 'Outro Banco', 'iban' => 'PT50000201231234567890154', 'codigo_moeda' => 'USD'], $this->h)->assertCreated()
            ->assertJsonPath('dados.predefinida', false)->json('dados.id');

        $this->postJson("{$url}/{$c2}/predefinida", [], $this->h)->assertOk()->assertJsonPath('dados.predefinida', true);
        $this->getJson($url, $this->h)->assertOk()->assertJsonPath('dados.0.id', $c2)->assertJsonPath('dados.1.predefinida', false);
        $this->getJson('/api/terceiros?pesquisa=Entidade', $this->h)->assertJsonPath('dados.0.contas_bancarias', 2)->assertJsonPath('dados.0.banco_predefinido', 'Outro Banco');
        $this->getJson("/api/terceiros/{$id}", $this->h)->assertJsonCount(2, 'dados.lista_contas_bancarias');

        // eliminar a predefinida promove a que resta
        $this->deleteJson("{$url}/{$c2}", [], $this->h)->assertOk();
        $this->getJson($url, $this->h)->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.id', $c1)->assertJsonPath('dados.0.predefinida', true);

        // sem permissão de gestão: 403; conta de outro terceiro: 404
        $leitor = $this->sessao(['tabelas_aux_view']);
        $this->getJson($url, $leitor)->assertOk();
        $this->postJson($url, ['iban' => 'AO30004000001234567890123'], $leitor)->assertForbidden();
        $outro = $this->aux(['nif' => '5000000005', 'conta_cliente' => '3111'])->json('dados.id');
        $this->deleteJson("/api/terceiros/{$outro}/contas-bancarias/{$c1}", [], $this->h)->assertNotFound();
    }

    #[Test]
    public function contas_bancarias_de_outra_empresa_nao_sao_visiveis(): void
    {
        $id = $this->aux(['papel' => 'FORNECEDOR', 'conta_fornecedor' => '3211'])->json('dados.id');
        $this->postJson("/api/terceiros/{$id}/contas-bancarias", ['iban' => 'AO30004000001234567890123'], $this->h)->assertCreated();

        $outra = $this->criarEmpresa(['nif' => '5417000098']);
        $perfil = $this->criarPerfil(['_v2' => true, 'aux_gerir' => true, 'tabelas_aux_view' => true]);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($outra->id);
        $h2 = $this->entrar($u) + ['X-Empresa-Id' => $outra->id];
        $this->getJson("/api/terceiros/{$id}/contas-bancarias", $h2)->assertNotFound();
        $this->postJson("/api/terceiros/{$id}/contas-bancarias", ['iban' => 'PT50000201231234567890154'], $h2)->assertNotFound();
    }

    #[Test]
    public function integracao_de_venda_usa_a_conta_de_cliente_e_para_quando_falta(): void
    {
        [$cliFor, $semConta, $produto] = $this->naEmpresa(fn () => [
            Terceiro::create(['nome' => 'Cliente e Fornecedor Fictício', 'nif' => '5000000011', 'tipo' => Terceiro::CLIENTE_FORNECEDOR,
                'codigo_conta' => '3211', 'conta_cliente' => '3112', 'conta_fornecedor' => '3211']),
            Terceiro::create(['nome' => 'Cliente Sem Conta', 'nif' => '5000000012', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '3211']),
            Produto::create(['codigo' => 'P1', 'nome' => 'Produto Fictício', 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => false]),
        ]);
        $doc = fn (Terceiro $c) => $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $c->id, 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $produto->id, 'quantidade' => 1]]], $this->h);

        // sem conta de cliente (o codigo_conta antigo é de fornecedor): não se factura, e a mensagem diz que conta falta e em que terceiro
        $r = $doc($semConta)->assertStatus(422)->assertJsonPath('codigo', 'CLIENTE_INVALIDO');
        $this->assertStringContainsString('Cliente Sem Conta não tem conta de cliente (classe 31, 37.91 ou 37.2)', $r->json('mensagem'));

        $ft = $doc($cliFor)->assertCreated()->json('dados.id');
        $lan = $this->postJson("/api/vendas/documentos/{$ft}/contabilizar", [], $this->h)->assertOk()->json('dados.numero_lan_contabilizacao');
        $contas = $this->naEmpresa(fn () => LancamentoContabil::query()->where('numero_lan', $lan)->where('tipo_dc', 'D')->pluck('codigo_conta')->all());
        $this->assertSame(['3112'], $contas);

        // a conta de cliente desaparece depois de facturar: a integração pára com TERCEIRO_SEM_CONTA
        $ft2 = $doc($cliFor)->assertCreated()->json('dados.id');
        $this->naEmpresa(fn () => $cliFor->refresh()->update(['conta_cliente' => null]));
        $r = $this->postJson("/api/vendas/documentos/{$ft2}/contabilizar", [], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'TERCEIRO_SEM_CONTA')
            ->assertJsonPath('erros.campo', 'conta_cliente');
        $this->assertStringContainsString('não pode ser integrado', $r->json('mensagem'));
    }

    #[Test]
    public function integracao_de_compra_usa_a_conta_de_fornecedor(): void
    {
        [$cliFor, $produto] = $this->naEmpresa(fn () => [
            Terceiro::create(['nome' => 'Fornecedor e Cliente Fictício', 'nif' => '5000000021', 'tipo' => Terceiro::CLIENTE_FORNECEDOR,
                'codigo_conta' => '3111', 'conta_cliente' => '3111', 'conta_fornecedor' => '3701']),
            Produto::create(['codigo' => 'S1', 'nome' => 'Serviço Fictício', 'preco_unitario' => 0, 'taxa_imposto' => 14, 'movimenta_stock' => false,
                'conta_custo' => '752', 'conta_iva_dedutivel' => '3451']),
        ]);
        $f = $this->postJson('/api/compras/faturas', ['fornecedor_id' => $cliFor->id, 'numero_fatura' => 'FF-1', 'data' => $this->hoje,
            'linhas' => [['produto_id' => $produto->id, 'quantidade' => 1, 'preco_unitario' => 100, 'taxa_imposto' => 14]]], $this->h)->assertCreated()->json('dados.id');
        $lan = $this->postJson("/api/compras/faturas/{$f}/contabilizar", [], $this->h)->assertOk()->json('dados.numero_lan_contabilizacao');
        $credito = $this->naEmpresa(fn () => LancamentoContabil::query()->where('numero_lan', $lan)->where('tipo_dc', 'C')->pluck('codigo_conta')->all());
        $this->assertSame(['3701'], $credito);

        // um terceiro só com conta de cliente não serve de fornecedor
        $soCliente = $this->naEmpresa(fn () => Terceiro::create(['nome' => 'Só Cliente', 'nif' => '5000000022', 'tipo' => Terceiro::CLIENTE_FORNECEDOR, 'conta_cliente' => '3111']));
        $this->postJson('/api/compras/faturas', ['fornecedor_id' => $soCliente->id, 'numero_fatura' => 'FF-2', 'data' => $this->hoje,
            'linhas' => [['produto_id' => $produto->id, 'quantidade' => 1, 'preco_unitario' => 100]]], $this->h)
            ->assertStatus(422)->assertJsonPath('codigo', 'FORNECEDOR_INVALIDO');
    }

    #[Test]
    public function importacao_de_terceiros_com_as_duas_colunas_de_conta(): void
    {
        $h = $this->sessao(['aux_gerir', 'config_migracao_view']);
        $linhas = [
            ['NIF' => '5000000031', 'Nome' => 'Importado Cliente', 'Tipo' => 'Cliente', 'Conta_Cliente' => '3111'],
            ['NIF' => '5000000032', 'Nome' => 'Importado Ambos', 'Tipo' => 'Cliente, Fornecedor', 'Conta_Cliente' => '3112', 'Conta_Fornecedor' => '3211'],
            ['NIF' => '5000000033', 'Nome' => 'Importado Antigo', 'Tipo' => 'Fornecedor', 'Conta' => '3701'],
        ];
        $r = $this->postJson('/api/sistema/migracao/importar/terceiros', ['linhas' => $linhas, 'decisao' => 'IGNORAR', 'simular' => false], $h);
        $r->assertOk()->assertJsonPath('dados.criados', 3);
        $this->naEmpresa(function () {
            $ambos = Terceiro::query()->where('nif', '5000000032')->first();
            $this->assertSame(Terceiro::CLIENTE_FORNECEDOR, $ambos->tipo);
            $this->assertSame(['3112', '3211'], [$ambos->conta_cliente, $ambos->conta_fornecedor]);
            // ficheiro antigo: a coluna «Conta» de um fornecedor é a conta de fornecedor
            $antigo = Terceiro::query()->where('nif', '5000000033')->first();
            $this->assertSame([null, '3701'], [$antigo->conta_cliente, $antigo->conta_fornecedor]);
        });
        // fornecedor sem conta de fornecedor: a importação recusa (nada fica gravado)
        $this->postJson('/api/sistema/migracao/importar/terceiros', ['linhas' => [['NIF' => '5000000034', 'Nome' => 'Sem Conta', 'Tipo' => 'Fornecedor']],
            'decisao' => 'IGNORAR', 'simular' => false], $h)->assertStatus(422)->assertJsonPath('codigo', 'IMPORTACAO_COM_ERROS');
        $this->getJson('/api/sistema/migracao/modelos', $h)->assertOk()->assertJsonFragment(['cabecalho' => 'Conta_Fornecedor']);
    }

    #[Test]
    public function a_lista_ordena_em_portugues_e_ignora_espacos_iniciais(): void
    {
        $this->naEmpresa(function () {
            foreach (['zeta Fictícia', ' Beta Fictícia', 'Ávila Fictícia', '	Carlos Fictício', 'Bárbara  Zulmira', 'alfa Fictícia'] as $i => $nome) {
                DB::table('terceiros')->insert(['empresa_id' => $this->empresa->id, 'nome' => $nome, 'nif' => '50000001'.$i, 'tipo' => 'CLIENTE']);
            }
        });
        $nomes = array_map('trim', array_column($this->getJson('/api/terceiros?por_pagina=50', $this->h)->assertOk()->json('dados'), 'nome'));

        $this->assertSame(['alfa Fictícia', 'Ávila Fictícia', 'Bárbara  Zulmira', 'Beta Fictícia', 'Carlos Fictício', 'zeta Fictícia'], $nomes);
    }

    #[Test]
    public function seletores_estritos_mostram_so_pelo_papel_como_o_legado(): void
    {
        [$forn, $cli] = $this->naEmpresa(function () {
            $forn = Terceiro::create(['nome' => 'Fornecedor Com Venda Fictício', 'nif' => '5000000301', 'tipo' => Terceiro::FORNECEDOR]);
            $cli = Terceiro::create(['nome' => 'Cliente Fictício Estrito', 'nif' => '5000000302', 'tipo' => Terceiro::CLIENTE]);
            DB::table('vendas')->insert(['empresa_id' => $this->empresa->id, 'cliente_id' => $forn->id, 'tipo_documento' => 'FT', 'numero_documento' => 'FT X/1',
                'data_emissao' => now(), 'total_bruto' => 100]);

            return [$forn, $cli];
        });
        $ids = fn (string $q) => array_column($this->getJson("/api/terceiros?papel=CLIENTE&por_pagina=50{$q}", $this->h)->assertOk()->json('dados'), 'id');

        $this->assertEqualsCanonicalizing([$forn->id, $cli->id], $ids(''), 'Vendas › Clientes mostra também quem tem documentos de venda');
        $this->assertSame([$cli->id], $ids('&estrito=1'), 'os seletores (POS, Armazém) só mostram quem tem o papel de cliente');
    }
}
