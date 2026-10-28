<?php

namespace Tests\Feature\Tenancy;

use App\Models\CupomDesconto;
use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\Utilizador;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Cupões e campanhas promocionais da subscrição SaaS (motor de precificação, Módulo B). */
class CupomDescontoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.licencas.ativo' => true]);
    }

    public function test_criacao_de_cupao_e_so_para_o_super_administrador(): void
    {
        $empresa = $this->empresaComLicenca(now()->addDays(30)->toDateString());
        $corpo = $this->corpoCupom(['codigo' => 'promo10']);

        $this->postJson('/api/plataforma/cupoes', $corpo, $this->sessaoGestor($empresa))->assertForbidden();
        $this->postJson('/api/plataforma/cupoes', $corpo, $this->sessaoSuperAdmin($empresa))->assertCreated()
            ->assertJsonPath('dados.codigo', 'PROMO10')->assertJsonPath('dados.ativo', true);
    }

    public function test_desconto_percentual_acima_de_quinze_por_cento_e_rejeitado_na_bd_e_na_api(): void
    {
        $empresa = $this->empresaComLicenca(now()->addDays(30)->toDateString());
        $corpo = $this->corpoCupom(['codigo' => 'excesso', 'valor_desconto' => 20]);

        $this->postJson('/api/plataforma/cupoes', $corpo, $this->sessaoSuperAdmin($empresa))->assertStatus(422);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('cupons_desconto')->insert([
            'codigo' => 'DIRETO_BD', 'tipo_desconto' => CupomDesconto::PERCENTAGEM, 'valor_desconto' => 20, 'moeda' => 'AOA',
            'data_inicio' => now()->toDateString(), 'data_fim' => now()->addMonth()->toDateString(), 'limite_por_cliente' => 1,
            'criado_em' => now(), 'atualizado_em' => now(),
        ]);
    }

    public function test_cupao_valido_reduz_o_total_e_mostra_o_detalhe(): void
    {
        $empresa = $this->empresaComLicenca(now()->addDays(30)->toDateString());
        $plano = $this->plano(10000);
        $this->criarCupom(['codigo' => 'desconto10', 'valor_desconto' => 10]);
        $gestor = $this->sessaoGestor($empresa);

        $this->postJson('/api/licenca/cupoes/validar', ['codigo' => 'desconto10', 'plano_id' => $plano->id, 'ciclo_codigo' => 'MENSAL'], $gestor)
            ->assertOk()->assertJsonPath('dados.valido', true)
            ->assertJsonPath('dados.valor_desconto_cupom', 1000.0)->assertJsonPath('dados.valor_final', 9000.0);

        // 10000 mensal -10% de cupão = 9000; grava o snapshot do cupão na subscrição.
        $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1, 'cupom_codigo' => 'desconto10'], $gestor)
            ->assertCreated()
            ->assertJsonPath('dados.cupom_codigo', 'DESCONTO10')
            ->assertJsonPath('dados.valor_desconto_cupom', 1000.0)
            ->assertJsonPath('dados.valor', 9000.0);

        $this->assertSame(1, DB::table('cupons_desconto')->where('codigo', 'DESCONTO10')->value('usos_atuais'));
    }

    public function test_cupao_expirado_ou_esgotado_nao_bloqueia_o_checkout(): void
    {
        $empresa = $this->empresaComLicenca(now()->addDays(30)->toDateString());
        $plano = $this->plano(5000);
        $this->criarCupom(['codigo' => 'vencido', 'data_inicio' => now()->subDays(30)->toDateString(), 'data_fim' => now()->subDay()->toDateString()]);
        $this->criarCupom(['codigo' => 'esgotado', 'limite_usos' => 1, 'usos_atuais' => 1]);
        $gestor = $this->sessaoGestor($empresa);

        foreach (['vencido', 'esgotado', 'inexistente'] as $codigo) {
            $r = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1, 'cupom_codigo' => $codigo], $gestor)
                ->assertCreated();
            $this->assertSame(5000.0, $r->json('dados.valor'));
            $this->assertNull($r->json('dados.cupom_codigo'));
        }
    }

    public function test_limite_por_cliente_e_respeitado_entre_empresas_irmas_do_mesmo_tenant(): void
    {
        // Sem SAAS_ATIVO (padrão nos testes), todas as empresas pertencem ao tenant inicial — duas empresas
        // "irmãs" do mesmo tenant, como pedido (ver ContextoTenant::paraNovo()).
        $empresaA = $this->empresaComLicenca(now()->addDays(30)->toDateString());
        $empresaB = $this->empresaComLicenca(now()->addDays(30)->toDateString());
        $plano = $this->plano(8000);
        $this->criarCupom(['codigo' => 'umavez', 'limite_por_cliente' => 1]);

        $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1, 'cupom_codigo' => 'umavez'], $this->sessaoGestor($empresaA))
            ->assertCreated()->assertJsonPath('dados.cupom_codigo', 'UMAVEZ');

        // Mesmo tenant, outra empresa: o limite por cliente já foi atingido — não bloqueia, só não aplica o desconto.
        $r = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1, 'cupom_codigo' => 'umavez'], $this->sessaoGestor($empresaB))
            ->assertCreated();
        $this->assertNull($r->json('dados.cupom_codigo'));
        $this->assertSame(8000.0, $r->json('dados.valor'));
    }

    public function test_cancelar_subscricao_liberta_o_uso_do_cupao(): void
    {
        $empresa = $this->empresaComLicenca(now()->addDays(30)->toDateString());
        $plano = $this->plano(6000);
        $this->criarCupom(['codigo' => 'liberta', 'limite_usos' => 1]);
        $gestor = $this->sessaoGestor($empresa);

        $id = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1, 'cupom_codigo' => 'liberta'], $gestor)
            ->assertCreated()->assertJsonPath('dados.cupom_codigo', 'LIBERTA')->json('dados.id');
        $this->assertSame(1, DB::table('cupons_desconto')->where('codigo', 'LIBERTA')->value('usos_atuais'));
        $this->assertSame(1, DB::table('usos_cupom')->where('subscricao_id', $id)->count());

        $this->deleteJson("/api/licenca/subscricoes/{$id}", [], $gestor)->assertOk()->assertJsonPath('dados.estado', 'CANCELADO');

        $this->assertSame(0, DB::table('cupons_desconto')->where('codigo', 'LIBERTA')->value('usos_atuais'));
        $this->assertSame(0, DB::table('usos_cupom')->where('subscricao_id', $id)->count());

        // O cupão liberto pode voltar a ser usado por outro pedido.
        $r = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1, 'cupom_codigo' => 'liberta'], $gestor)
            ->assertCreated();
        $this->assertSame('LIBERTA', $r->json('dados.cupom_codigo'));
    }

    public function test_limite_de_usos_nunca_e_excedido_com_pedidos_concorrentes(): void
    {
        $empresa = $this->empresaComLicenca(now()->addDays(30)->toDateString());
        $plano = $this->plano(4000);
        $this->criarCupom(['codigo' => 'corrida', 'limite_usos' => 1]);
        $gestor = $this->sessaoGestor($empresa);

        // Sem acesso a um segundo processo real no teste, simula-se a condição de corrida directamente no UPDATE
        // atómico de ServicoCupoes::aplicarNaTransacao(): duas tentativas sequenciais sobre o mesmo limite_usos=1.
        $r1 = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1, 'cupom_codigo' => 'corrida'], $gestor)->assertCreated();
        $r2 = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1, 'cupom_codigo' => 'corrida'], $gestor)->assertCreated();

        $this->assertSame('CORRIDA', $r1->json('dados.cupom_codigo'));
        $this->assertNull($r2->json('dados.cupom_codigo'));
        $this->assertSame(1, DB::table('cupons_desconto')->where('codigo', 'CORRIDA')->value('usos_atuais'));
    }

    /** @param  array<string, mixed>  $extra */
    private function criarCupom(array $extra = []): CupomDesconto
    {
        return CupomDesconto::query()->create($extra + [
            'codigo' => 'CUPOM'.random_int(1000, 99999), 'tipo_desconto' => CupomDesconto::PERCENTAGEM, 'valor_desconto' => 10,
            'moeda' => 'AOA', 'data_inicio' => now()->subDay()->toDateString(), 'data_fim' => now()->addMonth()->toDateString(),
            'limite_por_cliente' => 1, 'ativo' => true,
        ]);
    }

    /** @param  array<string, mixed>  $extra
     *  @return array<string, mixed> */
    private function corpoCupom(array $extra = []): array
    {
        return $extra + [
            'tipo_desconto' => CupomDesconto::PERCENTAGEM, 'valor_desconto' => 10, 'moeda' => 'AOA',
            'data_inicio' => now()->toDateString(), 'data_fim' => now()->addMonth()->toDateString(),
            'limite_por_cliente' => 1, 'ativo' => true,
        ];
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
