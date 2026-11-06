<?php

namespace Tests\Feature\Sistema;

use App\Exceptions\ManipuladorExcecoesApi;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Produção (06/11): o registo de uma empresa com um NIF já existente noutro cliente (que a RLS esconde da validação
 * normal) rebentava com 500 sem causa: o erro do índice único abortava a transacção e o `finally` de `executarComo`
 * tentava repor o tenant com uma consulta, que falhava com «current transaction is aborted» e escondia o erro verdadeiro.
 */
class TransaccaoAbortadaNifDuplicadoTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::selectOne("SELECT set_config('app.tenant_id', '', false)");

        parent::tearDown();
    }

    public function test_executar_como_deixa_seguir_o_erro_verdadeiro_numa_transaccao_abortada_e_repoe_o_tenant(): void
    {
        config(['erp.saas.ativo' => true]);
        $contexto = app(ContextoTenant::class);
        $contexto->definir(null);

        try {
            DB::transaction(function () use ($contexto) {
                $contexto->executarComo(5, fn () => DB::statement('select * from tabela_que_nao_existe_xyz'));
            });
            $this->fail('devia ter lançado o erro da operação');
        } catch (QueryException $e) {
            $this->assertStringContainsString('tabela_que_nao_existe_xyz', $e->getMessage(), 'o erro verdadeiro, não «transaction is aborted»');
            $this->assertStringNotContainsString('current transaction is aborted', $e->getMessage());
        }

        $this->assertNull($contexto->id(), 'o tenant em memória volta ao que era');
    }

    public function test_o_nif_duplicado_do_indice_global_responde_422_com_mensagem_clara_mesmo_dentro_de_outra_excepcao(): void
    {
        $unica = new UniqueConstraintViolationException('pgsql', 'insert into empresas …', [], new \PDOException('duplicate key value violates unique constraint "uq_empresas_nif_ativas"'));
        // como em produção: o erro verdadeiro chega como «previous» da falha seguinte
        $mascarada = new QueryException('pgsql', "SELECT set_config('app.tenant_id', ?, false)", [], new \PDOException('current transaction is aborted'));
        $encadeada = new \RuntimeException('falha ao repor', 0, $unica);

        foreach ([$unica, $encadeada] as $excepcao) {
            $resposta = ManipuladorExcecoesApi::renderizar($excepcao);
            $this->assertSame(422, $resposta->getStatusCode());
            $this->assertSame('NIF_DUPLICADO', $resposta->getData(true)['codigo']);
        }
        $this->assertSame(500, ManipuladorExcecoesApi::renderizar($mascarada)->getStatusCode(), 'outras falhas continuam 500');
    }

    public function test_outras_violacoes_de_unicidade_nao_sao_confundidas_com_o_nif(): void
    {
        $outra = new UniqueConstraintViolationException('pgsql', 'insert …', [], new \PDOException('duplicate key value violates unique constraint "outra_chave"'));

        $this->assertSame(500, ManipuladorExcecoesApi::renderizar($outra)->getStatusCode());
    }
}
