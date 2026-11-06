<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Support\POS\IntegridadePOS;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F0 do POS: restrições de integridade (UNIQUE, CHECK, FK) criadas só quando os dados o permitem, auditoria só de leitura
 * (`erp:pos:auditar-integridade`) e idempotência das migrações `2026_12_05_09*`. A base de testes é «limpa», logo as
 * restrições já existem; cada teste retira a restrição, mete dados problemáticos e prova o comportamento.
 */
final class POSIntegridadeTest extends TestCase
{
    private Empresa $empresa;

    private IntegridadePOS $integridade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000077']);
        $this->integridade = new IntegridadePOS(DB::connection());
    }

    private function estado(string $nome): array
    {
        return collect($this->integridade->auditar())->firstWhere('nome', $nome);
    }

    private function sessao(string $codigo, array $extra = []): int
    {
        return (int) DB::table('sessoes_pos')->insertGetId(array_merge(['empresa_id' => $this->empresa->id, 'codigo_sessao' => $codigo, 'estado' => 'FECHADA', 'criado_em' => now(), 'atualizado_em' => now()], $extra));
    }

    #[Test]
    public function numa_base_limpa_todas_as_restricoes_e_indices_existem(): void
    {
        foreach ($this->integridade->auditar() as $r) {
            $this->assertSame('ok', $r['estado'], "{$r['nome']} deveria existir e estar validada");
        }
        foreach ($this->integridade->auditarIndices() as $i) {
            $this->assertSame('ok', $i['estado'], $i['nome']);
        }
    }

    #[Test]
    public function a_unicidade_recusa_codigos_repetidos_na_mesma_empresa_mas_aceita_noutra(): void
    {
        $outra = $this->criarEmpresa(['nif' => '5417000078']);
        $this->sessao('T01-2026-0001', ['numero_z' => 'Z-T01-2026-0001']);
        DB::table('sessoes_pos')->insert(['empresa_id' => $outra->id, 'codigo_sessao' => 'T01-2026-0001', 'numero_z' => 'Z-T01-2026-0001', 'estado' => 'FECHADA', 'criado_em' => now(), 'atualizado_em' => now()]);
        $this->sessao('T01-2026-0002');   // sem Z: NULL não conta como repetido
        $this->sessao('T01-2026-0003');
        $this->expectException(QueryException::class);
        $this->sessao('T01-2026-0001');
    }

    #[Test]
    public function com_duplicados_a_restricao_nao_e_criada_nem_falha_e_so_se_cria_depois_de_corrigir(): void
    {
        DB::statement('DROP INDEX uq_sessoes_pos_empresa_id_codigo_sessao');
        $a = $this->sessao('T01-2026-0009');
        $b = $this->sessao('T01-2026-0009');
        $antes = DB::table('sessoes_pos')->count();
        Log::spy();

        $e = $this->estado('uq_sessoes_pos_empresa_id_codigo_sessao');
        $this->assertSame('bloqueada', $e['estado']);
        $this->assertSame([$a, $b], $e['problemas'][0]['ids']);
        $this->assertSame(2, $e['problemas'][0]['linhas']);

        $res = collect($this->integridade->aplicar(['unico']))->firstWhere('nome', 'uq_sessoes_pos_empresa_id_codigo_sessao');
        $this->assertSame('ignorada', $res['accao']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains((string) $m, 'uq_sessoes_pos_empresa_id_codigo_sessao') && str_contains((string) $m, 'NÃO criada'))->once();
        $this->assertSame('bloqueada', $this->estado('uq_sessoes_pos_empresa_id_codigo_sessao')['estado']);
        $this->assertSame($antes, DB::table('sessoes_pos')->count(), 'a migração nunca apaga dados');

        DB::table('sessoes_pos')->where('id', $b)->update(['codigo_sessao' => 'T01-2026-0010']);   // correcção feita pelo operador
        $res = collect($this->integridade->aplicar(['unico']))->firstWhere('nome', 'uq_sessoes_pos_empresa_id_codigo_sessao');
        $this->assertSame('criada', $res['accao']);
        $this->assertSame('ok', $this->estado('uq_sessoes_pos_empresa_id_codigo_sessao')['estado']);
        // idempotente
        $this->assertSame('existente', collect($this->integridade->aplicar(['unico']))->firstWhere('nome', 'uq_sessoes_pos_empresa_id_codigo_sessao')['accao']);
    }

    #[Test]
    public function a_unicidade_do_nome_da_mesa_ignora_maiusculas_e_a_da_configuracao_e_por_empresa(): void
    {
        $t = DB::table('terminais_pos')->insertGetId(['empresa_id' => $this->empresa->id, 'codigo' => 'R01', 'nome' => 'R', 'tipo' => 'RESTAURANTE', 'criado_em' => now(), 'atualizado_em' => now()]);
        DB::table('mesas_pos')->insert(['empresa_id' => $this->empresa->id, 'terminal_pos_id' => $t, 'nome' => 'Mesa 1', 'ordem' => 1, 'ativo' => true, 'criado_em' => now(), 'atualizado_em' => now()]);
        try {
            DB::transaction(fn () => DB::table('mesas_pos')->insert(['empresa_id' => $this->empresa->id, 'terminal_pos_id' => $t, 'nome' => 'MESA 1', 'ordem' => 2, 'ativo' => true, 'criado_em' => now(), 'atualizado_em' => now()]));
            $this->fail('devia recusar o nome repetido com outras maiúsculas');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        DB::table('configuracoes_lavandaria')->insert(['empresa_id' => $this->empresa->id, 'criado_em' => now(), 'atualizado_em' => now()]);
        $this->expectException(QueryException::class);
        DB::table('configuracoes_lavandaria')->insert(['empresa_id' => $this->empresa->id, 'criado_em' => now(), 'atualizado_em' => now()]);
    }

    #[Test]
    public function o_check_recusa_estados_inventados_mas_aceita_nulos_e_os_validos(): void
    {
        $this->sessao('T01-2026-0021', ['estado_contabilizacao' => 'CONTABILIZADA', 'estado_liquidacao' => 'SEM_MOVIMENTO']);
        $this->sessao('T01-2026-0022', ['estado_contabilizacao' => null]);
        $this->expectException(QueryException::class);
        $this->sessao('T01-2026-0023', ['estado' => 'A_MEIO']);
    }

    #[Test]
    public function com_valores_invalidos_o_check_nao_e_criado_e_depois_de_corrigir_cria_e_valida(): void
    {
        DB::statement('ALTER TABLE sessoes_pos DROP CONSTRAINT ck_sessoes_pos_estado');
        $id = $this->sessao('T01-2026-0031', ['estado' => 'OPEN']);
        $this->sessao('T01-2026-0032');
        Log::spy();

        $e = $this->estado('ck_sessoes_pos_estado');
        $this->assertSame('bloqueada', $e['estado']);
        $this->assertSame("estado='OPEN'", $e['problemas'][0]['chave']);
        $this->assertSame([$id], $e['problemas'][0]['ids']);

        $res = collect($this->integridade->aplicar(['check']))->firstWhere('nome', 'ck_sessoes_pos_estado');
        $this->assertSame('ignorada', $res['accao']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains((string) $m, 'ck_sessoes_pos_estado'))->once();
        $this->assertSame(0, DB::table('pg_constraint')->where('conname', 'ck_sessoes_pos_estado')->count());
        $this->assertSame('OPEN', DB::table('sessoes_pos')->where('id', $id)->value('estado'), 'nunca altera dados');

        DB::table('sessoes_pos')->where('id', $id)->update(['estado' => 'FECHADA']);
        $res = collect($this->integridade->aplicar(['check']))->firstWhere('nome', 'ck_sessoes_pos_estado');
        $this->assertSame('criada', $res['accao']);
        $this->assertTrue((bool) DB::table('pg_constraint')->where('conname', 'ck_sessoes_pos_estado')->value('convalidated'));
        $this->assertSame('existente', collect($this->integridade->aplicar(['check']))->firstWhere('nome', 'ck_sessoes_pos_estado')['accao']);
    }

    #[Test]
    public function um_check_que_ficou_not_valid_e_validado_quando_os_dados_o_permitem(): void
    {
        DB::statement('ALTER TABLE sessoes_pos DROP CONSTRAINT ck_sessoes_pos_estado');
        DB::statement("ALTER TABLE sessoes_pos ADD CONSTRAINT ck_sessoes_pos_estado CHECK (estado IS NULL OR estado::text IN ('ABERTA','FECHADA')) NOT VALID");
        $this->assertSame('nao_validada', $this->estado('ck_sessoes_pos_estado')['estado']);
        $res = collect($this->integridade->aplicar(['check']))->firstWhere('nome', 'ck_sessoes_pos_estado');
        $this->assertSame('validada', $res['accao']);
        $this->assertSame('ok', $this->estado('ck_sessoes_pos_estado')['estado']);
    }

    #[Test]
    public function a_chave_estrangeira_do_operador_so_se_cria_sem_orfaos(): void
    {
        DB::statement('ALTER TABLE sessoes_pos DROP CONSTRAINT fk_sessoes_pos_operador_id');
        $id = $this->sessao('T01-2026-0041', ['operador_id' => 987654321]);
        $e = $this->estado('fk_sessoes_pos_operador_id');
        $this->assertSame('bloqueada', $e['estado']);
        $this->assertSame([$id], $e['problemas'][0]['ids']);
        $this->assertSame('ignorada', collect($this->integridade->aplicar(['fk']))->firstWhere('nome', 'fk_sessoes_pos_operador_id')['accao']);

        DB::table('sessoes_pos')->where('id', $id)->update(['operador_id' => null]);
        $this->assertSame('criada', collect($this->integridade->aplicar(['fk']))->firstWhere('nome', 'fk_sessoes_pos_operador_id')['accao']);
        $u = $this->criarUtilizador();
        DB::table('sessoes_pos')->where('id', $id)->update(['operador_id' => $u->id]);
        DB::table('utilizadores')->where('id', $u->id)->delete();
        $this->assertNull(DB::table('sessoes_pos')->where('id', $id)->value('operador_id'), 'SET NULL preserva a sessão');
    }

    #[Test]
    public function as_migracoes_sao_idempotentes(): void
    {
        $antes = $this->integridade->auditar();
        foreach (['2026_12_05_090000_pos_integridade_unicidade', '2026_12_05_090100_pos_integridade_estados_check', '2026_12_05_090200_pos_integridade_chaves_estrangeiras'] as $m) {
            $migracao = require base_path("database/migrations/{$m}.php");
            $migracao->up();
            $migracao->up();
        }
        $this->assertEquals($antes, $this->integridade->auditar());
        $this->assertSame([], array_filter($this->integridade->auditar(), fn ($r) => $r['estado'] !== 'ok'));
    }

    #[Test]
    public function a_migracao_recria_o_que_falta_e_nao_falha_com_dados_problematicos(): void
    {
        DB::statement('DROP INDEX uq_pedidos_lavandaria_empresa_id_numero_encomenda');
        DB::statement('ALTER TABLE estadias_hotel DROP CONSTRAINT ck_estadias_hotel_modo');
        DB::table('pedidos_lavandaria')->insert([
            ['empresa_id' => $this->empresa->id, 'numero_encomenda' => 'OS/L01/2026/00001', 'criado_em' => now(), 'atualizado_em' => now()],
            ['empresa_id' => $this->empresa->id, 'numero_encomenda' => 'OS/L01/2026/00001', 'criado_em' => now(), 'atualizado_em' => now()],
        ]);
        DB::table('estadias_hotel')->insert(['empresa_id' => $this->empresa->id, 'estado' => 'ABERTA', 'modo' => 'NOITE', 'criado_em' => now(), 'atualizado_em' => now()]);

        foreach (['2026_12_05_090000_pos_integridade_unicidade', '2026_12_05_090100_pos_integridade_estados_check'] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();   // não pode lançar
        }
        $this->assertSame('bloqueada', $this->estado('uq_pedidos_lavandaria_empresa_id_numero_encomenda')['estado']);
        $this->assertSame('bloqueada', $this->estado('ck_estadias_hotel_modo')['estado']);
        $this->assertSame(2, DB::table('pedidos_lavandaria')->count());
        $this->assertSame('ok', $this->estado('uq_sessoes_pos_empresa_id_numero_z')['estado'], 'as restantes restrições não são afectadas');
    }

    #[Test]
    public function o_comando_de_auditoria_e_so_de_leitura_e_indica_o_que_corrigir(): void
    {
        $this->artisan('erp:pos:auditar-integridade')->expectsOutputToContain('Nada a corrigir')->assertExitCode(0);

        DB::statement('DROP INDEX uq_sessoes_pos_empresa_id_numero_z');
        $a = $this->sessao('T01-2026-0051', ['numero_z' => 'Z-T01-2026-0001']);
        $b = $this->sessao('T01-2026-0052', ['numero_z' => 'Z-T01-2026-0001']);
        $antes = DB::table('sessoes_pos')->get()->toArray();

        $this->artisan('erp:pos:auditar-integridade')
            ->expectsOutputToContain("empresa_id={$this->empresa->id} | numero_z=Z-T01-2026-0001: 2 linha(s); ids {$a},{$b}")
            ->assertExitCode(1);

        Artisan::call('erp:pos:auditar-integridade', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);
        $this->assertSame(1, $json['bloqueadas']);
        $r = collect($json['restricoes'])->firstWhere('nome', 'uq_sessoes_pos_empresa_id_numero_z');
        $this->assertSame('bloqueada', $r['estado']);
        $this->assertSame([$a, $b], $r['problemas'][0]['ids']);
        $this->assertEquals($antes, DB::table('sessoes_pos')->get()->toArray(), 'só leitura');
        $this->assertSame(0, DB::table('pg_indexes')->where('indexname', 'uq_sessoes_pos_empresa_id_numero_z')->count(), 'a auditoria não cria nada');
    }
}
