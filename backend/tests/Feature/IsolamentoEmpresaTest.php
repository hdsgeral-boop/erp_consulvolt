<?php

namespace Tests\Feature;

use App\Exceptions\ErroContextoEmpresa;
use App\Models\LogAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Global Scope multi-empresa (EscopoEmpresa + PertenceEmpresa), política fail-closed. */
final class IsolamentoEmpresaTest extends TestCase
{
    private function log(int $empresaId, string $detalhes): void
    {
        app(ContextoEmpresa::class)->semIsolamento(fn () => LogAuditoria::create([
            'empresa_id' => $empresaId, 'modulo' => 'Teste', 'acao' => 'Registo', 'detalhes' => $detalhes, 'ocorrido_em' => now(),
        ]));
    }

    #[Test]
    public function cada_empresa_so_ve_os_seus_registos_pela_api(): void
    {
        $a = $this->criarEmpresa();
        $b = $this->criarEmpresa();
        $this->log($a->id, 'registo da empresa A');
        $this->log($b->id, 'registo da empresa B');

        $u = $this->criarUtilizador(['acesso_todas_empresas' => true, 'perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'config_logs_view' => true])->id]);
        $cabecalhos = $this->entrar($u);

        $deA = $this->getJson('/api/sistema/logs?modulo=Teste', $cabecalhos + ['X-Empresa-Id' => $a->id])->assertOk();
        $deA->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.detalhes', 'registo da empresa A')
            ->assertJsonPath('metadados.paginacao.total', 1);

        $deB = $this->getJson('/api/sistema/logs?modulo=Teste', $cabecalhos + ['X-Empresa-Id' => $b->id])->assertOk();
        $deB->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.detalhes', 'registo da empresa B');
    }

    #[Test]
    public function consulta_sem_empresa_activa_e_recusada(): void
    {
        $this->expectException(ErroContextoEmpresa::class);

        LogAuditoria::query()->count();
    }

    #[Test]
    public function escrita_com_empresa_diferente_da_activa_e_recusada(): void
    {
        $a = $this->criarEmpresa();
        $b = $this->criarEmpresa();

        $this->expectException(ErroContextoEmpresa::class);
        $this->expectExceptionMessage("empresa {$b->id}");

        app(ContextoEmpresa::class)->executarComo($a->id, fn () => LogAuditoria::create([
            'empresa_id' => $b->id, 'modulo' => 'Teste', 'acao' => 'Registo', 'ocorrido_em' => now(),
        ]));
    }

    #[Test]
    public function criacao_preenche_a_empresa_activa_automaticamente(): void
    {
        $a = $this->criarEmpresa();
        $contexto = app(ContextoEmpresa::class);

        $log = $contexto->executarComo($a->id, fn () => LogAuditoria::create(['modulo' => 'Teste', 'acao' => 'Registo', 'ocorrido_em' => now()]));

        $this->assertSame($a->id, $log->empresa_id);
        $this->assertFalse($contexto->definida());   // contexto reposto após executarComo
    }
}
