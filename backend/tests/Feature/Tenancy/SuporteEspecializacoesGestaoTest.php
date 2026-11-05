<?php

namespace Tests\Feature\Tenancy;

use App\Models\PerfilUtilizador;
use App\Models\Tenant;
use App\Models\TicketSuporte;
use App\Models\Utilizador;
use App\Services\Plataforma\ServicoEspecializacoesSuporte;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Especializações do backoffice de suporte (ADR-077): criação idempotente das base, selector com dados, gestão
 * (criar/editar/desactivar/eliminar) com validação, visibilidade de tickets por categoria e não-escalada.
 */
final class SuporteEspecializacoesGestaoTest extends TestCase
{
    /** @return array<string, string> */
    private function superAdmin(): array
    {
        $s = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));
        config(['erp.saas.ativo' => true]);

        return $s;
    }

    private function ticket(string $categoria): TicketSuporte
    {
        $c = Tenant::query()->create(['nome' => 'Cliente '.uniqid(), 'slug' => 'cliente-'.uniqid()]);

        return TicketSuporte::query()->create(['tenant_id' => $c->id, 'assunto' => "T {$categoria}", 'categoria' => $categoria, 'estado' => 'ABERTO', 'criado_por' => 'x']);
    }

    private function limparEspecializacoes(): void
    {
        PerfilUtilizador::query()->where('restrito_plataforma', true)->get()
            ->filter(fn ($p) => ServicoEspecializacoesSuporte::soSuporte($p->permissoes ?? []) && ! Utilizador::query()->where('perfil_utilizador_id', $p->id)->exists())
            ->each->delete();
    }

    public function test_garantir_base_e_idempotente_e_nao_altera_perfis_existentes(): void
    {
        $this->limparEspecializacoes();
        $servico = app(ServicoEspecializacoesSuporte::class);

        $criados = $servico->garantirBase();
        $this->assertCount(count(ServicoEspecializacoesSuporte::BASE), $criados);
        $this->assertSame([], $servico->garantirBase());
        $total = PerfilUtilizador::query()->where('restrito_plataforma', true)->count();
        $this->assertSame([], $servico->garantirBase());
        $this->assertSame($total, PerfilUtilizador::query()->where('restrito_plataforma', true)->count());

        $tecnico = PerfilUtilizador::query()->where('nome', 'Agente de Suporte Técnico')->firstOrFail();
        $this->assertTrue($tecnico->restrito_plataforma);
        $this->assertEquals(['_v2' => true, 'suporte_tickets_tecnico_view' => true, 'suporte_tickets_tecnico_gerir' => true], $tecnico->permissoes);

        $todas = PerfilUtilizador::query()->where('nome', 'Agente de Suporte (todas as categorias)')->firstOrFail();
        $this->assertCount(4, $servico->gruposDe($todas->permissoes));
    }

    public function test_comando_cria_em_falta_e_simular_nao_grava(): void
    {
        $this->limparEspecializacoes();
        $antes = PerfilUtilizador::query()->count();

        $this->artisan('erp:suporte:criar-especializacoes --simular')->assertSuccessful();
        $this->assertSame($antes, PerfilUtilizador::query()->count());

        $this->artisan('erp:suporte:criar-especializacoes')->assertSuccessful();
        $this->assertSame($antes + count(ServicoEspecializacoesSuporte::BASE), PerfilUtilizador::query()->count());
        $this->artisan('erp:suporte:criar-especializacoes')->assertSuccessful();
        $this->assertSame($antes + count(ServicoEspecializacoesSuporte::BASE), PerfilUtilizador::query()->count());
    }

    public function test_selector_vazio_cria_as_base_de_forma_lazy_com_categorias(): void
    {
        $this->limparEspecializacoes();
        $s = $this->superAdmin();

        $dados = $this->getJson('/api/plataforma/perfis-suporte', $s)->assertOk()->json('dados');
        $this->assertCount(count(ServicoEspecializacoesSuporte::BASE), $dados);
        $fin = collect($dados)->firstWhere('nome', 'Agente de Suporte Financeiro');
        $this->assertEqualsCanonicalizing(['PLANO', 'FACTURACAO', 'PAGAMENTO', 'SUBSCRICAO'], $fin['categorias']);
    }

    public function test_gestao_cria_edita_desactiva_e_elimina_com_permissoes_geradas(): void
    {
        $s = $this->superAdmin();

        $r = $this->postJson('/api/plataforma/especializacoes-suporte', ['nome' => 'Agente Financeiro+Marketing', 'descricao' => 'Teste', 'grupos' => ['financeiro', 'marketing']], $s)->assertCreated();
        $id = $r->json('dados.id');
        $p = PerfilUtilizador::query()->findOrFail($id);
        $this->assertTrue($p->restrito_plataforma);
        $this->assertEquals(['_v2' => true, 'suporte_tickets_financeiro_view' => true, 'suporte_tickets_financeiro_gerir' => true, 'suporte_tickets_marketing_view' => true, 'suporte_tickets_marketing_gerir' => true], $p->permissoes);

        $this->putJson("/api/plataforma/especializacoes-suporte/{$id}", ['grupos' => ['tecnico']], $s)->assertOk()->assertJsonPath('dados.grupos', ['tecnico']);
        $this->assertArrayNotHasKey('suporte_tickets_financeiro_gerir', $p->refresh()->permissoes);

        $this->putJson("/api/plataforma/especializacoes-suporte/{$id}", ['ativo' => false], $s)->assertOk()->assertJsonPath('dados.ativo', false);
        $ids = collect($this->getJson('/api/plataforma/perfis-suporte', $s)->json('dados'))->pluck('id');
        $this->assertFalse($ids->contains($id));   // desactivada: não é oferecida a novos agentes
        $todas = collect($this->getJson('/api/plataforma/especializacoes-suporte', $s)->assertOk()->json('dados.especializacoes'))->pluck('id');
        $this->assertTrue($todas->contains($id));  // mas a gestão continua a vê-la

        $this->postJson('/api/plataforma/agentes-suporte', ['nome_utilizador' => 'ag.inativa', 'perfil_utilizador_id' => $id], $s)->assertUnprocessable();

        $this->deleteJson("/api/plataforma/especializacoes-suporte/{$id}", [], $s)->assertOk();
        $this->assertNull(PerfilUtilizador::query()->find($id));
    }

    public function test_validacao_grupos_nome_repetido_e_perfis_que_nao_sao_especializacoes(): void
    {
        $s = $this->superAdmin();
        $this->postJson('/api/plataforma/especializacoes-suporte', ['nome' => 'X', 'grupos' => []], $s)->assertUnprocessable();
        $this->postJson('/api/plataforma/especializacoes-suporte', ['nome' => 'X', 'grupos' => ['all']], $s)->assertUnprocessable();
        $this->postJson('/api/plataforma/especializacoes-suporte', ['nome' => 'X', 'grupos' => ['geral'], 'permissoes' => ['all' => true]], $s)->assertCreated();
        $x = PerfilUtilizador::query()->where('nome', 'X')->firstOrFail();
        $this->assertArrayNotHasKey('all', $x->permissoes);   // permissões vêm só dos grupos, nunca do pedido

        $this->postJson('/api/plataforma/especializacoes-suporte', ['nome' => ' x ', 'grupos' => ['geral']], $s)->assertUnprocessable();

        $normal = PerfilUtilizador::create(['nome' => 'Perfil Normal '.uniqid(), 'permissoes' => ['_v2' => true, 'dashboard_view' => true]]);
        $this->putJson("/api/plataforma/especializacoes-suporte/{$normal->id}", ['nome' => 'Hack'], $s)->assertNotFound();
        $this->deleteJson("/api/plataforma/especializacoes-suporte/{$normal->id}", [], $s)->assertNotFound();
        $this->assertNotNull(PerfilUtilizador::query()->find($normal->id));
    }

    public function test_nao_elimina_especializacao_em_uso(): void
    {
        $id = app(ServicoEspecializacoesSuporte::class)->criar(['nome' => 'Em uso', 'grupos' => ['geral']])->id;
        $this->criarUtilizador(['papel' => Utilizador::PAPEL_AGENTE_SUPORTE, 'perfil_utilizador_id' => $id]);
        $s = $this->superAdmin();

        $this->deleteJson("/api/plataforma/especializacoes-suporte/{$id}", [], $s)->assertUnprocessable()->assertJsonPath('codigo', 'ESPECIALIZACAO_EM_USO');
        $this->assertNotNull(PerfilUtilizador::query()->find($id));
    }

    public function test_agente_sem_permissao_nao_gere_especializacoes(): void
    {
        $agente = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_AGENTE_SUPORTE]));
        config(['erp.saas.ativo' => true]);
        $this->getJson('/api/plataforma/especializacoes-suporte', $agente)->assertForbidden();
        $this->postJson('/api/plataforma/especializacoes-suporte', ['nome' => 'Escalada', 'grupos' => ['geral']], $agente)->assertForbidden();
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function especializacoes(): array
    {
        return [
            'geral' => ['Agente de Suporte Geral', ['GERAL', 'MELHORIA', 'DUVIDA', 'ADMINISTRATIVA', 'OUTROS']],
            'tecnico' => ['Agente de Suporte Técnico', ['INCIDENTE_TECNICO', 'ERRO_FUNCIONAL', 'AUTENTICACAO', 'PERMISSOES', 'CONFIGURACAO', 'DESEMPENHO', 'INTEGRACAO', 'SEGURANCA']],
            'financeiro' => ['Agente de Suporte Financeiro', ['PLANO', 'FACTURACAO', 'PAGAMENTO', 'SUBSCRICAO']],
            'marketing' => ['Agente de Suporte Marketing e Comunicação', ['MARKETING']],
            'todas' => ['Agente de Suporte (todas as categorias)', TicketSuporte::CATEGORIAS],
        ];
    }

    #[DataProvider('especializacoes')]
    public function test_agente_com_cada_especializacao_base_ve_exactamente_as_suas_categorias(string $nome, array $esperadas): void
    {
        app(ServicoEspecializacoesSuporte::class)->garantirBase();
        $perfil = PerfilUtilizador::query()->where('nome', $nome)->firstOrFail();
        $s = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_AGENTE_SUPORTE, 'perfil_utilizador_id' => $perfil->id]));
        config(['erp.saas.ativo' => true]);
        foreach (TicketSuporte::CATEGORIAS as $c) {
            $this->ticket($c);
        }

        $vistas = collect($this->getJson('/api/suporte/tickets', $s)->assertOk()->json('dados'))->pluck('categoria')->all();
        $this->assertEqualsCanonicalizing($esperadas, $vistas);

        $eu = $this->getJson('/api/suporte/eu', $s)->assertOk()->json('dados');
        $this->assertEqualsCanonicalizing($esperadas, $eu['categorias']);
        $this->assertSame($nome, $eu['especializacao']);

        // as métricas do dashboard seguem a mesma regra
        $m = $this->getJson('/api/suporte/metricas', $s)->assertOk()->json('dados.por_estado');
        $this->assertSame(count($esperadas), $m['ABERTO']);
    }

    public function test_categorias_dos_grupos_cobrem_todas_as_categorias_de_tickets(): void
    {
        $todas = collect(app(ServicoEspecializacoesSuporte::class)->grupos())->flatMap(fn ($g) => $g['categorias'])->all();
        $this->assertEqualsCanonicalizing(TicketSuporte::CATEGORIAS, $todas);   // nenhuma categoria fica sem grupo/fila
    }

    public function test_agente_nao_escala_nem_alcanca_a_plataforma(): void
    {
        app(ServicoEspecializacoesSuporte::class)->garantirBase();
        $perfil = PerfilUtilizador::query()->where('nome', 'Agente de Suporte Marketing e Comunicação')->firstOrFail();
        $s = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_AGENTE_SUPORTE, 'perfil_utilizador_id' => $perfil->id]));
        config(['erp.saas.ativo' => true]);

        $this->getJson('/api/plataforma/clientes', $s)->assertForbidden();
        $this->getJson('/api/plataforma/agentes-suporte', $s)->assertForbidden();
        $this->putJson("/api/plataforma/especializacoes-suporte/{$perfil->id}", ['grupos' => ['geral', 'tecnico']], $s)->assertForbidden();
        $this->assertArrayNotHasKey('suporte_tickets_geral_gerir', $perfil->refresh()->permissoes);
    }
}
