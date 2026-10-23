<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migração «licença do cliente»: cada cliente fica com a licença mais favorável das suas empresas e nenhuma empresa perde
 * acesso (as cópias só sobem). É repetível.
 */
class LicencaDoClienteMigracaoTest extends TestCase
{
    private function empresaDoCliente(Tenant $c, string $origem, ?int $diasAteFim): int
    {
        $e = $this->criarEmpresa(['tenant_id' => $c->id]);
        DB::table('empresas')->where('id', $e->id)->update([
            'licenca_origem' => $origem, 'licenca_inicio' => $diasAteFim === null ? null : now()->subMonth()->toDateString(),
            'licenca_fim' => $diasAteFim === null ? null : now()->addDays($diasAteFim)->toDateString(),
        ]);

        return (int) $e->id;
    }

    private function cliente(string $slug): Tenant
    {
        return Tenant::query()->create(['nome' => $slug, 'slug' => $slug]);
    }

    public function test_cada_cliente_fica_com_a_licenca_mais_favoravel_e_nenhuma_empresa_perde_acesso(): void
    {
        $misto = $this->cliente('misto-mig');
        $plano = $this->empresaDoCliente($misto, 'PLANO', 10);
        $teste = $this->empresaDoCliente($misto, 'TESTE', 3);

        $vitalicio = $this->cliente('vitalicio-mig');
        $sem = $this->empresaDoCliente($vitalicio, 'SEM_LIMITE', null);
        $curta = $this->empresaDoCliente($vitalicio, 'PLANO', 2);

        $unico = $this->cliente('unico-mig');
        $so = $this->empresaDoCliente($unico, 'PLANO', 5);
        $vazio = $this->cliente('vazio-mig');   // sem empresas: não é bloqueado

        // estado anterior à migração: os clientes sem licença própria
        DB::table('tenants')->update(['licenca_origem' => 'SEM_LIMITE', 'licenca_inicio' => null, 'licenca_fim' => null]);

        $migracao = require base_path('database/migrations/2026_10_23_120000_licenca_do_cliente.php');
        $migracao->up();
        $migracao->up();   // repetível

        $t = fn (Tenant $c) => DB::table('tenants')->where('id', $c->id)->first();
        $e = fn (int $id) => DB::table('empresas')->where('id', $id)->first();
        $dia = fn (int $n) => now()->addDays($n)->toDateString();

        // misto: PLANO até +10 (a de fim mais tardio); a empresa em teste sobe para essa licença
        $this->assertSame(['PLANO', $dia(10)], [$t($misto)->licenca_origem, $t($misto)->licenca_fim]);
        $this->assertSame(['PLANO', $dia(10)], [$e($plano)->licenca_origem, $e($plano)->licenca_fim]);
        $this->assertSame(['PLANO', $dia(10)], [$e($teste)->licenca_origem, $e($teste)->licenca_fim]);

        // uma empresa sem limite torna o cliente sem limite, e a outra empresa sobe para sem limite
        $this->assertSame(['SEM_LIMITE', null], [$t($vitalicio)->licenca_origem, $t($vitalicio)->licenca_fim]);
        $this->assertSame(['SEM_LIMITE', null], [$e($curta)->licenca_origem, $e($curta)->licenca_fim]);
        $this->assertSame('SEM_LIMITE', $e($sem)->licenca_origem);

        // cliente com uma só empresa: mantém-na; cliente sem empresas: sem limite
        $this->assertSame(['PLANO', $dia(5)], [$t($unico)->licenca_origem, $t($unico)->licenca_fim]);
        $this->assertSame($dia(5), $e($so)->licenca_fim);
        $this->assertSame(['SEM_LIMITE', null], [$t($vazio)->licenca_origem, $t($vazio)->licenca_fim]);
    }
}
