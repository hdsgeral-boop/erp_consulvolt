<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Utilizador;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Middleware `empresa` (X-Empresa-Id) e selector de empresas. */
final class EmpresaAtivaTest extends TestCase
{
    private function utilizadorComLogs(): Utilizador
    {
        return $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'config_logs_view' => true])->id]);
    }

    #[Test]
    public function exige_o_cabecalho_x_empresa_id(): void
    {
        $cabecalhos = $this->entrar($this->utilizadorComLogs());

        $this->getJson('/api/sistema/logs', $cabecalhos)->assertStatus(422)->assertJsonPath('codigo', 'EMPRESA_NAO_INDICADA');
        $this->getJson('/api/sistema/logs', $cabecalhos + ['X-Empresa-Id' => 'abc'])->assertStatus(422)->assertJsonPath('codigo', 'EMPRESA_INVALIDA');
    }

    #[Test]
    public function recusa_empresa_sem_acesso(): void
    {
        $minha = $this->criarEmpresa();
        $alheia = $this->criarEmpresa();
        $u = $this->utilizadorComLogs();
        $u->empresas()->attach($minha->id);
        $cabecalhos = $this->entrar($u);

        $this->getJson('/api/sistema/logs', $cabecalhos + ['X-Empresa-Id' => $alheia->id])
            ->assertStatus(403)->assertJsonPath('codigo', 'EMPRESA_SEM_ACESSO');
        $this->getJson('/api/sistema/logs', $cabecalhos + ['X-Empresa-Id' => 999999])
            ->assertStatus(403)->assertJsonPath('codigo', 'EMPRESA_SEM_ACESSO');
        $this->getJson('/api/sistema/logs', $cabecalhos + ['X-Empresa-Id' => $minha->id])
            ->assertOk()->assertJsonPath('metadados.empresa_id', $minha->id);
    }

    #[Test]
    public function empresa_inactiva_e_recusada_com_codigo_proprio(): void
    {
        $empresa = $this->criarEmpresa(['estado' => Empresa::ESTADO_INATIVO]);
        $u = $this->utilizadorComLogs();
        $u->empresas()->attach($empresa->id);

        $this->getJson('/api/sistema/logs', $this->entrar($u) + ['X-Empresa-Id' => $empresa->id])
            ->assertStatus(403)->assertJsonPath('codigo', 'EMPRESA_INATIVA');
    }

    #[Test]
    public function acesso_a_todas_as_empresas_e_explicito(): void
    {
        $a = $this->criarEmpresa();
        $b = $this->criarEmpresa();
        $this->criarEmpresa(['estado' => Empresa::ESTADO_INATIVO]);
        $u = $this->criarUtilizador(['acesso_todas_empresas' => true]);

        $ids = collect($this->getJson('/api/sistema/empresas', $this->entrar($u))->assertOk()->json('dados'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$a->id, $b->id], $ids);
    }

    #[Test]
    public function selector_lista_so_as_empresas_ligadas_e_nao_revela_as_outras(): void
    {
        $minha = $this->criarEmpresa(['logotipo' => 'data:image/png;base64,AAAA']);
        $alheia = $this->criarEmpresa();
        $u = $this->criarUtilizador();
        $u->empresas()->attach($minha->id);
        $cabecalhos = $this->entrar($u);

        $lista = $this->getJson('/api/sistema/empresas', $cabecalhos)->assertOk();
        $lista->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.id', $minha->id)->assertJsonPath('dados.0.tem_logotipo', true);
        $lista->assertJsonMissingPath('dados.0.logotipo');   // logótipo só no detalhe

        $this->getJson("/api/sistema/empresas/{$minha->id}", $cabecalhos)->assertOk()->assertJsonPath('dados.logotipo', 'data:image/png;base64,AAAA');
        $this->getJson("/api/sistema/empresas/{$alheia->id}", $cabecalhos)->assertNotFound();
    }

    #[Test]
    public function consulta_de_logs_exige_a_permissao_do_ecra_config_logs(): void
    {
        $empresa = $this->criarEmpresa();
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'dashboard_view' => true])->id]);
        $u->empresas()->attach($empresa->id);

        $this->getJson('/api/sistema/logs', $this->entrar($u) + ['X-Empresa-Id' => $empresa->id])
            ->assertStatus(403)->assertJsonPath('codigo', 'SEM_PERMISSAO');
    }
}
