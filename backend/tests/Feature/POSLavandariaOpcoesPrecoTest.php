<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\PecaLavandaria;
use App\Models\PedidoLavandaria;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F5 — opções de preço da lavandaria (lavandaria.js:139-168 e 1830-1880; casos R-034…R-043 do legado), com números calculados à mão.
 * Peça «Camisa» (1.ª opção Peça 1 140; Kg 800; Tamanho M 1 300; Tamanho Casal 2 280) e serviço «Lavar» (IVA 14 %), com a matriz
 * serviço × opção: Lavar × Kg = 900, Lavar × M = 1 710. Os preços são COM IVA, por isso o valor da linha é quantidade × preço.
 */
final class POSLavandariaOpcoesPrecoTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000049']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['311' => 'Clientes', '3452' => 'IVA liquidado', '6221' => 'Serviços de lavandaria', '6222' => 'Taxas da lavandaria', '487' => 'Transitória transferências',
                '489' => 'Transitória numerário', '4511' => 'Caixa', '7811' => 'Indemnizações'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->ids['a'] = Armazem::create(['nome' => 'Loja', 'predefinido' => true])->id;
            $this->ids['cliente'] = Terceiro::create(['nome' => 'Cliente Empresa', 'nif' => '5000000009', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
        });
        $perm = ['pos_view', 'pos_venda', 'pos_fecho', 'pos_terminais_gerir', 'lav_ordens', 'lav_receber', 'lav_anular', 'lav_tabelas', 'pos_lavandaria_view'];
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($perm, true))->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->s = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
        $this->ids['t'] = $this->postJson('/api/pos/terminais', ['codigo' => 'L01', 'nome' => 'Lavandaria', 'tipo' => 'LAVANDARIA', 'armazem_id' => $this->ids['a'],
            'fundo_maneio_padrao' => 0, 'meios_pagamento' => [['id' => 'pm_num', 'tipo' => 'NUMERARIO', 'nome' => 'Numerário', 'conta_transitoria' => '489', 'conta_liquidacao' => '4511']]],
            $this->s)->assertCreated()->json('dados.id');
        $this->putJson('/api/pos/lavandaria/definicoes', ['conta_extras' => '6222', 'conta_compensacao' => '7811'], $this->s)->assertOk();
        $this->ids['lavar'] = $this->postJson('/api/pos/lavandaria/servicos', ['nome' => 'Lavar e engomar', 'grupo' => 'LAVANDARIA', 'codigo_conta' => '6221',
            'taxa_imposto' => 14, 'dias_entrega' => 2], $this->s)->assertCreated()->json('dados.id');
        $this->ids['seco'] = $this->postJson('/api/pos/lavandaria/servicos', ['nome' => 'Lavagem a seco', 'grupo' => 'LAVANDARIA', 'codigo_conta' => '6221',
            'taxa_imposto' => 14], $this->s)->assertCreated()->json('dados.id');
    }

    private function opcoes(): array
    {
        return [['modo' => 'PECA', 'tamanho' => '', 'preco' => 1140], ['modo' => 'KG', 'tamanho' => '', 'preco' => 800],
            ['modo' => 'TAMANHO', 'tamanho' => 'M', 'preco' => 1300], ['modo' => 'TAMANHO', 'tamanho' => 'Casal', 'preco' => 2280]];
    }

    private function camisa(): int
    {
        return $this->postJson('/api/pos/lavandaria/pecas', ['nome' => 'Camisa', 'tecido' => 'Algodão', 'opcoes_preco' => $this->opcoes(), 'precos_servico' => [
            ['produto_id' => $this->ids['lavar'], 'modo' => 'KG', 'tamanho' => '', 'preco' => 900], ['produto_id' => $this->ids['lavar'], 'modo' => 'TAMANHO', 'tamanho' => 'M', 'preco' => 1710]]],
            $this->s)->assertCreated()->json('dados.id');
    }

    private function registar(int $sessao, array $linhas)
    {
        return $this->postJson("/api/pos/lavandaria/sessoes/{$sessao}/ordens", ['cliente_id' => $this->ids['cliente'], 'modo_faturacao' => 'ENTREGA', 'itens' => array_map(
            fn ($l) => $l + ['peca_id' => $this->ids['camisa'], 'produto_id' => $this->ids['lavar'], 'estado_entrada' => 'Bom estado'], $linhas)], $this->s);
    }

    private function naEmpresa(callable $fn): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $fn);
    }

    #[Test]
    public function a_peca_guarda_as_opcoes_espelha_a_primeira_e_valida_como_o_legado(): void
    {
        $id = $this->camisa();
        $p = $this->naEmpresa(fn () => PecaLavandaria::query()->findOrFail($id));
        $this->assertSame(['NOVO', 'PECA', '1140.00'], [$p->opcoes_preco_origem, $p->unidade, (string) $p->preco]);
        $this->assertSame(['1140.00', '800.00', '1300.00', '2280.00'], array_column($p->opcoes_preco, 'preco'));
        $this->assertEquals([['produto_id' => $this->ids['lavar'], 'modo' => 'KG', 'tamanho' => '', 'preco' => '900.00'],
            ['produto_id' => $this->ids['lavar'], 'modo' => 'TAMANHO', 'tamanho' => 'M', 'preco' => '1710.00']], $p->precos_servico);

        $this->postJson('/api/pos/lavandaria/pecas', ['nome' => 'Saia', 'opcoes_preco' => [['modo' => 'TAMANHO', 'tamanho' => ' ', 'preco' => 10]]], $this->s)
            ->assertStatus(422)->assertJsonPath('mensagem', 'Dê nome ao tamanho (ex.: M, Casal) ou remova essa opção de preço.');
        $this->postJson('/api/pos/lavandaria/pecas', ['nome' => 'Saia', 'opcoes_preco' => [['modo' => 'TAMANHO', 'tamanho' => 'M', 'preco' => 10], ['modo' => 'TAMANHO', 'tamanho' => 'm', 'preco' => 20]]], $this->s)
            ->assertStatus(422)->assertJsonPath('mensagem', 'Há duas opções de preço iguais. Cada opção só pode aparecer uma vez.');
        // mesma designação e tecido: só se distingue pela 1.ª opção
        $this->postJson('/api/pos/lavandaria/pecas', ['nome' => 'camisa', 'tecido' => 'algodão', 'opcoes_preco' => [['modo' => 'PECA', 'preco' => 5]]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'PECA_DUPLICADA');
        $this->postJson('/api/pos/lavandaria/pecas', ['nome' => 'camisa', 'tecido' => 'algodão', 'opcoes_preco' => [['modo' => 'KG', 'preco' => 5]]], $this->s)->assertCreated();
        // a 1.ª opção por tamanho: unidade PECA (o tamanho vende-se à peça)
        $m = $this->postJson('/api/pos/lavandaria/pecas', ['nome' => 'Fato', 'opcoes_preco' => [['modo' => 'TAMANHO', 'tamanho' => 'L', 'preco' => 3000]]], $this->s)->assertCreated()->json('dados');
        $this->assertSame(['PECA', '3000.00'], [$m['unidade'], $m['preco']]);
    }

    #[Test]
    public function editar_a_peca_nao_apaga_modo_nem_tamanho_da_matriz(): void
    {
        $id = $this->camisa();
        // edita só a cor e reenvia o espelho unidade/preço (cliente antigo): nada se perde
        $this->putJson("/api/pos/lavandaria/pecas/{$id}", ['cor' => 'Azul', 'unidade' => 'PECA', 'preco' => 1140], $this->s)->assertOk();
        $p = $this->naEmpresa(fn () => PecaLavandaria::query()->findOrFail($id));
        $this->assertSame(4, count($p->opcoes_preco));
        $this->assertSame(['KG', 'TAMANHO'], array_column($p->precos_servico, 'modo'));
        $this->assertSame('M', $p->precos_servico[1]['tamanho']);
        // o cliente antigo muda o preço base: só a 1.ª opção muda
        $this->putJson("/api/pos/lavandaria/pecas/{$id}", ['preco' => 1200], $this->s)->assertOk();
        $p->refresh();
        $this->assertSame(['1200.00', '800.00', '1300.00', '2280.00'], array_column($p->opcoes_preco, 'preco'));
        // a matriz enviada pelo ecrã: remover a opção «Casal» e uma entrada de uma opção que já não existe descarta-a
        $this->putJson("/api/pos/lavandaria/pecas/{$id}", ['opcoes_preco' => array_slice($this->opcoes(), 0, 3), 'precos_servico' => [
            ['produto_id' => $this->ids['lavar'], 'modo' => 'TAMANHO', 'tamanho' => 'M', 'preco' => 1710], ['produto_id' => $this->ids['lavar'], 'modo' => 'TAMANHO', 'tamanho' => 'Casal', 'preco' => 2500],
            ['produto_id' => $this->ids['seco'], 'modo' => 'PECA', 'preco' => 2280]]], $this->s)->assertOk();
        $p->refresh();
        $this->assertSame([[$this->ids['lavar'], 'TAMANHO', 'M', '1710.00'], [$this->ids['seco'], 'PECA', '', '2280.00']],
            array_map(fn ($x) => [$x['produto_id'], $x['modo'], $x['tamanho'], $x['preco']], $p->precos_servico));
    }

    #[Test]
    public function a_ordem_cobra_pela_opcao_escolhida_e_guarda_modo_e_tamanho(): void
    {
        $this->ids['camisa'] = $this->camisa();
        $sessao = $this->postJson("/api/pos/terminais/{$this->ids['t']}/sessoes", [], $this->s)->assertCreated()->json('dados.id');
        $r = $this->registar($sessao, [
            ['quantidade' => 2],                                                          // sem pedido = 1.ª opção (Peça): 2 × 1 140 = 2 280
            ['quantidade' => 2.5, 'numero_pecas' => 3, 'modo' => 'KG'],                   // Lavar × Kg = 900: 2,5 × 900 = 2 250
            ['quantidade' => 2, 'modo' => 'TAMANHO', 'tamanho' => 'm'],                   // Lavar × M = 1 710: 3 000 (o tamanho não distingue maiúsculas)
            ['quantidade' => 1, 'modo' => 'TAMANHO', 'tamanho' => 'Casal'],               // sem entrada na matriz: preço da própria opção, 2 280
            ['quantidade' => 1, 'modo' => 'PECA', 'produto_id' => $this->ids['seco']],    // Lavagem a seco sem preço específico: 1 140
        ])->assertCreated()->json('dados.pedido');
        $itens = $r['itens'];
        $this->assertSame(['2280.00', '2250.00', '3420.00', '2280.00', '1140.00'], array_column($itens, 'valor'));
        $this->assertSame(['1140.00', '900.00', '1710.00', '2280.00', '1140.00'], array_column($itens, 'preco'));
        $this->assertSame(['PECA', 'KG', 'TAMANHO', 'TAMANHO', 'PECA'], array_column($itens, 'modo'));
        $this->assertSame(['', '', 'M', 'Casal', ''], array_column($itens, 'tamanho'));
        $this->assertSame(['PECA', 'KG', 'PECA', 'PECA', 'PECA'], array_column($itens, 'unidade'));
        $this->assertSame(11370.0, array_sum(array_map('floatval', array_column($itens, 'valor'))));   // 2 280 + 2 250 + 3 420 + 2 280 + 1 140

        $this->registar($sessao, [['quantidade' => 1, 'modo' => 'TAMANHO', 'tamanho' => 'XL']])->assertStatus(422)->assertJsonPath('codigo', 'OPCAO_PRECO_INVALIDA');
        $this->registar($sessao, [['quantidade' => 1.5, 'modo' => 'TAMANHO', 'tamanho' => 'M']])->assertStatus(422)->assertJsonPath('codigo', 'QUANTIDADE_INVALIDA');
        $this->registar($sessao, [['quantidade' => 1, 'modo' => 'LITRO']])->assertStatus(422);
        // sem preço na opção (preço 0 e sem entrada): mensagem do legado «sem preço para esta peça nesta opção»
        $sem = $this->postJson('/api/pos/lavandaria/pecas', ['nome' => 'Cortina', 'opcoes_preco' => [['modo' => 'PECA', 'preco' => 100], ['modo' => 'KG', 'preco' => 0]]], $this->s)->assertCreated()->json('dados.id');
        $this->registar($sessao, [['quantidade' => 1, 'modo' => 'KG', 'peca_id' => $sem]])->assertStatus(422)->assertJsonPath('codigo', 'PRECO_EM_FALTA');
    }

    #[Test]
    public function pecas_derivadas_com_uma_so_opcao_dao_o_preco_de_sempre(): void
    {
        $this->ids['camisa'] = $this->postJson('/api/pos/lavandaria/pecas', ['nome' => 'Calça', 'unidade' => 'PECA', 'preco' => 1000], $this->s)->assertCreated()->json('dados.id');
        $kg = $this->postJson('/api/pos/lavandaria/pecas', ['nome' => 'Roupa', 'unidade' => 'KG', 'preco' => 800], $this->s)->assertCreated()->json('dados.id');
        // estado migrado: sem opcoes_preco (DERIVADA) e a matriz antiga sem modo
        DB::table('pecas_lavandaria')->where('id', $this->ids['camisa'])->update(['opcoes_preco' => null, 'opcoes_preco_origem' => 'DERIVADA',
            'precos_servico' => json_encode([['produto_id' => $this->ids['lavar'], 'preco' => '900.00']])]);
        DB::table('pecas_lavandaria')->where('id', $kg)->update(['opcoes_preco' => json_encode([['modo' => 'KG', 'tamanho' => '', 'preco' => '800.00']]), 'opcoes_preco_origem' => 'DERIVADA']);
        $sessao = $this->postJson("/api/pos/terminais/{$this->ids['t']}/sessoes", [], $this->s)->assertCreated()->json('dados.id');
        $r = $this->registar($sessao, [['quantidade' => 3], ['quantidade' => 1.5, 'peca_id' => $kg], ['quantidade' => 2, 'produto_id' => $this->ids['seco']]])->assertCreated()->json('dados.pedido');
        // 3 × 900 (preço do serviço da matriz antiga) · 1,5 kg × 800 (preço base kg) · 2 × 1 000 (preço base, sem entrada para «Lavagem a seco»)
        $this->assertSame(['2700.00', '1200.00', '2000.00'], array_column($r['itens'], 'valor'));
        $this->assertSame(['PECA', 'KG', 'PECA'], array_column($r['itens'], 'modo'));
        // editar só o nome grava a origem NOVO e preserva preços e a matriz
        $this->putJson("/api/pos/lavandaria/pecas/{$this->ids['camisa']}", ['nome' => 'Calça de ganga'], $this->s)->assertOk();
        $p = $this->naEmpresa(fn () => PecaLavandaria::query()->findOrFail($this->ids['camisa']));
        $this->assertEquals(['NOVO', [['modo' => 'PECA', 'tamanho' => '', 'preco' => '1000.00']], '900.00'], [$p->opcoes_preco_origem, $p->opcoes_preco, $p->precos_servico[0]['preco']]);
        $r2 = $this->registar($sessao, [['quantidade' => 3]])->assertCreated()->json('dados.pedido');
        $this->assertSame('2700.00', $r2['itens'][0]['valor']);
    }

    #[Test]
    public function a_importacao_de_precos_respeita_a_opcao_e_nao_apaga_as_outras(): void
    {
        $id = $this->camisa();
        $codigo = $this->naEmpresa(fn () => PecaLavandaria::query()->findOrFail($id)->codigo);
        $cod = fn (int $p) => $this->naEmpresa(fn () => Produto::query()->findOrFail($p)->codigo);
        $imp = fn (array $linhas, bool $simular) => $this->post('/api/pos/lavandaria/importar', ['tipo' => 'precos', 'simular' => $simular ? '1' : '0',
            'ficheiro' => UploadedFile::fake()->createWithContent('p.csv', implode("\n", array_map(fn ($l) => implode(';', $l), $linhas)))], $this->s + ['Accept' => 'application/json']);
        $cab = ['Peça', 'Serviço', 'Preço', 'Opção'];
        $lavar = $cod($this->ids['lavar']);
        $linhas = [$cab, [$codigo, $lavar, '1600', 'Casal'], [$codigo, $lavar, '950', 'Kg'], [$codigo, $lavar, '0', 'M'], [$codigo, $lavar, '100', 'XL'], [$codigo, $lavar, '1100', 'Peça']];
        $r = $imp($linhas, true)->assertOk()->json('dados');
        $this->assertSame([5, 4, 1], [$r['lidas'], $r['validas'], $r['ignoradas']]);
        $this->assertSame(['CRIAR', 'ACTUALIZAR', 'REMOVER', 'IGNORADA', 'CRIAR'], array_column($r['linhas'], 'accao'));
        $this->assertStringContainsString('não tem a opção', implode(' ', $r['linhas'][3]['erros']));
        $imp($linhas, false)->assertOk();
        $p = $this->naEmpresa(fn () => PecaLavandaria::query()->findOrFail($id));
        $m = array_map(fn ($x) => "{$x['modo']}|{$x['tamanho']}={$x['preco']}", array_values(array_filter($p->precos_servico, fn ($x) => $x['produto_id'] === $this->ids['lavar'])));
        sort($m);
        $this->assertSame(['KG|=950.00', 'PECA|=1100.00', 'TAMANHO|Casal=1600.00'], $m);   // M removido (0); nada mais se perdeu
        $this->assertSame(4, count($p->opcoes_preco));
    }

    #[Test]
    public function a_matriz_antiga_sem_modo_continua_a_valer_para_as_opcoes_de_uma_peca_migrada(): void
    {
        // peça LEGADO com 3 opções e matriz traduzida pela F0b (modo/tamanho): a regra escolhe a entrada da opção, não a 1.ª do serviço
        $id = $this->camisa();
        DB::table('pecas_lavandaria')->where('id', $id)->update(['opcoes_preco_origem' => 'LEGADO']);
        $this->ids['camisa'] = $id;
        $sessao = $this->postJson("/api/pos/terminais/{$this->ids['t']}/sessoes", [], $this->s)->assertCreated()->json('dados.id');
        $o = $this->registar($sessao, [['quantidade' => 1, 'modo' => 'KG']])->assertCreated()->json('dados.pedido');
        $this->assertSame('900.00', $o['itens'][0]['valor']);   // antes da F5 sairia 900 também só por ser a 1.ª entrada; aqui é pela opção
        $this->assertSame(1, $this->naEmpresa(fn () => PedidoLavandaria::query()->count()));
    }
}
