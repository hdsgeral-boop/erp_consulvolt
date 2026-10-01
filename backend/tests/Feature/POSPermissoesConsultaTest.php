<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** O operador do POS (só pos_venda / hotelaria) consulta clientes, catálogo, categorias, stock e estadias (Fase 5). */
final class POSPermissoesConsultaTest extends TestCase
{
    #[Test]
    public function operador_do_pos_consulta_o_que_precisa_para_vender(): void
    {
        $empresa = $this->criarEmpresa();
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'pos_venda' => true, 'hotel_estadias' => true])->id]);
        $u->empresas()->attach($empresa->id);
        $s = $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];

        $this->getJson('/api/terceiros?papel=CLIENTE', $s)->assertOk();
        $this->getJson('/api/logistica/produtos/catalogo', $s)->assertOk();
        $this->getJson('/api/logistica/categorias-produtos', $s)->assertOk();
        $this->getJson('/api/pos/hotelaria/estadias', $s)->assertOk()->assertJsonPath('metadados.paginacao.pagina_atual', 1);
        $criar = $this->postJson('/api/terceiros', ['nome' => 'X'], $s);   // consultar não é gerir: nunca grava (validação 422 ou permissão 403)
        $this->assertContains($criar->status(), [403, 422]);
    }
}
