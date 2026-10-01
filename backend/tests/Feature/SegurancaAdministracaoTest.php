<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Utilizador;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 6 — revisão de segurança (ADR-065): administração de utilizadores e perfis entre empresas.
 * Um administrador SEM acesso total só actua sobre contas e perfis das empresas a que está ligado.
 */
final class SegurancaAdministracaoTest extends TestCase
{
    private Empresa $a;

    private Empresa $b;

    private Utilizador $adminA;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa(['nif' => '5417006501']);
        $this->b = $this->criarEmpresa(['nif' => '5417006502']);
        $this->adminA = $this->criarUtilizador(['nome_utilizador' => 'admin_a', 'perfil_utilizador_id' => $this->criarPerfil(['_v2' => true,
            'config_utilizadores_view' => true, 'config_util_gerir' => true, 'config_perfis_view' => true, 'config_perfis_gerir' => true,
            'config_manutencao_view' => true], 'Gestor A')->id]);
        $this->adminA->empresas()->attach($this->a->id);
        $this->s = $this->entrar($this->adminA) + ['X-Empresa-Id' => $this->a->id];
    }

    private function utilizadorDe(array $empresas, string $nome, ?int $perfil = null): Utilizador
    {
        $u = $this->criarUtilizador(['nome_utilizador' => $nome, 'perfil_utilizador_id' => $perfil ?? $this->criarPerfil(['_v2' => true, 'lancamentos_view' => true], "P {$nome}")->id]);
        foreach ($empresas as $e) {
            $u->empresas()->attach($e->id);
        }

        return $u;
    }

    #[Test]
    public function administrador_de_uma_empresa_nao_toma_contas_de_outra_empresa(): void
    {
        $vitima = $this->utilizadorDe([$this->b], 'so_da_b');
        $partilhado = $this->utilizadorDe([$this->a, $this->b], 'a_e_b');
        $daA = $this->utilizadorDe([$this->a], 'so_da_a');
        $todas = $this->criarUtilizador(['nome_utilizador' => 'todas', 'acesso_todas_empresas' => true,
            'perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'lancamentos_view' => true], 'Todas')->id]);
        $nova = ['palavra_passe' => 'Tomada#2026x', 'palavra_passe_confirmation' => 'Tomada#2026x'];

        foreach ([$vitima, $partilhado, $todas] as $alvo) {
            $this->postJson("/api/sistema/utilizadores/{$alvo->id}/palavra-passe", $nova, $this->s)->assertForbidden()
                ->assertJsonPath('codigo', 'SEM_PERMISSAO_ADMINISTRATIVA');
            $this->putJson("/api/sistema/utilizadores/{$alvo->id}/estado", ['ativo' => false], $this->s)->assertForbidden();
            $this->putJson("/api/sistema/utilizadores/{$alvo->id}", ['palavra_passe' => 'Tomada#2026x'], $this->s)->assertForbidden();
            $this->deleteJson("/api/sistema/utilizadores/{$alvo->id}", [], $this->s)->assertForbidden();
        }
        foreach ([$vitima, $todas] as $alvo) {
            $this->putJson("/api/sistema/utilizadores/{$alvo->id}", ['email' => 'x@exemplo.ao'], $this->s)->assertForbidden();
        }
        // um utilizador partilhado (A e B) continua editável nos dados e nas ligações à empresa A (regra do ADR-058)
        $this->putJson("/api/sistema/utilizadores/{$partilhado->id}", ['email' => 'partilhado@exemplo.ao'], $this->s)->assertOk();
        $this->entrar($partilhado);
        $this->assertTrue($vitima->fresh()->ativo);
        $this->entrar($vitima);   // a palavra-passe da vítima continua a mesma

        // um utilizador só da empresa A continua a ser gerido normalmente
        $this->postJson("/api/sistema/utilizadores/{$daA->id}/palavra-passe", $nova, $this->s)->assertOk();
        $this->entrar($daA, 'Tomada#2026x');
    }

    #[Test]
    public function lista_e_ficha_de_utilizadores_ficam_limitadas_as_empresas_do_administrador(): void
    {
        $vitima = $this->utilizadorDe([$this->b], 'so_da_b');
        $partilhado = $this->utilizadorDe([$this->a, $this->b], 'a_e_b');

        $nomes = collect($this->getJson('/api/sistema/utilizadores?por_pagina=500', $this->s)->assertOk()->json('dados'))->pluck('nome_utilizador');
        $this->assertContains('a_e_b', $nomes);
        $this->assertNotContains('so_da_b', $nomes);
        $this->getJson("/api/sistema/utilizadores/{$vitima->id}", $this->s)->assertNotFound();

        $ficha = $this->getJson("/api/sistema/utilizadores/{$partilhado->id}", $this->s)->assertOk()->json('dados');
        $this->assertSame([$this->a->id], array_column($ficha['empresas'], 'empresa_id'));   // a ligação à empresa B não é mostrada
    }

    #[Test]
    public function perfis_globais_nao_sao_alterados_fora_do_dominio_nem_o_proprio_perfil(): void
    {
        $perfilB = $this->criarPerfil(['_v2' => true, 'lancamentos_view' => true], 'Contabilista B');
        $this->utilizadorDe([$this->b], 'contab_b', $perfilB->id);
        $perfilA = $this->criarPerfil(['_v2' => true, 'lancamentos_view' => true], 'Contabilista A');
        $this->utilizadorDe([$this->a], 'contab_a', $perfilA->id);
        $dados = ['nome' => 'X', 'permissoes' => ['lancamentos_view', 'config_ferramentas']];

        $this->putJson("/api/sistema/perfis/{$perfilB->id}", ['nome' => 'Contabilista B'] + $dados, $this->s)->assertForbidden()
            ->assertJsonPath('codigo', 'SEM_PERMISSAO_ADMINISTRATIVA');
        $this->putJson("/api/sistema/perfis/{$this->adminA->perfil_utilizador_id}", ['nome' => 'Gestor A'] + $dados, $this->s)->assertForbidden();
        $this->assertArrayNotHasKey('config_ferramentas', $perfilB->fresh()->permissoes);

        $this->putJson("/api/sistema/perfis/{$perfilA->id}", ['nome' => 'Contabilista A', 'descricao' => 'Revisto', 'permissoes' => ['lancamentos_view']], $this->s)->assertOk();
    }
}
