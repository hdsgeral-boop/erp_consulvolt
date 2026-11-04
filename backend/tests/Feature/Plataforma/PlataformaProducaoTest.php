<?php

namespace Tests\Feature\Plataforma;

use App\Mail\PalavraPasseRedefinidaMail;
use App\Models\PlanoLicenca;
use App\Models\Tenant;
use App\Models\Utilizador;
use App\Services\Plataforma\ServicoClientes;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Integração da produção (Bloco A.9): utilizadores do cliente, planos, identidade, séries do painel, dados bancários. */
class PlataformaProducaoTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::statement('RESET ROLE');
        DB::selectOne("SELECT set_config('app.tenant_id', '', false)");

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function sessaoPlataforma(): array
    {
        return $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));
    }

    /** @return array{0: Tenant, 1: Utilizador, 2: Utilizador} cliente, administrador, utilizador comum */
    private function clienteComUtilizadores(string $slug = 'cliente-xyz'): array
    {
        $cliente = Tenant::query()->create(['nome' => 'Cliente '.$slug, 'slug' => $slug, 'estado' => Tenant::ESTADO_ATIVO]);
        $criar = function (string $nome, string $papel, string $email) use ($cliente) {
            $u = $this->criarUtilizador(['nome_utilizador' => $nome.$cliente->id, 'papel' => $papel, 'email' => $email]);
            DB::table('utilizadores')->where('id', $u->id)->update(['tenant_id' => $cliente->id]);

            return $u->refresh();
        };

        return [$cliente, $criar('adm', Utilizador::PAPEL_ADMINISTRADOR, "adm@{$slug}.test"), $criar('usr', Utilizador::PAPEL_UTILIZADOR, "usr@{$slug}.test")];
    }

    private function ligarSaas(): void
    {
        config(['erp.saas.ativo' => true]);
    }

    public function test_editar_utilizador_do_cliente_parciais_e_auditoria(): void
    {
        $s = $this->sessaoPlataforma();
        [$c, , $usr] = $this->clienteComUtilizadores();
        $this->ligarSaas();

        $this->putJson("/api/plataforma/clientes/{$c->id}/utilizadores/{$usr->id}", ['nome_completo' => 'Maria Nova', 'email' => 'Maria@Cliente.test', 'papel' => 'ADMINISTRADOR'], $s)
            ->assertOk()->assertJsonPath('dados.nome_completo', 'Maria Nova')->assertJsonPath('dados.papel', 'ADMINISTRADOR');
        $this->putJson("/api/plataforma/clientes/{$c->id}/utilizadores/{$usr->id}", ['ativo' => false], $s)->assertOk()->assertJsonPath('dados.ativo', false);

        $this->assertSame('Maria Nova', DB::table('utilizadores')->where('id', $usr->id)->value('nome_completo'));
        $this->assertFalse((bool) DB::table('utilizadores')->where('id', $usr->id)->value('ativo'));
        $this->assertTrue(DB::table('logs_auditoria')->where('acao', 'Alterar utilizador de cliente')->exists());
    }

    public function test_editar_utilizador_valida_email_unico_papel_e_ultimo_administrador(): void
    {
        $s = $this->sessaoPlataforma();
        [$c, $adm, $usr] = $this->clienteComUtilizadores();
        $this->ligarSaas();

        $this->putJson("/api/plataforma/clientes/{$c->id}/utilizadores/{$usr->id}", ['email' => $adm->email], $s)->assertStatus(422)->assertJsonPath('codigo', 'EMAIL_DUPLICADO');
        $this->putJson("/api/plataforma/clientes/{$c->id}/utilizadores/{$usr->id}", ['papel' => 'SUPER_ADMINISTRADOR'], $s)->assertStatus(422);
        $this->putJson("/api/plataforma/clientes/{$c->id}/utilizadores/{$usr->id}", ['email' => 'nao-e-email'], $s)->assertStatus(422);

        // único administrador activo: não se desactiva nem rebaixa
        $this->putJson("/api/plataforma/clientes/{$c->id}/utilizadores/{$adm->id}", ['ativo' => false], $s)->assertStatus(422)->assertJsonPath('codigo', 'ULTIMO_ADMINISTRADOR');
        $this->putJson("/api/plataforma/clientes/{$c->id}/utilizadores/{$adm->id}", ['papel' => 'UTILIZADOR'], $s)->assertStatus(422)->assertJsonPath('codigo', 'ULTIMO_ADMINISTRADOR');
        // com outro administrador já pode
        $this->putJson("/api/plataforma/clientes/{$c->id}/utilizadores/{$usr->id}", ['papel' => 'ADMINISTRADOR'], $s)->assertOk();
        $this->putJson("/api/plataforma/clientes/{$c->id}/utilizadores/{$adm->id}", ['ativo' => false], $s)->assertOk();
    }

    public function test_editar_utilizador_de_outro_cliente_da_404_e_exige_plataforma(): void
    {
        $s = $this->sessaoPlataforma();
        [$c1, , $usr1] = $this->clienteComUtilizadores('cliente-um');
        [$c2] = $this->clienteComUtilizadores('cliente-dois');
        $comum = $this->entrar($this->criarUtilizador());
        $this->ligarSaas();

        $this->putJson("/api/plataforma/clientes/{$c2->id}/utilizadores/{$usr1->id}", ['ativo' => false], $s)->assertNotFound();
        $this->putJson("/api/plataforma/clientes/{$c1->id}/utilizadores/{$usr1->id}", ['ativo' => false], $comum)->assertForbidden();
        $this->putJson("/api/plataforma/clientes/{$c1->id}/utilizadores/{$usr1->id}", ['ativo' => false])->assertUnauthorized();
    }

    public function test_editar_e_listar_utilizadores_do_cliente_sob_rls_real(): void
    {
        [$c, , $usr] = $this->clienteComUtilizadores('cliente-rls');
        $servico = app(ServicoClientes::class);
        $this->ligarSaas();

        DB::statement('SET ROLE erp_app');
        app(ContextoTenant::class)->definir(1);   // a sessão é a da plataforma: o cliente só se vê pelo contexto dele

        $lista = $servico->utilizadores($c);
        $this->assertCount(2, $lista);
        $r = $servico->atualizarUtilizador($c, $usr->id, ['nome_completo' => 'Sob RLS']);
        $this->assertSame('Sob RLS', $r['nome_completo']);
        $this->assertSame(1, app(ContextoTenant::class)->id(), 'o contexto da plataforma é reposto');
    }

    public function test_redefinir_palavra_passe_gera_no_servidor_e_exige_troca(): void
    {
        $s = $this->sessaoPlataforma();
        [$c, , $usr] = $this->clienteComUtilizadores();
        $this->ligarSaas();
        Mail::fake();

        $this->postJson("/api/plataforma/clientes/{$c->id}/utilizadores/{$usr->id}/redefinir", ['palavra_passe' => 'Escolhida#12345'], $s)->assertOk();
        $this->assertTrue((bool) DB::table('utilizadores')->where('id', $usr->id)->value('deve_alterar_password'));
        Mail::assertQueued(PalavraPasseRedefinidaMail::class);
    }

    public function test_criar_plano_com_modulos_dias_teste_e_activar_so_depois_de_publicar(): void
    {
        $s = $this->sessaoPlataforma();
        $this->ligarSaas();
        $modulos = array_slice(array_keys((array) config('erp.planos_catalogo.modulos')), 0, 2);

        $id = $this->postJson('/api/plataforma/planos', [
            'codigo' => 'ouro', 'nome' => 'Ouro', 'descricao' => 'Plano ouro', 'preco_mensal' => 25000, 'moeda' => 'aoa',
            'max_empresas' => 3, 'max_utilizadores' => 20, 'modulos' => $modulos, 'ordem' => 5, 'dias_teste' => 14,
        ], $s)->assertCreated()->assertJsonPath('dados.ativo', false)->json('dados.id');

        $this->postJson('/api/plataforma/planos', ['codigo' => 'ouro', 'nome' => 'Outro', 'preco_mensal' => 1, 'moeda' => 'AOA'], $s)->assertStatus(422);
        $this->postJson('/api/plataforma/planos', ['codigo' => 'x1', 'nome' => 'X', 'preco_mensal' => 1, 'moeda' => 'AOA', 'modulos' => ['inexistente']], $s)->assertStatus(422);

        $plano = collect($this->getJson('/api/plataforma/planos', $s)->assertOk()->json('dados.planos'))->firstWhere('id', $id);
        $this->assertSame('Plano ouro', $plano['descricao']);
        $this->assertSame(5, $plano['ordem']);
        $this->assertFalse($plano['tem_versao_publicada']);
        $this->assertSame(14, $plano['dias_teste']);
        $this->assertSame($modulos, $plano['versoes'][0]['modulos']);

        $this->putJson("/api/plataforma/planos/{$id}", ['ativo' => true], $s)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_SEM_VERSAO_PUBLICADA');
        $this->postJson('/api/plataforma/versoes/'.$plano['versoes'][0]['id'].'/publicar', [], $s)->assertOk();
        $this->putJson("/api/plataforma/planos/{$id}", ['ativo' => true, 'nome' => 'Ouro Plus', 'ordem' => 2], $s)->assertOk()->assertJsonPath('dados.ativo', true);
        $plano = collect($this->getJson('/api/plataforma/planos', $s)->json('dados.planos'))->firstWhere('id', $id);
        $this->assertTrue($plano['tem_versao_publicada']);
        $this->assertSame('Ouro Plus', $plano['nome']);
        $this->assertTrue(DB::table('logs_auditoria')->where('acao', 'Criou plano')->exists());
    }

    public function test_desactivar_plano_em_uso_exige_confirmacao(): void
    {
        $s = $this->sessaoPlataforma();
        $comum = $this->entrar($this->criarUtilizador());
        $this->ligarSaas();
        $plano = PlanoLicenca::query()->create(['codigo' => 'prata', 'nome' => 'Prata', 'preco_mensal' => 10, 'moeda' => 'AOA', 'ativo' => true]);
        Tenant::query()->create(['nome' => 'Usa Prata', 'slug' => 'usa-prata', 'estado' => 'ATIVO', 'plano' => 'prata']);

        $this->putJson("/api/plataforma/planos/{$plano->id}", ['ativo' => false], $s)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_EM_USO');
        $this->putJson("/api/plataforma/planos/{$plano->id}", ['ativo' => false, 'confirmar_desativacao' => true], $s)->assertOk()->assertJsonPath('dados.ativo', false);
        $this->postJson('/api/plataforma/planos', ['codigo' => 'z', 'nome' => 'Z', 'preco_mensal' => 1, 'moeda' => 'AOA'], $comum)->assertForbidden();
    }

    public function test_clientes_filtram_por_pesquisa_estado_e_plano_e_devolvem_o_plano(): void
    {
        $s = $this->sessaoPlataforma();
        $this->ligarSaas();
        Tenant::query()->create(['nome' => 'Alfa Lda', 'slug' => 'alfa', 'estado' => 'ATIVO', 'plano' => 'basico']);
        Tenant::query()->create(['nome' => 'Beta Lda', 'slug' => 'beta', 'estado' => 'SUSPENSO', 'plano' => 'pro']);
        Tenant::query()->create(['nome' => '100% Gama', 'slug' => 'gama', 'estado' => 'ATIVO']);

        $nomes = fn (array $q) => collect($this->getJson('/api/plataforma/clientes?'.http_build_query($q), $s)->assertOk()->json('dados'))->pluck('nome')->all();
        $this->assertSame(['Alfa Lda'], $nomes(['q' => 'ALF']));
        $this->assertSame(['Beta Lda'], $nomes(['estado' => 'SUSPENSO']));
        $this->assertSame(['Beta Lda'], $nomes(['plano' => 'pro']));
        $this->assertSame(['100% Gama'], $nomes(['q' => '100%']));
        $this->assertSame([], $nomes(['q' => '%%xx']));
        $this->assertSame('basico', collect($this->getJson('/api/plataforma/clientes?q=alfa', $s)->json('dados'))->first()['plano']);
        $this->getJson('/api/plataforma/clientes?estado=XPTO', $s)->assertStatus(422);
    }

    public function test_identidade_do_sistema_por_cliente_com_precedencia_e_reserva_global(): void
    {
        $png = 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $empresa = $this->criarEmpresa();
        $super = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true])) + ['X-Empresa-Id' => $empresa->id];
        $comumU = $this->criarUtilizador();
        $comumU->empresas()->attach($empresa->id);
        $comum = $this->entrar($comumU) + ['X-Empresa-Id' => $empresa->id];
        $this->ligarSaas();

        $this->getJson('/api/sistema/logotipo-login')->assertOk()->assertJsonPath('dados.logotipo', null)->assertJsonPath('dados.nome_sistema', 'CONSULVOLT ERP');

        $this->putJson('/api/sistema/configuracoes/logotipo-login', ['logotipo' => $png, 'nome_sistema' => 'Meu Painel'], $super)->assertOk();
        $this->assertSame($png, DB::table('tenants')->where('id', 1)->value('logotipo_sistema'));
        $this->assertSame($png, DB::table('configuracoes_sistema')->where('chave', 'login_logo')->value('valor'));   // reserva global (comportamento anterior)
        $this->getJson('/api/sistema/logotipo-login')->assertOk()->assertJsonPath('dados.logotipo', $png)->assertJsonPath('dados.nome_sistema', 'Meu Painel');

        // só o nome: não toca no logótipo
        $this->putJson('/api/sistema/configuracoes/logotipo-login', ['nome_sistema' => 'Outro Nome'], $super)->assertOk();
        $this->getJson('/api/sistema/logotipo-login')->assertJsonPath('dados.logotipo', $png)->assertJsonPath('dados.nome_sistema', 'Outro Nome');

        // dados inválidos
        $this->putJson('/api/sistema/configuracoes/logotipo-login', ['logotipo' => 'data:text/html;base64,'.base64_encode('<b>x</b>')], $super)->assertStatus(422);
        $this->putJson('/api/sistema/configuracoes/logotipo-login', ['nome_sistema' => str_repeat('x', 81)], $super)->assertStatus(422);
        $this->putJson('/api/sistema/configuracoes/logotipo-login', ['logotipo' => $png], $comum)->assertForbidden();

        $this->putJson('/api/sistema/configuracoes/logotipo-login', ['logotipo' => null, 'nome_sistema' => null], $super)->assertOk();
        $this->getJson('/api/sistema/logotipo-login')->assertJsonPath('dados.logotipo', null)->assertJsonPath('dados.nome_sistema', 'CONSULVOLT ERP');
    }

    public function test_identidade_de_um_cliente_nao_afecta_o_outro(): void
    {
        config(['erp.saas.ativo' => true, 'erp.dominio.base' => 'meuerp.test']);
        $png = 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        Tenant::query()->create(['nome' => 'A', 'slug' => 'aaa', 'estado' => 'ATIVO', 'nome_sistema' => 'Sistema A'])->forceFill(['logotipo_sistema' => $png])->save();
        Tenant::query()->create(['nome' => 'B', 'slug' => 'bbb', 'estado' => 'ATIVO']);

        $this->getJson('http://aaa.meuerp.test/api/sistema/logotipo-login')->assertJsonPath('dados.logotipo', $png)->assertJsonPath('dados.nome_sistema', 'Sistema A');
        $this->getJson('http://bbb.meuerp.test/api/sistema/logotipo-login')->assertJsonPath('dados.logotipo', null)->assertJsonPath('dados.nome_sistema', 'CONSULVOLT ERP');
    }

    public function test_painel_devolve_series_e_so_conta_clientes_da_plataforma_fora(): void
    {
        $s = $this->sessaoPlataforma();
        $u = $this->criarUtilizador();
        $this->ligarSaas();
        PlanoLicenca::query()->create(['codigo' => 'pro', 'nome' => 'Profissional', 'preco_mensal' => 10, 'moeda' => 'AOA', 'ativo' => true]);
        Tenant::query()->create(['nome' => 'C1', 'slug' => 'c1', 'estado' => 'ATIVO', 'plano' => 'pro']);
        Tenant::query()->create(['nome' => 'C2', 'slug' => 'c2', 'estado' => 'ATIVO', 'plano' => 'pro']);
        Tenant::query()->create(['nome' => 'C3', 'slug' => 'c3', 'estado' => 'ATIVO']);
        DB::table('utilizadores')->where('id', $u->id)->update(['tenant_id' => Tenant::query()->where('slug', 'c1')->value('id'), 'ultimo_acesso_em' => now()]);
        Cache::forget('plataforma:painel:series');

        $i = $this->getJson('/api/plataforma/painel', $s)->assertOk()->json('dados.indicadores');
        $this->assertSame('AOA', $i['moeda']);
        $this->assertCount(12, $i['meses']);
        $this->assertCount(12, $i['clientes_novos_mes']);
        $this->assertCount(12, $i['receita_mes']);
        $this->assertCount(30, $i['dias']);
        $this->assertCount(30, $i['acessos_dia']);
        $this->assertSame(3, $i['clientes_novos_mes'][11]);   // o cliente da plataforma não conta
        $this->assertSame(1, $i['acessos_dia'][29]);
        $this->assertEqualsCanonicalizing([['plano' => 'Profissional', 'clientes' => 2], ['plano' => 'Sem plano', 'clientes' => 1]], $i['por_plano']);
        // campos existentes mantêm-se
        $this->assertArrayHasKey('mrr', $i);
        $this->assertArrayHasKey('tickets', $i);
    }

    public function test_series_do_painel_atravessam_todos_os_clientes_sob_rls_real(): void
    {
        $outro = Tenant::query()->create(['nome' => 'RLS Outro', 'slug' => 'rls-outro', 'estado' => 'ATIVO']);
        DB::table('utilizadores')->insert(['tenant_id' => $outro->id, 'nome_utilizador' => 'rls_acesso', 'palavra_passe' => 'x', 'papel' => 'UTILIZADOR', 'ativo' => true,
            'ultimo_acesso_em' => now(), 'criado_em' => now(), 'atualizado_em' => now()]);
        $this->ligarSaas();

        DB::statement('SET ROLE erp_app');
        app(ContextoTenant::class)->definir(1);

        $this->assertSame(0, DB::table('utilizadores')->where('nome_utilizador', 'rls_acesso')->count(), 'a consulta directa não vê o outro cliente');
        $acessos = collect(DB::select('SELECT dia, total FROM plataforma_acessos_por_dia(?, ?)', [now()->subDays(2), 1]))->pluck('total', 'dia');
        $this->assertSame(1, (int) ($acessos[now()->format('Y-m-d')] ?? 0), 'a função SECURITY DEFINER vê todos (excepto a plataforma)');
        $this->assertIsArray(DB::select('SELECT mes, total FROM plataforma_receita_por_mes(?)', [now()->subYear()]));
    }

    public function test_dados_bancarios_so_para_quem_gere_a_licenca(): void
    {
        config(['erp.licencas.ativo' => true]);
        $empresa = $this->criarEmpresa();
        $gestor = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'config_licenca_gerir' => true, 'config_licenca_view' => true])->id]);
        $gestor->empresas()->attach($empresa->id);
        $sessaoGestor = $this->entrar($gestor) + ['X-Empresa-Id' => $empresa->id];
        DB::table('configuracoes_gateway')->updateOrInsert(['chave' => 'iban'], ['valor' => 'AO06000000000000000000000']);
        DB::table('configuracoes_gateway')->updateOrInsert(['chave' => 'banco_nome'], ['valor' => 'Banco X']);

        $this->getJson('/api/licenca/dados-bancarios', $sessaoGestor)->assertOk()->assertJsonPath('dados.iban', 'AO06000000000000000000000')->assertJsonPath('dados.banco', 'Banco X');

        $comum = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'config_licenca_view' => true], 'Só ver')->id]);
        $comum->empresas()->attach($empresa->id);
        $this->getJson('/api/licenca/dados-bancarios', $this->entrar($comum) + ['X-Empresa-Id' => $empresa->id])->assertForbidden();
    }
}
