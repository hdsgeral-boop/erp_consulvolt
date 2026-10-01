<?php

namespace Tests\Feature;

use App\Models\Empresa;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 6 — revisão de segurança (ADR-065): permissões das operações, limites de entrada e limite de tentativas de login por IP.
 */
final class SegurancaAutorizacaoTest extends TestCase
{
    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417006511']);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function eliminar_contrato_de_trabalho_exige_a_tarefa_de_rescindir_e_eliminar(): void
    {
        // a permissão é verificada antes de procurar o contrato
        $this->deleteJson('/api/rh/contratos/999999', [], $this->sessao(['contratos_view', 'contratos_new']))->assertForbidden();
        $this->deleteJson('/api/rh/contratos/999999', [], $this->sessao(['contratos_view', 'contratos_terminate']))->assertNotFound();
    }

    #[Test]
    public function cubo_recusa_medida_desconhecida_na_contagem_em_vez_de_erro_500(): void
    {
        $s = $this->sessao(['dashboard_view', 'lancamentos_view']);
        $base = ['conjunto' => 'contabilidade', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31'];
        $this->postJson('/api/gestao/cubo/consultar', $base + ['medidas' => [['agregacao' => 'contagem']]], $s)->assertOk();
        $this->postJson('/api/gestao/cubo/consultar', $base + ['medidas' => [['agregacao' => 'contagem', 'medida' => 'nao_existe']]], $s)
            ->assertStatus(422);
        // filtros com demasiadas chaves são recusados pela validação
        $this->postJson('/api/gestao/cubo/consultar', $base + ['filtros' => array_fill_keys(array_map(fn ($i) => "d{$i}", range(1, 31)), ['x'])], $s)
            ->assertStatus(422);
    }

    #[Test]
    public function login_tem_tambem_um_limite_por_ip_contra_password_spraying(): void
    {
        RateLimiter::clear('ip|127.0.0.1');
        $estados = [];
        for ($i = 1; $i <= 61; $i++) {
            $estados[] = $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => "inexistente{$i}", 'palavra_passe' => 'Errada#2026'])->status();
        }
        $this->assertNotContains(429, array_slice($estados, 0, 60));   // nomes diferentes: o limite por utilizador e IP nunca dispara
        $this->assertSame(429, end($estados));
    }
}
