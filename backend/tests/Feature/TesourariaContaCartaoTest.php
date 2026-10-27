<?php

namespace Tests\Feature;

use App\Models\Banco;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Conta-cartão de tesouraria: uma conta da classe 3 marcada pelo utilizador como cartão de crédito/débito da
 * empresa. Espelha FaTesourariaContasBancariasTest (42/43), mas para a classe 3. Só valores fictícios.
 */
final class TesourariaContaCartaoTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa Ficticia, Lda']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['32' => ['Fornecedores', 'T'], '3282' => ['Cartão de crédito BAI', 'M'], '3211' => ['Fornecedor normal', 'M'],
                '4511' => ['Caixa', 'M'], '611' => ['Vendas', 'M']] as $c => [$d, $t]) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => $t]);
            }
        });
        $this->s = $this->sessao(['config_plano_view', 'contab_plano_gerir']);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function conta(string $codigo): PlanoConta
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => PlanoConta::query()->where('codigo', $codigo)->firstOrFail());
    }

    #[Test]
    public function marca_uma_conta_da_classe_3_como_cartao_com_banco_e_numero(): void
    {
        $id = $this->conta('3282')->id;
        $this->putJson("/api/contabilidade/plano-contas/{$id}", ['cartao_tesouraria' => true, 'cartao_banco' => 'BAI', 'cartao_numero' => '**** 4417'], $this->s)
            ->assertOk()->assertJsonPath('dados.cartao_tesouraria', true)->assertJsonPath('dados.cartao_banco', 'BAI')->assertJsonPath('dados.cartao_numero', '**** 4417');

        $this->getJson('/api/tesouraria/contas-cartao/3282', $this->s)->assertOk()
            ->assertJsonPath('dados.cartao_banco', 'BAI')->assertJsonPath('dados.cartao_numero', '**** 4417')->assertJsonPath('dados.codigo_moeda', 'AOA');

        $lista = $this->getJson('/api/tesouraria/contas-cartao', $this->s)->assertOk()->json('dados');
        $this->assertSame(['3282'], array_column($lista, 'codigo'));
    }

    #[Test]
    public function exige_o_banco_associado(): void
    {
        $id = $this->conta('3282')->id;
        $this->putJson("/api/contabilidade/plano-contas/{$id}", ['cartao_tesouraria' => true], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CARTAO_SEM_BANCO');
        $this->assertFalse((bool) $this->conta('3282')->cartao_tesouraria);
    }

    #[Test]
    public function so_uma_conta_de_movimento_da_classe_3_pode_ser_marcada(): void
    {
        // totalizadora (32)
        $this->putJson('/api/contabilidade/plano-contas/'.$this->conta('32')->id, ['cartao_tesouraria' => true, 'cartao_banco' => 'BAI'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CARTAO_CONTA_TOTALIZADORA');
        // classe 4 (não é dívida a terceiros)
        $this->putJson('/api/contabilidade/plano-contas/'.$this->conta('4511')->id, ['cartao_tesouraria' => true, 'cartao_banco' => 'BAI'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CARTAO_CLASSE_INVALIDA');
    }

    #[Test]
    public function desmarcar_limpa_os_campos_do_cartao(): void
    {
        $id = $this->conta('3282')->id;
        $this->putJson("/api/contabilidade/plano-contas/{$id}", ['cartao_tesouraria' => true, 'cartao_banco' => 'BAI', 'cartao_numero' => '4417'], $this->s)->assertOk();
        $this->putJson("/api/contabilidade/plano-contas/{$id}", ['cartao_tesouraria' => false], $this->s)->assertOk()
            ->assertJsonPath('dados.cartao_tesouraria', false)->assertJsonPath('dados.cartao_banco', null)->assertJsonPath('dados.cartao_numero', null);
        $this->getJson('/api/tesouraria/contas-cartao/3282', $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_NAO_CARTAO');
    }

    #[Test]
    public function moeda_estrangeira_passa_a_estar_disponivel_para_uma_conta_cartao(): void
    {
        // antes de marcar como cartão: classe 3 não pode ter moeda estrangeira
        $id = $this->conta('3211')->id;
        $this->putJson("/api/contabilidade/plano-contas/{$id}", ['codigo_moeda' => 'USD'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'MOEDA_SO_CLASSE_4');
        // depois de marcada como cartão, passa a poder
        $idCartao = $this->conta('3282')->id;
        $this->putJson("/api/contabilidade/plano-contas/{$idCartao}", ['cartao_tesouraria' => true, 'cartao_banco' => 'BAI', 'codigo_moeda' => 'USD'], $this->s)
            ->assertOk()->assertJsonPath('dados.codigo_moeda', 'USD');
    }

    #[Test]
    public function isolamento_por_empresa_e_sugestao_de_bancos(): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            PlanoConta::query()->where('codigo', '3282')->update(['cartao_tesouraria' => true, 'cartao_banco' => 'BAI']);
            Banco::create(['nome' => 'Banco Ficticio B']);
            Banco::create(['nome' => 'Banco Ficticio A']);
        });
        $outra = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($outra->id, function () {
            PlanoConta::create(['codigo' => '3299', 'descricao' => 'Cartão de outra empresa', 'tipo' => 'M', 'cartao_tesouraria' => true, 'cartao_banco' => 'Outro']);
            Banco::create(['nome' => 'Banco de outra empresa']);
        });

        $this->getJson('/api/tesouraria/contas-cartao/3299', $this->s)->assertStatus(404)->assertJsonPath('codigo', 'CONTA_INEXISTENTE');
        $this->assertNotContains('3299', array_column($this->getJson('/api/tesouraria/contas-cartao', $this->s)->json('dados'), 'codigo'));
        $this->getJson('/api/tesouraria/contas-cartao/sugestoes-bancos', $this->s)->assertOk()->assertJsonPath('dados', ['Banco Ficticio A', 'Banco Ficticio B']);
    }
}
