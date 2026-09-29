<?php

namespace Tests\Feature;

use App\Models\LogAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Auditoria automática (paridade com os hooks do Dexie) e tabela particionada por ano. */
final class AuditoriaTest extends TestCase
{
    private function logs(string $tabela): Collection
    {
        return app(ContextoEmpresa::class)->semIsolamento(fn () => LogAuditoria::query()->where('tabela', $tabela)->orderBy('id')->get());
    }

    #[Test]
    public function regista_criacao_alteracao_e_eliminacao_com_valores_anteriores_e_novos(): void
    {
        $empresa = $this->criarEmpresa(['nome' => 'Nome Antigo, Lda']);
        $empresa->update(['nome' => 'Nome Novo, Lda']);
        $empresa->delete();

        $logs = $this->logs('empresas');
        $this->assertSame(['Criou', 'Atualizou', 'Eliminou'], $logs->pluck('acao')->all());
        $this->assertSame($empresa->id, $logs[0]->empresa_id);
        $this->assertSame(['nome' => 'Nome Antigo, Lda'], $logs[1]->dados_anteriores);
        $this->assertSame(['nome' => 'Nome Novo, Lda'], $logs[1]->dados_novos);
        $this->assertSame('Sistema/Empresas', $logs[1]->modulo);
    }

    #[Test]
    public function nunca_grava_segredos_no_log(): void
    {
        $u = $this->criarUtilizador();

        $criacao = $this->logs('utilizadores')->firstWhere('acao', 'Criou');
        $this->assertSame($u->nome_utilizador, $criacao->dados_novos['nome_utilizador']);
        $this->assertArrayNotHasKey('palavra_passe', $criacao->dados_novos);
    }

    #[Test]
    public function registos_de_auditoria_sao_imutaveis(): void
    {
        $this->criarEmpresa();
        $log = $this->logs('empresas')->first();

        $log->detalhes = 'adulterado';
        $this->assertFalse($log->save());
        $this->assertFalse($log->delete());
        $this->assertNotSame('adulterado', DB::table('logs_auditoria')->where('id', $log->id)->value('detalhes'));
    }

    #[Test]
    public function linhas_vao_para_a_particao_do_ano_e_o_comando_cria_particoes_novas(): void
    {
        $contexto = app(ContextoEmpresa::class);
        $contexto->semIsolamento(fn () => LogAuditoria::create(['modulo' => 'Teste', 'acao' => 'X', 'ocorrido_em' => '2026-06-01 10:00:00']));
        $contexto->semIsolamento(fn () => LogAuditoria::create(['modulo' => 'Teste', 'acao' => 'Y', 'ocorrido_em' => '2031-03-01 10:00:00']));

        $this->assertSame(1, DB::table('logs_auditoria_2026')->where('modulo', 'Teste')->count());
        $this->assertSame(1, DB::table('logs_auditoria_padrao')->where('modulo', 'Teste')->count());   // 2031 ainda sem partição

        $this->artisan('erp:auditoria:particoes', ['--desde' => 2031, '--anos' => 2031 - now()->year])->assertSuccessful();

        $this->assertSame(1, DB::table('logs_auditoria_2031')->where('modulo', 'Teste')->count());
        $this->assertSame(0, DB::table('logs_auditoria_padrao')->where('modulo', 'Teste')->count());
        $this->assertSame(2, DB::table('logs_auditoria')->where('modulo', 'Teste')->count());
    }
}
