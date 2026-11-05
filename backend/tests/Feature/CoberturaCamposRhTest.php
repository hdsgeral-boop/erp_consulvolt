<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\TipoOrganizacaoRH;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Auditoria de cobertura de campos (docs/paridade/COBERTURA_CAMPOS_2026-11.md), lote RH:
 * colaboradores.data_cessacao é devolvida pela listagem e pela ficha (o ecrã passa a mostrá-la). Dados fictícios.
 */
final class CoberturaCamposRhTest extends TestCase
{
    #[Test]
    public function data_de_cessacao_do_colaborador_chega_a_listagem_e_a_ficha(): void
    {
        $empresa = $this->criarEmpresa();
        $ids = app(ContextoEmpresa::class)->executarComo($empresa->id, function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $c = Colaborador::create(['nome_completo' => 'Ana Fictícia', 'nif' => '900000001LA001', 'estado' => 'INACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22]);
            $c->forceFill(['data_cessacao' => '2026-05-15'])->save();

            return $c->id;
        });
        $perfil = $this->criarPerfil(['_v2' => true, 'colaboradores_view' => true]);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($empresa->id);
        $s = $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];

        $lista = $this->getJson('/api/rh/colaboradores', $s)->assertOk()->json('dados');
        $this->assertSame('2026-05-15', substr((string) collect($lista)->firstWhere('id', $ids)['data_cessacao'], 0, 10));
        $ficha = $this->getJson("/api/rh/colaboradores/{$ids}", $s)->assertOk()->json('dados');
        $this->assertSame('2026-05-15', substr((string) $ficha['data_cessacao'], 0, 10));
    }
}
