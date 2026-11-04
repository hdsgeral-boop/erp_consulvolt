<?php

namespace Tests\Feature\Tenancy;

use App\Models\Empresa;
use App\Models\Moeda;
use App\Models\TaxaCambio;
use App\Models\Tenant;
use App\Models\Utilizador;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Isolamento entre clientes: com SaaS activo, o catálogo de moedas e os câmbios de âmbito «todas» são comuns a todos os
 * clientes e só a plataforma os altera. O cliente regista os seus câmbios com âmbito «empresa».
 */
class MoedasCambiosPartilhadosTest extends TestCase
{
    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000777']);
        foreach ([['AOA', 'Kwanza', 'Kz'], ['USD', 'Dólar dos EUA', 'US$']] as [$c, $n, $s]) {
            Moeda::create(['codigo' => $c, 'nome' => $n, 'simbolo' => $s, 'casas_decimais' => 2, 'ativo' => true]);
        }
    }

    private function sessao(string $papel, int $tenant): array
    {
        $perfil = $this->criarPerfil(['_v2' => true, 'config_moedas_view' => true, 'config_moedas_gerir' => true]);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id, 'papel' => $papel, 'acesso_todas_empresas' => true]);
        if ($tenant !== 1) {
            $cliente = Tenant::query()->create(['nome' => 'Cliente moedas', 'slug' => 'cliente-moedas']);
            DB::table('empresas')->where('id', $this->empresa->id)->update(['tenant_id' => $cliente->id]);
            DB::table('utilizadores')->where('id', $u->id)->update(['tenant_id' => $cliente->id]);
        }

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    public function test_cliente_nao_cria_moeda_nem_cambio_partilhado_mas_regista_o_seu(): void
    {
        $s = $this->sessao(Utilizador::PAPEL_ADMINISTRADOR, 2);
        config(['erp.saas.ativo' => true]);

        $this->postJson('/api/sistema/moedas', ['codigo' => 'ZAR', 'nome' => 'Rand', 'simbolo' => 'R'], $s)->assertForbidden();
        $this->postJson('/api/sistema/cambios', ['data_taxa' => '2026-09-01', 'codigo_moeda' => 'USD', 'taxa' => 900, 'ambito' => 'TODAS'], $s)
            ->assertForbidden()->assertJsonPath('codigo', 'CAMBIO_PARTILHADO');
        $this->postJson('/api/sistema/cambios', ['data_taxa' => '2026-09-01', 'codigo_moeda' => 'USD', 'taxa' => 900, 'ambito' => 'EMPRESA'], $s)->assertCreated();
    }

    public function test_cliente_nao_altera_nem_elimina_cambio_partilhado(): void
    {
        $partilhado = TaxaCambio::create(['codigo_moeda' => 'USD', 'data_taxa' => '2026-09-02', 'taxa' => 910, 'fonte_dados' => 'BNA']);
        $s = $this->sessao(Utilizador::PAPEL_ADMINISTRADOR, 2);
        config(['erp.saas.ativo' => true]);

        $this->putJson("/api/sistema/cambios/{$partilhado->id}", ['data_taxa' => '2026-09-02', 'codigo_moeda' => 'USD', 'taxa' => 1, 'ambito' => 'EMPRESA'], $s)->assertForbidden();
        $this->deleteJson("/api/sistema/cambios/{$partilhado->id}", [], $s)->assertForbidden();
        $this->assertSame(1, TaxaCambio::query()->whereKey($partilhado->id)->count());
    }

    public function test_plataforma_gere_moedas_e_cambios_partilhados(): void
    {
        $s = $this->sessao(Utilizador::PAPEL_SUPER_ADMINISTRADOR, 1);
        config(['erp.saas.ativo' => true]);

        $this->postJson('/api/sistema/moedas', ['codigo' => 'ZAR', 'nome' => 'Rand', 'simbolo' => 'R'], $s)->assertCreated();
        $this->postJson('/api/sistema/cambios', ['data_taxa' => '2026-09-01', 'codigo_moeda' => 'USD', 'taxa' => 900, 'ambito' => 'TODAS'], $s)->assertCreated();
    }

    public function test_sem_saas_nada_muda(): void
    {
        config(['erp.saas.ativo' => false]);
        $s = $this->sessao(Utilizador::PAPEL_ADMINISTRADOR, 1);

        $this->postJson('/api/sistema/moedas', ['codigo' => 'ZAR', 'nome' => 'Rand', 'simbolo' => 'R'], $s)->assertCreated();
        $this->postJson('/api/sistema/cambios', ['data_taxa' => '2026-09-01', 'codigo_moeda' => 'USD', 'taxa' => 900, 'ambito' => 'TODAS'], $s)->assertCreated();
    }
}
