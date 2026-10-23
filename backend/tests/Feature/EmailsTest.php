<?php

namespace Tests\Feature;

use App\Mail\AdministradorPlataformaCriadoMail;
use App\Mail\BoasVindasEmpresaMail;
use App\Mail\ContaSuspensaMail;
use App\Mail\LicencaAVencerMail;
use App\Mail\LicencaExpiradaMail;
use App\Mail\NovoUtilizadorMail;
use App\Mail\PalavraPasseRedefinidaMail;
use App\Mail\RecuperacaoPasswordMail;
use App\Mail\TicketCriadoMail;
use App\Mail\TicketRespostaMail;
use App\Models\Empresa;
use App\Models\Utilizador;
use App\Services\Plataforma\ServicoClientes;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Os emails do sistema (app/Mail, resources/views/emails) despoletam nos pontos reais que precisam de avisar um
 * utilizador: Mail::fake() confirma que são enviados, ao destinatário certo, sem precisar de um servidor SMTP.
 */
final class EmailsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Mail::fake();
    }

    public function test_registo_de_empresa_envia_boas_vindas(): void
    {
        $this->postJson('/api/autenticacao/registar-empresa', [
            'nome_comercial' => 'Padaria Central, Lda', 'nif' => '5418291099', 'telefone' => '923000111',
            'provincia' => 'Luanda', 'nome_completo' => 'Joana Neto', 'nome_utilizador' => 'joana.neto',
            'email' => 'joana@padaria.co.ao', 'palavra_passe' => 'Segredo@2026!Forte',
        ])->assertCreated();

        Mail::assertQueued(BoasVindasEmpresaMail::class, fn ($m) => $m->hasTo('joana@padaria.co.ao'));
    }

    public function test_recuperacao_de_password_so_envia_se_o_utilizador_existir_e_tiver_email(): void
    {
        $this->criarUtilizador(['nome_utilizador' => 'com.email', 'email' => 'com.email@teste.ao']);
        $this->criarUtilizador(['nome_utilizador' => 'sem.email', 'email' => null]);

        $this->postJson('/api/autenticacao/recuperar-password', ['identificador' => 'com.email'])->assertOk();
        Mail::assertQueued(RecuperacaoPasswordMail::class, fn ($m) => $m->hasTo('com.email@teste.ao'));

        Mail::fake();
        $this->postJson('/api/autenticacao/recuperar-password', ['identificador' => 'sem.email'])->assertOk();
        Mail::assertNotQueued(RecuperacaoPasswordMail::class);

        Mail::fake();
        $this->postJson('/api/autenticacao/recuperar-password', ['identificador' => 'nao.existe.ninguem'])->assertOk();
        Mail::assertNotQueued(RecuperacaoPasswordMail::class);
    }

    public function test_criar_utilizador_com_email_envia_credenciais_e_sem_email_nao_envia(): void
    {
        $perfil = $this->criarPerfil(['all' => true, '_v2' => true]);
        $admin = $this->criarUtilizador(['papel' => Utilizador::PAPEL_ADMINISTRADOR, 'perfil_utilizador_id' => $perfil->id, 'acesso_todas_empresas' => true]);
        $empresa = $this->criarEmpresa();
        $sessao = $this->entrar($admin) + ['X-Empresa-Id' => $empresa->id];

        $this->postJson('/api/sistema/utilizadores', [
            'nome_utilizador' => 'novo.com.email', 'palavra_passe' => 'Segredo@2026!Forte', 'perfil_utilizador_id' => $perfil->id, 'email' => 'novo@empresa.co.ao',
        ], $sessao)->assertCreated();
        Mail::assertQueued(NovoUtilizadorMail::class, fn ($m) => $m->hasTo('novo@empresa.co.ao'));

        Mail::fake();
        $this->postJson('/api/sistema/utilizadores', [
            'nome_utilizador' => 'novo.sem.email', 'palavra_passe' => 'Segredo@2026!Forte', 'perfil_utilizador_id' => $perfil->id,
        ], $sessao)->assertCreated();
        Mail::assertNotQueued(NovoUtilizadorMail::class);
    }

    public function test_repor_password_envia_aviso_de_seguranca(): void
    {
        $perfil = $this->criarPerfil(['all' => true, '_v2' => true]);
        $admin = $this->criarUtilizador(['papel' => Utilizador::PAPEL_ADMINISTRADOR, 'perfil_utilizador_id' => $perfil->id, 'acesso_todas_empresas' => true]);
        $empresa = $this->criarEmpresa();
        $sessao = $this->entrar($admin) + ['X-Empresa-Id' => $empresa->id];
        $alvo = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id, 'email' => 'alvo@empresa.co.ao']);

        $this->postJson("/api/sistema/utilizadores/{$alvo->id}/palavra-passe", [
            'palavra_passe' => 'NovaSegura@2026!', 'palavra_passe_confirmation' => 'NovaSegura@2026!',
        ], $sessao)->assertOk();

        Mail::assertQueued(PalavraPasseRedefinidaMail::class, fn ($m) => $m->hasTo('alvo@empresa.co.ao'));
    }

    public function test_criar_administrador_da_plataforma_com_email_envia_credenciais(): void
    {
        $plat = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));

        $this->postJson('/api/plataforma/administradores', [
            'nome_utilizador' => 'novo.admin.plat', 'palavra_passe' => 'SegredoPlataforma@2026', 'email' => 'admin@consulvolt.ao',
        ], $plat)->assertCreated();

        Mail::assertQueued(AdministradorPlataformaCriadoMail::class, fn ($m) => $m->hasTo('admin@consulvolt.ao'));
    }

    public function test_criar_ticket_avisa_o_suporte_e_resposta_da_plataforma_avisa_o_cliente(): void
    {
        \Illuminate\Support\Facades\DB::table('definicoes_plataforma')->updateOrInsert(['chave' => 'email_suporte'], ['valor' => 'suporte@consulvolt.ao', 'atualizado_em' => now()]);

        $plat = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));
        $cliente = app(ServicoClientes::class)->criar([
            'nome' => 'ticket-cliente', 'slug' => 'ticket-cliente',
            'administrador' => ['nome_utilizador' => 'adm_ticket', 'nome_completo' => 'Admin Ticket', 'palavra_passe' => 'SenhaForte123'],
        ]);
        $cliente['administrador']->forceFill(['email' => 'admin@ticketcliente.ao'])->save();
        $sessaoCliente = $this->entrar($cliente['administrador'], 'SenhaForte123');
        config(['erp.saas.ativo' => true]);

        $ticket = $this->postJson('/api/minha-conta/suporte', ['assunto' => 'Preciso de ajuda', 'descricao' => 'Não consigo emitir facturas'], $sessaoCliente)
            ->assertCreated()->json('dados.id');
        Mail::assertQueued(TicketCriadoMail::class, fn ($m) => $m->hasTo('suporte@consulvolt.ao'));

        $this->postJson("/api/plataforma/suporte/{$ticket}/mensagens", ['mensagem' => 'Já estamos a ver.'], $plat)->assertCreated();
        Mail::assertQueued(TicketRespostaMail::class, fn ($m) => $m->hasTo('admin@ticketcliente.ao'));
    }

    public function test_suspender_cliente_avisa_o_administrador(): void
    {
        $plat = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));
        $cliente = app(ServicoClientes::class)->criar([
            'nome' => 'cliente-suspenso', 'slug' => 'cliente-suspenso',
            'administrador' => ['nome_utilizador' => 'adm_suspenso', 'nome_completo' => 'Admin Suspenso', 'palavra_passe' => 'SenhaForte123'],
        ]);
        $cliente['administrador']->forceFill(['email' => 'admin@clientesuspenso.ao'])->save();
        config(['erp.saas.ativo' => true]);

        $this->putJson("/api/plataforma/clientes/{$cliente['cliente']->id}/estado", ['estado' => 'SUSPENSO'], $plat)->assertOk();

        Mail::assertQueued(ContaSuspensaMail::class, fn ($m) => $m->hasTo('admin@clientesuspenso.ao'));
    }

    public function test_comando_de_avisos_de_licenca_envia_nos_dias_certos(): void
    {
        config(['erp.licencas.ativo' => true]);
        $perfil = $this->criarPerfil(['all' => true, '_v2' => true]);
        $admin = $this->criarUtilizador(['papel' => Utilizador::PAPEL_ADMINISTRADOR, 'perfil_utilizador_id' => $perfil->id, 'email' => 'dono@expira.ao']);
        $empresaAVencer = $this->criarEmpresa(['nome' => 'Empresa A Vencer', 'licenca_origem' => Empresa::LICENCA_TESTE, 'licenca_fim' => now()->addDays(3)->toDateString()]);
        $admin->empresas()->attach($empresaAVencer->id);
        $this->criarEmpresa(['nome' => 'Empresa Longe Da Expiracao', 'licenca_origem' => Empresa::LICENCA_TESTE, 'licenca_fim' => now()->addDays(20)->toDateString()]);

        $this->artisan('erp:licencas:avisar')->assertSuccessful();

        Mail::assertQueued(LicencaAVencerMail::class, 1);
        Mail::assertNotQueued(LicencaExpiradaMail::class);
    }

    /**
     * Mail::fake() nunca chega a renderizar a vista Blade (só captura o Mailable) — isto é o que apanharia um erro
     * de sintaxe Blade ou uma variável em falta em qualquer um dos modelos de email.
     */
    public function test_todos_os_emails_renderizam_sem_erro(): void
    {
        $emails = [
            new BoasVindasEmpresaMail('Ana Silva', 'Empresa Teste, Lda', 30, 'Plano Básico'),
            new RecuperacaoPasswordMail('ana.silva', 'Ana Silva'),
            new NovoUtilizadorMail('Bruno Costa', 'bruno.costa', 'P@lavra123Segura', 'Empresa Teste, Lda'),
            new PalavraPasseRedefinidaMail('Bruno Costa', 'bruno.costa', 'Empresa Teste, Lda'),
            new LicencaAVencerMail('Ana Silva', 'Empresa Teste, Lda', 3, now()->addDays(3)->toDateString()),
            new LicencaExpiradaMail('Ana Silva', 'Empresa Teste, Lda'),
            new TicketCriadoMail(42, 'Empresa Teste, Lda', 'Não emite facturas', 'URGENTE', 'GERAL', 'Descrição detalhada do problema.'),
            new TicketRespostaMail(42, 'Não emite facturas', 'Ana Silva', 'Suporte Consulvolt', 'Já estamos a analisar o caso.', 'http://localhost:8080'),
            new ContaSuspensaMail('Ana Silva', 'Empresa Teste, Lda'),
            new AdministradorPlataformaCriadoMail('Carlos Mendes', 'carlos.mendes', 'OutraP@lavra456'),
        ];

        foreach ($emails as $email) {
            $html = $email->render();
            $this->assertStringContainsString('CONSULVOLT', $html, get_class($email).' não mostra a marca.');
            $this->assertStringNotContainsString('ErrorException', $html, get_class($email).' tem um erro de renderização.');
        }
    }
}
