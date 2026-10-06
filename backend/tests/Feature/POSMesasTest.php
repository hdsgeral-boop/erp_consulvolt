<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Services\Logistica\ServicoStock;
use App\Services\Vendas\ServicoConfigVendas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** M-15 (decisão 14): mesas do POS restaurante e contas por mesa no servidor. Produto: 1 140 Kz com IVA 14 %. */
final class POSMesasTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000099']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['311', '3452', '611', '2611', '7111', '489', '4511'] as $c) {
                PlanoConta::create(['codigo' => $c, 'descricao' => "Conta {$c}", 'tipo' => 'M']);
            }
            app(ServicoConfigVendas::class)->definir(['clientes_default' => '311']);
            $this->ids['p'] = Produto::create(['codigo' => 'P1', 'nome' => 'Prato do dia', 'preco_unitario' => 1140, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => true, 'conta_inventario' => '2611', 'conta_custo' => '7111'])->id;
            $this->ids['a'] = Armazem::create(['nome' => 'Cozinha', 'predefinido' => true])->id;
            app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['a'], '20', '600', now()->toDateString(), 'Stock inicial');
        });
        $this->s = $this->sessao(['pos_view', 'pos_venda', 'pos_fecho', 'pos_terminais_gerir']);
        $this->ids['t'] = $this->postJson('/api/pos/terminais', ['codigo' => 'R01', 'nome' => 'Restaurante', 'tipo' => 'RESTAURANTE', 'armazem_id' => $this->ids['a'],
            'meios_pagamento' => [['id' => 'pm_num', 'tipo' => 'NUMERARIO', 'nome' => 'Numerário', 'conta_transitoria' => '489', 'conta_liquidacao' => '4511']]], $this->s)
            ->assertCreated()->json('dados.id');
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function mesa(string $nome): int
    {
        return $this->postJson("/api/pos/terminais/{$this->ids['t']}/mesas", ['nome' => $nome], $this->s)->assertCreated()->json('dados.id');
    }

    #[Test]
    public function cria_mesas_guarda_a_conta_no_servidor_e_mostra_o_total(): void
    {
        $m1 = $this->mesa('Mesa 1');
        $this->mesa('Terraço 2');
        $this->postJson("/api/pos/terminais/{$this->ids['t']}/mesas", ['nome' => 'mesa 1'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'MESA_DUPLICADA');

        $c = $this->putJson("/api/pos/mesas/{$m1}/conta", ['linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 2]]], $this->s)->assertOk()->json('dados');
        $this->assertSame(1, $c['versao']);
        $this->assertSame('2280.00', $c['total']);

        // outro posto vê a mesa ocupada
        $outro = $this->sessao(['pos_venda']);
        $lista = collect($this->getJson("/api/pos/terminais/{$this->ids['t']}/mesas", $outro)->assertOk()->json('dados'))->keyBy('nome');
        $this->assertSame('2280.00', $lista['Mesa 1']['conta']['total']);
        $this->assertNull($lista['Terraço 2']['conta']);

        // versão desactualizada: 409
        $this->putJson("/api/pos/mesas/{$m1}/conta", ['linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 3]], 'versao' => 1], $outro)->assertOk()->assertJsonPath('dados.versao', 2);
        $this->putJson("/api/pos/mesas/{$m1}/conta", ['linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 1]], 'versao' => 1], $this->s)
            ->assertStatus(409)->assertJsonPath('codigo', 'CONTA_MESA_ALTERADA');

        // desconto exige pos_desconto
        $this->putJson("/api/pos/mesas/{$m1}/conta", ['linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 1]], 'percentagem_desconto' => 10], $outro)->assertForbidden();

        // libertar (sem linhas)
        $this->putJson("/api/pos/mesas/{$m1}/conta", ['linhas' => []], $this->s)->assertOk()->assertJsonPath('dados', null);
        $this->getJson("/api/pos/mesas/{$m1}/conta", $this->s)->assertOk()->assertJsonPath('dados', null);
    }

    #[Test]
    public function cobrar_a_mesa_emite_a_fr_com_o_nome_da_mesa_e_fecha_a_conta(): void
    {
        $m = $this->mesa('Mesa 5');
        $this->putJson("/api/pos/mesas/{$m}/conta", ['linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 1]]], $this->s)->assertOk();
        $sessao = $this->postJson("/api/pos/terminais/{$this->ids['t']}/sessoes", [], $this->s)->assertCreated()->json('dados.id');

        $v = $this->postJson("/api/pos/sessoes/{$sessao}/mesas/{$m}/cobrar", ['linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 2]],
            'pagamentos' => [['meio_id' => 'pm_num', 'valor' => 3000]], 'versao' => 1], $this->s)->assertCreated()->json('dados');
        $this->assertSame('Mesa 5', $v['nome_tabela']);
        $this->assertSame('2280.00', $v['total_bruto']);
        $this->assertSame('720.00', $v['pos_troco']);
        $conta = DB::table('contas_mesa_pos')->where('mesa_pos_id', $m)->first();
        $this->assertSame('FECHADA', $conta->estado);
        $this->assertSame($v['id'], (int) $conta->venda_id);
        $this->getJson("/api/pos/mesas/{$m}/conta", $this->s)->assertOk()->assertJsonPath('dados', null);

        // eliminar uma mesa com histórico só a desactiva
        $this->deleteJson("/api/pos/mesas/{$m}", [], $this->s)->assertOk();
        $this->assertFalse((bool) DB::table('mesas_pos')->where('id', $m)->value('ativo'));
    }

    #[Test]
    public function so_terminais_restaurante_tem_mesas_e_outra_empresa_nao_ve(): void
    {
        $loja = $this->postJson('/api/pos/terminais', ['codigo' => 'L01', 'nome' => 'Loja', 'tipo' => 'LOJA', 'armazem_id' => $this->ids['a'],
            'meios_pagamento' => [['id' => 'pm_num', 'tipo' => 'NUMERARIO', 'nome' => 'Numerário', 'conta_transitoria' => '489', 'conta_liquidacao' => '4511']]], $this->s)->json('dados.id');
        $this->postJson("/api/pos/terminais/{$loja}/mesas", ['nome' => 'Mesa X'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'TERMINAL_SEM_MESAS');

        $m = $this->mesa('Mesa 9');
        $outra = $this->criarEmpresa(['nif' => '5417000098']);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'pos_venda' => true])->id]);
        $u->empresas()->attach($outra->id);
        $cab = $this->entrar($u) + ['X-Empresa-Id' => $outra->id];
        $this->getJson("/api/pos/mesas/{$m}/conta", $cab)->assertNotFound();
    }
}
