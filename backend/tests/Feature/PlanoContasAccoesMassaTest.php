<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Plano de contas › acções em massa do legado (caixas de selecção na 1.ª coluna, js/app_v2.js:8828-9037):
 * «Modificar em Massa» (tipo / descrição / sufixo; conta com lançamentos não passa a totalizadora) com contab_plano_gerir e
 * «Eliminar Seleccionadas» (as com lançamentos ficam; simulação antes) com contab_tabelas_del. Contas de outra empresa
 * nunca são tocadas. Valores fictícios.
 */
final class PlanoContasAccoesMassaTest extends TestCase
{
    private Empresa $a;

    private Empresa $b;

    /** @var array<string, int> codigo => id (empresa A) */
    private array $ids;

    private int $alheia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa(['nome' => 'Empresa Massa', 'nif' => '5000777711']);
        $this->b = $this->criarEmpresa(['nome' => 'Outra Massa', 'nif' => '5000777722']);
        $this->ids = $this->em($this->a, fn () => collect([['3111', 'Clientes'], ['3112', 'Clientes B'], ['62', 'Gastos'], ['6211', 'Salários']])
            ->mapWithKeys(fn ($c) => [$c[0] => PlanoConta::create(['codigo' => $c[0], 'descricao' => $c[1], 'tipo' => 'M'])->id])->all());
        $this->alheia = $this->em($this->b, fn () => PlanoConta::create(['codigo' => '3111', 'descricao' => 'Alheia', 'tipo' => 'M'])->id);
        DB::table('lancamentos_contabeis')->insert([
            ['empresa_id' => $this->a->id, 'codigo_conta' => '3111', 'tipo_dc' => 'D', 'valor' => 10, 'data_documento' => '2026-01-10'],
            ['empresa_id' => $this->a->id, 'codigo_conta' => '3111', 'tipo_dc' => 'C', 'valor' => 10, 'data_documento' => '2026-01-11'],
        ]);
    }

    private function em(Empresa $e, \Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($e->id, $f);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->a->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->a->id];
    }

    /** @return array<string, array{0: string, 1: string}> codigo => [descricao, tipo] */
    private function planoA(): array
    {
        return $this->em($this->a, fn () => PlanoConta::query()->orderBy('codigo')->get()->mapWithKeys(fn ($c) => [$c->codigo => [$c->descricao, $c->tipo]])->all());
    }

    #[Test]
    public function modificar_em_massa_exige_permissao_e_nao_converte_contas_com_movimentos(): void
    {
        $ids = [$this->ids['3111'], $this->ids['62'], $this->alheia];
        $this->postJson('/api/contabilidade/plano-contas/massa/modificar', ['ids' => $ids, 'tipo' => 'T'], $this->sessao(['config_plano_view', 'contab_tabelas_del']))->assertForbidden();
        $s = $this->sessao(['config_plano_view', 'contab_plano_gerir']);
        $this->postJson('/api/contabilidade/plano-contas/massa/modificar', ['ids' => $ids], $s)->assertStatus(422)->assertJsonPath('codigo', 'SEM_ALTERACOES');
        $this->postJson('/api/contabilidade/plano-contas/massa/modificar', ['ids' => []], $s)->assertStatus(422);

        $r = $this->postJson('/api/contabilidade/plano-contas/massa/modificar', ['ids' => $ids, 'tipo' => 'T', 'sufixo' => '(Inactiva)'], $s)->assertOk()->json('dados');
        $this->assertSame(2, $r['actualizadas']);
        $this->assertSame([['codigo' => '3111', 'descricao' => 'Clientes', 'movimentos' => 2]], $r['sem_tipo']);
        $p = $this->planoA();
        $this->assertSame(['Clientes (Inactiva)', 'M'], $p['3111']);   // descrição sim, tipo não (tem lançamentos)
        $this->assertSame(['Gastos (Inactiva)', 'T'], $p['62']);
        $this->assertSame(['Clientes B', 'M'], $p['3112']);
        // a conta de outra empresa não é tocada
        $this->assertSame('Alheia', $this->em($this->b, fn () => PlanoConta::query()->find($this->alheia)?->descricao));

        $this->postJson('/api/contabilidade/plano-contas/massa/modificar', ['ids' => [$this->ids['3112'], $this->ids['6211']], 'descricao' => '  Nova descrição '], $s)->assertOk();
        $this->assertSame(['Nova descrição', 'Nova descrição'], [$this->planoA()['3112'][0], $this->planoA()['6211'][0]]);
        // a cache do plano é invalidada: a lista já mostra as descrições novas
        $lista = collect($this->getJson('/api/contabilidade/plano-contas', $s)->assertOk()->json('dados'))->pluck('descricao', 'codigo');
        $this->assertSame('Gastos (Inactiva)', $lista['62']);
    }

    #[Test]
    public function eliminar_em_massa_simula_mantem_as_com_movimentos_e_exige_permissao(): void
    {
        $ids = [$this->ids['3111'], $this->ids['3112'], $this->ids['62'], $this->alheia];
        $this->postJson('/api/contabilidade/plano-contas/massa/eliminar', ['ids' => $ids], $this->sessao(['config_plano_view', 'contab_plano_gerir']))->assertForbidden();
        $s = $this->sessao(['config_plano_view', 'contab_tabelas_del']);

        $sim = $this->postJson('/api/contabilidade/plano-contas/massa/eliminar', ['ids' => $ids, 'simular' => true], $s)->assertOk()->json('dados');
        $this->assertSame([['3112', '62'], true], [$sim['eliminadas'], $sim['simulacao']]);
        $this->assertSame([['codigo' => '3111', 'descricao' => 'Clientes', 'movimentos' => 2]], $sim['mantidas']);
        $this->assertCount(4, $this->planoA());   // a simulação não apaga

        $r = $this->postJson('/api/contabilidade/plano-contas/massa/eliminar', ['ids' => $ids], $s)->assertOk()->json('dados');
        $this->assertSame(['3112', '62'], $r['eliminadas']);
        $this->assertSame(['3111', '6211'], array_map('strval', array_keys($this->planoA())));
        $this->assertSame('Alheia', $this->em($this->b, fn () => PlanoConta::query()->find($this->alheia)?->descricao));
    }
}
