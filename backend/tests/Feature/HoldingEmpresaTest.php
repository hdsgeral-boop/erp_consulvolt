<?php

namespace Tests\Feature;

use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\Terceiro;
use App\Models\Utilizador;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Holding (empresa de consolidação) como empresa do sistema (agente r3_holding):
 *   - quem vê a holding na troca de empresa (criador, acesso a todas as empresas do grupo; não com acesso parcial);
 *   - na holding só Início, Dashboard e Contabilidade — no menu, nas permissões efectivas e no servidor (Gate);
 *   - Início da holding com o resumo consolidado e a comparação por empresa;
 *   - separador «Comparar empresas» (resposta com `modulo`; na holding, por omissão, as empresas do grupo);
 *   - migração que associa os grupos existentes a uma holding marcada.
 */
final class HoldingEmpresaTest extends TestCase
{
    private Empresa $a;

    private Empresa $b;

    private Empresa $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa(['nif' => '5417100571']);
        $this->b = $this->criarEmpresa(['nif' => '5417100572']);
        $this->c = $this->criarEmpresa(['nif' => '5417100573']);
        foreach ([$this->a, $this->b] as $e) {
            app(ContextoEmpresa::class)->executarComo($e->id, function () {
                foreach (['4511' => 'Caixa', '6111' => 'Vendas', '7111' => 'Compras'] as $c => $d) {
                    PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
                }
                DiarioContabil::create(['codigo' => 'OD', 'nome' => 'Diversos']);
                Terceiro::create(['nome' => 'Cliente externo', 'nif' => '9999999']);
            });
        }
        $this->lancar($this->a, [['4511', 'D', 800], ['6111', 'C', 800]]);
        $this->lancar($this->b, [['4511', 'D', 300], ['6111', 'C', 300]]);
        $this->lancar($this->b, [['7111', 'D', 100], ['4511', 'C', 100]]);
    }

    private function lancar(Empresa $e, array $linhas): void
    {
        app(ContextoEmpresa::class)->executarComo($e->id, function () use ($linhas) {
            app(ServicoLancamentos::class)->criar(['diario_id' => DiarioContabil::query()->where('codigo', 'OD')->value('id'), 'data_documento' => now()->startOfYear()->addDays(5)->toDateString(),
                'linhas' => array_map(fn ($l) => ['codigo_conta' => $l[0], 'tipo_dc' => $l[1], 'valor' => $l[2]], $linhas)]);
        });
    }

    /** @return array{0: Utilizador, 1: array<string, string>} */
    private function utilizador(array $permissoes, array $empresas): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach(array_map(fn (Empresa $e) => $e->id, $empresas));

        return [$u, $this->entrar($u)];
    }

    private function criarHolding(array $cab): array
    {
        return $this->postJson('/api/consolidacao/grupos', ['nome' => 'Grupo AB — Consolidado', 'nif' => '5417100579', 'membros' => [$this->a->id, $this->b->id]],
            $cab + ['X-Empresa-Id' => $this->a->id])->assertCreated()->json('dados');
    }

    private static function idsEmpresas(array $r): array
    {
        return array_map(fn ($e) => $e['id'], $r);
    }

    #[Test]
    public function holding_aparece_a_quem_a_cria_e_a_quem_acede_a_todas_as_empresas_do_grupo(): void
    {
        [, $criador] = $this->utilizador(['consolidacao_view', 'consol_gerir', 'consol_executar'], [$this->a, $this->b]);
        [$uTodas, $todas] = $this->utilizador(['lancamentos_view'], [$this->a, $this->b]);
        [, $parcial] = $this->utilizador(['lancamentos_view'], [$this->a, $this->c]);
        // pedidos antes da criação: a lista fica em cache e tem de ser invalidada pelo grupo novo
        $this->getJson('/api/autenticacao/eu', $todas)->assertOk();

        $g = $this->criarHolding($criador);
        $h = $g['holding']['id'];
        $this->assertContains($h, self::idsEmpresas($this->getJson('/api/autenticacao/eu', $criador)->assertOk()->json('dados.empresas')));
        $eu = collect($this->getJson('/api/autenticacao/eu', $todas)->assertOk()->json('dados.empresas'))->keyBy('id');
        $this->assertTrue($eu->has($h));
        $this->assertTrue($eu[$h]['e_consolidacao']);
        $this->assertNotContains($h, self::idsEmpresas($this->getJson('/api/autenticacao/eu', $parcial)->assertOk()->json('dados.empresas')));
        $this->getJson('/api/sistema/menu', $parcial + ['X-Empresa-Id' => $h])->assertForbidden();
        $this->getJson('/api/sistema/menu', $todas + ['X-Empresa-Id' => $h])->assertOk();

        // tirar uma empresa do grupo ao utilizador retira-lhe a holding (derivada)
        $uTodas->empresas()->detach($this->b->id);
        $this->assertNotContains($h, self::idsEmpresas($this->getJson('/api/autenticacao/eu', $todas)->assertOk()->json('dados.empresas')));
    }

    #[Test]
    public function na_holding_so_inicio_dashboard_e_contabilidade_tambem_no_servidor(): void
    {
        $s = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR]);
        $cab = $this->entrar($s);
        $g = $this->criarHolding($cab);
        $h = $g['holding']['id'];

        $menu = $this->getJson('/api/sistema/menu', $cab + ['X-Empresa-Id' => $h])->assertOk()->json('dados');
        $this->assertSame(['geral', 'contab'], array_column($menu['menu'], 'id'));
        $this->assertSame(['dashboard'], array_column($menu['menu'][0]['ecras'], 'id'));
        $this->assertContains('consolidacao', array_column($menu['menu'][1]['ecras'], 'id'));
        // numa empresa normal o menu fica completo
        $normal = $this->getJson('/api/sistema/menu', $cab + ['X-Empresa-Id' => $this->a->id])->assertOk()->json('dados.menu');
        $this->assertContains('rh', array_column($normal, 'id'));

        // servidor: outros módulos recusados na holding (mesmo ao superadministrador), contabilidade e dashboard aceites
        $this->getJson('/api/rh/colaboradores', $cab + ['X-Empresa-Id' => $h])->assertForbidden();
        $this->getJson('/api/rh/colaboradores', $cab + ['X-Empresa-Id' => $this->a->id])->assertOk();
        $this->getJson('/api/consolidacao/grupos', $cab + ['X-Empresa-Id' => $h])->assertOk();
        $this->getJson('/api/gestao/paineis', $cab + ['X-Empresa-Id' => $h])->assertOk()->assertJsonPath('dados.holding', true);

        // perfil restrito: as permissões efectivas na holding perdem as chaves dos outros módulos
        [, $rest] = $this->utilizador(['colaboradores_view', 'lancamentos_view', 'dashboard_view'], [$this->a, $this->b]);
        $ef = $this->getJson('/api/sistema/menu', $rest + ['X-Empresa-Id' => $h])->assertOk()->json('dados.permissoes');
        $this->assertSame(['dashboard_view', 'lancamentos_view'], $ef);
        $this->getJson('/api/rh/colaboradores', $rest + ['X-Empresa-Id' => $h])->assertForbidden();
    }

    #[Test]
    public function comparar_empresas_traz_o_modulo_e_na_holding_compara_as_empresas_do_grupo(): void
    {
        [, $cab] = $this->utilizador(['consolidacao_view', 'consol_gerir', 'consol_executar', 'dashboard_view', 'lancamentos_view'], [$this->a, $this->b, $this->c]);
        $ano = now()->year;
        $mes = now()->month;
        $r = $this->getJson("/api/gestao/paineis/comparacao?ano={$ano}&mes={$mes}", $cab + ['X-Empresa-Id' => $this->a->id])->assertOk()->json('dados');
        $this->assertSame(['id' => '__comparacao', 'nome' => 'Comparar empresas'], $r['modulo']);
        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id, $this->c->id], array_column($r['empresas'], 'empresa_id'));

        $g = $this->criarHolding($cab);
        $h = $g['holding']['id'];
        $this->postJson("/api/consolidacao/grupos/{$g['id']}/executar", ['data_fim' => now()->endOfYear()->toDateString()], $cab + ['X-Empresa-Id' => $h])->assertOk();
        $r = $this->getJson("/api/gestao/paineis/comparacao?ano={$ano}&mes={$mes}", $cab + ['X-Empresa-Id' => $h])->assertOk()->json('dados');
        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id], array_column($r['empresas'], 'empresa_id'));
        $quadro = collect(collect($r['tabelas'])->firstWhere('id', 'quadro_comparativo')['linhas'])->keyBy('chave');
        $this->assertSame('800.00', $quadro[(string) $this->a->id]['proveitos']);
        $this->assertSame('200.00', $quadro[(string) $this->b->id]['resultado']);
        $this->assertSame('1100.00', $quadro['TOTAL']['proveitos']);

        // painel do grupo e Início da holding: consolidado + comparação por empresa
        $grupo = $this->getJson("/api/gestao/paineis/grupo?ano={$ano}&mes={$mes}", $cab + ['X-Empresa-Id' => $h])->assertOk()->json('dados');
        $this->assertSame('1100.00', collect($grupo['kpis'])->firstWhere('id', 'proveitos')['valor']);
        $ini = $this->getJson('/api/gestao/inicio', $cab + ['X-Empresa-Id' => $h])->assertOk()->json('dados');
        $this->assertTrue($ini['empresa']['holding']);
        $this->assertSame(2, $ini['holding']['empresas']);
        $this->assertSame('1000.00', collect($ini['holding']['kpis'])->firstWhere('id', 'resultado')['valor']);
        $this->assertSame([false], array_values(array_unique(array_column(array_filter($ini['modulos'], fn ($m) => $m['id'] !== 'contabilidade'), 'autorizado'))));
        $this->assertSame([], $ini['pendentes']);

        // um movimento novo numa empresa do grupo fica pendente de consolidação no Início da holding
        DB::table('lancamentos_contabeis')->update(['criado_em' => now()->subHour()]);
        DB::table('execucoes_consolidacao')->update(['executado_em' => now()->subMinutes(30)]);
        $this->lancar($this->a, [['4511', 'D', 50], ['6111', 'C', 50]]);
        $ini = $this->getJson('/api/gestao/inicio', $cab + ['X-Empresa-Id' => $h])->assertOk()->json('dados');
        $this->assertSame([['id' => 'consolidacao_desactualizada', 'rotulo' => 'Linhas das empresas por consolidar', 'quantidade' => 2, 'vista' => 'consolidacao']], $ini['pendentes']);

        // sem empresas para comparar não há SQL inválido
        $this->getJson('/api/gestao/paineis/comparacao?empresas[]='.$this->c->id, $cab + ['X-Empresa-Id' => $this->a->id])->assertOk()->assertJsonPath('dados.modulo.id', '__comparacao');
    }

    #[Test]
    public function migracao_associa_os_grupos_existentes_a_uma_holding_marcada(): void
    {
        $semMarca = $this->criarEmpresa(['nif' => '5417100590']);
        $g1 = DB::table('grupos_consolidacao')->insertGetId(['empresa_id' => $semMarca->id, 'empresa_holding_id' => $semMarca->id, 'nome' => 'Grupo antigo']);
        DB::table('membros_consolidacao')->insert(['empresa_id' => $semMarca->id, 'grupo_consolidacao_id' => $g1, 'empresa_membro_id' => $this->a->id, 'percentagem' => 100, 'metodo' => 'INTEGRAL']);
        $g2 = DB::table('grupos_consolidacao')->insertGetId(['empresa_id' => $this->c->id, 'empresa_holding_id' => null, 'nome' => 'Grupo sem holding']);
        DB::table('membros_consolidacao')->insert(['empresa_id' => $this->c->id, 'grupo_consolidacao_id' => $g2, 'empresa_membro_id' => $this->b->id, 'percentagem' => 100, 'metodo' => 'INTEGRAL']);
        // empresa com movimentos próprios não é marcada (fica para decisão manual)
        $g3 = DB::table('grupos_consolidacao')->insertGetId(['empresa_id' => $this->a->id, 'empresa_holding_id' => $this->a->id, 'nome' => 'Grupo errado']);

        (require database_path('migrations/2026_10_17_101530_r3_holding_associar_grupos_holdings.php'))->up();

        $this->assertTrue((bool) DB::table('empresas')->where('id', $semMarca->id)->value('e_consolidacao'));
        $nova = DB::table('grupos_consolidacao')->where('id', $g2)->value('empresa_holding_id');
        $this->assertNotNull($nova);
        $this->assertSame(['Grupo sem holding', 'HOLDING-G'.$g2, true], array_values((array) DB::table('empresas')->where('id', $nova)->first(['nome', 'nif', 'e_consolidacao'])));
        $this->assertSame((int) $nova, (int) DB::table('membros_consolidacao')->where('grupo_consolidacao_id', $g2)->value('empresa_id'));
        $this->assertFalse((bool) DB::table('empresas')->where('id', $this->a->id)->value('e_consolidacao'));
        $this->assertSame($this->a->id, (int) DB::table('grupos_consolidacao')->where('id', $g3)->value('empresa_holding_id'));
        // idempotente
        (require database_path('migrations/2026_10_17_101530_r3_holding_associar_grupos_holdings.php'))->up();
        $this->assertSame(1, DB::table('empresas')->where('nif', 'HOLDING-G'.$g2)->count());
    }
}
