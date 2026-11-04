<?php

namespace Tests\Feature\Tenancy;

use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\PlanoLicencaVersao;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Comandos de arranque do catálogo de planos (erp:planos:criar-interno, erp:licencas:planos-iniciais) e cache de empresas. */
class ComandosPlanosTest extends TestCase
{
    public function test_cria_o_plano_interno_sem_limites_e_publica_a_versao(): void
    {
        $this->artisan('erp:planos:criar-interno')->assertSuccessful();

        $plano = PlanoLicenca::query()->where('codigo', 'interno')->firstOrFail();
        $this->assertTrue($plano->ativo);
        $this->assertNull($plano->max_empresas);
        $this->assertNull($plano->max_utilizadores);
        $this->assertSame(0.0, (float) $plano->preco_mensal);

        $versao = PlanoLicencaVersao::query()->where('plano_id', $plano->id)->firstOrFail();
        $this->assertSame(PlanoLicencaVersao::PUBLICADA, $versao->estado);
        $this->assertSame(1, (int) $versao->numero);
        $this->assertNull($versao->max_empresas);
        $this->assertNull($versao->max_utilizadores);
        $this->assertEqualsCanonicalizing(array_keys(config('erp.planos_catalogo.modulos')), $versao->modulos);
    }

    public function test_repetir_o_comando_nao_duplica_o_plano_nem_a_versao(): void
    {
        $this->artisan('erp:planos:criar-interno')->assertSuccessful();
        $this->artisan('erp:planos:criar-interno')->assertSuccessful();

        $plano = PlanoLicenca::query()->where('codigo', 'interno')->firstOrFail();
        $this->assertSame(1, PlanoLicenca::query()->where('codigo', 'interno')->count());
        $this->assertSame(1, PlanoLicencaVersao::query()->where('plano_id', $plano->id)->count());
    }

    public function test_planos_iniciais_criam_so_o_que_falta_e_nao_alteram_planos_existentes(): void
    {
        // plano existente com preço alterado: nunca é reposto
        $existente = PlanoLicenca::query()->updateOrCreate(['codigo' => 'basico'], ['nome' => 'Plano Básico', 'preco_mensal' => 12345, 'moeda' => 'AOA', 'max_empresas' => 2, 'max_utilizadores' => 4, 'ativo' => true, 'ordem' => 1]);
        PlanoLicenca::query()->whereIn('codigo', ['interno', 'profissional', 'empresarial'])->delete();

        $this->artisan('erp:licencas:planos-iniciais')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['interno', 'basico', 'profissional', 'empresarial'], PlanoLicenca::query()->pluck('codigo')->all());
        $this->assertSame(12345.0, (float) $existente->refresh()->preco_mensal);
        $this->assertFalse((bool) PlanoLicenca::query()->where('codigo', 'interno')->value('ativo'), 'o plano interno não é oferecido aos clientes');
        $pro = PlanoLicenca::query()->where('codigo', 'profissional')->firstOrFail();
        $this->assertContains('estrutura', PlanoLicencaVersao::query()->where('plano_id', $pro->id)->firstOrFail()->modulos, 'usa a definição actual do teste (módulos novos incluídos)');

        // repetir não duplica
        $this->artisan('erp:licencas:planos-iniciais')->assertSuccessful();
        $this->assertSame(4, PlanoLicenca::query()->count());
    }

    public function test_planos_iniciais_nao_atribuem_o_interno_a_clientes_sem_pedido_explicito(): void
    {
        $cliente = Tenant::query()->create(['nome' => 'Cliente Real', 'slug' => 'cliente-real', 'plano' => 'basico']);
        $empresa = $this->criarEmpresa(['nif' => '5000000555']);
        DB::table('empresas')->where('id', $empresa->id)->update(['tenant_id' => $cliente->id, 'licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_fim' => now()->addDays(10)->toDateString()]);

        $this->artisan('erp:licencas:planos-iniciais')->assertSuccessful();

        $this->assertSame('basico', Tenant::query()->findOrFail($cliente->id)->plano);
        $this->assertSame(Empresa::LICENCA_PLANO, DB::table('empresas')->where('id', $empresa->id)->value('licenca_origem'));

        $this->artisan('erp:licencas:planos-iniciais', ['--atribuir-interno' => 'cliente-real'])->assertSuccessful();

        $this->assertSame('interno', Tenant::query()->findOrFail($cliente->id)->plano);
        $this->assertSame(Empresa::LICENCA_SEM_LIMITE, DB::table('empresas')->where('id', $empresa->id)->value('licenca_origem'));
    }

    public function test_invalidar_cache_de_empresas_muda_a_versao_da_chave(): void
    {
        Cache::put('empresas:versao', 5);

        $this->artisan('erp:empresas:invalidar-cache')->assertSuccessful();

        $this->assertGreaterThan(5, (int) Cache::get('empresas:versao'));
    }
}
