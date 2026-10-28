<?php

namespace Tests\Feature\Tenancy;

use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\Utilizador;
use App\Services\Plataforma\ServicoVersoesPlano;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Cobrança da licença por plano e tempo (ADR-074): pedido → pagamento confirmado por super-administrador → licença prolongada. */
class LicencaCobrancaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.licencas.ativo' => true]);
    }

    /** A landing page (sem autenticação) mostra sempre os módulos reais do plano em vigor — nunca uma lista à parte. */
    public function test_planos_publicos_e_definicoes_publicas_expoem_os_modulos_reais(): void
    {
        $plano = PlanoLicenca::create(['codigo' => 'publico-teste', 'nome' => 'Plano Público Teste', 'preco_mensal' => 5000, 'moeda' => 'AOA', 'ativo' => true]);
        $servico = app(ServicoVersoesPlano::class);
        $servico->publicar($servico->atualizarRascunho($servico->criarRascunho($plano), ['modulos' => ['vendas', 'teso']]));

        $this->getJson('/api/sistema/planos-publicos')->assertOk()
            ->assertJsonFragment(['codigo' => 'publico-teste', 'modulos' => ['vendas', 'teso']]);

        $this->getJson('/api/sistema/definicoes-publicas')->assertOk()
            ->assertJsonPath('dados.modulos_catalogo.vendas', 'Vendas');
    }

    public function test_estado_avisa_quando_falta_pouco_e_quando_expirou(): void
    {
        $quase = $this->empresaComLicenca(now()->addDays(3)->toDateString());
        $expirada = $this->empresaComLicenca(now()->subDay()->toDateString());

        $this->getJson('/api/licenca/estado', $this->sessaoGestor($quase))->assertOk()
            ->assertJsonPath('dados.aviso', 'A_EXPIRAR')->assertJsonPath('dados.dias_restantes', 3)->assertJsonPath('dados.somente_leitura', false);
        $this->getJson('/api/licenca/estado', $this->sessaoGestor($expirada))->assertOk()
            ->assertJsonPath('dados.aviso', 'EXPIRADA')->assertJsonPath('dados.somente_leitura', true);
    }

    public function test_renovacao_de_empresa_expirada_comeca_hoje_e_so_o_super_admin_confirma(): void
    {
        $empresa = $this->empresaComLicenca(now()->subDays(10)->toDateString());
        $plano = $this->plano(25000);
        $gestor = $this->sessaoGestor($empresa);

        // A empresa está só de leitura, mas pode pedir a renovação. 3 meses = ciclo TRIMESTRAL, 5% de desconto:
        // 25000 × 3 = 75000 bruto, -5% = 71250 líquido (CalculadoraPrecoPlano).
        $id = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 3], $gestor)->assertCreated()
            ->assertJsonPath('dados.valor', 71250.0)->assertJsonPath('dados.valor_bruto', 75000.0)
            ->assertJsonPath('dados.ciclo_codigo', 'TRIMESTRAL')->assertJsonPath('dados.desconto_ciclo_percentagem', 5.0)
            ->assertJsonPath('dados.estado', 'PENDENTE')->json('dados.id');

        // Um gestor da empresa não confirma o próprio pagamento.
        $this->postJson("/api/licenca/subscricoes/{$id}/confirmar-pagamento", [], $gestor)->assertForbidden();
        $this->assertTrue($empresa->fresh()->licencaExpirada());

        $super = $this->sessaoSuperAdmin($empresa);
        $this->postJson("/api/licenca/subscricoes/{$id}/confirmar-pagamento", ['metodo' => 'transferencia'], $super)->assertOk()
            ->assertJsonPath('dados.estado', 'PAGO')
            ->assertJsonPath('dados.periodo_inicio', now()->toDateString())
            ->assertJsonPath('dados.periodo_fim', now()->addMonthsNoOverflow(3)->subDay()->toDateString());

        $e = $empresa->fresh();
        $this->assertSame(Empresa::LICENCA_PLANO, $e->licenca_origem);
        $this->assertFalse($e->licencaExpirada());
        $this->postJson('/api/licenca/subscricoes/'.$id.'/confirmar-pagamento', [], $super)->assertStatus(422)->assertJsonPath('codigo', 'SUBSCRICAO_NAO_PENDENTE');
    }

    public function test_renovacao_antecipada_prolonga_a_partir_do_fim_actual(): void
    {
        $fim = now()->addDays(10)->startOfDay();
        $empresa = $this->empresaComLicenca($fim->toDateString());
        $plano = $this->plano(1000);

        $id = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1], $this->sessaoGestor($empresa))->assertCreated()->json('dados.id');
        $this->postJson("/api/licenca/subscricoes/{$id}/confirmar-pagamento", [], $this->sessaoSuperAdmin($empresa))->assertOk()
            ->assertJsonPath('dados.periodo_inicio', $fim->copy()->addDay()->toDateString())
            ->assertJsonPath('dados.periodo_fim', $fim->copy()->addDay()->addMonthsNoOverflow(1)->subDay()->toDateString());
    }

    public function test_pedido_pendente_pode_ser_cancelado_e_nao_prolonga(): void
    {
        $empresa = $this->empresaComLicenca(now()->subDay()->toDateString());
        $plano = $this->plano(500);
        $gestor = $this->sessaoGestor($empresa);

        $id = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1], $gestor)->assertCreated()->json('dados.id');
        $this->deleteJson("/api/licenca/subscricoes/{$id}", [], $gestor)->assertOk()->assertJsonPath('dados.estado', 'CANCELADO');
        $this->postJson("/api/licenca/subscricoes/{$id}/confirmar-pagamento", [], $this->sessaoSuperAdmin($empresa))->assertStatus(422);
        $this->assertTrue($empresa->fresh()->licencaExpirada());
    }

    public function test_empresa_sem_limite_nao_precisa_de_renovar_e_plano_inactivo_e_recusado(): void
    {
        config(['erp.licencas.ativo' => false]);
        $semLimite = $this->criarEmpresa();   // criada sem licenças activas: SEM_LIMITE (sem período de teste)
        config(['erp.licencas.ativo' => true]);
        $plano = $this->plano(100);
        $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1], $this->sessaoGestor($semLimite))
            ->assertStatus(422)->assertJsonPath('codigo', 'LICENCA_SEM_LIMITE');

        $empresa = $this->empresaComLicenca(now()->subDay()->toDateString());
        $inactivo = $this->plano(100, ['ativo' => false, 'codigo' => 'velho']);
        $this->postJson('/api/licenca/renovacao', ['plano_id' => $inactivo->id, 'meses' => 1], $this->sessaoGestor($empresa))
            ->assertStatus(422)->assertJsonPath('codigo', 'PLANO_INEXISTENTE');
    }

    /** CalculadoraPrecoPlano: /api/licenca/planos devolve a matriz dos 4 ciclos pré-calculada, sem pedido extra. */
    public function test_planos_devolvem_a_matriz_de_ciclos_pre_calculada(): void
    {
        $empresa = $this->empresaComLicenca(now()->addDays(30)->toDateString());
        $this->plano(10000);

        $planos = $this->getJson('/api/licenca/planos', $this->sessaoGestor($empresa))->assertOk()->json('dados');
        $ciclos = $planos[0]['ciclos'];

        $this->assertSame(['ciclo_codigo' => 'MENSAL', 'meses' => 1, 'desconto_percentagem' => 0.0, 'valor_bruto' => 10000.0, 'valor_desconto_ciclo' => 0.0, 'valor_apos_ciclo' => 10000.0, 'preco_mensal_efetivo' => 10000.0, 'rotulo' => 'Mensal'],
            $ciclos['MENSAL']);
        // Anual: 10000×12 = 120000 bruto, -20% = 96000 líquido, 8000/mês efectivo.
        $this->assertSame(120000.0, $ciclos['ANUAL']['valor_bruto']);
        $this->assertSame(20.0, $ciclos['ANUAL']['desconto_percentagem']);
        $this->assertSame(96000.0, $ciclos['ANUAL']['valor_apos_ciclo']);
        $this->assertSame(8000.0, $ciclos['ANUAL']['preco_mensal_efetivo']);
    }

    /** Ciclo anual (12 meses) aplica 20% e grava o valor líquido exacto na subscrição. */
    public function test_renovacao_anual_aplica_vinte_por_cento_de_desconto(): void
    {
        $empresa = $this->empresaComLicenca(now()->addDays(30)->toDateString());
        $plano = $this->plano(21000);   // Plano Empresarial

        $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 12], $this->sessaoGestor($empresa))
            ->assertCreated()
            ->assertJsonPath('dados.ciclo_codigo', 'ANUAL')
            ->assertJsonPath('dados.valor_bruto', 252000.0)
            ->assertJsonPath('dados.valor_desconto_ciclo', 50400.0)
            ->assertJsonPath('dados.valor', 201600.0);
    }

    /** Um nº de meses fora dos 4 ciclos conhecidos (ex.: 2) não aplica nenhum desconto — fica PERSONALIZADO. */
    public function test_renovacao_fora_dos_ciclos_conhecidos_fica_personalizado_sem_desconto(): void
    {
        $empresa = $this->empresaComLicenca(now()->addDays(30)->toDateString());
        $plano = $this->plano(5000);

        $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 2], $this->sessaoGestor($empresa))
            ->assertCreated()
            ->assertJsonPath('dados.ciclo_codigo', 'PERSONALIZADO')
            ->assertJsonPath('dados.desconto_ciclo_percentagem', 0.0)
            ->assertJsonPath('dados.valor', 10000.0);
    }

    public function test_catalogo_de_planos_so_o_super_admin_altera(): void
    {
        $empresa = $this->empresaComLicenca(now()->addDays(30)->toDateString());
        $corpo = ['codigo' => 'basico', 'nome' => 'Básico', 'preco_mensal' => 15000, 'moeda' => 'AOA', 'ativo' => true];

        $this->postJson('/api/licenca/planos', $corpo, $this->sessaoGestor($empresa))->assertForbidden();
        $id = $this->postJson('/api/licenca/planos', $corpo, $this->sessaoSuperAdmin($empresa))->assertCreated()->json('dados.id');
        $this->putJson("/api/licenca/planos/{$id}", ['preco_mensal' => 20000] + $corpo, $this->sessaoSuperAdmin($empresa))->assertOk()->assertJsonPath('dados.preco_mensal', 20000.0);
        $this->getJson('/api/licenca/planos', $this->sessaoGestor($empresa))->assertOk()->assertJsonCount(1, 'dados');
    }

    private function empresaComLicenca(?string $fim): Empresa
    {
        $e = $this->criarEmpresa();
        DB::table('empresas')->where('id', $e->id)->update(['licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_fim' => $fim, 'licenca_inicio' => now()->subYear()->toDateString()]);

        return $e->fresh();
    }

    /** @param  array<string, mixed>  $extra */
    private function plano(float $precoMensal, array $extra = []): PlanoLicenca
    {
        return PlanoLicenca::query()->create($extra + ['codigo' => 'plano'.random_int(100, 99999), 'nome' => 'Plano', 'preco_mensal' => $precoMensal, 'moeda' => 'AOA', 'ativo' => true]);
    }

    /** @return array<string, mixed> */
    private function sessaoGestor(Empresa $empresa): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'config_licenca_gerir' => true, 'config_licenca_view' => true])->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    /** @return array<string, mixed> */
    private function sessaoSuperAdmin(Empresa $empresa): array
    {
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }
}
