<?php

namespace Tests\Feature;

use App\Mail\ContaSuspensaMail;
use App\Mail\NovoUtilizadorMail;
use App\Mail\PalavraPasseRedefinidaMail;
use App\Models\Tenant;
use App\Models\Utilizador;
use App\Services\Plataforma\ServicoClientes;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** E-mails de conta (da v6, adaptados): enviados em fila, com o endereço do cliente; só o de conta nova leva a palavra-passe provisória. */
class EmailsContaTest extends TestCase
{
    private const SENHA = 'Segredo#Novo2026';

    /** @return array{0: array<string, string>, 1: int} */
    private function admin(): array
    {
        $e = $this->criarEmpresa();
        $perfil = $this->criarPerfil(['all' => true], 'Total');
        $a = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true, 'perfil_utilizador_id' => $perfil->id]);

        return [$this->entrar($a) + ['X-Empresa-Id' => $e->id], $perfil->id];
    }

    public function test_utilizador_novo_recebe_o_acesso_com_o_sistema_do_cliente_e_a_palavra_passe_provisoria(): void
    {
        Mail::fake();
        [$h, $perfil] = $this->admin();
        $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'ana.nova', 'email' => 'ana@exemplo.test', 'palavra_passe' => self::SENHA,
            'perfil_utilizador_id' => $perfil, 'acesso_todas_empresas' => true], $h)->assertCreated();

        Mail::assertQueued(NovoUtilizadorMail::class, function (NovoUtilizadorMail $m) {
            $html = $m->render();
            $this->assertStringContainsString('ana.nova', $html);
            // a pedido (2026-10-22): a palavra-passe provisória vai no e-mail; o primeiro acesso pede para a alterar
            $this->assertStringContainsString(self::SENHA, $html);
            $this->assertStringContainsString('provisória', $html);
            $this->assertStringContainsString('MEU ERP', $html);

            return $m->hasTo('ana@exemplo.test');
        });
    }

    public function test_sem_email_nao_envia_e_a_reposicao_avisa_o_proprio(): void
    {
        Mail::fake();
        [$h, $perfil] = $this->admin();
        $id = $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'sem.email', 'palavra_passe' => self::SENHA,
            'perfil_utilizador_id' => $perfil, 'acesso_todas_empresas' => true], $h)->assertCreated()->json('dados.id');
        Mail::assertNothingQueued();

        Utilizador::query()->whereKey($id)->update(['email' => 'se@exemplo.test']);
        $this->postJson("/api/sistema/utilizadores/{$id}/palavra-passe", ['palavra_passe' => self::SENHA.'x', 'palavra_passe_confirmation' => self::SENHA.'x'], $h)->assertOk();
        Mail::assertQueued(PalavraPasseRedefinidaMail::class, fn (PalavraPasseRedefinidaMail $m) => $m->hasTo('se@exemplo.test')
            && ! str_contains($m->render(), self::SENHA));
    }

    public function test_suspender_um_cliente_avisa_os_administradores_dele(): void
    {
        Mail::fake();
        $cliente = Tenant::query()->create(['nome' => 'Acme', 'slug' => 'acme', 'estado' => Tenant::ESTADO_ATIVO]);
        $adm = $this->criarUtilizador(['nome_utilizador' => 'adm.acme', 'email' => 'adm@acme.test', 'papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR]);
        $adm->forceFill(['tenant_id' => $cliente->id])->save();

        app(ServicoClientes::class)->definirEstado($cliente, Tenant::ESTADO_SUSPENSO);
        Mail::assertQueued(ContaSuspensaMail::class, fn (ContaSuspensaMail $m) => $m->hasTo('adm@acme.test'));

        // voltar a gravar o mesmo estado não repete o aviso
        app(ServicoClientes::class)->definirEstado($cliente->refresh(), Tenant::ESTADO_SUSPENSO);
        Mail::assertQueuedCount(1);
    }
}
