<?php

namespace Tests\Feature\Outbox;

use App\Mail\NotificacaoRHMail;
use App\Models\NotificacaoSistema;
use App\Models\OutboxEvento;
use App\Models\Utilizador;
use App\Services\Sistema\PublicadorOutbox;
use App\Support\Outbox\Manipuladores\NotificacaoRHManipulador;
use App\Support\Outbox\ManipuladorOutbox;
use App\Support\Outbox\Outbox;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Manipulador de teste: regista cada execução (tipo, chave, empresa activa) e falha se for pedido.
 */
final class ManipuladorDeTeste implements ManipuladorOutbox
{
    /** @var list<array{chave: string, empresa: int|null, carga: array<string, mixed>}> */
    public static array $execucoes = [];

    public static bool $falhar = false;

    public function tipo(): string
    {
        return 'teste.efeito';
    }

    public function manipular(array $carga, OutboxEvento $evento): void
    {
        if (self::$falhar) {
            throw new RuntimeException('falha simulada do manipulador');
        }
        self::$execucoes[] = ['chave' => $evento->chave, 'empresa' => app(ContextoEmpresa::class)->id(), 'carga' => $carga];
    }
}

/**
 * Transactional Outbox (ADR-080): atomicidade com a escrita de negócio, publicação imediata depois do COMMIT, retentativas com
 * recuo, lease entre publicadores, mortos, idempotência do produtor, limpeza, saúde, isolamento por empresa e os manipuladores
 * `notificacao.rh` e `email.enviar`.
 */
final class OutboxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ManipuladorDeTeste::$execucoes = [];
        ManipuladorDeTeste::$falhar = false;
        config(['erp.outbox.manipuladores' => config('erp.outbox.manipuladores') + ['teste.efeito' => ManipuladorDeTeste::class]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function evento(int $id): OutboxEvento
    {
        return OutboxEvento::query()->findOrFail($id);
    }

    #[Test]
    public function rollback_da_transaccao_de_negocio_nao_deixa_nenhum_evento(): void
    {
        $empresa = $this->criarEmpresa();
        try {
            DB::transaction(function () use ($empresa) {
                Outbox::registar('teste.efeito', ['x' => 1], 'k-rollback', $empresa->id);
                throw new RuntimeException('a escrita de negócio falhou');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::table('outbox_eventos')->count());
        $this->assertSame([], ManipuladorDeTeste::$execucoes);   // nem sequer foi publicado
    }

    #[Test]
    public function commit_publica_logo_e_o_efeito_acontece_uma_so_vez(): void
    {
        $empresa = $this->criarEmpresa();
        $id = DB::transaction(fn () => Outbox::registar('teste.efeito', ['x' => 1], 'k-commit', $empresa->id, 'documento', 77));

        $e = $this->evento($id);
        $this->assertSame(OutboxEvento::PUBLICADO, $e->estado);
        $this->assertNotNull($e->publicado_em);
        $this->assertSame(['documento', 77], [$e->agregado_tipo, $e->agregado_id]);
        $this->assertCount(1, ManipuladorDeTeste::$execucoes);

        // o agendador não volta a entregar um evento já publicado
        $this->assertSame(0, app(PublicadorOutbox::class)->publicarLote()['publicados']);
        $this->assertCount(1, ManipuladorDeTeste::$execucoes);
    }

    #[Test]
    public function falha_do_manipulador_fica_pendente_com_recuo_e_a_retentativa_tem_sucesso(): void
    {
        $empresa = $this->criarEmpresa();
        ManipuladorDeTeste::$falhar = true;
        $id = Outbox::registar('teste.efeito', ['x' => 1], 'k-falha', $empresa->id);

        $e = $this->evento($id);
        $this->assertSame(OutboxEvento::PENDENTE, $e->estado);
        $this->assertSame(1, $e->tentativas);
        $this->assertStringContainsString('falha simulada', (string) $e->ultimo_erro);
        $this->assertTrue($e->proxima_tentativa_em->isFuture());
        $this->assertNull($e->reservado_ate);

        // ainda não é devido: o agendador não lhe toca
        $this->assertSame(['publicados' => 0, 'falhados' => 0, 'mortos' => 0], app(PublicadorOutbox::class)->publicarLote());

        ManipuladorDeTeste::$falhar = false;
        Carbon::setTestNow(now()->addMinutes(5));
        $this->assertSame(1, app(PublicadorOutbox::class)->publicarLote()['publicados']);
        $this->assertSame(OutboxEvento::PUBLICADO, $this->evento($id)->estado);
        $this->assertCount(1, ManipuladorDeTeste::$execucoes);
    }

    #[Test]
    public function o_recuo_cresce_com_as_tentativas(): void
    {
        $empresa = $this->criarEmpresa();
        config(['erp.outbox.max_tentativas' => 10]);
        ManipuladorDeTeste::$falhar = true;
        $id = Outbox::registar('teste.efeito', [], 'k-recuo', $empresa->id);
        $esperas = [];
        for ($i = 0; $i < 3; $i++) {
            $e = $this->evento($id);
            $esperas[] = now()->diffInSeconds($e->proxima_tentativa_em, true);
            Carbon::setTestNow($e->proxima_tentativa_em->copy()->addSecond());
            app(PublicadorOutbox::class)->publicarLote();
        }
        // 30 s, 2 min e 10 min com jitter de ±20 %
        $this->assertEqualsWithDelta(30, $esperas[0], 8);
        $this->assertEqualsWithDelta(120, $esperas[1], 30);
        $this->assertEqualsWithDelta(600, $esperas[2], 130);
    }

    #[Test]
    public function dois_publicadores_nao_duplicam_o_lease_protege_o_evento_reclamado(): void
    {
        $empresa = $this->criarEmpresa();
        ManipuladorDeTeste::$falhar = true;
        foreach (['a', 'b', 'c'] as $k) {
            Outbox::registar('teste.efeito', ['k' => $k], "k-conc-{$k}", $empresa->id);
        }
        ManipuladorDeTeste::$falhar = false;
        Carbon::setTestNow(now()->addHour());

        $a = app(PublicadorOutbox::class);
        $b = app(PublicadorOutbox::class);
        $reclamados = $a->reclamar(null, 10);   // o publicador A reserva os três (FOR UPDATE SKIP LOCKED + lease)
        $this->assertCount(3, $reclamados);
        $this->assertNotNull($this->evento($reclamados[0]->id)->reservado_ate);

        // enquanto o lease vale, o publicador B não vê nada
        $this->assertSame(['publicados' => 0, 'falhados' => 0, 'mortos' => 0], $b->publicarLote());
        $this->assertSame([], ManipuladorDeTeste::$execucoes);
        $this->assertSame([], $b->reclamar(null, 10));

        // se A morrer, o lease expira (2 min) e B recolhe-os — uma só vez
        Carbon::setTestNow(now()->addMinutes(3));
        $this->assertSame(3, $b->publicarLote()['publicados']);
        $this->assertSame(0, $a->publicarLote()['publicados']);
        $this->assertCount(3, ManipuladorDeTeste::$execucoes);
    }

    #[Test]
    public function esgotadas_as_tentativas_o_evento_morre_e_os_administradores_sao_avisados(): void
    {
        $empresa = $this->criarEmpresa();
        $admin = $this->criarUtilizador(['papel' => Utilizador::PAPEL_ADMINISTRADOR]);
        $admin->empresas()->attach($empresa->id);
        $comum = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'x' => true])->id]);
        $comum->empresas()->attach($empresa->id);

        config(['erp.outbox.max_tentativas' => 2]);
        ManipuladorDeTeste::$falhar = true;
        $id = Outbox::registar('teste.efeito', [], 'k-morto', $empresa->id);
        $this->assertSame(OutboxEvento::PENDENTE, $this->evento($id)->estado);

        Carbon::setTestNow(now()->addHour());
        $r = app(PublicadorOutbox::class)->publicarLote();
        $this->assertSame(1, $r['mortos']);
        $e = $this->evento($id);
        $this->assertSame([OutboxEvento::MORTO, 2], [$e->estado, $e->tentativas]);
        $this->assertNull($e->reservado_ate);

        $notificacoes = app(ContextoEmpresa::class)->executarComo($empresa->id, fn () => NotificacaoSistema::query()->get());
        $this->assertCount(1, $notificacoes);
        $n = $notificacoes->first();
        $this->assertSame([$admin->id, 'URGENTE', 'sistema'], [$n->utilizador_id, $n->tipo, $n->categoria]);
        $this->assertStringContainsString("#{$id}", $n->mensagem);

        // um evento MORTO não volta a ser publicado
        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame(0, app(PublicadorOutbox::class)->publicarLote()['falhados']);
    }

    #[Test]
    public function tipo_sem_manipulador_vai_logo_para_morto_com_erro_claro(): void
    {
        $id = Outbox::registar('tipo.desconhecido', [], 'k-sem-manipulador');
        $e = $this->evento($id);
        $this->assertSame(OutboxEvento::MORTO, $e->estado);
        $this->assertStringContainsString('Sem manipulador registado', (string) $e->ultimo_erro);
        $this->assertStringContainsString('tipo.desconhecido', (string) $e->ultimo_erro);
    }

    #[Test]
    public function a_mesma_chave_nunca_cria_dois_eventos_mas_empresas_distintas_podem_repetir_a_chave(): void
    {
        $a = $this->criarEmpresa();
        $b = $this->criarEmpresa();
        $primeiro = Outbox::registar('teste.efeito', ['v' => 1], 'k-unica', $a->id);
        $this->assertNotNull($primeiro);
        $this->assertNull(Outbox::registar('teste.efeito', ['v' => 2], 'k-unica', $a->id));
        $this->assertNotNull(Outbox::registar('teste.efeito', ['v' => 3], 'k-unica', $b->id));
        $this->assertNotNull(Outbox::registar('teste.efeito', ['v' => 4], 'k-unica'));   // global
        $this->assertNull(Outbox::registar('teste.efeito', ['v' => 5], 'k-unica'));      // global repetido
        $this->assertSame(3, DB::table('outbox_eventos')->where('chave', 'k-unica')->count());
        $this->assertSame(1, collect(ManipuladorDeTeste::$execucoes)->where('empresa', $a->id)->count());   // o efeito da empresa A só aconteceu uma vez
    }

    #[Test]
    public function limpeza_so_apaga_publicados_antigos(): void
    {
        $empresa = $this->criarEmpresa();
        $linha = fn (string $chave, string $estado, ?string $publicadoEm) => DB::table('outbox_eventos')->insert([
            'empresa_id' => $empresa->id, 'tipo' => 'teste.efeito', 'chave' => $chave, 'carga' => '{}', 'estado' => $estado, 'publicado_em' => $publicadoEm,
            'criado_em' => now()->subDays(40), 'proxima_tentativa_em' => now()->subDays(40)]);
        $linha('publicado-antigo', 'PUBLICADO', now()->subDays(20)->toDateTimeString());
        $linha('publicado-recente', 'PUBLICADO', now()->subDays(2)->toDateTimeString());
        $linha('morto-antigo', 'MORTO', null);
        $linha('pendente-antigo', 'PENDENTE', null);

        $this->assertSame(0, Artisan::call('erp:outbox:limpar', ['--dias' => 14]));
        $this->assertEqualsCanonicalizing(['publicado-recente', 'morto-antigo', 'pendente-antigo'], DB::table('outbox_eventos')->pluck('chave')->all());
    }

    #[Test]
    public function a_saude_mostra_pendentes_e_mortos_em_aviso_sem_passar_a_503(): void
    {
        $empresa = $this->criarEmpresa();
        $this->getJson('/api/saude')->assertOk()->assertJsonPath('dados.informacao.outbox.estado', 'OK')->assertJsonPath('dados.informacao.outbox.pendentes', 0);

        // um pendente com mais de 10 minutos → AVISO
        ManipuladorDeTeste::$falhar = true;
        Outbox::registar('teste.efeito', [], 'k-saude-1', $empresa->id);
        Carbon::setTestNow(now()->addMinutes(11));
        $r = $this->getJson('/api/saude')->assertOk()->assertJsonPath('dados.estado', 'OK');
        $r->assertJsonPath('dados.informacao.outbox.estado', 'AVISO')->assertJsonPath('dados.informacao.outbox.pendentes', 1)->assertJsonPath('dados.informacao.outbox.mortos', 0);
        $this->assertGreaterThan(600, $r->json('dados.informacao.outbox.mais_antigo_segundos'));

        // um morto → AVISO mesmo sem pendentes antigos
        DB::table('outbox_eventos')->delete();
        Outbox::registar('tipo.desconhecido', [], 'k-saude-2');
        $this->getJson('/api/saude')->assertOk()->assertJsonPath('dados.informacao.outbox.estado', 'AVISO')->assertJsonPath('dados.informacao.outbox.mortos', 1);
    }

    #[Test]
    public function os_comandos_de_estado_e_de_reprocessamento_funcionam(): void
    {
        $id = Outbox::registar('tipo.desconhecido', [], 'k-reprocessar');
        $this->assertSame(OutboxEvento::MORTO, $this->evento($id)->estado);
        $this->assertSame(1, Artisan::call('erp:outbox:estado'));   // há mortos → código de saída 1
        $this->assertStringContainsString('Mortos: 1', Artisan::output());

        $this->assertSame(1, Artisan::call('erp:outbox:reprocessar', ['id' => 999999]));   // inexistente
        $this->assertSame(2, Artisan::call('erp:outbox:reprocessar'));                    // sem id nem --mortos
        config(['erp.outbox.manipuladores' => config('erp.outbox.manipuladores') + ['tipo.desconhecido' => ManipuladorDeTeste::class]]);
        $this->assertSame(0, Artisan::call('erp:outbox:reprocessar', ['--mortos' => true]));
        $e = $this->evento($id);
        $this->assertSame([OutboxEvento::PENDENTE, 0], [$e->estado, $e->tentativas]);

        $this->assertSame(0, Artisan::call('erp:outbox:publicar'));
        $this->assertSame(OutboxEvento::PUBLICADO, $this->evento($id)->estado);
        $this->assertSame(0, Artisan::call('erp:outbox:estado'));
    }

    #[Test]
    public function cada_manipulador_corre_no_contexto_da_empresa_do_evento(): void
    {
        $a = $this->criarEmpresa();
        $b = $this->criarEmpresa();
        Outbox::registar('teste.efeito', [], 'k-ctx-a', $a->id);
        Outbox::registar('teste.efeito', [], 'k-ctx-b', $b->id);
        Outbox::registar('teste.efeito', [], 'k-ctx-global');

        $porChave = collect(ManipuladorDeTeste::$execucoes)->pluck('empresa', 'chave')->all();
        $this->assertSame(['k-ctx-a' => $a->id, 'k-ctx-b' => $b->id, 'k-ctx-global' => null], $porChave);
        $this->assertNull(app(ContextoEmpresa::class)->id());   // e o contexto é reposto
    }

    #[Test]
    public function notificacao_rh_cria_uma_so_notificacao_por_destinatario_mesmo_com_reentrega(): void
    {
        $empresa = $this->criarEmpresa();
        $u = $this->criarUtilizador(['email' => 'destino@empresa.ao']);
        $u->empresas()->attach($empresa->id);
        $carga = ['utilizadores' => [$u->id], 'titulo' => 'Aviso', 'mensagem' => 'Mensagem', 'link' => '/m/rh/rh_portal', 'categoria' => 'rh', 'tipo' => 'INFO', 'autor' => 'Sistema', 'unica' => false];

        Mail::fake();
        config(['erp.notificacoes.email' => true]);
        $id = Outbox::registar('notificacao.rh', $carga, 'k-rh', $empresa->id);
        $contar = fn () => app(ContextoEmpresa::class)->executarComo($empresa->id, fn () => NotificacaoSistema::query()->where('titulo', 'Aviso')->count());
        $this->assertSame(1, $contar());
        Mail::assertQueued(NotificacaoRHMail::class, 1);

        // reentrega «pelo menos uma vez»: o mesmo evento outra vez não duplica nada
        app(ContextoEmpresa::class)->executarComo($empresa->id, fn () => app(NotificacaoRHManipulador::class)->manipular($carga, $this->evento($id)));
        $this->assertSame(1, $contar());
        Mail::assertQueued(NotificacaoRHMail::class, 1);
        $this->assertSame("rh:ev{$id}:u{$u->id}", app(ContextoEmpresa::class)->executarComo($empresa->id, fn () => NotificacaoSistema::query()->value('chave_origem')));
    }

    #[Test]
    public function email_enviar_so_aceita_mailables_da_lista_branca(): void
    {
        Mail::fake();
        $id = Outbox::email(NotificacaoRHMail::class, 'alguem@empresa.ao', ['titulo' => 'T', 'mensagem' => 'M', 'url' => 'https://x'], 'k-email-ok');
        $this->assertSame(OutboxEvento::PUBLICADO, $this->evento($id)->estado);
        Mail::assertQueued(NotificacaoRHMail::class, fn ($m) => $m->hasTo('alguem@empresa.ao'));

        $fora = Outbox::email(Mailable::class, 'alguem@empresa.ao', [], 'k-email-fora');
        $e = $this->evento($fora);
        $this->assertSame([OutboxEvento::PENDENTE, 1], [$e->estado, $e->tentativas]);
        $this->assertStringContainsString('lista branca', (string) $e->ultimo_erro);
        Mail::assertQueuedCount(1);
    }
}
