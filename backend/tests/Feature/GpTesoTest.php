<?php

namespace Tests\Feature;

use App\Models\CorrespondenciaReconciliacao;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\LinhaExtratoBancario;
use App\Models\PlanoConta;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * gp_teso: campos novos da API para as últimas diferenças de ecrã da Tesouraria —
 *   - histórico de reconciliações com Período (datas mínima/máxima das linhas) e Itens (correspondências), como o legado;
 *   - lista de sessões de caixa com o número de movimentos (acções na linha: eliminar só sem movimentos).
 */
final class GpTesoTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa();
        $this->planoBase($this->empresa);
        $this->s = $this->sessao($this->empresa, ['teso_gestao_pagamentos_view', 'teso_doc_emitir', 'teso_integrar', 'teso_gestao_conciliacao_view', 'teso_conc_importar',
            'teso_conc_confirmar', 'teso_conc_anular', 'teso_folha_caixa_view', 'teso_caixa_operar', 'teso_caixa_eliminar']);
    }

    private function planoBase(Empresa $e): void
    {
        app(ContextoEmpresa::class)->executarComo($e->id, function () {
            foreach (['4311' => 'Banco BAI', '4511' => 'Caixa sede', '4512' => 'Caixa loja', '611' => 'Vendas', '752' => 'Serviços'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
        });
    }

    private function sessao(Empresa $e, array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($e->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $e->id];
    }

    /** Recebimento integrado na 4311 e a linha do extracto correspondente (com outra data); devolve [lançamento, linha]. */
    private function recebimentoEExtrato(array $s, Empresa $e, string $dataExtrato): array
    {
        $doc = $this->postJson('/api/tesouraria/documentos', ['tipo' => 'RECEBIMENTO', 'data_documento' => $this->hoje, 'conta_financeira' => '4311',
            'descricao' => 'Recebimento', 'linhas' => [['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => '1140']]], $s)->assertCreated()->json('dados.id');
        $this->postJson("/api/tesouraria/documentos/{$doc}/integrar", [], $s)->assertOk();
        $lan = $this->getJson("/api/tesouraria/documentos/{$doc}", $s)->json('dados.numero_lan_contabilizacao');
        $csv = 'Data;Referencia;Descrição;Debito;Credito'."\n".date('d/m/Y', strtotime($dataExtrato)).";TRF1;Recebimento;;1.140,00\n";
        $this->post('/api/tesouraria/extrato/importar', ['codigo_conta' => '4311', 'ficheiro' => UploadedFile::fake()->createWithContent('extrato.csv', $csv)],
            $s + ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('dados.importadas', 1);

        return app(ContextoEmpresa::class)->executarComo($e->id, fn () => [
            LancamentoContabil::query()->where('numero_lan', $lan)->where('codigo_conta', '4311')->value('id'),
            LinhaExtratoBancario::query()->where('codigo_conta', '4311')->value('id'),
        ]);
    }

    #[Test]
    public function historico_devolve_periodo_e_itens_tambem_nas_anuladas_e_isolado_por_empresa(): void
    {
        $antes = now()->subDays(5)->toDateString();
        [$lan, $linha] = $this->recebimentoEExtrato($this->s, $this->empresa, $antes);
        $codigo = $this->postJson('/api/tesouraria/reconciliacao', ['codigo_conta' => '4311', 'grupos' => [['extrato' => [$linha], 'lancamentos' => [$lan]]]], $this->s)
            ->assertCreated()->json('dados.reconciliacao_codigo');

        // outra empresa com o MESMO código (a numeração é por empresa) e linhas antigas: não pode contar nem mexer no período
        $outra = $this->criarEmpresa();
        $this->planoBase($outra);
        app(ContextoEmpresa::class)->executarComo($outra->id, function () use ($codigo) {
            $l = LinhaExtratoBancario::create(['codigo_conta' => '4311', 'data' => '2020-01-01', 'valor' => '1.00', 'tipo_dc' => 'C', 'estado' => 'CONCILIADO', 'reconciliacao_codigo' => $codigo]);
            foreach ([1, 2, 3] as $_) {
                CorrespondenciaReconciliacao::create(['reconciliacao_codigo' => $codigo, 'linha_extrato_bancario_id' => $l->id, 'tipo_correspondencia' => 'MANUAL', 'valor' => '1.00', 'data' => now()]);
            }
        });

        $item = fn () => collect($this->getJson('/api/tesouraria/reconciliacao/historico?codigo_conta=4311', $this->s)->assertOk()->json('dados'))
            ->firstWhere('reconciliacao_codigo', $codigo);
        $this->assertSame(['periodo_inicio' => $antes, 'periodo_fim' => $this->hoje, 'itens' => 1],
            array_intersect_key($item(), array_flip(['periodo_inicio', 'periodo_fim', 'itens'])));

        // anulada: as correspondências ficam, por isso o período e os itens continuam a sair
        $this->postJson("/api/tesouraria/reconciliacao/{$codigo}/anular", ['motivo' => 'Correspondência errada'], $this->s)->assertOk();
        $this->assertSame(['ANULADA', $antes, $this->hoje, 1], [$item()['estado'], $item()['periodo_inicio'], $item()['periodo_fim'], $item()['itens']]);
    }

    #[Test]
    public function historico_sem_reconciliacoes_e_sem_permissao(): void
    {
        $this->getJson('/api/tesouraria/reconciliacao/historico', $this->s)->assertOk()->assertJsonCount(0, 'dados');
        $this->getJson('/api/tesouraria/reconciliacao/historico', $this->sessao($this->empresa, ['teso_folha_caixa_view']))->assertForbidden();
    }

    #[Test]
    public function lista_de_sessoes_devolve_o_numero_de_movimentos(): void
    {
        $com = $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4511', 'data' => $this->hoje], $this->s)->assertCreated()->json('dados.id');
        $sem = $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4512', 'data' => $this->hoje], $this->s)->assertCreated()->json('dados.id');
        foreach ([100, 50] as $v) {
            $this->postJson("/api/tesouraria/caixa/sessoes/{$com}/movimentos", ['tipo' => 'REC', 'conta_contrapartida' => '611', 'valor' => $v,
                'data_documento' => $this->hoje, 'descricao' => 'Venda a dinheiro'], $this->s)->assertCreated();
        }
        $lista = collect($this->getJson('/api/tesouraria/caixa/sessoes', $this->s)->assertOk()->json('dados'))->keyBy('id');
        $this->assertSame(2, $lista[$com]['n_movimentos']);
        $this->assertSame('150.00', $lista[$com]['total_entradas']);
        $this->assertSame(0, $lista[$sem]['n_movimentos']);

        // a regra da linha é a do servidor: com movimentos não elimina, sem movimentos elimina
        $this->deleteJson("/api/tesouraria/caixa/sessoes/{$com}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'SESSAO_NAO_ELIMINAVEL');
        $this->deleteJson("/api/tesouraria/caixa/sessoes/{$sem}", [], $this->s)->assertOk();
    }
}
