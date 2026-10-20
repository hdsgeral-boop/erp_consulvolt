<?php

namespace Tests\Feature\Tenancy;

use App\Models\PlanoLicenca;
use App\Models\PlanoLicencaVersao;
use Tests\TestCase;

/** Plano interno da equipa: sem limites, todos os módulos, sem custo, com a versão 1 publicada; repetível. */
class PlanoInternoTest extends TestCase
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

        $this->assertSame(1, PlanoLicenca::query()->where('codigo', 'interno')->count());
        $plano = PlanoLicenca::query()->where('codigo', 'interno')->firstOrFail();
        $this->assertSame(1, PlanoLicencaVersao::query()->where('plano_id', $plano->id)->count());
    }
}
