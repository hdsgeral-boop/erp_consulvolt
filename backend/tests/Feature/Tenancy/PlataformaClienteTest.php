<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\Tenant;
use App\Models\TicketSuporte;
use App\Models\Utilizador;
use App\Services\Plataforma\ServicoClientes;
use App\Services\Plataforma\ServicoLimitesPlano;
use App\Support\Tenancy\ContextoTenant;
use Tests\TestCase;

/** Fase 5 (cliente): planos com versões e limites, "Minha conta", conversa de suporte e manual de códigos (ADR-076). */
class PlataformaClienteTest extends TestCase
{
    private function admin(): array
    {
        return $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));
    }

    private function plano(array $extra = []): PlanoLicenca
    {
        return PlanoLicenca::query()->create($extra + ['codigo' => 'p'.random_int(1000, 99999), 'nome' => 'Plano', 'preco_mensal' => 1000, 'moeda' => 'AOA', 'ativo' => true]);
    }

    /** @return array{0: Tenant, 1: Utilizador, 2: array<string, string>} */
    private function cliente(string $slug): array
    {
        $c = app(ServicoClientes::class)->criar([
            'nome' => $slug, 'slug' => $slug,
            'administrador' => ['nome_utilizador' => 'adm_'.$slug, 'palavra_passe' => 'SenhaForte123'],
        ]);

        return [$c['cliente'], $c['administrador'], $this->entrar($c['administrador'], 'SenhaForte123')];
    }

    public function test_versao_publicada_e_imutavel_e_copia_limites_para_o_plano(): void
    {
        $sessao = $this->admin();
        $plano = $this->plano();
        config(['erp.saas.ativo' => true]);

        $v = $this->postJson("/api/plataforma/planos/{$plano->id}/versoes", [], $sessao)->assertCreated()->assertJsonPath('dados.numero', 1)->json('dados.id');
        $this->putJson("/api/plataforma/versoes/{$v}", ['preco_mensal' => 5000, 'max_empresas' => 2, 'max_utilizadores' => 5, 'modulos' => ['vendas', 'stock']], $sessao)->assertOk();
        $this->putJson("/api/plataforma/versoes/{$v}", ['modulos' => ['inexistente']], $sessao)->assertStatus(422);

        $this->postJson("/api/plataforma/versoes/{$v}/publicar", [], $sessao)->assertOk()->assertJsonPath('dados.estado', 'PUBLICADA');
        $p = $plano->fresh();
        $this->assertSame(2, (int) $p->max_empresas);
        $this->assertSame(5, (int) $p->max_utilizadores);
        $this->assertEquals(5000, (float) $p->preco_mensal);

        $this->putJson("/api/plataforma/versoes/{$v}", ['preco_mensal' => 1], $sessao)->assertStatus(422)->assertJsonPath('codigo', 'VERSAO_PUBLICADA');
        $this->postJson("/api/plataforma/versoes/{$v}/publicar", [], $sessao)->assertStatus(422)->assertJsonPath('codigo', 'VERSAO_NAO_RASCUNHO');

        $v2 = $this->postJson("/api/plataforma/planos/{$plano->id}/versoes", [], $sessao)->assertCreated()->assertJsonPath('dados.numero', 2)->assertJsonPath('dados.max_empresas', 2)->json('dados.id');
        $this->postJson("/api/plataforma/versoes/{$v2}/publicar", [], $sessao)->assertOk();
        $this->getJson('/api/plataforma/planos', $sessao)->assertOk()->assertJsonPath('dados.planos.0.versoes.1.estado', 'DESCONTINUADA');
    }

    public function test_limites_do_plano_aplicam_se_a_empresas_e_utilizadores_independentemente_do_saas_global(): void
    {
        $plano = $this->plano(['max_empresas' => 2, 'max_utilizadores' => 3]);
        [$cliente] = $this->cliente('limitado');
        $cliente->update(['plano' => $plano->codigo]);
        $limites = app(ServicoLimitesPlano::class);

        // Ainda em demonstração (sem nenhuma empresa paga): o limite é sempre 1/1, mesmo o plano permitindo mais, e
        // não depende do interruptor global do SaaS (erp.saas.ativo continua desligado neste teste).
        try {
            $limites->exigirLimiteUtilizadores((int) $cliente->id);
            $this->fail('O administrador já ocupa o único lugar do período de demonstração.');
        } catch (ErroNegocio $e) {
            $this->assertSame('LIMITE_PLANO_UTILIZADORES', $e->codigo);
        }
        $limites->exigirLimiteEmpresas((int) $cliente->id); // 0 empresas de 1: ainda passa

        // Depois de uma empresa com licença paga, os limites do plano (não mais 1/1) passam a valer.
        app(ContextoTenant::class)->executarComo((int) $cliente->id, fn () => Empresa::query()->create(['nome' => 'Empresa Paga', 'nif' => '9988776655',
            'moeda_funcional' => 'AOA', 'licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_inicio' => now()->toDateString(), 'licenca_fim' => now()->addMonth()->toDateString()]));
        $uso = $limites->uso($cliente->fresh());
        $this->assertFalse($uso['em_demonstracao']);
        $this->assertSame(2, $uso['empresas']['limite']);
        $this->assertSame(3, $uso['utilizadores']['limite']);

        // Cliente sem plano: sem limites, em nenhum dos dois casos.
        [$livre] = $this->cliente('sem-plano');
        $limites->exigirLimiteUtilizadores((int) $livre->id);
        $limites->exigirLimiteEmpresas((int) $livre->id);
    }

    public function test_plataforma_atribui_plano_e_minha_conta_mostra_o_uso(): void
    {
        $sessao = $this->admin();
        $plano = $this->plano(['max_empresas' => 3, 'max_utilizadores' => 10]);
        [$cliente, , $sessaoCliente] = $this->cliente('minha-conta-a');
        config(['erp.saas.ativo' => true]);

        $this->putJson("/api/plataforma/clientes/{$cliente->id}/plano", ['plano' => 'nao-existe'], $sessao)->assertStatus(422);
        $this->putJson("/api/plataforma/clientes/{$cliente->id}/plano", ['plano' => $plano->codigo], $sessao)->assertOk();

        // Com uma empresa de licença paga, os limites mostrados são os do plano (não o 1/1 do período de demonstração).
        app(ContextoTenant::class)->executarComo((int) $cliente->id, fn () => Empresa::query()->create(['nome' => 'Empresa Paga', 'nif' => '1122334455',
            'moeda_funcional' => 'AOA', 'licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_inicio' => now()->toDateString(), 'licenca_fim' => now()->addMonth()->toDateString()]));

        $this->getJson('/api/minha-conta', $sessaoCliente)->assertOk()
            ->assertJsonPath('dados.cliente.slug', 'minha-conta-a')
            ->assertJsonPath('dados.uso.plano.codigo', $plano->codigo)
            ->assertJsonPath('dados.uso.utilizadores.usados', 1)
            ->assertJsonPath('dados.uso.utilizadores.limite', 10);
        $this->putJson('/api/minha-conta/identidade', ['nome_sistema' => 'Gestão Alfa'], $sessaoCliente)->assertOk();
        $this->assertSame('Gestão Alfa', $cliente->fresh()->nome_sistema);
        $this->getJson('/api/minha-conta/planos', $sessaoCliente)->assertOk();
    }

    public function test_minha_conta_so_para_o_administrador_do_cliente(): void
    {
        $comum = $this->entrar($this->criarUtilizador());
        config(['erp.saas.ativo' => true]);

        foreach (['', '/planos', '/suporte'] as $s) {
            $this->getJson('/api/minha-conta'.$s, $comum)->assertForbidden();
        }
        $this->postJson('/api/minha-conta/suporte', ['assunto' => 'x'], $comum)->assertForbidden();
        $this->getJson('/api/minha-conta')->assertUnauthorized();
    }

    public function test_conversa_de_suporte_isola_clientes_e_reabre_o_ticket(): void
    {
        $plat = $this->admin();
        [$a, , $sa] = $this->cliente('suporte-a');
        [, , $sb] = $this->cliente('suporte-b');
        config(['erp.saas.ativo' => true]);

        $ticket = $this->postJson('/api/minha-conta/suporte', ['assunto' => 'Ajuda com faturas', 'descricao' => 'Não imprime'], $sa)->assertCreated()->json('dados.id');
        $this->assertSame($a->id, (int) TicketSuporte::query()->findOrFail($ticket)->tenant_id);

        // Outro cliente não vê nem lê nem responde ao ticket (404, sem revelar que existe).
        $this->getJson('/api/minha-conta/suporte', $sb)->assertOk()->assertJsonCount(0, 'dados');
        $this->getJson("/api/minha-conta/suporte/{$ticket}/mensagens", $sb)->assertNotFound();
        $this->postJson("/api/minha-conta/suporte/{$ticket}/mensagens", ['mensagem' => 'intruso'], $sb)->assertNotFound();

        $this->postJson("/api/plataforma/suporte/{$ticket}/mensagens", ['mensagem' => 'Já vemos isto.'], $plat)->assertCreated();
        $this->assertSame('EM_CURSO', TicketSuporte::query()->findOrFail($ticket)->estado);

        TicketSuporte::query()->whereKey($ticket)->update(['estado' => 'RESOLVIDO']);
        $this->postJson("/api/minha-conta/suporte/{$ticket}/mensagens", ['mensagem' => 'Continua a falhar.'], $sa)->assertCreated();
        $this->assertSame('ABERTO', TicketSuporte::query()->findOrFail($ticket)->estado);

        $this->getJson("/api/minha-conta/suporte/{$ticket}/mensagens", $sa)->assertOk()->assertJsonCount(2, 'dados')
            ->assertJsonPath('dados.0.autor_tipo', 'PLATAFORMA')->assertJsonPath('dados.1.autor_tipo', 'CLIENTE');
        $this->getJson("/api/plataforma/suporte/{$ticket}/mensagens", $plat)->assertOk()->assertJsonCount(2, 'dados');
        $this->postJson("/api/minha-conta/suporte/{$ticket}/mensagens", ['mensagem' => ''], $sa)->assertStatus(422);
    }

    public function test_manual_de_codigos_pesquisa_e_so_a_plataforma(): void
    {
        $plat = $this->admin();
        $comum = $this->entrar($this->criarUtilizador());
        config(['erp.saas.ativo' => true]);

        $r = $this->getJson('/api/plataforma/codigos?q=UTILIZADOR_DUPLICADO', $plat)->assertOk();
        $this->assertNotEmpty($r->json('dados'));
        $this->getJson('/api/plataforma/codigos', $comum)->assertForbidden();
    }
}