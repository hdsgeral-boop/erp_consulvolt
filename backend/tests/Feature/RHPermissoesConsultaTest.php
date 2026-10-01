<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Os ecrãs de mapas e recibos de RH (rh_rel_*) e os ecrãs de RH que listam colaboradores lêem períodos e colaboradores (Fase 5). */
final class RHPermissoesConsultaTest extends TestCase
{
    private function sessao(array $permissoes): array
    {
        $empresa = $this->criarEmpresa();
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    #[Test]
    public function mapas_de_rh_leem_periodos_e_colaboradores(): void
    {
        $s = $this->sessao(['rh_rel_irt_view']);
        $this->getJson('/api/rh/salarios/periodos', $s)->assertOk();
        $this->getJson('/api/rh/colaboradores', $s)->assertOk();
        $this->getJson('/api/rh/infotipos', $s)->assertForbidden();   // as rubricas não são precisas nos mapas

        $p = $this->sessao(['rh_produtividade_view']);
        $this->getJson('/api/rh/infotipos', $p)->assertOk();
        $this->getJson('/api/rh/colaboradores', $p)->assertOk();
        $this->getJson('/api/rh/salarios/periodos', $p)->assertForbidden();
    }
}
