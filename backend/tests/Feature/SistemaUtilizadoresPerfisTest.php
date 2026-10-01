<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\LogAuditoria;
use App\Models\PerfilUtilizador;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Administração (ADR-058): utilizadores (empresas, colaborador, papel, estado, palavra-passe, protecções contra escalada
 * de privilégios) e perfis v2 (catálogo, segregação de funções, perfis-modelo).
 */
final class SistemaUtilizadoresPerfisTest extends TestCase
{
    private Empresa $empresa;

    private Empresa $outra;

    private Utilizador $admin;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000101']);
        $this->outra = $this->criarEmpresa(['nif' => '5417000102']);
        $this->admin = $this->criarUtilizador(['nome_utilizador' => 'gestor', 'perfil_utilizador_id' => $this->criarPerfil(['_v2' => true,
            'config_utilizadores_view' => true, 'config_util_gerir' => true, 'config_perfis_view' => true, 'config_perfis_gerir' => true], 'Gestor de acessos')->id]);
        $this->admin->empresas()->attach($this->empresa->id);
        $this->s = $this->entrar($this->admin) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function colaborador(Empresa $e, string $nome): int
    {
        return app(ContextoEmpresa::class)->executarComo($e->id, fn () => Colaborador::create(['nome_completo' => $nome, 'estado' => 'ACTIVO'])->id);
    }

    #[Test]
    public function cria_utilizador_com_empresa_e_colaborador_sem_devolver_segredos_e_o_login_funciona(): void
    {
        $perfil = $this->criarPerfil(['_v2' => true, 'lancamentos_view' => true], 'Operador');
        $colab = $this->colaborador($this->empresa, 'Ana Teste');
        $r = $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'ana', 'nome_completo' => 'Ana Teste', 'palavra_passe' => 'Segredo#2026',
            'perfil_utilizador_id' => $perfil->id, 'empresas' => [['empresa_id' => $this->empresa->id, 'colaborador_id' => $colab]]], $this->s)->assertCreated();

        $this->assertSame(Utilizador::PAPEL_UTILIZADOR, $r->json('dados.papel'));
        $this->assertSame($colab, $r->json('dados.empresas.0.colaborador_id'));
        $this->assertStringNotContainsString('palavra_passe"', (string) $r->getContent());
        $this->assertStringNotContainsString('argon', (string) $r->getContent());
        $u = Utilizador::query()->where('nome_utilizador', 'ana')->firstOrFail();
        $this->assertStringStartsWith('$argon2id$', $u->palavra_passe);
        $this->entrar($u, 'Segredo#2026');
        $log = app(ContextoEmpresa::class)->semIsolamento(fn () => LogAuditoria::query()->where('acao', 'Criar utilizador')->firstOrFail());
        $this->assertStringNotContainsString('Segredo', json_encode($log->dados_novos));

        // papel derivado do nome do perfil (paridade app_v2.js:3165)
        $adm = $this->criarPerfil(['_v2' => true, 'lancamentos_view' => true], 'Administração financeira');
        $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'rui', 'palavra_passe' => 'Segredo#2026', 'perfil_utilizador_id' => $adm->id], $this->s)
            ->assertCreated()->assertJsonPath('dados.papel', Utilizador::PAPEL_ADMINISTRADOR);
    }

    #[Test]
    public function valida_nome_duplicado_palavra_passe_curta_e_colaborador(): void
    {
        $perfil = $this->criarPerfil(['_v2' => true, 'lancamentos_view' => true], 'Simples');
        $base = ['palavra_passe' => 'Segredo#2026', 'perfil_utilizador_id' => $perfil->id];
        $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'gestor'] + $base, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'UTILIZADOR_DUPLICADO');
        $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'x1', 'palavra_passe' => 'curta', 'perfil_utilizador_id' => $perfil->id], $this->s)->assertStatus(422);

        $colabOutra = $this->colaborador($this->outra, 'De outra empresa');
        $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'x2', 'empresas' => [['empresa_id' => $this->empresa->id, 'colaborador_id' => $colabOutra]]] + $base, $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'COLABORADOR_INVALIDO');

        $colab = $this->colaborador($this->empresa, 'Ligado');
        $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'x3', 'empresas' => [['empresa_id' => $this->empresa->id, 'colaborador_id' => $colab]]] + $base, $this->s)->assertCreated();
        $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'x4', 'empresas' => [['empresa_id' => $this->empresa->id, 'colaborador_id' => $colab]]] + $base, $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'COLABORADOR_JA_LIGADO');
        $lista = $this->getJson('/api/sistema/utilizadores/colaboradores', $this->s)->assertOk()->json('dados');
        $this->assertSame('x3', collect($lista)->firstWhere('id', $colab)['ligado_a']);
    }

    #[Test]
    public function quem_nao_tem_acesso_total_nao_escala_privilegios(): void
    {
        $total = PerfilUtilizador::create(['nome' => 'Super', 'permissoes' => ['all' => true]]);
        $simples = $this->criarPerfil(['_v2' => true, 'lancamentos_view' => true], 'Simples');
        $base = ['nome_utilizador' => 'novo', 'palavra_passe' => 'Segredo#2026'];

        $this->postJson('/api/sistema/utilizadores', $base + ['perfil_utilizador_id' => $total->id], $this->s)->assertStatus(403)->assertJsonPath('codigo', 'SEM_PERMISSAO_ADMINISTRATIVA');
        $this->postJson('/api/sistema/utilizadores', $base + ['perfil_utilizador_id' => $simples->id, 'acesso_todas_empresas' => true], $this->s)->assertStatus(403);
        $this->postJson('/api/sistema/utilizadores', $base + ['perfil_utilizador_id' => $simples->id, 'empresas' => [['empresa_id' => $this->outra->id]]], $this->s)
            ->assertStatus(403)->assertJsonPath('codigo', 'EMPRESA_SEM_ACESSO');
        $this->postJson('/api/sistema/utilizadores', $base + ['perfil_utilizador_id' => $simples->id, 'papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR], $this->s)->assertStatus(403);

        $super = $this->criarUtilizador(['nome_utilizador' => 'super', 'papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR]);
        $this->putJson("/api/sistema/utilizadores/{$super->id}", ['nome_completo' => 'Mudado'], $this->s)->assertStatus(403);
        $this->postJson("/api/sistema/utilizadores/{$super->id}/palavra-passe", ['palavra_passe' => 'Outra#2026x', 'palavra_passe_confirmation' => 'Outra#2026x'], $this->s)->assertStatus(403);
        $this->postJson('/api/sistema/perfis', ['nome' => 'Tudo', 'acesso_total' => true], $this->s)->assertStatus(403);

        // as ligações a empresas que o gestor não controla mantêm-se
        $u = $this->criarUtilizador(['nome_utilizador' => 'misto', 'perfil_utilizador_id' => $simples->id]);
        $u->empresas()->attach([$this->empresa->id, $this->outra->id]);
        $this->putJson("/api/sistema/utilizadores/{$u->id}", ['empresas' => []], $this->s)->assertOk();
        $this->assertSame([$this->outra->id], $u->empresas()->pluck('empresas.id')->map(fn ($i) => (int) $i)->all());
    }

    #[Test]
    public function desactivar_e_repor_palavra_passe_revogam_as_sessoes(): void
    {
        $perfil = $this->criarPerfil(['_v2' => true, 'config_logs_view' => true], 'Leitor');
        $u = $this->criarUtilizador(['nome_utilizador' => 'leitor', 'perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);
        $su = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
        $this->getJson('/api/sistema/logs', $su)->assertOk();

        $this->putJson("/api/sistema/utilizadores/{$u->id}/estado", ['ativo' => false], $this->s)->assertOk()->assertJsonPath('dados.ativo', false);
        $this->getJson('/api/sistema/logs', $su)->assertUnauthorized();
        $this->assertSame(0, $u->tokens()->count());
        $this->putJson("/api/sistema/utilizadores/{$u->id}/estado", ['ativo' => true], $this->s)->assertOk();

        $su = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
        $this->postJson("/api/sistema/utilizadores/{$u->id}/palavra-passe", ['palavra_passe' => 'NovaChave#26', 'palavra_passe_confirmation' => 'NovaChave#26'], $this->s)->assertOk();
        $this->getJson('/api/sistema/logs', $su)->assertUnauthorized();
        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'leitor', 'palavra_passe' => 'Palavra#Passe2026'])->assertUnauthorized();
        $this->entrar($u, 'NovaChave#26');
    }

    #[Test]
    public function protege_a_propria_conta_e_o_ultimo_super_administrador(): void
    {
        $this->deleteJson("/api/sistema/utilizadores/{$this->admin->id}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OPERACAO_PROPRIA_CONTA');
        $this->putJson("/api/sistema/utilizadores/{$this->admin->id}/estado", ['ativo' => false], $this->s)->assertStatus(422);

        $super = $this->criarUtilizador(['nome_utilizador' => 'raiz', 'papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR]);
        $super->empresas()->attach($this->empresa->id);
        $ss = $this->entrar($super) + ['X-Empresa-Id' => $this->empresa->id];
        $outro = $this->criarUtilizador(['nome_utilizador' => 'raiz2', 'papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR]);
        $this->putJson("/api/sistema/utilizadores/{$outro->id}/estado", ['ativo' => false], $ss)->assertOk();
        $this->deleteJson("/api/sistema/utilizadores/{$super->id}", [], $ss)->assertStatus(422);
        $this->putJson("/api/sistema/utilizadores/{$super->id}", ['papel' => Utilizador::PAPEL_UTILIZADOR], $ss)->assertStatus(422)->assertJsonPath('codigo', 'ULTIMO_SUPER_ADMINISTRADOR');

        $alvo = $this->criarUtilizador(['nome_utilizador' => 'apagar']);
        $this->deleteJson("/api/sistema/utilizadores/{$alvo->id}", [], $this->s)->assertOk();
        $this->assertSoftDeleted('utilizadores', ['id' => $alvo->id], deletedAtColumn: 'eliminado_em');
    }

    #[Test]
    public function sem_permissao_de_gestao_so_consulta(): void
    {
        $u = $this->criarUtilizador(['nome_utilizador' => 'consulta', 'perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'config_utilizadores_view' => true], 'Consulta')->id]);
        $u->empresas()->attach($this->empresa->id);
        $sc = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
        $this->getJson('/api/sistema/utilizadores', $sc)->assertOk()->assertJsonPath('metadados.paginacao.total', 2);
        $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'x', 'palavra_passe' => 'Segredo#2026', 'perfil_utilizador_id' => 1], $sc)->assertForbidden();
        $this->getJson('/api/sistema/perfis', $sc)->assertForbidden();
    }

    #[Test]
    public function editor_de_perfis_valida_catalogo_ecras_e_segregacao(): void
    {
        $this->postJson('/api/sistema/perfis', ['nome' => 'Mau', 'permissoes' => ['lancamentos_view', 'chave_inventada']], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'PERMISSAO_INEXISTENTE');
        $this->postJson('/api/sistema/perfis', ['nome' => 'Sem ecrãs', 'permissoes' => []], $this->s)->assertStatus(422);

        $conflito = ['nome' => 'Administrador de acessos', 'permissoes' => ['config_utilizadores_view', 'config_util_gerir', 'config_perfis_gerir']];
        $r = $this->postJson('/api/sistema/perfis', $conflito, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'SEGREGACAO_FUNCOES');
        $this->assertSame('config_util_gerir', $r->json('erros.conflitos.0.a'));
        $criado = $this->postJson('/api/sistema/perfis', $conflito + ['confirmar_conflitos' => true], $this->s)->assertCreated();
        $this->assertCount(1, $criado->json('dados.avisos'));
        $this->assertEquals(['_v2' => true, 'config_utilizadores_view' => true, 'config_util_gerir' => true, 'config_perfis_gerir' => true],
            PerfilUtilizador::query()->findOrFail($criado->json('dados.id'))->permissoes);

        $this->postJson('/api/sistema/perfis', ['nome' => 'ADMINISTRADOR DE ACESSOS', 'permissoes' => ['lancamentos_view']], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'PERFIL_DUPLICADO');

        $avaliacao = $this->postJson('/api/sistema/perfis/avaliar', ['permissoes' => ['lancamentos_view', 'lancamentos_post']], $this->s)->assertOk();
        $this->assertSame(1, $avaliacao->json('dados.ecras'));
        $this->assertSame(1, $avaliacao->json('dados.tarefas'));

        $dup = $this->postJson("/api/sistema/perfis/{$criado->json('dados.id')}/duplicar", [], $this->s)->assertCreated();
        $this->assertSame('Cópia de Administrador de acessos', $dup->json('dados.nome'));
    }

    #[Test]
    public function perfil_em_uso_nao_se_elimina_e_modelos_sao_criados_e_actualizados(): void
    {
        $p = $this->criarPerfil(['_v2' => true, 'lancamentos_view' => true], 'Em uso');
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $p->id]);
        $u->delete();
        $this->deleteJson("/api/sistema/perfis/{$p->id}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PERFIL_EM_USO');
        $livre = $this->criarPerfil(['_v2' => true, 'lancamentos_view' => true], 'Livre');
        $this->deleteJson("/api/sistema/perfis/{$livre->id}", [], $this->s)->assertOk();

        $sim = $this->postJson('/api/sistema/perfis/modelos', ['simular' => true], $this->s)->assertOk();
        $this->assertContains('Contabilista', $sim->json('dados.perfis'));
        $this->assertSame(0, PerfilUtilizador::query()->where('nome', 'Contabilista')->count());
        $this->postJson('/api/sistema/perfis/modelos', [], $this->s)->assertOk();
        $contabilista = PerfilUtilizador::query()->where('nome', 'Contabilista')->firstOrFail();

        $menos = $contabilista->permissoes;
        unset($menos['lancamentos_post']);
        $contabilista->update(['permissoes' => $menos + ['extra_manual' => true]]);
        $alt = $this->postJson('/api/sistema/perfis/modelos/actualizar', [], $this->s)->assertOk()->json('dados.alteracoes');
        $this->assertSame(['lancamentos_post'], collect($alt)->firstWhere('nome', 'Contabilista')['acrescentar']);
        $this->assertTrue($contabilista->refresh()->permissoes['lancamentos_post']);
        $this->assertTrue($contabilista->permissoes['extra_manual']);
        $this->assertSame(0, (int) DB::table('perfis_utilizador')->where('nome', 'Contabilista')->count() - 1);
    }
}
