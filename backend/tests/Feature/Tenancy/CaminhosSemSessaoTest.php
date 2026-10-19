<?php

namespace Tests\Feature\Tenancy;

use App\Models\LigacaoAgtEmpresa;
use App\Services\Vendas\Agt\ServicoWebhookAgt;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * SaaS fase 3 (ADR-073): os caminhos SEM sessão têm de funcionar com a RLS activa. Aqui a ligação passa a `erp_app`
 * (`SET ROLE`) e o SaaS fica activo, sem tenant definido, como num pedido do webhook da AGT, do Power BI ou do agendador.
 */
class CaminhosSemSessaoTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::statement('RESET ROLE');
        DB::selectOne("SELECT set_config('app.tenant_id', '', false)");

        parent::tearDown();
    }

    public function test_webhook_agt_encontra_a_empresa_pelo_token_sem_tenant(): void
    {
        $empresa = $this->criarEmpresa();
        $token = str_repeat('a1B2', 8);
        app(ContextoEmpresa::class)->executarComo($empresa->id, fn () => LigacaoAgtEmpresa::query()->forceCreate([
            'empresa_id' => $empresa->id, 'ativo' => true, 'webhook_ativo' => true,
            'webhook_token_hash' => hash('sha256', $token), 'webhook_segredo' => 'segredo-de-teste',
        ]));

        $this->comSaasComoApp();

        // Encontrada (falta o carimbo/nonce/assinatura => 401); se estivesse cega seria 404.
        $r = app(ServicoWebhookAgt::class)->receber($token, '{}', null, null, null, '127.0.0.1');
        $this->assertSame(401, $r['estado']);

        // Token desconhecido continua a dar 404 uniforme.
        $this->assertSame(404, app(ServicoWebhookAgt::class)->receber(str_repeat('z9Y8', 8), '{}', null, null, null, null)['estado']);
    }

    public function test_funcoes_de_resolucao_so_devolvem_o_id_da_empresa(): void
    {
        $empresa = $this->criarEmpresa();
        DB::table('tokens_bi')->insert(['empresa_id' => $empresa->id, 'hash_token' => hash('sha256', 'bi_teste_123')]);

        $this->comSaasComoApp();

        $this->assertSame($empresa->id, (int) DB::selectOne('SELECT app_empresa_por_token_bi(?) AS e', [hash('sha256', 'bi_teste_123')])->e);
        $this->assertNull(DB::selectOne('SELECT app_empresa_por_token_bi(?) AS e', [hash('sha256', 'desconhecido')])->e);
        // Sem a função, a tabela continua cega para a role da aplicação sem tenant.
        $this->assertSame(0, DB::table('tokens_bi')->count());
    }

    public function test_ciclo_agt_percorre_os_tenants_e_ve_as_ligacoes(): void
    {
        config(['erp.agt.driver' => 'desligado']);
        $empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($empresa->id, fn () => LigacaoAgtEmpresa::query()->forceCreate(['empresa_id' => $empresa->id, 'ativo' => true]));
        Bus::fake();

        $this->comSaasComoApp();

        Artisan::call('erp:agt:ciclo');

        // Cego (sem porTenant) escreveria «nada a fazer»; com o tenant definido percorre a empresa.
        $this->assertStringContainsString('Ciclo AGT agendado', Artisan::output());
    }

    public function test_comandos_de_manutencao_passam_para_a_ligacao_do_dono(): void
    {
        config(['erp.saas.ativo' => true, 'erp.saas.ligacao_dono' => 'pgsql_owner']);
        $anterior = DB::getDefaultConnection();

        try {
            event(new CommandStarting('erp:migracao:pos-carga', new ArrayInput([]), new NullOutput));
            $this->assertSame('pgsql_owner', DB::getDefaultConnection());
        } finally {
            DB::setDefaultConnection($anterior);
        }

        event(new CommandStarting('erp:agt:ciclo', new ArrayInput([]), new NullOutput));
        $this->assertSame($anterior, DB::getDefaultConnection());
    }

    private function comSaasComoApp(): void
    {
        config(['erp.saas.ativo' => true]);
        app(ContextoTenant::class)->limpar();
        DB::statement('SET ROLE erp_app');
    }
}
