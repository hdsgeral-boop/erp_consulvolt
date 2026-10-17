<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\PedidoPortalColaborador;
use App\Models\TipoOrganizacaoRH;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RH › Pedidos do Portal: filtro de estado da lista do RH (GET /api/rh/portal/pedidos).
 * «Por decidir» (antigo: portal_ui.js:855) junta PENDENTE_CHEFIA e PENDENTE_RH; os estados aceites são validados.
 */
final class RhPortalPedidosFiltroEstadoTest extends TestCase
{
    private Empresa $empresa;

    private array $h = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa Fictícia', 'nif' => '5000888777']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $c = Colaborador::create(['nome_completo' => 'Colaborador Fictício', 'nif' => '999000111', 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22])->id;
            foreach (['PENDENTE_CHEFIA', 'PENDENTE_RH', 'APROVADO', 'RECUSADO', 'EMITIDO'] as $e) {
                PedidoPortalColaborador::create(['colaborador_id' => $c, 'tipo' => 'DOCUMENTO', 'dados' => [], 'etapas' => [], 'estado' => $e]);
            }
        });
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'rh_portal_aprovar' => true, 'rh_portal_gestao_view' => true])->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->h = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function por_decidir_junta_os_dois_estados_pendentes(): void
    {
        $estados = collect($this->getJson('/api/rh/portal/pedidos?estado=POR_DECIDIR', $this->h)->assertOk()->json('dados'))->pluck('estado')->sort()->values()->all();
        $this->assertSame(['PENDENTE_CHEFIA', 'PENDENTE_RH'], $estados);
    }

    #[Test]
    public function um_estado_isolado_e_sem_filtro(): void
    {
        $this->getJson('/api/rh/portal/pedidos?estado=PENDENTE_RH', $this->h)->assertOk()->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.estado', 'PENDENTE_RH');
        $this->getJson('/api/rh/portal/pedidos', $this->h)->assertOk()->assertJsonCount(5, 'dados');
    }

    #[Test]
    public function estado_desconhecido_e_recusado_na_validacao(): void
    {
        $this->getJson('/api/rh/portal/pedidos?estado='.urlencode("X' OR 1=1"), $this->h)->assertStatus(422);
        $this->getJson('/api/rh/portal/pedidos?estado=PENDENTE', $this->h)->assertStatus(422);
    }
}
