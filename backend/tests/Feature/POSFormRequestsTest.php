<?php

namespace Tests\Feature;

use App\Models\Empresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F0 do POS: os FormRequests (App\Http\Requests\POS) verificam a permissão ANTES de validar, como o `exigir()` do controlador:
 * sem permissão é 403 mesmo com o corpo inválido; com permissão, o corpo inválido dá 422 com as mensagens em português de sempre.
 */
final class POSFormRequestsTest extends TestCase
{
    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000066']);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function sem_permissao_e_403_mesmo_com_o_corpo_invalido(): void
    {
        $s = $this->sessao(['pos_view']);
        $this->postJson('/api/pos/terminais', ['codigo' => str_repeat('x', 40)], $s)->assertForbidden();
        $this->postJson('/api/pos/sessoes/1/vendas', [], $s)->assertForbidden();
        $this->postJson('/api/pos/integracao/pre-visualizacao', ['ids' => []], $s)->assertForbidden();
        $this->postJson('/api/pos/terminais/1/mesas', [], $s)->assertForbidden();
        $this->postJson('/api/pos/lavandaria/ordens/1/anular', [], $s)->assertForbidden();
    }

    #[Test]
    public function com_permissao_o_corpo_invalido_da_422_com_mensagens_em_portugues(): void
    {
        $s = $this->sessao(['pos_terminais_gerir', 'pos_integrar', 'pos_venda']);
        $r = $this->postJson('/api/pos/terminais', ['codigo' => str_repeat('x', 40), 'tipo' => 'INVENTADO'], $s)->assertStatus(422);
        $this->assertArrayHasKey('codigo', $r->json('erros'));
        $this->assertArrayHasKey('nome', $r->json('erros'));
        $this->assertArrayHasKey('tipo', $r->json('erros'));
        $this->assertStringContainsString('obrigatória', implode(' ', $r->json('erros.nome')));

        $r = $this->postJson('/api/pos/integracao/pre-visualizacao', ['ids' => [1, 1]], $s)->assertStatus(422);
        $this->assertArrayHasKey('ids.0', $r->json('erros'));
        $r = $this->postJson('/api/pos/terminais/1/mesas', [], $s)->assertStatus(422);
        $this->assertStringContainsString('nome da mesa', implode(' ', $r->json('erros.nome')));
    }
}
