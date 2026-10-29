<?php

namespace Tests\Feature\Tenancy;

use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\Tenant;
use App\Models\Utilizador;
use App\Services\Plataforma\ServicoClientes;
use App\Support\Tenancy\ContextoTenant;
use Tests\TestCase;

/**
 * Fase 8 da extensão SaaS (segurança.txt, secção 17, âmbito reduzido aprovado pelo utilizador): período
 * experimental configurável por plano, e aviso (não bloqueio silencioso) quando um downgrade deixa o cliente
 * acima dos novos limites de empresas/utilizadores. Sem prorata/crédito nem novas dimensões de limite — fora do
 * âmbito aprovado, por tocarem em facturação ou não terem nenhuma medição real no sistema.
 */
final class PlanoAvancadoTest extends TestCase
{
    private function admin(): array
    {
        return $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));
    }

    private function plano(array $extra = []): PlanoLicenca
    {
        return PlanoLicenca::query()->create($extra + ['codigo' => 'pav'.random_int(1000, 99999), 'nome' => 'Plano', 'preco_mensal' => 1000, 'moeda' => 'AOA', 'ativo' => true]);
    }

    /** @return array{0: Tenant, 1: Utilizador} */
    private function cliente(string $slug): array
    {
        $c = app(ServicoClientes::class)->criar([
            'nome' => $slug, 'slug' => $slug,
            'administrador' => ['nome_utilizador' => 'adm_'.$slug, 'palavra_passe' => 'SenhaForte123'],
        ]);

        return [$c['cliente'], $c['administrador']];
    }

    public function test_empresa_nova_usa_os_dias_de_teste_do_plano_do_tenant_em_vez_do_global(): void
    {
        config(['erp.licencas.ativo' => true, 'erp.licencas.dias_teste' => 30]);
        app(ContextoTenant::class)->definir(1);
        $plano = $this->plano(['dias_teste' => 7]);
        [$cliente] = $this->cliente('dias-teste-plano');
        $cliente->update(['plano' => $plano->codigo]);

        $empresa = app(ContextoTenant::class)->executarComo((int) $cliente->id, fn () => Empresa::query()->create(['nome' => 'Nova', 'nif' => '5500000001', 'moeda_funcional' => 'AOA']));

        $this->assertSame(now()->addDays(7)->toDateString(), $empresa->licenca_fim->toDateString());
    }

    public function test_empresa_nova_usa_o_global_quando_o_plano_nao_tem_dias_de_teste_proprios(): void
    {
        config(['erp.licencas.ativo' => true, 'erp.licencas.dias_teste' => 45]);
        app(ContextoTenant::class)->definir(1);
        $plano = $this->plano(); // sem dias_teste (null)
        [$cliente] = $this->cliente('dias-teste-global');
        $cliente->update(['plano' => $plano->codigo]);

        $empresa = app(ContextoTenant::class)->executarComo((int) $cliente->id, fn () => Empresa::query()->create(['nome' => 'Nova', 'nif' => '5500000002', 'moeda_funcional' => 'AOA']));

        $this->assertSame(now()->addDays(45)->toDateString(), $empresa->licenca_fim->toDateString());
    }

    public function test_downgrade_que_excede_o_limite_exige_confirmacao_e_nao_bloqueia_para_sempre(): void
    {
        $sessao = $this->admin();
        $planoGrande = $this->plano(['max_empresas' => 10, 'max_utilizadores' => 10]);
        $planoPequeno = $this->plano(['max_empresas' => 1, 'max_utilizadores' => 1]);
        [$cliente] = $this->cliente('downgrade');
        $cliente->update(['plano' => $planoGrande->codigo]);
        config(['erp.saas.ativo' => true]);

        // 2 empresas pagas (licença PLANO): já excede o plano pequeno (limite 1).
        app(ContextoTenant::class)->executarComo((int) $cliente->id, function () {
            Empresa::query()->create(['nome' => 'A', 'nif' => '5500000101', 'moeda_funcional' => 'AOA', 'licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_inicio' => now()->toDateString(), 'licenca_fim' => now()->addMonth()->toDateString()]);
            Empresa::query()->create(['nome' => 'B', 'nif' => '5500000102', 'moeda_funcional' => 'AOA', 'licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_inicio' => now()->toDateString(), 'licenca_fim' => now()->addMonth()->toDateString()]);
        });

        $this->putJson("/api/plataforma/clientes/{$cliente->id}/plano", ['plano' => $planoPequeno->codigo], $sessao)
            ->assertStatus(422)->assertJsonPath('codigo', 'PLANO_ABAIXO_DO_USO');
        $this->assertSame($planoGrande->codigo, $cliente->fresh()->plano, 'Sem confirmação, o plano não deve mudar.');

        $this->putJson("/api/plataforma/clientes/{$cliente->id}/plano", ['plano' => $planoPequeno->codigo, 'confirmar' => true], $sessao)->assertOk();
        $this->assertSame($planoPequeno->codigo, $cliente->fresh()->plano);
    }

    public function test_mudanca_de_plano_dentro_do_limite_nao_exige_confirmacao(): void
    {
        $sessao = $this->admin();
        $planoA = $this->plano(['max_empresas' => 5, 'max_utilizadores' => 5]);
        $planoB = $this->plano(['max_empresas' => 3, 'max_utilizadores' => 3]);
        [$cliente] = $this->cliente('mudanca-normal');
        $cliente->update(['plano' => $planoA->codigo]);
        config(['erp.saas.ativo' => true]);

        $this->putJson("/api/plataforma/clientes/{$cliente->id}/plano", ['plano' => $planoB->codigo], $sessao)->assertOk();
        $this->assertSame($planoB->codigo, $cliente->fresh()->plano);
    }
}
