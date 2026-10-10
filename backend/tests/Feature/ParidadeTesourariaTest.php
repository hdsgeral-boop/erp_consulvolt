<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\LogAuditoria;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paridade visual da Tesouraria (verificacao_visual/RELATORIO.md, secção 7): colunas do legado na lista de documentos
 * (7.1.1/7.4.1), filtros do Histórico, indicadores dos agregadores (7.0.1), meios de pagamento (7.7.1), reconciliação
 * com um extracto de teste (7.6.1) e a reparação SEGURA de integrações sem lançamento (7.4.1). Dados fictícios.
 */
final class ParidadeTesourariaTest extends TestCase
{
    private Empresa $empresa;

    private Terceiro $cliente;

    private array $s;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa();
        $this->emEmpresa(function () {
            foreach (['3111' => 'Clientes', '3452' => 'IVA liquidado', '4311' => 'Banco Ficticio', '4511' => 'Caixa sede', '611' => 'Vendas', '752' => 'Servicos'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->cliente = Terceiro::create(['nome' => 'Cliente Ficticio', 'nif' => '5000000099', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '3111']);
            Produto::create(['codigo' => 'P1', 'nome' => 'Produto', 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'codigo_conta' => '611', 'conta_iva_liquidado' => '3452']);
        });
        $this->s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fat_contabilizar', 'teso_gestao_pagamentos_view', 'teso_doc_emitir',
            'teso_doc_eliminar', 'teso_integrar', 'teso_desintegrar', 'teso_contab_historico_view', 'teso_meios_pagamento_view', 'teso_meios_gerir',
            'teso_gestao_conciliacao_view', 'teso_conc_importar', 'teso_conc_confirmar']);
    }

    private function emEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function doc(string $tipo, array $linhas, bool $integrar = true, string $conta = '4311'): int
    {
        $id = $this->postJson('/api/tesouraria/documentos', ['tipo' => $tipo, 'data_documento' => $this->hoje, 'conta_financeira' => $conta,
            'descricao' => 'Movimento de teste', 'referencia' => 'REF-T1', 'linhas' => $linhas], $this->s)->assertCreated()->json('dados.id');
        if ($integrar) {
            $this->postJson("/api/tesouraria/documentos/{$id}/integrar", [], $this->s)->assertOk();
        }

        return $id;
    }

    /** FT de 2 850,00 contabilizada → [id, n.º] */
    private function factura(): array
    {
        $produto = $this->emEmpresa(fn () => Produto::query()->value('id'));
        $r = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $this->cliente->id, 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $produto, 'quantidade' => 2.5]]], $this->s)->assertCreated();
        $this->postJson('/api/vendas/documentos/'.$r->json('dados.id').'/contabilizar', [], $this->s)->assertOk();

        return [$r->json('dados.id'), $r->json('dados.numero_documento')];
    }

    #[Test]
    public function lista_devolve_as_colunas_do_legado_sem_n_mais_1(): void
    {
        [$un, $cc] = $this->emEmpresa(fn () => [UnidadeNegocio::create(['codigo' => 'UN1', 'nome' => 'Sede'])->id, CentroCusto::create(['codigo' => 'CC9', 'descricao' => 'Geral'])->id]);
        [$ft, $numero] = $this->factura();
        $rec = $this->doc('RECEBIMENTO', [
            ['codigo_conta' => '3111', 'tipo_dc' => 'C', 'valor' => 1000, 'terceiro_id' => $this->cliente->id, 'venda_id' => $ft],
            ['codigo_conta' => '752', 'tipo_dc' => 'D', 'valor' => 40, 'unidade_negocio_id' => $un, 'centro_custo_id' => $cc],
        ]);
        $pag = $this->doc('PAGAMENTO', [['codigo_conta' => '752', 'tipo_dc' => 'D', 'valor' => 75]], false);

        $r = $this->getJson('/api/tesouraria/documentos?tipo=RECEBIMENTO', $this->s)->assertOk()->assertJsonCount(1, 'dados');
        $d = $r->json('dados.0');
        $this->assertSame($rec, $d['id']);
        $this->assertSame([['3111', 'C', '1000.00'], ['752', 'D', '40.00']], array_map(fn ($c) => [$c['codigo_conta'], $c['tipo_dc'], $c['valor']], $d['contrapartidas']));
        $this->assertSame($numero, $d['contrapartidas'][0]['numero_documento']);
        $this->assertSame(['UN1', 'CC9'], [$d['contrapartidas'][1]['unidade_negocio'], $d['contrapartidas'][1]['centro_custo']]);
        $this->assertSame([['nome' => 'Cliente Ficticio', 'nif' => '5000000099']], $d['entidades']);
        $this->assertNull($d['reconciliacao_bancaria']);
        $this->assertNull($d['compensacao']);

        // filtros do Histórico: C. Tesouraria / C. Contrapartida por prefixo e valor exacto
        $this->getJson('/api/tesouraria/documentos?contrapartida_prefixo=31', $this->s)->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.id', $rec);
        $this->getJson('/api/tesouraria/documentos?conta_prefixo=43&valor=75', $this->s)->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.id', $pag);
        $this->getJson('/api/tesouraria/documentos?contrapartida_prefixo=31%25', $this->s)->assertStatus(422);   // só dígitos e pontos

        // o n.º de consultas não cresce com o n.º de documentos
        $contar = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/tesouraria/documentos', $this->s)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $antes = $contar();
        foreach (range(1, 4) as $i) {
            $this->doc('PAGAMENTO', [['codigo_conta' => '752', 'tipo_dc' => 'D', 'valor' => 10 + $i, 'unidade_negocio_id' => $un]]);
        }
        $this->assertLessThanOrEqual($antes + 1, $contar());
    }

    #[Test]
    public function compensacao_e_reconciliacao_bancaria_com_extracto_de_teste(): void
    {
        $rec = $this->doc('RECEBIMENTO', [['codigo_conta' => '3111', 'tipo_dc' => 'C', 'valor' => 1140, 'terceiro_id' => $this->cliente->id]]);
        $lan = $this->getJson("/api/tesouraria/documentos/{$rec}", $this->s)->json('dados.numero_lan_contabilizacao');
        // compensação de terceiros (código REC_… na linha do cliente)
        $this->emEmpresa(fn () => LancamentoContabil::query()->where('numero_lan', $lan)->where('codigo_conta', '3111')->update(['reconciliacao_codigo' => 'REC_1700000000_7']));
        $this->getJson('/api/tesouraria/documentos', $this->s)->assertJsonPath('dados.0.compensacao.codigo', 'REC_1700000000_7')->assertJsonPath('dados.0.reconciliacao_bancaria', null);

        // extracto fictício importado, sugestão e confirmação: a lista passa a mostrar a reconciliação bancária
        $data = now()->format('d/m/Y');
        $csv = "Data;Referencia;Descrição;Debito;Credito\n{$data};TRF1;Recebimento cliente;;1.140,00\n";
        $this->post('/api/tesouraria/extrato/importar', ['codigo_conta' => '4311', 'ficheiro' => UploadedFile::fake()->createWithContent('extrato.csv', $csv)],
            $this->s + ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('dados.importadas', 1);
        $sug = $this->getJson('/api/tesouraria/reconciliacao/sugestoes?codigo_conta=4311', $this->s)->assertOk()->assertJsonCount(1, 'dados')->json('dados.0');
        $codigo = $this->postJson('/api/tesouraria/reconciliacao', ['codigo_conta' => '4311', 'grupos' => [['extrato' => [$sug['linha_extrato_id']], 'lancamentos' => [$sug['lancamento_id']]]]], $this->s)
            ->assertCreated()->json('dados.reconciliacao_codigo');
        $this->getJson('/api/tesouraria/documentos', $this->s)->assertJsonPath('dados.0.reconciliacao_bancaria.codigo', $codigo)
            ->assertJsonPath('dados.0.reconciliacao_bancaria.estado', 'CONCILIADO_BANCO')->assertJsonPath('dados.0.compensacao.codigo', 'REC_1700000000_7');
        // anular (desintegrar) continua bloqueado pela reconciliação bancária
        $this->postJson("/api/tesouraria/documentos/{$rec}/desintegrar", ['motivo' => 'Teste de bloqueio'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'LANCAMENTO_RECONCILIADO');
    }

    #[Test]
    public function resumo_dos_agregadores_e_meios_de_pagamento(): void
    {
        $this->doc('PAGAMENTO', [['codigo_conta' => '752', 'tipo_dc' => 'D', 'valor' => 10]]);
        $this->doc('PAGAMENTO', [['codigo_conta' => '752', 'tipo_dc' => 'D', 'valor' => 11]], false);
        $this->doc('RECEBIMENTO', [['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => 12]], false);
        $this->getJson('/api/tesouraria/resumo', $this->s)->assertOk()->assertJsonPath('dados.pendentes', 2)->assertJsonPath('dados.integrados', 1);
        // isolamento: outra empresa não conta
        $outra = $this->criarEmpresa();
        $this->assertSame(0, app(ContextoEmpresa::class)->executarComo($outra->id, fn () => DB::table('documentos_tesouraria')->where('empresa_id', $outra->id)->count()));
        // sem nenhum ecrã da tesouraria: 403
        $this->getJson('/api/tesouraria/resumo', $this->sessao(['dashboard_view']))->assertForbidden();

        $this->postJson('/api/tesouraria/meios-pagamento', ['nome' => 'Banco de teste', 'codigo_conta' => '4311', 'predefinido' => true], $this->s)->assertCreated();
        $this->postJson('/api/tesouraria/meios-pagamento', ['nome' => 'Caixa de teste', 'codigo_conta' => '4511'], $this->s)->assertCreated();
        $m = collect($this->getJson('/api/tesouraria/meios-pagamento', $this->s)->assertOk()->json('dados'))->keyBy('nome');
        $this->assertSame(['Banco Ficticio', 'BANCO'], [$m['Banco de teste']['descricao_conta'], $m['Banco de teste']['tipo']]);
        $this->assertSame(['Caixa sede', 'CAIXA'], [$m['Caixa de teste']['descricao_conta'], $m['Caixa de teste']['tipo']]);
    }

    #[Test]
    public function reparacao_de_integracao_sem_lancamento_e_segura_e_auditada(): void
    {
        [$ft] = $this->factura();
        $doc = $this->doc('RECEBIMENTO', [['codigo_conta' => '3111', 'tipo_dc' => 'C', 'valor' => 1000, 'terceiro_id' => $this->cliente->id, 'venda_id' => $ft]]);
        $sao = $this->doc('PAGAMENTO', [['codigo_conta' => '752', 'tipo_dc' => 'D', 'valor' => 30]]);
        $this->getJson("/api/vendas/documentos/{$ft}", $this->s)->assertJsonPath('dados.valor_pago', '1000.00');
        // nada a reparar enquanto os lançamentos existem
        $this->getJson('/api/tesouraria/reparacao/integracoes', $this->s)->assertOk()->assertJsonPath('dados.total', 0);

        // o lançamento do recebimento é estornado directamente na Contabilidade: o documento fica preso
        $lan = $this->getJson("/api/tesouraria/documentos/{$doc}", $this->s)->json('dados.numero_lan_contabilizacao');
        $this->emEmpresa(fn () => app(ServicoLancamentos::class)->estornar(LancamentoContabil::query()->where('numero_lan', $lan)->orderBy('id')->firstOrFail(), 'Estorno na contabilidade'));
        $this->postJson("/api/tesouraria/documentos/{$doc}/desintegrar", ['motivo' => 'Tentativa normal'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'LANCAMENTO_NAO_ENCONTRADO');

        // pré-visualização: lista só o documento preso, com o motivo; não grava
        $p = $this->getJson('/api/tesouraria/reparacao/integracoes', $this->s)->assertOk()->assertJsonPath('dados.total', 1)
            ->assertJsonPath('dados.documentos.0.id', $doc)->assertJsonPath('dados.documentos.0.motivo', 'LANCAMENTO_ESTORNADO');
        $this->getJson('/api/tesouraria/reparacao/integracoes?pesquisa='.urlencode((string) $p->json('dados.documentos.0.numero_documento')), $this->s)->assertJsonPath('dados.total', 1);
        $this->getJson('/api/tesouraria/reparacao/integracoes?pesquisa=NAO-EXISTE', $this->s)->assertJsonPath('dados.total', 0);
        $this->getJson("/api/tesouraria/documentos/{$doc}", $this->s)->assertJsonPath('dados.estado', 'INTEGRADO');

        // permissões: sem teso_desintegrar não vê nem executa
        $consulta = $this->sessao(['teso_gestao_pagamentos_view', 'teso_contab_historico_view']);
        $this->getJson('/api/tesouraria/reparacao/integracoes', $consulta)->assertForbidden();
        $this->postJson('/api/tesouraria/reparacao/integracoes', ['ids' => [$doc], 'motivo' => 'Sem permissão'], $consulta)->assertForbidden();
        // motivo obrigatório
        $this->postJson('/api/tesouraria/reparacao/integracoes', ['ids' => [$doc]], $this->s)->assertStatus(422);

        // execução: só o preso é reposto; o que tem lançamento é recusado (usa-se Anular)
        $this->postJson('/api/tesouraria/reparacao/integracoes', ['ids' => [$doc, $sao], 'motivo' => 'Lançamento estornado na contabilidade'], $this->s)->assertOk()
            ->assertJsonPath('dados.repostos.0.id', $doc)->assertJsonPath('dados.erros.0.id', $sao)->assertJsonPath('dados.erros.0.codigo', 'LANCAMENTO_EXISTE');
        $this->getJson("/api/tesouraria/documentos/{$doc}", $this->s)->assertJsonPath('dados.estado', 'PENDENTE')->assertJsonPath('dados.numero_lan_contabilizacao', null);
        $this->getJson("/api/tesouraria/documentos/{$sao}", $this->s)->assertJsonPath('dados.estado', 'INTEGRADO');
        // o «pago» da factura volta ao que a desintegração deixaria
        $this->getJson("/api/vendas/documentos/{$ft}", $this->s)->assertJsonPath('dados.valor_pago', '0.00');
        // auditado e idempotente
        $this->assertTrue($this->emEmpresa(fn () => LogAuditoria::query()->where('acao', 'Reparou integração sem lançamento')->where('registo_id', (string) $doc)->exists()));
        $this->postJson('/api/tesouraria/reparacao/integracoes', ['ids' => [$doc], 'motivo' => 'Segunda vez'], $this->s)->assertOk()
            ->assertJsonPath('dados.erros.0.codigo', 'DOCUMENTO_NAO_INTEGRADO');
        // e pode voltar a ser integrado normalmente
        $this->postJson("/api/tesouraria/documentos/{$doc}/integrar", [], $this->s)->assertOk()->assertJsonPath('dados.estado', 'INTEGRADO');
    }

    #[Test]
    public function documentos_do_legado_sem_ligacao_exigem_confirmacao_e_os_duvidosos_nao_aparecem(): void
    {
        // dois documentos «do legado» (integrados sem n.º de lançamento): um sem nada no Diário, outro com um movimento da
        // conta financeira na mesma data e valor (ex.: folha de caixa agregada) — este não é considerado órfão
        $a = $this->doc('PAGAMENTO', [['codigo_conta' => '752', 'tipo_dc' => 'D', 'valor' => 321]], false);
        $b = $this->doc('PAGAMENTO', [['codigo_conta' => '752', 'tipo_dc' => 'D', 'valor' => 654]], false);
        $this->doc('PAGAMENTO', [['codigo_conta' => '752', 'tipo_dc' => 'D', 'valor' => 654]]);   // lançamento 4311 C 654 na mesma data
        $this->emEmpresa(fn () => DB::table('documentos_tesouraria')->whereIn('id', [$a, $b])
            ->update(['estado' => 'INTEGRADO', 'numero_documento' => null, 'referencia' => DB::raw("'LEG-' || id")]));

        $this->getJson('/api/tesouraria/reparacao/integracoes', $this->s)->assertOk()->assertJsonPath('dados.total', 1)
            ->assertJsonPath('dados.documentos.0.id', $a)->assertJsonPath('dados.documentos.0.motivo', 'LEGADO_SEM_LIGACAO');
        $this->postJson('/api/tesouraria/reparacao/integracoes', ['ids' => [$a, $b], 'motivo' => 'Documento do legado sem lançamento'], $this->s)->assertOk()
            ->assertJsonPath('dados.erros.0.codigo', 'CONFIRMAR_LEGADO')->assertJsonPath('dados.erros.1.codigo', 'LANCAMENTO_EXISTE')->assertJsonCount(0, 'dados.repostos');
        $this->postJson('/api/tesouraria/reparacao/integracoes', ['ids' => [$a], 'motivo' => 'Verificado no razão', 'confirmar_legado' => true], $this->s)->assertOk()
            ->assertJsonPath('dados.repostos.0.id', $a);
        $this->getJson("/api/tesouraria/documentos/{$b}", $this->s)->assertJsonPath('dados.estado', 'INTEGRADO');
    }
}
