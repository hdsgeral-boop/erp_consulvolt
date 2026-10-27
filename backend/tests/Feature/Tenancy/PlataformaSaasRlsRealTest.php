<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\Utilizador;
use App\Services\Plataforma\ServicoClientes;
use App\Services\Plataforma\ServicoLicencaCliente;
use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Área da plataforma sob RLS real: a ligação de teste é superutilizador (ignora a RLS), por isso aqui muda-se para a
 * role `erp_app` (`SET ROLE`), igual à produção. Garante que o que a plataforma lê de TODOS os clientes funciona e que o
 * indicador de isolamento só fica verde quando tudo é verdade.
 */
class PlataformaSaasRlsRealTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::statement('RESET ROLE');
        DB::selectOne("SELECT set_config('app.tenant_id', '', false)");

        parent::tearDown();
    }

    /** @return array{0: array<string, string>, 1: int, 2: int} sessão da plataforma, empresa do tenant 1, empresa do outro cliente */
    private function cenario(): array
    {
        $sessao = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));
        $propria = $this->criarEmpresa(['nif' => '5000000101']);
        $alheia = $this->criarEmpresa(['nif' => '5000000102']);
        $outro = Tenant::query()->create(['nome' => 'Cliente Alheio', 'slug' => 'cliente-alheio']);
        DB::table('empresas')->where('id', $alheia->id)->update(['tenant_id' => $outro->id]);
        app(ServicoLicencaCliente::class)->definir((int) $outro->id, 'TESTE', now()->startOfDay(), now()->addDays(5)->startOfDay());
        config(['erp.saas.ativo' => true]);

        return [$sessao, (int) $propria->id, (int) $alheia->id];
    }

    public function test_indicador_so_fica_verde_com_saas_ligacao_sem_privilegios_e_politicas(): void
    {
        $rls = new IsolamentoRls(DB::connection());

        // SaaS desligado: mesmo com políticas, não está efectivo e diz porquê.
        config(['erp.saas.ativo' => false]);
        $estado = $rls->estado();
        $this->assertFalse($estado['ativo']);
        $this->assertFalse($estado['verificacoes']['saas_ativo']['ok']);
        $this->assertNotEmpty($estado['verificacoes']['saas_ativo']['motivo']);
        $this->assertTrue($estado['verificacoes']['politicas']['ok']);
        foreach (['saas_ativo', 'ligacao_sem_privilegios', 'nao_dona_das_tabelas', 'row_security', 'politicas'] as $chave) {
            $this->assertArrayHasKey($chave, $estado['verificacoes']);
        }

        // Ligação privilegiada (superutilizador de teste): continua a não estar efectivo.
        config(['erp.saas.ativo' => true]);
        $papel = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        if ($papel->rolsuper || $papel->rolbypassrls) {
            $estado = $rls->estado();
            $this->assertFalse($estado['ativo']);
            $this->assertFalse($estado['verificacoes']['ligacao_sem_privilegios']['ok']);
        }

        // Como erp_app, com SaaS ligado: tudo verdadeiro.
        DB::statement('SET ROLE erp_app');
        $estado = $rls->estado();
        $this->assertSame([], $estado['tabelas_sem_rls']);
        $this->assertTrue($estado['ativo'], json_encode($estado['verificacoes']));
        $this->assertSame('erp_app', $estado['utilizador']);
    }

    public function test_estado_devolve_valor_efetivo_e_divergencia_com_o_gravado(): void
    {
        [$sessao] = $this->cenario();
        config(['erp.saas.ativo' => true, 'erp.licencas.ativo' => false]);
        DB::table('definicoes_plataforma')->updateOrInsert(['chave' => 'licencas_ativas'], ['valor' => 'true', 'atualizado_em' => now()]);

        $dados = $this->getJson('/api/plataforma/saas/estado', $sessao)->assertOk()->json('dados');

        $this->assertTrue($dados['licencas_ativas']);
        $this->assertFalse($dados['efetivo']['licencas_ativas']);
        $this->assertTrue($dados['divergencia']['licencas_ativas']);
        $this->assertTrue($dados['efetivo']['saas_ativo']);
        $this->assertArrayHasKey('verificacoes', $dados['isolamento']);
        $this->assertIsBool($dados['isolamento']['ativo']);
    }

    public function test_plataforma_ve_empresas_de_todos_os_clientes_com_rls_real(): void
    {
        [$sessao, $propria, $alheia] = $this->cenario();
        DB::statement('SET ROLE erp_app');

        // Serviço de clientes (painel, lista de clientes e empresas).
        $ids = app(ServicoClientes::class)->empresas()->pluck('id')->all();
        $this->assertContains($propria, $ids);
        $this->assertContains($alheia, $ids);
        // as listas do negócio não incluem a própria plataforma (não é um cliente)
        $clientes = app(ServicoClientes::class)->empresas(false)->pluck('id')->all();
        $this->assertNotContains($propria, $clientes);
        $this->assertContains($alheia, $clientes);
        $porCliente = app(ServicoClientes::class)->listar()->mapWithKeys(fn ($i) => [$i['tenant']->slug => $i['empresas']]);
        $this->assertSame(1, $porCliente['cliente-alheio']);

        // Gestão de licenças: lista e estatísticas incluem o outro cliente.
        $lista = $this->getJson('/api/plataforma/saas/empresas', $sessao)->assertOk()->json('dados');
        $linha = collect($lista)->firstWhere('id', $alheia);
        $this->assertNotNull($linha);
        $this->assertSame('Cliente Alheio', $linha['tenant_nome']);
        $this->assertSame('TESTE', $linha['origem']);

        $estado = $this->getJson('/api/plataforma/saas/estado', $sessao)->assertOk();
        $estado->assertJsonPath('dados.estatisticas.clientes_em_teste', 1);   // a licença é do cliente
        $this->assertSame(1, $estado->json('dados.estatisticas.empresas_total'));   // só a do cliente: a da plataforma não conta
        $this->assertNull(collect($lista)->firstWhere('id', $propria));
    }

    public function test_plataforma_altera_licenca_de_empresa_de_outro_cliente_com_rls_real(): void
    {
        [$sessao, , $alheia] = $this->cenario();
        DB::statement('SET ROLE erp_app');

        $this->putJson("/api/plataforma/saas/empresas/{$alheia}", ['origem' => 'SEM_LIMITE'], $sessao)
            ->assertOk()->assertJsonPath('dados.origem', 'SEM_LIMITE');
        $this->putJson('/api/plataforma/saas/empresas/999999', ['origem' => 'SEM_LIMITE'], $sessao)->assertStatus(404);

        DB::statement('RESET ROLE');
        $this->assertSame('SEM_LIMITE', DB::table('empresas')->where('id', $alheia)->value('licenca_origem'));
    }
}
