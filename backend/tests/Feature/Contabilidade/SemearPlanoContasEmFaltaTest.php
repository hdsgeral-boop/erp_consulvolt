<?php

namespace Tests\Feature\Contabilidade;

use App\Models\PlanoConta;
use App\Services\Contabilidade\ServicoPlanoContasModelo;
use App\Support\Tenancy\ContextoEmpresa;
use Tests\TestCase;

final class SemearPlanoContasEmFaltaTest extends TestCase
{
    public function test_semeia_apenas_as_empresas_sem_nenhuma_conta_propria(): void
    {
        $modelo = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($modelo->id, function () {
            PlanoConta::create(['codigo' => '61', 'descricao' => 'Custo das mercadorias vendidas', 'tipo' => PlanoConta::TIPO_TOTALIZADORA]);
            PlanoConta::create(['codigo' => '611', 'descricao' => 'Mercadorias', 'tipo' => PlanoConta::TIPO_MOVIMENTO]);
        });

        $semPlano = $this->criarEmpresa();
        $jaPersonalizada = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($jaPersonalizada->id, function () {
            PlanoConta::create(['codigo' => '99', 'descricao' => 'Conta própria da empresa', 'tipo' => PlanoConta::TIPO_MOVIMENTO]);
        });

        $semeadas = app(ServicoPlanoContasModelo::class)->semearEmFalta();

        $this->assertContains($semPlano->id, $semeadas);
        $this->assertNotContains($jaPersonalizada->id, $semeadas);
        $this->assertNotContains($modelo->id, $semeadas);

        app(ContextoEmpresa::class)->executarComo($semPlano->id, function () use ($semPlano) {
            $this->assertDatabaseHas('plano_contas', ['empresa_id' => $semPlano->id, 'codigo' => '611']);
        });
        app(ContextoEmpresa::class)->executarComo($jaPersonalizada->id, function () use ($jaPersonalizada) {
            $this->assertDatabaseMissing('plano_contas', ['empresa_id' => $jaPersonalizada->id, 'codigo' => '611']);
            $this->assertDatabaseHas('plano_contas', ['empresa_id' => $jaPersonalizada->id, 'codigo' => '99']);
        });
    }

    public function test_e_idempotente_a_segunda_execucao_nao_duplica_nem_semeia_de_novo(): void
    {
        $modelo = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($modelo->id, function () {
            PlanoConta::create(['codigo' => '61', 'descricao' => 'Custo das mercadorias vendidas', 'tipo' => PlanoConta::TIPO_TOTALIZADORA]);
        });
        $semPlano = $this->criarEmpresa();

        $servico = app(ServicoPlanoContasModelo::class);
        $primeira = $servico->semearEmFalta();
        $segunda = $servico->semearEmFalta();

        $this->assertContains($semPlano->id, $primeira);
        $this->assertSame([], $segunda);
    }
}
