<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\SerieFaturacaoEletronica;
use App\Models\Terceiro;
use App\Services\Vendas\RegrasNumeracaoAgt;
use App\Services\Vendas\ServicoConfigVendas;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ronda 3 — r3_vendas: catálogo do POS por serviço (produtos.servicos_pos), regras do n.º de série contra a AGT/SAF-T,
 * pesquisa de clientes na tabela de terceiros (com passagem de fornecedor a cliente) e observação por linha. Dados fictícios.
 */
final class R3VendasTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private int $ano;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ano = (int) now()->year;
        $this->empresa = $this->criarEmpresa(['nif' => '5417000077']);
        $this->naEmpresa(function () {
            foreach (['311' => 'Clientes', '3112' => 'Clientes nacionais', '3211' => 'Fornecedores', '3452' => 'IVA liquidado', '611' => 'Vendas',
                '489' => 'Transitória numerário', '4511' => 'Caixa'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            app(ServicoConfigVendas::class)->definir(['clientes_default' => '311']);
            $base = ['preco_unitario' => 100, 'taxa_imposto' => 14, 'codigo_conta' => '611', 'conta_iva_liquidado' => '3452', 'movimenta_stock' => false, 'e_servico' => true];
            foreach (['todos' => null, 'loja' => ['LOJA'], 'rest' => ['RESTAURANTE', 'HOTELARIA'], 'nenhum' => []] as $k => $servicos) {
                $p = Produto::create(['codigo' => strtoupper($k), 'nome' => "Produto {$k}"] + $base);
                $p->forceFill(['servicos_pos' => $servicos === null ? null : json_encode($servicos)])->save();
                $this->ids[$k] = $p->id;
            }
            $this->ids['quarto'] = Produto::create(['codigo' => 'Q1', 'nome' => 'Quarto 1', 'e_quarto' => true] + $base)->id;
            $this->ids['lav'] = Produto::create(['codigo' => 'LV1', 'nome' => 'Lavar camisa', 'lavandaria_grupo' => 'LAVANDARIA'] + $base)->id;
            $bloqueado = Produto::create(['codigo' => 'BLQ', 'nome' => 'Bloqueado', 'bloqueado' => true] + $base);
            $this->ids['bloqueado'] = $bloqueado->id;
            $this->ids['a'] = Armazem::create(['nome' => 'Loja', 'predefinido' => true])->id;
            $this->ids['cliente'] = Terceiro::create(['nome' => 'Cliente Alfa', 'nif' => '5000000011', 'tipo' => Terceiro::CLIENTE, 'conta_cliente' => '311'])->id;
            $this->ids['fornecedor'] = Terceiro::create(['nome' => 'Fornecedor Alfa', 'nif' => '5000000022', 'tipo' => Terceiro::FORNECEDOR, 'conta_fornecedor' => '3211'])->id;
            $this->ids['colaborador'] = Terceiro::create(['nome' => 'Colaborador Alfa', 'nif' => '5000000033', 'tipo' => Terceiro::COLABORADOR])->id;
        });
    }

    private function naEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function terminal(array $s, string $codigo, string $tipo): int
    {
        return $this->postJson('/api/pos/terminais', ['codigo' => $codigo, 'nome' => "Terminal {$codigo}", 'tipo' => $tipo, 'armazem_id' => $this->ids['a'], 'meios_pagamento' => [
            ['id' => 'pm_num', 'tipo' => 'NUMERARIO', 'nome' => 'Numerário', 'conta_transitoria' => '489', 'conta_liquidacao' => '4511'],
        ]], $s)->assertCreated()->json('dados.id');
    }

    // ───────────── 1. POS por serviço ─────────────

    #[Test]
    public function catalogo_de_cada_terminal_mostra_so_os_produtos_marcados_para_o_servico(): void
    {
        $s = $this->sessao(['pos_view', 'pos_venda', 'pos_terminais_gerir']);
        $esperado = [
            'LOJA' => ['todos', 'loja'],
            'RESTAURANTE' => ['todos', 'rest'],
            'HOTELARIA' => ['todos', 'rest', 'quarto'],
            'LAVANDARIA' => ['todos', 'lav'],
        ];
        foreach ($esperado as $tipo => $chaves) {
            $t = $this->terminal($s, 'T'.substr($tipo, 0, 3), $tipo);
            $ids = array_column($this->getJson("/api/pos/terminais/{$t}/catalogo", $s)->assertOk()->json('dados'), 'id');
            $this->assertEqualsCanonicalizing(array_map(fn ($k) => $this->ids[$k], $chaves), $ids, "Catálogo do terminal {$tipo}");
        }
        $linha = collect($this->getJson("/api/pos/terminais/{$t}/catalogo", $s)->json('dados'))->firstWhere('id', $this->ids['todos']);
        $this->assertNull($linha['servicos_pos']);
        $this->assertArrayHasKey('preco_unitario', $linha);

        $this->getJson("/api/pos/terminais/{$t}/catalogo", $this->sessao(['vendas_produtos_view']))->assertForbidden();
    }

    #[Test]
    public function marcacao_do_produto_grava_valida_e_exige_permissao(): void
    {
        $gestor = $this->sessao(['vendas_produtos_view', 'vendas_produtos_gerir']);
        $leitor = $this->sessao(['vendas_produtos_view']);
        $id = $this->ids['todos'];

        $this->putJson("/api/pos/produtos/{$id}/servicos", ['servicos_pos' => ['RESTAURANTE', 'LOJA', 'LOJA']], $gestor)->assertOk()
            ->assertJsonPath('dados.servicos_pos', ['LOJA', 'RESTAURANTE']);   // sem repetidos, pela ordem dos serviços
        $this->putJson("/api/pos/produtos/{$id}/servicos", ['servicos_pos' => ['BAR']], $gestor)->assertUnprocessable();
        $this->putJson("/api/pos/produtos/{$id}/servicos", [], $gestor)->assertUnprocessable();   // o campo tem de vir (null = todos)
        $this->putJson("/api/pos/produtos/{$id}/servicos", ['servicos_pos' => ['LOJA']], $leitor)->assertForbidden();

        $m = $this->getJson('/api/pos/produtos/servicos', $leitor)->assertOk();
        $this->assertSame(['LOJA', 'RESTAURANTE'], $m->json('dados.produtos.'.$id));
        $this->assertNull($m->json('dados.produtos.'.$this->ids['quarto']));
        $this->assertSame(['LOJA', 'RESTAURANTE', 'LAVANDARIA', 'HOTELARIA'], array_column($m->json('dados.servicos'), 'valor'));

        $this->putJson("/api/pos/produtos/{$id}/servicos", ['servicos_pos' => null], $gestor)->assertOk()->assertJsonPath('dados.servicos_pos', null);
        $this->putJson("/api/pos/produtos/{$id}/servicos", ['servicos_pos' => []], $gestor)->assertOk()->assertJsonPath('dados.servicos_pos', []);
    }

    #[Test]
    public function venda_ao_balcao_recusa_produto_nao_marcado_para_o_servico(): void
    {
        $s = $this->sessao(['pos_view', 'pos_venda', 'pos_terminais_gerir']);
        $t = $this->terminal($s, 'L01', 'LOJA');
        $sessao = $this->postJson("/api/pos/terminais/{$t}/sessoes", ['fundo_maneio' => 0], $s)->assertCreated()->json('dados.id');
        $venda = fn (string $k) => $this->postJson("/api/pos/sessoes/{$sessao}/vendas", ['linhas' => [['produto_id' => $this->ids[$k], 'quantidade' => 1]],
            'pagamentos' => [['meio_id' => 'pm_num', 'valor' => 114]]], $s);

        $venda('rest')->assertUnprocessable()->assertJsonPath('codigo', 'PRODUTO_FORA_DO_SERVICO');
        $venda('quarto')->assertUnprocessable()->assertJsonPath('codigo', 'PRODUTO_FORA_DO_SERVICO');
        $venda('loja')->assertCreated();
        $venda('todos')->assertCreated();
    }

    // ───────────── 3. Séries internas vs AGT/SAF-T ─────────────

    #[Test]
    public function codigo_de_serie_nao_se_repete_no_mesmo_tipo_noutro_ano(): void
    {
        $s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fe_config', 'vendas_series_gerir', 'vendas_series_view']);
        $this->postJson('/api/vendas/series', ['tipo' => 'FT', 'ano' => $this->ano - 1, 'codigo' => 'LIVRO'], $s)->assertCreated();
        $this->postJson('/api/vendas/series', ['tipo' => 'FT', 'ano' => $this->ano, 'codigo' => 'livro'], $s)
            ->assertUnprocessable()->assertJsonPath('codigo', 'SERIE_DUPLICADA');
        // noutro tipo o mesmo código não colide (o n.º leva o tipo: «FR LIVRO/1» ≠ «FT LIVRO/1»)
        $this->postJson('/api/vendas/series', ['tipo' => 'FR', 'ano' => $this->ano, 'codigo' => 'LIVRO'], $s)->assertCreated();
        // código com espaço ou «/» quebraria o InvoiceNo do SAF-T
        $this->postJson('/api/vendas/series', ['tipo' => 'NC', 'ano' => $this->ano, 'codigo' => 'A/1'], $s)->assertUnprocessable();
    }

    #[Test]
    public function serie_automatica_salta_o_codigo_ja_usado_noutro_ano(): void
    {
        $s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fe_config', 'vendas_series_gerir', 'vendas_series_view']);
        // série do ano passado com o código que a série automática deste ano usaria («A<ano>»)
        $this->postJson('/api/vendas/series', ['tipo' => 'FT', 'ano' => $this->ano - 1, 'codigo' => "A{$this->ano}"], $s)->assertCreated();
        $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $this->ids['cliente'], 'data_emissao' => now()->toDateString(),
            'linhas' => [['produto_id' => $this->ids['todos'], 'quantidade' => 1]]], $s)->assertCreated()
            ->assertJsonPath('dados.numero_documento', "FT A{$this->ano}B/1");
    }

    #[Test]
    public function numero_fora_do_formato_saft_nao_e_emitido(): void
    {
        $s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir']);
        $this->naEmpresa(fn () => SerieFaturacaoEletronica::create(['codigo' => 'X Y', 'tipo' => 'FT', 'ano' => $this->ano, 'origem' => 'GERAL',
            'estado' => 'ATIVA', 'proximo_numero' => 1]));
        $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $this->ids['cliente'], 'data_emissao' => now()->toDateString(),
            'linhas' => [['produto_id' => $this->ids['todos'], 'quantidade' => 1]]], $s)
            ->assertUnprocessable()->assertJsonPath('codigo', 'NUMERO_DOCUMENTO_INVALIDO');
        $this->assertSame(1, (int) $this->naEmpresa(fn () => SerieFaturacaoEletronica::query()->where('codigo', 'X Y')->value('proximo_numero')));   // não consumiu
    }

    #[Test]
    public function regras_do_numero_e_do_codigo_automatico(): void
    {
        $this->assertTrue(RegrasNumeracaoAgt::numeroValido('FT A2026/15'));
        $this->assertTrue(RegrasNumeracaoAgt::numeroValido('RCP A2026/1'));
        foreach (['FT A 2026/1', 'FT A/2026/1', 'FT-POS/T01/2026/0001', 'FT A2026/1a', 'FT '.str_repeat('A', 60).'/1'] as $mau) {
            $this->assertFalse(RegrasNumeracaoAgt::numeroValido($mau), $mau);
        }
        $this->naEmpresa(function () {
            $longo = RegrasNumeracaoAgt::codigoAutomatico('FR', $this->ano, 'TERMINAL-DA-LOJA-PRINCIPAL-DO-CENTRO-COMERCIAL');
            $this->assertLessThanOrEqual(30, strlen($longo));
            $this->assertTrue(RegrasNumeracaoAgt::codigoValido($longo));
            $this->assertStringEndsWith((string) $this->ano, $longo);
            $this->assertSame("T{$this->ano}", RegrasNumeracaoAgt::codigoAutomatico('FR', $this->ano, '---'));
            $this->assertSame("T01{$this->ano}", RegrasNumeracaoAgt::codigoAutomatico('FR', $this->ano, 'T-01'));
        });
    }

    // ───────────── 4. Pesquisa de clientes na tabela de terceiros ─────────────

    #[Test]
    public function pesquisa_de_clientes_inclui_fornecedores_e_exclui_colaboradores(): void
    {
        $s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir']);
        $sem = $this->getJson('/api/vendas/clientes/pesquisa', $s)->assertOk();
        $this->assertSame([$this->ids['cliente']], array_column($sem->json('dados.itens'), 'id'));   // sem texto: só clientes
        $this->assertSame('311', $sem->json('dados.conta_cliente_sugerida'));

        $r = $this->getJson('/api/vendas/clientes/pesquisa?q=alfa', $s)->assertOk()->json('dados.itens');
        $this->assertSame([$this->ids['cliente'], $this->ids['fornecedor']], array_column($r, 'id'));   // clientes primeiro
        $this->assertSame([true, false], array_column($r, 'e_cliente'));
        $this->assertSame(['id', 'nome', 'nif', 'tipo', 'codigo_moeda', 'e_cliente', 'conta_cliente'], array_keys($r[0]));

        $this->assertSame([$this->ids['fornecedor']], array_column($this->getJson('/api/vendas/clientes/pesquisa?q=5000000022', $s)->json('dados.itens'), 'id'));
        $this->assertCount(1, $this->getJson('/api/vendas/clientes/pesquisa?q=alfa&limite=1', $s)->json('dados.itens'));
        $this->assertSame([], $this->getJson('/api/vendas/clientes/pesquisa?q=%25', $s)->json('dados.itens'));   // «%» é texto, não curinga
        $this->getJson('/api/vendas/clientes/pesquisa?limite=500', $s)->assertUnprocessable();
        $this->getJson('/api/vendas/clientes/pesquisa', $this->sessao(['pos_view']))->assertForbidden();
    }

    #[Test]
    public function fornecedor_passa_a_cliente_com_conta_da_classe_31(): void
    {
        $emitir = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir']);
        $gestor = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_clientes_gerir']);
        $f = $this->ids['fornecedor'];

        $this->postJson("/api/vendas/clientes/{$f}/papel-cliente", ['conta_cliente' => '3112'], $emitir)->assertForbidden();
        $this->postJson("/api/vendas/clientes/{$f}/papel-cliente", ['conta_cliente' => '3211'], $gestor)->assertUnprocessable()->assertJsonPath('codigo', 'CONTA_CLASSE_ERRADA');
        $this->postJson("/api/vendas/clientes/{$this->ids['colaborador']}/papel-cliente", ['conta_cliente' => '3112'], $gestor)
            ->assertUnprocessable()->assertJsonPath('codigo', 'TIPO_TERCEIRO_INCOMPATIVEL');

        $this->postJson("/api/vendas/clientes/{$f}/papel-cliente", ['conta_cliente' => '3112'], $gestor)->assertOk()
            ->assertJsonPath('dados.tipo', Terceiro::CLIENTE_FORNECEDOR)->assertJsonPath('dados.e_cliente', true)->assertJsonPath('dados.conta_cliente', '3112');
        $t = $this->naEmpresa(fn () => Terceiro::query()->find($f));
        $this->assertSame('3211', $t->conta_fornecedor);   // a conta de fornecedor fica

        $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $f, 'data_emissao' => now()->toDateString(),
            'linhas' => [['produto_id' => $this->ids['todos'], 'quantidade' => 1]]], $emitir)->assertCreated();
    }

    // ───────────── 5. Observação por linha ─────────────

    #[Test]
    public function observacao_da_linha_fica_no_documento_e_acompanha_a_conversao(): void
    {
        $s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir']);
        $or = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'OR', 'cliente_id' => $this->ids['cliente'], 'data_emissao' => now()->toDateString(),
            'linhas' => [['produto_id' => $this->ids['todos'], 'quantidade' => 2, 'observacoes' => 'Cor azul, entregar até sexta'],
                ['produto_id' => $this->ids['loja'], 'quantidade' => 1]]], $s)->assertCreated();
        $id = $or->json('dados.id');
        $linhas = $this->getJson("/api/vendas/documentos/{$id}", $s)->assertOk()->json('dados.linhas');
        $this->assertSame(['Cor azul, entregar até sexta', null], array_column($linhas, 'observacoes'));

        $ft = $this->postJson("/api/vendas/documentos/{$id}/converter", ['tipo_destino' => 'FT'], $s)->assertCreated()->json('dados.id');
        $this->assertSame(['Cor azul, entregar até sexta', null], array_column($this->getJson("/api/vendas/documentos/{$ft}", $s)->json('dados.linhas'), 'observacoes'));

        $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $this->ids['cliente'], 'data_emissao' => now()->toDateString(),
            'linhas' => [['produto_id' => $this->ids['todos'], 'quantidade' => 1, 'observacoes' => str_repeat('x', 2001)]]], $s)->assertUnprocessable();
    }

    // ───────────── Auditoria: mesas do POS ─────────────

    #[Test]
    public function mesa_muda_de_nome_e_nao_se_elimina_com_conta_aberta(): void
    {
        $s = $this->sessao(['pos_view', 'pos_venda', 'pos_terminais_gerir']);
        $t = $this->terminal($s, 'R01', 'RESTAURANTE');
        $m = $this->postJson("/api/pos/terminais/{$t}/mesas", ['nome' => 'Mesa 1'], $s)->assertCreated()->json('dados.id');
        $this->postJson("/api/pos/terminais/{$t}/mesas", ['nome' => 'Mesa 2'], $s)->assertCreated();

        $this->putJson("/api/pos/terminais/{$t}/mesas/{$m}", ['nome' => 'Terraço 1', 'ordem' => 9], $s)->assertOk()
            ->assertJsonPath('dados.nome', 'Terraço 1')->assertJsonPath('dados.ordem', 9);
        $this->putJson("/api/pos/terminais/{$t}/mesas/{$m}", ['nome' => 'mesa 2'], $s)->assertUnprocessable()->assertJsonPath('codigo', 'MESA_DUPLICADA');
        $this->putJson("/api/pos/terminais/{$t}/mesas/{$m}", ['nome' => 'X'], $this->sessao(['pos_venda']))->assertForbidden();

        $this->putJson("/api/pos/mesas/{$m}/conta", ['linhas' => [['produto_id' => $this->ids['rest'], 'quantidade' => 1]]], $s)->assertOk();
        $this->deleteJson("/api/pos/mesas/{$m}", [], $s)->assertUnprocessable()->assertJsonPath('codigo', 'MESA_COM_CONTA');
        $this->putJson("/api/pos/mesas/{$m}/conta", ['linhas' => []], $s)->assertOk();   // liberta a mesa (conta anulada)
        $this->deleteJson("/api/pos/mesas/{$m}", [], $s)->assertOk();
    }
}
