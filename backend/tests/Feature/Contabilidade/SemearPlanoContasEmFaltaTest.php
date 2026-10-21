<?php

namespace Tests\Feature\Contabilidade;

use App\Services\Contabilidade\ServicoPlanoContasModelo;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Plano de contas modelo (da v6, adaptado): só a estrutura genérica passa para outras empresas, nunca dados bancários. */
class SemearPlanoContasEmFaltaTest extends TestCase
{
    private function conta(int $empresa, string $codigo, string $descricao, array $extra = []): void
    {
        DB::table('plano_contas')->insert($extra + ['empresa_id' => $empresa, 'codigo' => $codigo, 'descricao' => $descricao, 'tipo' => 'M',
            'criado_em' => now(), 'atualizado_em' => now()]);
    }

    public function test_copia_so_a_estrutura_generica_e_nao_toca_em_empresas_com_contas(): void
    {
        $modelo = $this->criarEmpresa(['nome' => 'Modelo']);
        $this->conta($modelo->id, '31', 'CLIENTES', ['tipo' => 'R']);
        $this->conta($modelo->id, '311', 'Clientes correntes', ['tipo' => 'R']);
        $this->conta($modelo->id, '31121', 'Nacionais');
        $this->conta($modelo->id, '43101001', 'Banco AOA BAI - 05071015510001');            // conta da empresa: não passa
        $this->conta($modelo->id, '4311', 'Depósitos à ordem', ['banco_iban' => 'AO06000000000000000000000']);   // dados bancários: não passa
        $this->conta($modelo->id, '4312', 'Conta 1234567 BFA');                             // número longo na descrição: não passa
        config(['erp.plano_contas.empresa_modelo' => $modelo->id]);

        $vazia = $this->criarEmpresa(['nome' => 'Sem plano']);
        $comContas = $this->criarEmpresa(['nome' => 'Já com plano']);
        $this->conta($comContas->id, '11', 'Imobilizações corpóreas');

        $feitas = app(ServicoPlanoContasModelo::class)->semearEmFalta();

        $this->assertSame([$vazia->id => 3], $feitas);
        $this->assertSame(['31', '311', '31121'], DB::table('plano_contas')->where('empresa_id', $vazia->id)->orderBy('codigo')->pluck('codigo')->all());
        $this->assertSame(0, DB::table('plano_contas')->where('empresa_id', $vazia->id)->whereNotNull('banco_iban')->count());
        $this->assertSame(1, DB::table('plano_contas')->where('empresa_id', $comContas->id)->count());

        // idempotente
        $this->assertSame([], app(ServicoPlanoContasModelo::class)->semearEmFalta());
        $this->artisan('erp:contabilidade:semear-plano-contas-em-falta')->assertSuccessful();
    }

    public function test_empresa_nova_recebe_a_estrutura_do_modelo(): void
    {
        $modelo = $this->criarEmpresa(['nome' => 'Modelo']);
        $this->conta($modelo->id, '61', 'Vendas', ['tipo' => 'R']);
        $this->conta($modelo->id, '611', 'Produtos acabados');
        config(['erp.plano_contas.empresa_modelo' => $modelo->id]);

        $actor = $this->criarUtilizador(['papel' => 'SUPER_ADMINISTRADOR', 'acesso_todas_empresas' => true]);
        $nova = app(\App\Services\Sistema\ServicoGestaoEmpresas::class)->criar(['nome' => 'Nova, Lda', 'nif' => '5000999111'], $actor);

        $this->assertSame(['61', '611'], DB::table('plano_contas')->where('empresa_id', $nova->id)->orderBy('codigo')->pluck('codigo')->all());
    }
}
