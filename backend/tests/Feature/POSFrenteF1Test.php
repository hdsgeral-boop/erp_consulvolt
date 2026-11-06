<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\MeioPagamento;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Venda;
use App\Services\Logistica\ServicoStock;
use App\Services\Vendas\ServicoConfigVendas;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F1 do POS (frente de caixa e talão): elementos fiscais do talão (QR da AGT, série, cliente, coordenadas bancárias), produtos
 * bloqueados visíveis no catálogo e recusados na venda, e mensagens de stock com os textos do legado (ui_sales.js:7158-7202),
 * medidas no armazém do terminal.
 */
final class POSFrenteF1Test extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000071']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['311' => 'Clientes', '3452' => 'IVA liquidado', '611' => 'Vendas', '2611' => 'Mercadorias', '7111' => 'CMV', '489' => 'Transitória numerário',
                '488' => 'Transitória TPA', '4511' => 'Caixa', '43101' => 'Banco'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            app(ServicoConfigVendas::class)->definir(['clientes_default' => '311']);
            $base = ['preco_unitario' => 1140, 'taxa_imposto' => 14, 'codigo_conta' => '611', 'conta_iva_liquidado' => '3452', 'movimenta_stock' => true,
                'conta_inventario' => '2611', 'conta_custo' => '7111', 'e_servico' => false];
            $this->ids['p'] = Produto::create(['codigo' => 'P1', 'nome' => 'Produto Um'] + $base)->id;
            $this->ids['b'] = Produto::create(['codigo' => 'PB', 'nome' => 'Produto Bloqueado', 'bloqueado' => true] + $base)->id;
            $this->ids['q'] = Produto::create(['codigo' => 'Q1', 'nome' => 'Suite 1', 'e_quarto' => true] + $base)->id;
            $this->ids['a'] = Armazem::create(['nome' => 'Loja', 'predefinido' => true])->id;
            app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['a'], '10', '600', now()->toDateString(), 'Stock inicial');
            MeioPagamento::create(['nome' => 'BAI Kwanzas', 'codigo_conta' => '43101', 'iban' => 'AO06004000008888888810158', 'swift' => 'BKBKAOLU', 'ativo' => true]);
            MeioPagamento::create(['nome' => 'Inactivo', 'codigo_conta' => '43101', 'iban' => 'AO06000000000000000000002', 'ativo' => false]);
            MeioPagamento::create(['nome' => 'Sem IBAN', 'codigo_conta' => '4511', 'ativo' => true]);
        });
        $this->s = $this->sessao(['pos_view', 'pos_venda', 'pos_fecho', 'pos_terminais_gerir']);
        $this->ids['t'] = $this->postJson('/api/pos/terminais', ['codigo' => 'T01', 'nome' => 'Loja 1', 'armazem_id' => $this->ids['a'], 'fundo_maneio_padrao' => 0,
            'meios_pagamento' => [['id' => 'pm_num', 'tipo' => 'NUMERARIO', 'nome' => 'Numerário', 'conta_transitoria' => '489', 'conta_liquidacao' => '4511']]], $this->s)
            ->assertCreated()->json('dados.id');
        $this->ids['sessao'] = $this->postJson("/api/pos/terminais/{$this->ids['t']}/sessoes", [], $this->s)->assertCreated()->json('dados.id');
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function vender(string $produto, float $q, float $valor, ?array $s = null)
    {
        return $this->postJson("/api/pos/sessoes/{$this->ids['sessao']}/vendas", ['linhas' => [['produto_id' => $this->ids[$produto], 'quantidade' => $q]],
            'pagamentos' => [['meio_id' => 'pm_num', 'valor' => $valor]]], $s ?? $this->s);
    }

    #[Test]
    public function talao_sem_regime_traz_cliente_final_coordenadas_dos_meios_activos_e_nenhum_qr(): void
    {
        $id = $this->vender('p', 1, 1200)->assertCreated()->json('dados.id');
        $d = $this->getJson("/api/pos/frente/talao/{$id}", $this->s)->assertOk()->json('dados');

        $this->assertFalse($d['fe_regime']);
        $this->assertNull($d['qr_svg']);
        $this->assertNull($d['url_consulta']);
        $this->assertNull($d['serie']);
        $this->assertSame('999999999', $d['cliente_nif']);
        $this->assertSame('Consumidor Final', $d['cliente_nome']);
        $this->assertSame([['nome' => 'BAI Kwanzas', 'iban' => 'AO06004000008888888810158', 'swift' => 'BKBKAOLU']], $d['coordenadas_bancarias'], 'só meios activos com IBAN');
        $this->assertNotEmpty($d['operador'], 'o operador real, não «CAIXA POS»');
    }

    #[Test]
    public function talao_no_regime_traz_o_qr_com_o_endereco_de_consulta_da_agt_e_a_serie(): void
    {
        $id = $this->vender('p', 1, 1140)->assertCreated()->json('dados.id');
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Venda::query()->whereKey($id)->update(['fe_regime' => true, 'fe_tipo' => 'FR', 'fe_serie' => 'T012026']));
        $d = $this->getJson("/api/pos/frente/talao/{$id}", $this->s)->assertOk()->json('dados');

        $this->assertTrue($d['fe_regime']);
        $this->assertSame('FR T012026', $d['serie']);
        $this->assertStringContainsString('<svg', $d['qr_svg']);
        $this->assertStringContainsString('consultar-fe?emissor=5417000071&document=', $d['url_consulta']);
        $this->assertStringContainsString(str_replace(' ', '%20', $d['numero_documento']), $d['url_consulta']);
    }

    #[Test]
    public function talao_so_para_vendas_do_pos_e_com_permissao(): void
    {
        $this->getJson('/api/pos/frente/talao/99999999', $this->s)->assertNotFound();
        $id = $this->vender('p', 1, 1140)->assertCreated()->json('dados.id');
        $this->getJson("/api/pos/frente/talao/{$id}", $this->sessao(['dashboard_view']))->assertForbidden();
        $this->getJson("/api/pos/frente/talao/{$id}", $this->sessao(['pos_venda']))->assertOk();
    }

    #[Test]
    public function produto_bloqueado_aparece_no_catalogo_sinalizado_e_a_venda_e_recusada_com_o_texto_do_legado(): void
    {
        $catalogo = collect($this->getJson("/api/pos/terminais/{$this->ids['t']}/catalogo", $this->s)->assertOk()->json('dados'));
        $this->assertTrue($catalogo->firstWhere('codigo', 'PB')['bloqueado']);
        $this->assertFalse($catalogo->firstWhere('codigo', 'P1')['bloqueado']);

        $this->vender('b', 1, 1140)->assertStatus(422)->assertJsonPath('codigo', 'PRODUTO_BLOQUEADO')
            ->assertJsonPath('mensagem', 'O artigo "Produto Bloqueado" está bloqueado para venda.');
    }

    #[Test]
    public function mensagens_de_stock_do_legado_pelo_armazem_do_terminal(): void
    {
        // 11 > 10 existentes
        $this->vender('p', 11, 12540)->assertStatus(422)->assertJsonPath('codigo', 'STOCK_INSUFICIENTE')
            ->assertJsonPath('mensagem', 'Não é possível faturar mais unidades deste produto do que o stock existente (10).');
        // vende as 10 → fora de stock
        $this->vender('p', 10, 11400)->assertCreated();
        $this->vender('p', 1, 1140)->assertStatus(422)->assertJsonPath('codigo', 'STOCK_INSUFICIENTE')
            ->assertJsonPath('mensagem', 'O artigo Produto Um encontra-se fora de stock.');
    }

    #[Test]
    public function quarto_numa_caixa_de_loja_usa_o_texto_do_legado(): void
    {
        $this->vender('q', 1, 1140)->assertStatus(422)->assertJsonPath('codigo', 'PRODUTO_FORA_DO_SERVICO')
            ->assertJsonPath('mensagem', 'O artigo de hospedagem/quarto "Suite 1" só está disponível no POS do tipo Hotelaria.');
    }
}
