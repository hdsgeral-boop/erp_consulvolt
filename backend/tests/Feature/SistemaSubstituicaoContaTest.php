<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\LogAuditoria;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Substituir conta (ADR-058): pré-visualização por empresa, só contas de movimento existentes, transacção única,
 * alteração das fichas/configurações e dos documentos de tesouraria pendentes — nunca dos lançamentos (ADR-016).
 */
final class SistemaSubstituicaoContaTest extends TestCase
{
    private Empresa $a;

    private Empresa $b;

    private array $s;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa(['nif' => '5417000401']);
        $this->b = $this->criarEmpresa(['nif' => '5417000402']);
        $contexto = app(ContextoEmpresa::class);
        $contexto->executarComo($this->a->id, function () {
            foreach (['3212' => ['Fornecedores antigos', 'M'], '32121' => ['Fornecedores nacionais', 'M'], '32' => ['Fornecedores', 'T'], '752' => ['Serviços', 'M']] as $c => [$d, $t]) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => $t]);
            }
            $this->ids['terceiro'] = Terceiro::create(['nome' => 'Fornecedor', 'nif' => '5000000011', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '3212'])->id;
            $this->ids['outro'] = Terceiro::create(['nome' => 'Outro', 'nif' => '5000000012', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '752'])->id;
            $this->ids['produto'] = Produto::create(['codigo' => 'S1', 'nome' => 'Serviço', 'movimenta_stock' => false, 'conta_custo' => '3212'])->id;
        });
        $contexto->executarComo($this->b->id, fn () => PlanoConta::create(['codigo' => '3212', 'descricao' => 'Fornecedores', 'tipo' => 'M']));
        $pendente = DB::table('documentos_tesouraria')->insertGetId(['empresa_id' => $this->a->id, 'estado' => 'PENDENTE', 'conta_financeira' => '4311']);
        $integrado = DB::table('documentos_tesouraria')->insertGetId(['empresa_id' => $this->a->id, 'estado' => 'INTEGRADO', 'conta_financeira' => '4311']);
        DB::table('itens_documento_tesouraria')->insert([['empresa_id' => $this->a->id, 'documento_tesouraria_id' => $pendente, 'codigo_conta' => '3212'],
            ['empresa_id' => $this->a->id, 'documento_tesouraria_id' => $integrado, 'codigo_conta' => '3212']]);
        DB::table('lancamentos_contabeis')->insert(['empresa_id' => $this->a->id, 'codigo_conta' => '3212', 'tipo_dc' => 'C', 'valor' => 100, 'data_documento' => '2026-01-10']);
        DB::table('configuracoes_acrescimos_diferimentos')->insert(['empresa_id' => $this->a->id, 'contas' => json_encode(['ACRESCIMO_CUSTO' => '3212', 'DIFERIMENTO_CUSTO' => '3743'])]);
        $this->ids['pendente'] = $pendente;

        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'contab_conta_substituir' => true])->id]);
        $u->empresas()->attach([$this->a->id, $this->b->id]);
        $this->s = $this->entrar($u) + ['X-Empresa-Id' => $this->a->id];
    }

    #[Test]
    public function pre_visualiza_por_empresa_separando_alteraveis_e_historico(): void
    {
        $r = $this->postJson('/api/sistema/plano-contas/substituir/simular', ['origem' => '3212', 'destino' => '32121', 'empresas' => [$this->a->id, $this->b->id]], $this->s)->assertOk();
        [$a, $b] = $r->json('dados');
        $this->assertTrue($a['pode_substituir']);
        $alteraveis = collect($a['alteraveis'])->mapWithKeys(fn ($x) => ["{$x['tabela']}.{$x['coluna']}" => $x['registos']])->all();
        $this->assertEquals(['terceiros.codigo_conta' => 1, 'produtos.conta_custo' => 1, 'itens_documento_tesouraria.codigo_conta' => 1,
            'configuracoes_acrescimos_diferimentos.contas' => 1], $alteraveis);
        $informativos = collect($a['informativos'])->mapWithKeys(fn ($x) => ["{$x['tabela']}.{$x['coluna']}" => $x['registos']])->all();
        $this->assertEquals(['lancamentos_contabeis.codigo_conta' => 1, 'itens_documento_tesouraria.codigo_conta' => 1], $informativos);
        $this->assertFalse($b['pode_substituir']);
        $this->assertStringContainsString('32121 não existe', $b['motivo']);
    }

    #[Test]
    public function substitui_numa_transaccao_sem_tocar_nos_lancamentos(): void
    {
        $this->postJson('/api/sistema/plano-contas/substituir', ['origem' => '3212', 'destino' => '32121', 'empresas' => [$this->a->id, $this->b->id], 'confirmar' => true], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'SUBSTITUICAO_IMPOSSIVEL');
        $this->assertSame('3212', DB::table('terceiros')->where('id', $this->ids['terceiro'])->value('codigo_conta'));

        $r = $this->postJson('/api/sistema/plano-contas/substituir', ['origem' => '3212', 'destino' => '32121', 'confirmar' => true], $this->s)->assertOk();
        $this->assertSame(4, $r->json('dados.0.total'));
        $this->assertSame('32121', DB::table('terceiros')->where('id', $this->ids['terceiro'])->value('codigo_conta'));
        $this->assertSame('752', DB::table('terceiros')->where('id', $this->ids['outro'])->value('codigo_conta'));
        $this->assertSame('32121', DB::table('produtos')->where('id', $this->ids['produto'])->value('conta_custo'));
        $this->assertSame('32121', DB::table('itens_documento_tesouraria')->where('documento_tesouraria_id', $this->ids['pendente'])->value('codigo_conta'));
        $this->assertSame(1, DB::table('itens_documento_tesouraria')->where('codigo_conta', '3212')->count());
        $this->assertSame('3212', DB::table('lancamentos_contabeis')->value('codigo_conta'));
        $this->assertSame('32121', json_decode(DB::table('configuracoes_acrescimos_diferimentos')->value('contas'), true)['ACRESCIMO_CUSTO']);
        $log = app(ContextoEmpresa::class)->semIsolamento(fn () => LogAuditoria::query()->where('acao', 'Substituir conta')->firstOrFail());
        $this->assertSame([$this->ids['terceiro']], $log->dados_anteriores['registos']['terceiros.codigo_conta']);
        $this->assertSame($this->a->id, $log->empresa_id);
    }

    #[Test]
    public function exige_contas_de_movimento_confirmacao_permissao_e_acesso(): void
    {
        $this->postJson('/api/sistema/plano-contas/substituir', ['origem' => '3212', 'destino' => '32', 'confirmar' => true], $this->s)->assertStatus(422);
        $this->postJson('/api/sistema/plano-contas/substituir', ['origem' => '9999', 'destino' => '32121', 'confirmar' => true], $this->s)->assertStatus(422);
        $this->postJson('/api/sistema/plano-contas/substituir', ['origem' => '3212', 'destino' => '3212', 'confirmar' => true], $this->s)->assertStatus(422);
        $this->postJson('/api/sistema/plano-contas/substituir', ['origem' => '3212', 'destino' => '32121', 'confirmar' => false], $this->s)->assertStatus(422);
        $this->postJson('/api/sistema/plano-contas/substituir/simular', ['origem' => '3212', 'destino' => '32121', 'empresas' => [$this->criarEmpresa()->id]], $this->s)
            ->assertStatus(403)->assertJsonPath('codigo', 'EMPRESA_SEM_ACESSO');
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'contab_plano_gerir' => true], 'Plano')->id]);
        $u->empresas()->attach($this->a->id);
        $this->postJson('/api/sistema/plano-contas/substituir/simular', ['origem' => '3212', 'destino' => '32121'], $this->entrar($u) + ['X-Empresa-Id' => $this->a->id])->assertForbidden();
    }
}
