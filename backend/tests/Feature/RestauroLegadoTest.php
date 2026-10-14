<?php

namespace Tests\Feature;

use App\Jobs\Sistema\SimularRestauroLegado;
use App\Models\Empresa;
use App\Models\RestauroLegado;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoRestauroLegado;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Restauro da plataforma pelo ecrã: fluxo e salvaguardas (a migração real não corre aqui — substituiria a base de
 * testes; o motor tem os seus próprios testes em MigracaoLegadoTest).
 */
final class RestauroLegadoTest extends TestCase
{
    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->empresa = $this->criarEmpresa();
    }

    protected function tearDown(): void
    {
        foreach (RestauroLegado::query()->whereNotNull('caminho')->get() as $r) {
            Storage::disk('local')->delete($r->caminho);
        }
        parent::tearDown();
    }

    /** @return array{0: Utilizador, 1: array<string, mixed>} */
    private function admin(bool $super = true): array
    {
        $u = $this->criarUtilizador(['papel' => $super ? Utilizador::PAPEL_SUPER_ADMINISTRADOR : Utilizador::PAPEL_UTILIZADOR,
            'perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'all' => true])->id]);
        $u->empresas()->attach($this->empresa->id);

        return [$u, $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id]];
    }

    private function backup(string $formato = 'dexie'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('backup_teste.json', json_encode(['formatName' => $formato, 'formatVersion' => 1, 'data' => ['databaseName' => 'teste', 'data' => []]]));
    }

    #[Test]
    public function so_super_administradores_com_a_palavra_passe_carregam_e_a_simulacao_arranca(): void
    {
        [, $comum] = $this->admin(false);
        $this->post('/api/sistema/restauros-legado', ['ficheiro' => $this->backup(), 'palavra_passe' => 'Palavra#Passe2026'], $comum + ['Accept' => 'application/json'])
            ->assertStatus(403);

        [, $s] = $this->admin();
        $this->post('/api/sistema/restauros-legado', ['ficheiro' => $this->backup(), 'palavra_passe' => 'errada'], $s + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('codigo', 'PALAVRA_PASSE_INCORRECTA');
        $this->post('/api/sistema/restauros-legado', ['ficheiro' => $this->backup('outro'), 'palavra_passe' => 'Palavra#Passe2026'], $s + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('codigo', 'BACKUP_FORMATO');
        $this->assertSame(0, RestauroLegado::query()->count(), 'ficheiro recusado não deixa pedido');

        $id = $this->post('/api/sistema/restauros-legado', ['ficheiro' => $this->backup(), 'palavra_passe' => 'Palavra#Passe2026'], $s + ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('dados.estado', 'SIMULANDO')->json('dados.id');
        Queue::assertPushed(SimularRestauroLegado::class, fn ($j) => $j->restauroId === $id);
        $this->assertTrue(Storage::disk('local')->exists(RestauroLegado::query()->find($id)->caminho));
        $this->assertTrue(DB::table('logs_auditoria')->where('acao', 'Tentativa falhada')->exists());

        // um de cada vez
        $this->post('/api/sistema/restauros-legado', ['ficheiro' => $this->backup(), 'palavra_passe' => 'Palavra#Passe2026'], $s + ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJsonPath('codigo', 'RESTAURO_ACTIVO');
        $this->getJson('/api/sistema/restauros-legado', $s)->assertOk()->assertJsonPath('dados.pode', true)->assertJsonMissingPath('dados.pedidos.0.caminho');
    }

    #[Test]
    public function aprovacao_por_outro_super_administrador_e_so_com_simulacao_sem_problemas(): void
    {
        [$pedinte, $s1] = $this->admin();
        [, $s2] = $this->admin();
        $id = $this->post('/api/sistema/restauros-legado', ['ficheiro' => $this->backup(), 'palavra_passe' => 'Palavra#Passe2026'], $s1 + ['Accept' => 'application/json'])->json('dados.id');

        $this->postJson("/api/sistema/restauros-legado/{$id}/aprovar", ['palavra_passe' => 'Palavra#Passe2026'], $s2)->assertStatus(422)->assertJsonPath('codigo', 'RESTAURO_ESTADO');

        RestauroLegado::query()->whereKey($id)->update(['estado' => 'SIMULADO', 'simulacao' => json_encode(['sem_problemas' => false, 'divergentes' => ['employees']])]);
        $this->postJson("/api/sistema/restauros-legado/{$id}/aprovar", ['palavra_passe' => 'Palavra#Passe2026'], $s2)->assertStatus(422)->assertJsonPath('codigo', 'RESTAURO_SIMULACAO_COM_PROBLEMAS');

        RestauroLegado::query()->whereKey($id)->update(['simulacao' => json_encode(['sem_problemas' => true])]);
        $this->postJson("/api/sistema/restauros-legado/{$id}/aprovar", ['palavra_passe' => 'Palavra#Passe2026'], $s1)->assertStatus(403)->assertJsonPath('codigo', 'RESTAURO_MESMO_UTILIZADOR');
        $this->postJson("/api/sistema/restauros-legado/{$id}/aprovar", ['palavra_passe' => 'errada'], $s2)->assertStatus(422);
        $this->postJson("/api/sistema/restauros-legado/{$id}/aprovar", ['palavra_passe' => 'Palavra#Passe2026', 'nota' => 'Cópia de 10/10 conferida'], $s2)
            ->assertOk()->assertJsonPath('dados.estado', 'APROVADO');
        $this->assertNotNull(RestauroLegado::query()->find($id)->expira_em);
        $this->assertSame($pedinte->id, RestauroLegado::query()->find($id)->pedido_por['id']);

        // aprovação expirada volta a «simulado»
        RestauroLegado::query()->whereKey($id)->update(['expira_em' => now()->subMinute()]);
        $this->getJson('/api/sistema/restauros-legado', $s1)->assertOk()->assertJsonPath('dados.pedidos.0.estado', 'SIMULADO');
    }

    #[Test]
    public function cancelar_apaga_o_ficheiro_e_a_simulacao_falhada_fica_registada(): void
    {
        [, $s] = $this->admin();
        $id = $this->post('/api/sistema/restauros-legado', ['ficheiro' => $this->backup(), 'palavra_passe' => 'Palavra#Passe2026'], $s + ['Accept' => 'application/json'])->json('dados.id');
        $caminho = RestauroLegado::query()->find($id)->caminho;

        // ficheiro alterado depois de carregado: a simulação falha sem tocar em nada
        Storage::disk('local')->put($caminho, '{"formatName":"dexie","alterado":true}');
        (new SimularRestauroLegado($id))->handle(app(ServicoRestauroLegado::class));
        $r = RestauroLegado::query()->find($id);
        $this->assertSame('SIMULACAO_FALHADA', $r->estado);
        $this->assertStringContainsString('alterado', (string) $r->erro);

        $this->postJson("/api/sistema/restauros-legado/{$id}/cancelar", [], $s)->assertOk()->assertJsonPath('dados.estado', 'CANCELADO');
        $this->assertFalse(Storage::disk('local')->exists($caminho));
        $this->assertNull(RestauroLegado::query()->find($id)->caminho);
    }

    #[Test]
    public function execucao_exige_aprovacao_valida_e_a_confirmacao_escrita(): void
    {
        [, $s1] = $this->admin();
        $id = $this->post('/api/sistema/restauros-legado', ['ficheiro' => $this->backup(), 'palavra_passe' => 'Palavra#Passe2026'], $s1 + ['Accept' => 'application/json'])->json('dados.id');
        $r = $this->postJson("/api/sistema/restauros-legado/{$id}/executar", ['palavra_passe' => 'Palavra#Passe2026', 'confirmacao' => "RESTAURAR #{$id}"], $s1);
        // sem a execução instalada responde 503; com ela, sem aprovação, 422 — em nenhum caso começa
        $this->assertContains($r->status(), [422, 503]);
        $this->assertSame('SIMULANDO', RestauroLegado::query()->find($id)->estado);
    }
}
