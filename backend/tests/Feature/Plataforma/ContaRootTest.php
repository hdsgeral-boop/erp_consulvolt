<?php

namespace Tests\Feature\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\Tenant;
use App\Models\Terceiro;
use App\Models\Utilizador;
use App\Services\Sistema\CatalogoPermissoes;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoPermissoes;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Conta ROOT global (ADR-084): atravessa tenant, plano, licença e permissões, com a RLS activa (SET ROLE erp_app, como
 * em produção), auditada com `root=true`, e blindada contra qualquer escalada (nenhuma API a cria, edita ou desactiva).
 */
class ContaRootTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.api.pedidos_por_minuto' => 1000000, 'erp.sessao.stepup_ativo' => false]);
    }

    protected function tearDown(): void
    {
        DB::statement('RESET ROLE');
        DB::selectOne("SELECT set_config('app.tenant_id', '', false)");
        Utilizador::$autorizarAlteracaoRoot = false;

        parent::tearDown();
    }

    /**
     * Dois clientes (A e B), uma empresa e um terceiro em cada, e uma conta root no cliente A. Tudo criado com a ligação do
     * dono; só no fim se liga o SaaS e se muda para a role da aplicação (RLS a sério).
     *
     * @return array{a: Tenant, b: Tenant, ea: Empresa, eb: Empresa, root: Utilizador, super: Utilizador}
     */
    private function cenario(bool $comRls = true): array
    {
        $a = Tenant::query()->create(['nome' => 'Cliente A', 'slug' => 'cliente-a', 'estado' => Tenant::ESTADO_ATIVO]);
        $b = Tenant::query()->create(['nome' => 'Cliente B', 'slug' => 'cliente-b', 'estado' => Tenant::ESTADO_ATIVO]);
        $ea = $this->criarEmpresa(['nif' => '5000000701']);
        $eb = $this->criarEmpresa(['nif' => '5000000702']);
        DB::table('empresas')->where('id', $ea->id)->update(['tenant_id' => $a->id]);
        DB::table('empresas')->where('id', $eb->id)->update(['tenant_id' => $b->id]);
        foreach ([[$ea, 'Terceiro da A', '5000000711'], [$eb, 'Terceiro da B', '5000000712']] as [$e, $nome, $nif]) {
            app(ContextoEmpresa::class)->executarComo($e->id, fn () => Terceiro::create(['nome' => $nome, 'nif' => $nif, 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311']));
        }
        $root = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true, 'email' => 'root@exemplo.test']);
        DB::table('utilizadores')->where('id', $root->id)->update(['tenant_id' => $a->id, 'conta_root' => true]);
        $super = $this->superAdminDe($a);
        $root = $root->fresh();

        if ($comRls) {
            config(['erp.saas.ativo' => true]);
            app(ContextoTenant::class)->limpar();
            DB::statement('SET ROLE erp_app');
        }

        return ['a' => $a, 'b' => $b, 'ea' => $ea, 'eb' => $eb, 'root' => $root, 'super' => $super];
    }

    /** Super-administrador de um cliente que NÃO é root. */
    private function superAdminDe(Tenant $t, string $nome = 'super_cliente'): Utilizador
    {
        $u = $this->criarUtilizador(['nome_utilizador' => $nome, 'papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);
        DB::table('utilizadores')->where('id', $u->id)->update(['tenant_id' => $t->id]);

        return $u;
    }

    /** @return array<string, string> */
    private function como(Utilizador $u, ?int $empresaId = null): array
    {
        $cab = $this->entrar($u);

        return $empresaId === null ? $cab : $cab + ['X-Empresa-Id' => (string) $empresaId];
    }

    public function test_a_migracao_nao_marca_ninguem_e_o_login_devolve_o_campo(): void
    {
        $this->assertSame(0, DB::table('utilizadores')->where('conta_root', true)->count());
        $u = $this->criarUtilizador(['nome_utilizador' => 'quem_quer']);
        $this->assertFalse((bool) DB::table('utilizadores')->where('id', $u->id)->value('conta_root'), 'default false');

        DB::table('utilizadores')->where('id', $u->id)->update(['conta_root' => true]);
        $linha = DB::selectOne('SELECT conta_root FROM login_localizar_utilizador(?, NULL)', ['quem_quer']);
        $this->assertTrue((bool) $linha->conta_root, 'a função SQL de login devolve a coluna');
        $this->assertTrue(Utilizador::fromQuery('SELECT * FROM login_localizar_utilizador(?, NULL)', ['quem_quer'])->first()->eRoot());
    }

    public function test_root_abre_todas_as_rotas_get_sem_parametros_sob_rls(): void
    {
        $c = $this->cenario();
        $cab = $this->como($c['root'], $c['eb']->id);   // a empresa activa é de OUTRO cliente: a root não pertence-lhe

        $falhas = [];
        $pessoais = [];
        $total = 0;
        foreach (Route::getRoutes() as $rota) {
            $uri = $rota->uri();
            if (! in_array('GET', $rota->methods(), true) || ! str_starts_with($uri, 'api/') || str_contains($uri, '{')
                || str_starts_with($uri, 'api/autenticacao/google') || str_starts_with($uri, 'api/pagamentos/webhook')) {
                continue;
            }
            $total++;
            $resposta = $this->getJson('/'.$uri, $cab);
            $estado = $resposta->getStatusCode();
            if ($estado === 403 && in_array($resposta->json('codigo'), ['SEM_COLABORADOR', 'SEM_ACESSO_PORTAL'], true)) {
                // dados PESSOAIS do portal («o meu recibo»): exigem um registo de colaborador ligado ao utilizador na empresa —
                // condição de dados, não barreira de privilégio (a root não é colaborador de nenhuma empresa)
                $pessoais[] = $uri;

                continue;
            }
            if ($estado === 401 || $estado === 403 || $estado >= 500) {
                $falhas[] = "/{$uri} ({$rota->getName()}) => {$estado}";
            }
        }
        $this->assertNotEmpty($pessoais);
        $this->assertTrue(collect($pessoais)->every(fn ($u) => str_starts_with($u, 'api/rh/')), 'só rotas pessoais do RH são isentas');
        $this->assertGreaterThan(300, $total, 'o percurso tem de cobrir as rotas GET da aplicação');
        $this->assertSame([], $falhas, 'rotas GET sem parâmetros que a conta root não consegue abrir');
    }

    public function test_root_de_um_cliente_abre_a_consola_da_plataforma_e_o_suporte(): void
    {
        $c = $this->cenario();
        $cab = $this->como($c['root']);

        $this->getJson('/api/plataforma/painel', $cab)->assertOk();
        $this->getJson('/api/plataforma/clientes', $cab)->assertOk();
        $this->getJson('/api/suporte/metricas', $cab)->assertOk();
        $this->getJson('/api/autenticacao/eu', $cab)->assertOk()
            ->assertJsonPath('dados.utilizador.conta_root', true)
            ->assertJsonPath('dados.utilizador.area_plataforma', true)
            ->assertJsonPath('dados.utilizador.area_suporte', true);
    }

    public function test_root_entra_em_empresas_de_dois_clientes_e_ve_so_os_dados_da_empresa_escolhida(): void
    {
        $c = $this->cenario();

        foreach ([[$c['ea'], 'Terceiro da A', 'Terceiro da B'], [$c['eb'], 'Terceiro da B', 'Terceiro da A']] as [$empresa, $quero, $nao]) {
            $cab = $this->como($c['root'], $empresa->id);
            $nomes = collect($this->getJson('/api/terceiros', $cab)->assertOk()->json('dados'))->pluck('nome')->all();
            $this->assertContains($quero, $nomes);
            $this->assertNotContains($nao, $nomes, 'a RLS só mostra a empresa escolhida');
        }
    }

    public function test_root_escolhe_a_empresa_da_sessao_de_outro_cliente_e_o_menu_e_completo(): void
    {
        $c = $this->cenario();
        $cab = $this->como($c['root']);

        $this->putJson('/api/autenticacao/empresa-activa', ['empresa_id' => $c['eb']->id], $cab)->assertOk();
        $menu = $this->getJson('/api/sistema/menu', $cab)->assertOk();   // sem X-Empresa-Id: vale a empresa activa da sessão (cliente B)
        $this->assertSame(['*'], $menu->json('dados.permissoes'));
        $ids = collect($menu->json('dados.menu'))->pluck('id')->all();
        $todos = collect(CatalogoPermissoes::completo()['modulos'])->pluck('id')->all();
        $this->assertSame([], array_values(array_diff($todos, $ids)), 'o menu da root traz todos os módulos do catálogo (plano e holding à parte)');
        $ecras = collect($menu->json('dados.menu'))->flatMap(fn ($m) => collect($m['ecras'])->pluck('id'))->all();
        $this->assertContains('plataforma_clientes', $ecras);
        $this->assertTrue(collect($ecras)->contains(fn ($e) => str_starts_with($e, 'suporte_')));
    }

    public function test_root_tem_toda_a_permissao_incluindo_as_futuras_e_atravessa_licenca_e_plano(): void
    {
        $c = $this->cenario(false);
        $p = app(ServicoPermissoes::class);
        $this->assertTrue($p->tem($c['root'], 'permissao_que_ainda_nao_existe_no_catalogo'));
        $this->assertTrue($p->tem($c['root'], 'plataforma_clientes_view'));
        $this->assertTrue($p->tem($c['root'], 'suporte_tickets_geral_gerir'));
        $this->assertTrue($p->tem($c['root'], 'ia_chat_view'));
        $this->assertTrue($p->autoriza($c['root'], 'vendas_faturas_emitir'));

        // licença suspensa da empresa do outro cliente: a root continua a escrever (a conta de cliente não)
        config(['erp.licencas.ativo' => true]);
        DB::table('empresas')->where('id', $c['eb']->id)->update(['estado_licenca' => Empresa::LICENCA_SUSPENSA]);
        $cab = $this->como($c['root'], $c['eb']->id);
        $this->putJson('/api/autenticacao/empresa-activa', ['empresa_id' => $c['eb']->id], $cab)->assertOk();
        $r = $this->postJson('/api/terceiros', ['nome' => ''], $cab);   // chega à validação (422), não ao bloqueio da licença (403)
        $this->assertNotContains($r->getStatusCode(), [401, 403]);
    }

    public function test_quem_nao_e_root_nao_ganha_nada(): void
    {
        $c = $this->cenario();
        $outro = $c['super'];
        $cab = $this->como($outro, $c['eb']->id);

        $this->getJson('/api/terceiros', $cab)->assertForbidden();   // empresa de outro cliente: sem acesso
        $this->getJson('/api/plataforma/painel', $this->como($outro))->assertForbidden();
        $this->getJson('/api/suporte/metricas', $this->como($outro))->assertForbidden();
        $this->getJson('/api/plataforma/root/empresas', $this->como($outro))->assertForbidden();
        $this->getJson('/api/autenticacao/eu', $this->como($outro))->assertOk()->assertJsonPath('dados.utilizador.conta_root', false);
        $this->assertFalse($outro->fresh()->eRoot());
    }

    public function test_super_administrador_da_plataforma_tambem_nao_ve_o_selector_root(): void
    {
        $plataforma = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);   // tenant 1
        $this->getJson('/api/plataforma/root/empresas', $this->entrar($plataforma))->assertForbidden()->assertJsonPath('codigo', 'AREA_RESERVADA_ROOT');
    }

    public function test_so_o_selector_de_empresas_usa_o_middleware_root_e_com_a_barreira_da_plataforma(): void
    {
        $comRoot = [];
        foreach (Route::getRoutes() as $rota) {
            if (in_array('root', $rota->middleware(), true)) {
                $comRoot[] = $rota->getName();
                $this->assertContains('plataforma', $rota->middleware(), 'as rotas root passam primeiro pela barreira da plataforma');
            }
        }
        $this->assertSame(['root.empresas'], $comRoot, 'qualquer rota nova só da root tem de ser decidida de propósito');
    }

    public function test_selector_de_empresas_da_root_e_paginado_e_sem_dados_pessoais(): void
    {
        $c = $this->cenario();
        $cab = $this->como($c['root']);

        $r = $this->getJson('/api/plataforma/root/empresas?por_pagina=1', $cab)->assertOk();
        $this->assertCount(1, $r->json('dados'));
        $this->assertGreaterThanOrEqual(2, $r->json('metadados.paginacao.total'));
        $todas = $this->getJson('/api/plataforma/root/empresas?por_pagina=100', $cab)->assertOk()->json('dados');
        $clientes = collect($todas)->pluck('cliente_nome')->all();
        $this->assertContains('Cliente A', $clientes);
        $this->assertContains('Cliente B', $clientes);
        $this->assertSame(['cliente_id', 'cliente_nome', 'estado', 'id', 'logotipo', 'nif', 'nome'], collect($todas[0])->keys()->sort()->values()->all(), 'só metadados da empresa e do cliente');
        $nomes = collect($todas)->pluck('nome')->all();
        $this->assertSame(collect($nomes)->sortBy(fn ($n) => mb_strtolower((string) $n))->values()->all(), $nomes, 'empresas por ordem alfabética do nome');
        $this->getJson('/api/plataforma/root/empresas?pesquisa=Cliente%20B', $cab)->assertOk()
            ->assertJsonPath('dados.0.cliente_nome', 'Cliente B');
    }

    public function test_cada_pedido_da_root_fica_auditado_com_a_marca_root_e_a_empresa_alvo(): void
    {
        $c = $this->cenario();
        $cab = $this->como($c['root'], $c['eb']->id);

        $this->getJson('/api/terceiros', $cab)->assertOk();
        $log = DB::table('logs_auditoria')->where('modulo', 'Root')->where('empresa_id', $c['eb']->id)->orderByDesc('id')->first();
        $this->assertNotNull($log, 'o pedido da root fica em logs_auditoria com a empresa alvo');
        $novos = json_decode((string) $log->dados_novos, true);
        $this->assertTrue($novos['root']);
        $this->assertSame((int) $c['eb']->id, $novos['empresa_alvo_id']);
        $this->assertSame((int) $c['b']->id, $novos['tenant_alvo_id']);
        $this->assertSame('/api/terceiros', $novos['caminho']);
        $this->assertSame($c['root']->id, (int) $log->utilizador_id);
    }

    public function test_alteracoes_de_modelos_feitas_pela_root_levam_a_marca_root(): void
    {
        $c = $this->cenario(false);
        Auth::setUser($c['root']);
        app(ServicoAuditoria::class)->registar('Teste', 'Acção', 'x', empresaId: $c['eb']->id);

        $log = DB::table('logs_auditoria')->where('modulo', 'Teste')->first();
        $novos = json_decode((string) $log->dados_novos, true);
        $this->assertTrue($novos['root']);
        $this->assertSame((int) $c['eb']->id, $novos['empresa_alvo_id']);
    }

    public function test_conta_root_nao_se_define_por_mass_assignment_nem_por_codigo_aplicacional(): void
    {
        $base = ['nome_utilizador' => 'tentativa', 'palavra_passe' => 'Palavra#Passe2026', 'papel' => Utilizador::PAPEL_UTILIZADOR, 'ativo' => true];
        try {
            Utilizador::create($base + ['conta_root' => true]);   // fora do $fillable: o modo estrito recusa-o em vez de o ignorar
            $this->fail('mass assignment de conta_root tem de falhar');
        } catch (MassAssignmentException) {
            $this->addToAssertionCount(1);
        }
        $u = Utilizador::create($base);
        $this->assertNotContains('conta_root', $u->getFillable());
        $this->assertFalse((bool) $u->fresh()->conta_root);

        $this->expectException(ErroNegocio::class);
        $u->forceFill(['conta_root' => true])->save();
    }

    public function test_api_nao_cria_nem_promove_contas_root_nem_por_super_administradores(): void
    {
        $c = $this->cenario();
        $super = $c['super'];
        $cab = $this->como($super);

        $r = $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'novo_root', 'nome_completo' => 'Novo', 'email' => 'novo@exemplo.test', 'papel' => 'SUPER_ADMINISTRADOR', 'conta_root' => true, 'root' => true], $cab);
        $this->assertLessThan(500, $r->getStatusCode());
        $this->assertSame(0, DB::table('utilizadores')->where('nome_utilizador', 'novo_root')->where('conta_root', true)->count());

        $this->putJson('/api/sistema/utilizadores/'.$super->id, ['nome_completo' => 'Eu', 'conta_root' => true], $cab);
        $this->assertFalse((bool) DB::table('utilizadores')->where('id', $super->id)->value('conta_root'), 'um super-administrador não escala para root');
        $this->assertSame(1, DB::table('utilizadores')->where('conta_root', true)->count(), 'continua só a conta root do cenário');
    }

    public function test_ninguem_edita_desactiva_ou_elimina_a_root_e_ela_nao_aparece_nas_listas(): void
    {
        $c = $this->cenario();
        $super = $c['super'];
        $cab = $this->como($super, $c['ea']->id);
        $root = $c['root'];

        $resp = $this->getJson('/api/sistema/utilizadores', $cab);
        $lista = collect($resp->assertOk()->json('dados'))->pluck('id')->all();
        $this->assertNotContains($root->id, $lista, 'a root não se lista a outros utilizadores');
        $this->getJson('/api/sistema/utilizadores/'.$root->id, $cab)->assertNotFound();

        foreach ([
            ['put', '/api/sistema/utilizadores/'.$root->id, ['nome_completo' => 'Hackeada']],
            ['put', '/api/sistema/utilizadores/'.$root->id.'/estado', ['ativo' => false]],
            ['post', '/api/sistema/utilizadores/'.$root->id.'/palavra-passe', []],
            ['delete', '/api/sistema/utilizadores/'.$root->id, []],
        ] as [$metodo, $uri, $corpo]) {
            $estado = $this->{$metodo.'Json'}($uri, $corpo, $cab)->getStatusCode();
            $this->assertContains($estado, [403, 404, 422], "{$metodo} {$uri} => {$estado}");
        }
        $fresco = DB::table('utilizadores')->where('id', $root->id)->first();
        $this->assertTrue((bool) $fresco->ativo);
        $this->assertNull($fresco->eliminado_em);
        $this->assertNotSame('Hackeada', $fresco->nome_completo);

        // nem a própria root se desactiva, despromove ou elimina (impossível desactivar a última root pela API)
        $minha = $this->como($root, $c['ea']->id);
        foreach ([
            ['put', '/api/sistema/utilizadores/'.$root->id.'/estado', ['ativo' => false]],
            ['delete', '/api/sistema/utilizadores/'.$root->id, []],
            ['put', '/api/sistema/utilizadores/'.$root->id, ['papel' => 'UTILIZADOR']],
        ] as [$metodo, $uri, $corpo]) {
            $estado = $this->{$metodo.'Json'}($uri, $corpo, $minha)->getStatusCode();
            $this->assertContains($estado, [403, 404, 422], "root: {$metodo} {$uri} => {$estado}");
        }
        $fresco = DB::table('utilizadores')->where('id', $root->id)->first();
        $this->assertTrue((bool) $fresco->ativo);
        $this->assertTrue((bool) $fresco->conta_root);
        $this->assertSame(Utilizador::PAPEL_SUPER_ADMINISTRADOR, $fresco->papel);
    }

    public function test_modelo_recusa_alterar_a_root_por_outro_utilizador_ou_desactiva_la(): void
    {
        $c = $this->cenario(false);
        $outro = $c['super'];
        $root = Utilizador::query()->findOrFail($c['root']->id);

        Auth::setUser($outro);
        try {
            $root->nome_completo = 'X';
            $root->save();
            $this->fail('outro utilizador não altera a root');
        } catch (ErroNegocio) {
            $this->addToAssertionCount(1);
        }

        Auth::setUser($root);
        $root = Utilizador::query()->findOrFail($c['root']->id);
        $root->ativo = false;
        $this->expectException(ErroNegocio::class);
        $root->save();
    }

    public function test_root_nao_desactiva_o_mfa_pela_api(): void
    {
        $c = $this->cenario();
        $r = $this->postJson('/api/autenticacao/mfa/desactivar', ['palavra_passe' => 'Palavra#Passe2026'], $this->como($c['root']));
        $r->assertForbidden()->assertJsonPath('codigo', 'ROOT_MFA_OBRIGATORIO');
    }

    public function test_conta_root_desactivada_perde_os_poderes_e_nao_entra(): void
    {
        $c = $this->cenario(false);
        DB::table('utilizadores')->where('id', $c['root']->id)->update(['ativo' => false]);
        $this->assertFalse(Utilizador::query()->find($c['root']->id)->eRoot());
        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => $c['root']->nome_utilizador, 'palavra_passe' => 'Palavra#Passe2026'])
            ->assertStatus(401);
    }

    // ---------------------------------------------------------------- comando artisan

    private function utilizadorComum(string $nome = 'candidato'): Utilizador
    {
        return $this->criarUtilizador(['nome_utilizador' => $nome, 'papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR]);
    }

    public function test_comando_define_a_root_com_confirmacao_e_audita(): void
    {
        $u = $this->utilizadorComum();

        $this->artisan('erp:root:definir', ['utilizador_id' => $u->id])->expectsConfirmation('Tornar esta conta ROOT? Fica com acesso a TODOS os clientes, empresas, planos e permissões (acções auditadas).', 'no')
            ->assertFailed();
        $this->assertFalse((bool) DB::table('utilizadores')->where('id', $u->id)->value('conta_root'), 'sem confirmação não muda nada');

        $this->artisan('erp:root:definir', ['utilizador_id' => $u->id])->expectsConfirmation('Tornar esta conta ROOT? Fica com acesso a TODOS os clientes, empresas, planos e permissões (acções auditadas).', 'yes')
            ->assertSuccessful();
        $this->assertTrue((bool) DB::table('utilizadores')->where('id', $u->id)->value('conta_root'));
        $log = DB::table('logs_auditoria')->where('modulo', 'Root')->where('acao', 'Definiu conta root')->first();
        $this->assertNotNull($log);
        $this->assertTrue(json_decode((string) $log->dados_novos, true)['root']);
        $this->assertFalse(Utilizador::$autorizarAlteracaoRoot, 'o interruptor volta a desligar-se');
    }

    public function test_comando_por_nome_e_nao_interactivo_exige_confirmar(): void
    {
        $this->utilizadorComum('so_um');

        $this->artisan('erp:root:definir', ['--nome' => 'so_um', '--no-interaction' => true])->assertFailed();
        $this->assertSame(0, DB::table('utilizadores')->where('conta_root', true)->count());

        $this->artisan('erp:root:definir', ['--nome' => 'so_um', '--confirmar' => true])->assertSuccessful();
        $this->assertSame(1, DB::table('utilizadores')->where('conta_root', true)->count());
        $this->artisan('erp:root:listar')->expectsOutputToContain('so_um')->assertSuccessful();
    }

    public function test_comando_recusa_nome_ambiguo_inexistente_e_contas_que_nao_sao_super_administradores(): void
    {
        $this->utilizadorComum('Admin');
        $this->utilizadorComum('admin');
        $this->artisan('erp:root:definir', ['--nome' => 'admin', '--confirmar' => true])->expectsOutputToContain('ambíguo')->assertFailed();
        $this->artisan('erp:root:definir', ['--nome' => 'ninguem', '--confirmar' => true])->assertFailed();
        $this->artisan('erp:root:definir', ['utilizador_id' => 999999, '--confirmar' => true])->assertFailed();
        $this->artisan('erp:root:definir', ['--confirmar' => true])->assertFailed();

        $comum = $this->criarUtilizador(['nome_utilizador' => 'comum', 'papel' => Utilizador::PAPEL_UTILIZADOR]);
        $this->artisan('erp:root:definir', ['utilizador_id' => $comum->id, '--confirmar' => true])->assertFailed();
        $this->assertSame(0, DB::table('utilizadores')->where('conta_root', true)->count());
    }

    public function test_comando_revoga_mas_protege_a_ultima_root(): void
    {
        $a = $this->utilizadorComum('root_a');
        $b = $this->utilizadorComum('root_b');
        $this->artisan('erp:root:definir', ['utilizador_id' => $a->id, '--confirmar' => true])->assertSuccessful();
        $this->artisan('erp:root:definir', ['utilizador_id' => $b->id, '--confirmar' => true])->assertSuccessful();

        $this->artisan('erp:root:definir', ['utilizador_id' => $a->id, '--revogar' => true, '--confirmar' => true])->assertSuccessful();
        $this->assertFalse((bool) DB::table('utilizadores')->where('id', $a->id)->value('conta_root'));

        $this->artisan('erp:root:definir', ['utilizador_id' => $b->id, '--revogar' => true, '--confirmar' => true])->expectsOutputToContain('última conta root')->assertFailed();
        $this->assertTrue((bool) DB::table('utilizadores')->where('id', $b->id)->value('conta_root'));

        $this->artisan('erp:root:definir', ['utilizador_id' => $b->id, '--revogar' => true, '--ultima' => true, '--confirmar' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('utilizadores')->where('conta_root', true)->count());
    }
}
