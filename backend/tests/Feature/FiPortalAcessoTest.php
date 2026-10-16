<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Portal autónomo do colaborador (Versão Actual: portal.html — ADR-071): a guarda GET /api/portal/acesso no servidor.
 * Entra quem tem colaborador associado NA EMPRESA ACTIVA, quem tem aprovações pendentes ou o aprovador RH; os outros
 * recebem 403 SEM_ACESSO_PORTAL com mensagem clara. Dados fictícios.
 */
final class FiPortalAcessoTest extends TestCase
{
    private Empresa $a;

    private Empresa $b;

    private int $colabA;

    private int $colabB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa(['nome' => 'Alfa Portal, Lda', 'nif' => '5419000001']);
        $this->b = $this->criarEmpresa(['nome' => 'Beta Portal, Lda', 'nif' => '5419000002']);
        $this->colabA = app(ContextoEmpresa::class)->executarComo($this->a->id, fn () => Colaborador::create(['nome_completo' => 'Joana Fictícia', 'estado' => 'ACTIVO'])->id);
        $this->colabB = app(ContextoEmpresa::class)->executarComo($this->b->id, fn () => Colaborador::create(['nome_completo' => 'Outra Pessoa', 'estado' => 'ACTIVO'])->id);
    }

    /** @param  array<int, int|null>  $empresas  empresa_id => colaborador_id */
    private function utilizador(array $empresas, array $permissoes = []): Utilizador
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        foreach ($empresas as $empresa => $colaborador) {
            $u->empresas()->attach($empresa, ['colaborador_id' => $colaborador]);
        }

        return $u;
    }

    #[Test]
    public function colaborador_associado_entra_e_so_ve_os_seus_dados(): void
    {
        $u = $this->utilizador([$this->a->id => $this->colabA, $this->b->id => null]);
        $h = $this->entrar($u);
        $r = $this->getJson('/api/portal/acesso', $h + ['X-Empresa-Id' => $this->a->id])->assertOk()->assertJsonStructure($this->envelope());
        $this->assertSame(['id' => $this->colabA, 'nome_completo' => 'Joana Fictícia'], $r->json('dados.colaborador'));
        $this->assertSame(0, $r->json('dados.aprovacoes_pendentes'));
        $this->assertFalse($r->json('dados.aprova_rh'));
        $this->assertStringNotContainsString('Outra Pessoa', $r->getContent());

        // isolamento por empresa: na empresa B o mesmo utilizador não tem colaborador → sem portal
        $this->getJson('/api/portal/acesso', $h + ['X-Empresa-Id' => $this->b->id])->assertForbidden()->assertJsonPath('codigo', 'SEM_ACESSO_PORTAL')
            ->assertJsonPath('sucesso', false);
    }

    #[Test]
    public function sem_colaborador_nem_aprovacoes_recebe_mensagem_clara(): void
    {
        $h = $this->entrar($this->utilizador([$this->a->id => null], ['rh_portal_usar']));
        $r = $this->getJson('/api/portal/acesso', $h + ['X-Empresa-Id' => $this->a->id])->assertForbidden()->assertJsonPath('codigo', 'SEM_ACESSO_PORTAL');
        $this->assertStringContainsString('associados a um colaborador', $r->json('mensagem'));
        // os dados do portal também não abrem (o servidor exige o colaborador do próprio)
        $this->getJson('/api/rh/portal/resumo', $h + ['X-Empresa-Id' => $this->a->id])->assertForbidden()->assertJsonPath('codigo', 'SEM_COLABORADOR');
    }

    #[Test]
    public function aprovador_rh_sem_colaborador_entra_so_para_as_aprovacoes(): void
    {
        $h = $this->entrar($this->utilizador([$this->a->id => null], ['rh_portal_aprovar']));
        $r = $this->getJson('/api/portal/acesso', $h + ['X-Empresa-Id' => $this->a->id])->assertOk();
        $this->assertNull($r->json('dados.colaborador'));
        $this->assertTrue($r->json('dados.aprova_rh'));
    }

    #[Test]
    public function sem_sessao_ou_sem_acesso_a_empresa_nao_entra(): void
    {
        $this->getJson('/api/portal/acesso', ['X-Empresa-Id' => $this->a->id])->assertUnauthorized();
        // utilizador da empresa A não pode pedir o portal da empresa B (não confiar no cabeçalho)
        $h = $this->entrar($this->utilizador([$this->a->id => $this->colabA]));
        $this->getJson('/api/portal/acesso', $h + ['X-Empresa-Id' => $this->b->id])->assertForbidden();
        // sem empresa activa
        $this->assertContains($this->getJson('/api/portal/acesso', $h)->status(), [400, 403, 409, 422, 428]);
    }
}
