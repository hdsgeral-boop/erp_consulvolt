<?php

namespace Tests\Feature;

use App\Mail\RecuperacaoPasswordMail;
use App\Models\LogAuditoria;
use App\Models\Utilizador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Recuperação de palavra-passe (ADR-082): a filosofia mantém-se (gerada pelo sistema, troca obrigatória), mas ninguém
 * consegue, só por conhecer o utilizador, trocar-lhe a palavra-passe em série nem sem que ela seja entregue.
 */
final class RecuperacaoPasswordEndurecidaTest extends TestCase
{
    private function pedir(string $identificador)
    {
        return $this->postJson('/api/autenticacao/recuperar-password', ['identificador' => $identificador])->assertOk();
    }

    private function conta(): Utilizador
    {
        return $this->criarUtilizador(['nome_utilizador' => 'titular', 'email' => 'titular@exemplo.ao', 'nome_completo' => 'Titular']);
    }

    #[Test]
    public function a_resposta_e_identica_exista_a_conta_ou_nao_e_nao_revela_nada(): void
    {
        Mail::fake();
        $this->conta();
        $existente = $this->pedir('titular');
        $inexistente = $this->pedir('ninguem.assim');
        $this->assertSame($existente->json('dados'), $inexistente->json('dados'));
        $this->assertSame($existente->json('mensagem'), $inexistente->json('mensagem'));
        $this->assertSame($existente->getStatusCode(), $inexistente->getStatusCode());
    }

    #[Test]
    public function o_tempo_de_resposta_tem_um_minimo_igual_para_conta_existente_e_inexistente(): void
    {
        Mail::fake();
        config(['erp.sessao.recuperacao_tempo_minimo_ms' => 350]);
        $this->conta();
        foreach (['titular', 'ninguem.assim'] as $id) {
            $t = hrtime(true);
            $this->pedir($id);
            $this->assertGreaterThanOrEqual(340, (hrtime(true) - $t) / 1e6, "duração mínima para «{$id}»");
        }
    }

    #[Test]
    public function a_palavra_passe_actual_so_deixa_de_valer_depois_de_a_nova_ser_entregue(): void
    {
        $u = $this->conta();
        $sessao = $this->entrar($u);

        // o envio falha: nada muda
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('sendNow')->once()->andThrow(new \RuntimeException('SMTP em baixo'));
        $this->pedir('titular');

        $u->refresh();
        $this->assertTrue(Hash::check('Palavra#Passe2026', $u->palavra_passe), 'a palavra-passe actual continua válida');
        $this->assertFalse((bool) $u->deve_alterar_password);
        $this->getJson('/api/autenticacao/eu', $sessao)->assertOk();   // sessões intactas
    }

    #[Test]
    public function entregue_a_nova_palavra_passe_a_troca_e_obrigatoria_as_sessoes_caem_e_o_titular_sabe_quem_pediu(): void
    {
        Mail::fake();
        $u = $this->conta();
        $sessao = $this->entrar($u);

        $this->withServerVariables(['REMOTE_ADDR' => '41.7.8.9'])->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64) Firefox/130.0')
            ->postJson('/api/autenticacao/recuperar-password', ['identificador' => 'titular@exemplo.ao'])->assertOk();

        Mail::assertSent(RecuperacaoPasswordMail::class, function (RecuperacaoPasswordMail $m) {
            $dados = (new \ReflectionMethod($m, 'dados'))->invoke($m);

            return $m->hasTo('titular@exemplo.ao') && $dados['ip'] === '41.7.8.x' && str_contains((string) $dados['dispositivo'], 'Firefox') && ! empty($dados['quando']);
        });
        $u->refresh();
        $this->assertFalse(Hash::check('Palavra#Passe2026', $u->palavra_passe));
        $this->assertTrue((bool) $u->deve_alterar_password);
        $this->getJson('/api/autenticacao/eu', $sessao)->assertStatus(401);
        $this->assertTrue(LogAuditoria::withoutGlobalScopes()->where('acao', 'Recuperar palavra-passe')->where('utilizador_id', $u->id)->exists());
    }

    #[Test]
    public function o_email_de_recuperacao_mostra_quem_pediu(): void
    {
        $html = (new RecuperacaoPasswordMail('titular', 'Titular', 'Xy7$gerada1234', '41.7.8.x', '19/11/2026 10:00', 'Firefox em Linux'))->render();
        $this->assertStringContainsString('41.7.8.x', $html);
        $this->assertStringContainsString('Firefox em Linux', $html);
        $this->assertStringContainsString('19/11/2026 10:00', $html);
    }

    #[Test]
    public function intervalo_minimo_entre_pedidos_da_mesma_conta_ignora_o_segundo_pedido_e_regista(): void
    {
        Mail::fake();
        $u = $this->conta();
        $this->pedir('titular');
        $primeiro = $u->refresh()->palavra_passe;

        $this->pedir('titular');   // dentro dos 120 s: ignorado, com a mesma resposta
        Mail::assertSent(RecuperacaoPasswordMail::class, 1);
        $this->assertSame($primeiro, $u->refresh()->palavra_passe, 'a palavra-passe não foi trocada outra vez');
        $this->assertTrue(LogAuditoria::withoutGlobalScopes()->where('acao', 'Recuperação de palavra-passe limitada')->exists());

        $this->travel(121)->seconds();
        $this->pedir('titular');
        Mail::assertSent(RecuperacaoPasswordMail::class, 2);
    }

    #[Test]
    public function limite_por_conta_por_hora_mesmo_variando_o_identificador_ou_o_ip(): void
    {
        Mail::fake();
        config(['erp.sessao.recuperacao_max_por_hora' => 3]);
        $this->conta();
        // nome, e-mail e nome de novo: é sempre a mesma CONTA
        foreach (['titular', 'titular@exemplo.ao', 'titular'] as $i => $id) {
            $this->travel(130)->seconds();
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.($i + 1)])->postJson('/api/autenticacao/recuperar-password', ['identificador' => $id])->assertOk();
        }
        Mail::assertSent(RecuperacaoPasswordMail::class, 3);

        $this->travel(130)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])->postJson('/api/autenticacao/recuperar-password', ['identificador' => 'titular'])->assertOk();
        Mail::assertSent(RecuperacaoPasswordMail::class, 3);   // o 4.º pedido da hora não faz nada

        $this->travel(61)->minutes();
        $this->pedir('titular');
        Mail::assertSent(RecuperacaoPasswordMail::class, 4);
    }

    #[Test]
    public function conta_inactiva_ou_sem_email_nao_gera_envio_nem_troca_e_fica_em_auditoria(): void
    {
        Mail::fake();
        $semEmail = $this->criarUtilizador(['nome_utilizador' => 'sem.email', 'email' => null]);
        $inactivo = $this->criarUtilizador(['nome_utilizador' => 'inactivo', 'email' => 'i@exemplo.ao', 'ativo' => false]);
        $antes = [DB::table('utilizadores')->where('id', $semEmail->id)->value('palavra_passe'), DB::table('utilizadores')->where('id', $inactivo->id)->value('palavra_passe')];

        $this->pedir('sem.email');
        $this->pedir('inactivo');

        Mail::assertNothingSent();
        $this->assertSame($antes, [DB::table('utilizadores')->where('id', $semEmail->id)->value('palavra_passe'), DB::table('utilizadores')->where('id', $inactivo->id)->value('palavra_passe')]);
        $this->assertSame(2, LogAuditoria::withoutGlobalScopes()->where('acao', 'Recuperação de palavra-passe sem efeito')->count());
    }
}
