<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\DocumentoTesouraria;
use App\Models\Empresa;
use App\Models\ItemDocumentoTesouraria;
use App\Models\LancamentoContabil;
use App\Models\MovimentoCaixa;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\SessaoPOS;
use App\Services\Logistica\ServicoStock;
use App\Services\Vendas\ServicoConfigVendas;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POS parte 2 (ADR-048): prestação de contas das sessões (numerário → folha de caixa, TPA com comissão e transferências →
 * recebimentos na tesouraria), anulação sem apagar, segregação de funções e relatórios do POS.
 * Produto P1: 1 140 Kz com IVA 14 %, 20 em stock. Transitórias: 489 numerário, 488 TPA, 487 transferências.
 */
final class POSPrestacaoTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    /** operador: abre, vende, fecha e integra as sessões */
    private array $s;

    /** gestor: presta contas, anula, delibera e opera a tesouraria */
    private array $g;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000002']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['311' => 'Clientes', '3452' => 'IVA liquidado', '611' => 'Vendas', '2611' => 'Mercadorias', '7111' => 'CMV', '487' => 'Transitória transferências',
                '488' => 'Transitória TPA', '489' => 'Transitória numerário', '4511' => 'Caixa', '43101' => 'Banco', '6881' => 'Sobras de caixa', '7881' => 'Quebras de caixa',
                '3791' => 'Operadores', '7671' => 'Comissões TPA'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            PlanoConta::create(['codigo' => '43102', 'descricao' => 'Banco USD', 'tipo' => 'M', 'codigo_moeda' => 'USD']);
            app(ServicoConfigVendas::class)->definir(['clientes_default' => '311']);
            $this->ids['p'] = Produto::create(['codigo' => 'P1', 'nome' => 'Produto', 'preco_unitario' => 1140, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => true, 'conta_inventario' => '2611', 'conta_custo' => '7111'])->id;
            $this->ids['a'] = Armazem::create(['nome' => 'Loja', 'predefinido' => true])->id;
            app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['a'], '20', '600', now()->toDateString(), 'Stock inicial');
        });
        [$this->s, $this->ids['operador']] = $this->sessao(['pos_view', 'pos_venda', 'pos_desconto', 'pos_fecho', 'pos_integrar', 'pos_descontabilizar', 'pos_terminais_gerir', 'pos_prestar',
            'pos_prestacao_view', 'pos_relatorios_view']);
        [$this->g] = $this->sessao(['pos_prestacao_view', 'pos_prestar', 'pos_prestacao_anular', 'pos_desvio_deliberar', 'pos_relatorios_view',
            'teso_folha_caixa_view', 'teso_caixa_operar', 'teso_caixa_fechar', 'teso_caixa_contabilizar', 'teso_integrar', 'teso_desintegrar', 'teso_gestao_pagamentos_view']);
        $this->putJson('/api/pos/definicoes', ['conta_sobra' => '6881', 'conta_quebra' => '7881', 'conta_operador' => '3791', 'tolerancia_desvio' => 5], $this->s)->assertOk();
        $this->ids['t'] = $this->postJson('/api/pos/terminais', ['codigo' => 'T01', 'nome' => 'Loja 1', 'armazem_id' => $this->ids['a'], 'meios_pagamento' => [
            ['id' => 'pm_num', 'tipo' => 'NUMERARIO', 'nome' => 'Numerário', 'conta_transitoria' => '489', 'conta_liquidacao' => '4511'],
            ['id' => 'pm_tpa', 'tipo' => 'TPA', 'nome' => 'Multicaixa', 'conta_transitoria' => '488', 'conta_liquidacao' => '43101', 'codigo_tpa' => 'TPA1', 'comissao_pct' => 1,
                'conta_comissao' => '7671'],
            ['id' => 'pm_trf', 'tipo' => 'TRANSFERENCIA', 'nome' => 'Transferência', 'conta_transitoria' => '487', 'conta_liquidacao' => '43101'],
        ]], $this->s)->assertCreated()->json('dados.id');
    }

    /** @return array{0: array<string, mixed>, 1: int} */
    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return [$this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id], $u->id];
    }

    private function na(callable $fn): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $fn);
    }

    private function vender(int $sessao, array $pagamentos, float $q = 1, array $extra = []): array
    {
        return $this->postJson("/api/pos/sessoes/{$sessao}/vendas", $extra + ['linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => $q]], 'pagamentos' => $pagamentos], $this->s)
            ->assertCreated()->json('dados');
    }

    private function abrir(float $fundo = 5000): int
    {
        return $this->postJson("/api/pos/terminais/{$this->ids['t']}/sessoes", ['fundo_maneio' => $fundo], $this->s)->assertCreated()->json('dados.id');
    }

    /** D − C da conta em todos os lançamentos (os estornos anulam-se). */
    private function saldo(string $conta): string
    {
        return $this->na(fn () => number_format((float) LancamentoContabil::query()->where('codigo_conta', $conta)
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END), 0) AS s")->value('s'), 2, '.', ''));
    }

    private function itens(int $sessao): array
    {
        return collect($this->getJson("/api/pos/sessoes/{$sessao}/prestacao", $this->g)->assertOk()->json('dados.itens'))->keyBy('chave_item')->all();
    }

    private function abrirCaixa(float $saldo = 0): int
    {
        return $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4511', 'data' => now()->toDateString(), 'saldo_abertura' => $saldo], $this->g)->assertCreated()->json('dados.id');
    }

    /**
     * Sessão com TPA 1 000 + numerário 1 052 (venda 1, 2 × 1 140 − 10 %) e transferência 1 140 (venda 2); contados 6 050
     * (desvio −2, dentro da tolerância → falta automática); talão do TPA 1 010 (diferença +10).
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function sessaoCompleta(): array
    {
        $sessao = $this->abrir();
        $this->vender($sessao, [['meio_id' => 'pm_tpa', 'valor' => 1000], ['meio_id' => 'pm_num', 'valor' => 1100]], 2, ['percentagem_desconto' => 10]);
        $v2 = $this->vender($sessao, [['meio_id' => 'pm_trf', 'valor' => 1140, 'referencia' => 'TRF-77']]);
        $this->postJson("/api/pos/sessoes/{$sessao}/fechar", ['numerario_contado' => 6050, 'justificacao' => 'Talão com operação a mais',
            'fechos_tpa' => [['meio_id' => 'pm_tpa', 'valor_talao' => 1010, 'operacoes_talao' => 2, 'referencia_lote' => 'L1']]], $this->s)->assertOk()
            ->assertJsonPath('dados.deliberacao.automatica', true);

        return [$sessao, $v2];
    }

    #[Test]
    public function prestacao_completa_salda_as_transitorias_com_desvio_automatico(): void
    {
        [$sessao, $v2] = $this->sessaoCompleta();
        $trf = "TRF:{$v2['id']}:pm_trf";

        // antes da integração: tudo bloqueado
        $this->assertSame(['BLOQUEADO', 'SESSAO_NAO_INTEGRADA'], [$this->itens($sessao)['NUM:pm_num']['estado'], $this->itens($sessao)['NUM:pm_num']['bloqueio']['codigo']]);
        $this->postJson("/api/pos/sessoes/{$sessao}/prestacao", ['chave_item' => 'NUM:pm_num'], $this->g)->assertStatus(422)->assertJsonPath('codigo', 'SESSAO_NAO_INTEGRADA');
        $this->postJson("/api/pos/sessoes/{$sessao}/contabilizar", [], $this->s)->assertOk();

        // itens: numerário = sistema + desvio automático; TPA pelo sistema com a diferença do talão; uma transferência
        $i = $this->itens($sessao);
        $this->assertSame(['NUM:pm_num', 'TPA:pm_tpa', $trf], array_keys($i));
        $this->assertSame(['1050.00', '1052.00', '-2.00', 'REC', 'POR_PRESTAR'],
            [$i['NUM:pm_num']['valor'], $i['NUM:pm_num']['numerario_sistema'], $i['NUM:pm_num']['desvio_aplicado'], $i['NUM:pm_num']['movimento'], $i['NUM:pm_num']['estado']]);
        $this->assertSame(['1000.00', '1010.00', '10.00', '10.10', 'L1', 'TPA1'], [$i['TPA:pm_tpa']['valor'], $i['TPA:pm_tpa']['valor_talao'], $i['TPA:pm_tpa']['diferenca'],
            $i['TPA:pm_tpa']['comissao_sugerida'], $i['TPA:pm_tpa']['referencia_lote'], $i['TPA:pm_tpa']['codigo_tpa']]);
        $this->assertSame(['1140.00', 'TRF-77', $v2['numero_documento']], [$i[$trf]['valor'], $i[$trf]['referencia'], $i[$trf]['numero_documento']]);
        $this->getJson('/api/pos/prestacao', $this->g)->assertOk()->assertJsonPath('dados.sessoes.0.id', $sessao)->assertJsonCount(3, 'dados.sessoes.0.itens');

        // segregação: quem operou a sessão não presta contas dela
        $this->postJson("/api/pos/sessoes/{$sessao}/prestacao", ['chave_item' => 'NUM:pm_num'], $this->s)->assertStatus(403)->assertJsonPath('codigo', 'SEGREGACAO_FUNCOES');
        $this->postJson("/api/pos/sessoes/{$sessao}/prestacao", ['chave_item' => 'XPTO'], $this->g)->assertStatus(422)->assertJsonPath('codigo', 'ITEM_INEXISTENTE');

        // numerário: exige a folha de caixa aberta da conta de liquidação
        $this->postJson("/api/pos/sessoes/{$sessao}/prestacao", ['chave_item' => 'NUM:pm_num'], $this->g)->assertStatus(422)->assertJsonPath('codigo', 'SEM_FOLHA_CAIXA');
        $caixa = $this->abrirCaixa();
        $num = $this->postJson("/api/pos/sessoes/{$sessao}/prestacao", ['chave_item' => 'NUM:pm_num'], $this->g)->assertCreated()
            ->assertJsonPath('dados.alvo', 'FOLHA_CAIXA')->assertJsonPath('dados.montante_bruto', '1050.00')->assertJsonPath('dados.sessao_caixa_id', $caixa)->json('dados');
        $m = $this->na(fn () => MovimentoCaixa::query()->findOrFail($num['movimento_caixa_id']));
        $this->assertSame(['REC', '4511', '489', '1050.00', 'POS', $num['id']], [$m->tipo, $m->conta_debito, $m->conta_credito, $m->valor, $m->tipo_origem, (int) $m->origem_id]);
        $this->getJson("/api/pos/sessoes/{$sessao}", $this->s)->assertJsonPath('dados.estado_liquidacao', 'PARCIAL');
        $this->postJson("/api/pos/sessoes/{$sessao}/prestacao", ['chave_item' => 'NUM:pm_num'], $this->g)->assertStatus(422)->assertJsonPath('codigo', 'ITEM_JA_PRESTADO');

        // TPA: conta em moeda estrangeira recusada; comissão 1 % do talão deduzida no recebimento
        $this->postJson("/api/pos/sessoes/{$sessao}/prestacao", ['chave_item' => 'TPA:pm_tpa', 'conta_financeira' => '43102'], $this->g)
            ->assertStatus(422)->assertJsonPath('codigo', 'MOEDA_NAO_SUPORTADA');
        $this->postJson("/api/pos/sessoes/{$sessao}/prestacao", ['chave_item' => 'TPA:pm_tpa', 'comissao' => 1000], $this->g)->assertStatus(422)->assertJsonPath('codigo', 'COMISSAO_INVALIDA');
        $tpa = $this->postJson("/api/pos/sessoes/{$sessao}/prestacao", ['chave_item' => 'TPA:pm_tpa'], $this->g)->assertCreated()
            ->assertJsonPath('dados.comissao', '10.10')->assertJsonPath('dados.montante_liquido', '989.90')->assertJsonPath('dados.documento_comissao_id', null);
        $this->assertStringContainsString('talão', $tpa->json('mensagem'));
        $doc = $this->na(fn () => DocumentoTesouraria::query()->findOrFail($tpa->json('dados.documento_tesouraria_id')));
        $this->assertSame(['RECEBIMENTO', 'PENDENTE', '43101', '989.90', 'POS-TPA-'.$this->na(fn () => SessaoPOS::query()->find($sessao)->numero_z).'-pm_tpa'],
            [$doc->tipo, $doc->estado, $doc->conta_financeira, $doc->valor_total, $doc->referencia]);
        $this->assertSame(['C 488 1000.00', 'D 7671 10.10'], $this->na(fn () => ItemDocumentoTesouraria::query()->where('documento_tesouraria_id', $doc->id)->get()
            ->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->sort()->values()->all()));

        // transferências em lote: um recebimento por comprovativo, com o cliente e o n.º da venda
        $lote = $this->postJson("/api/pos/sessoes/{$sessao}/prestacao/transferencias", [], $this->g)->assertOk()->assertJsonCount(1, 'dados.registadas')
            ->assertJsonCount(0, 'dados.erros')->json('dados.registadas.0');
        $linha = $this->na(fn () => ItemDocumentoTesouraria::query()->where('documento_tesouraria_id', $lote['documento_tesouraria_id'])->sole());
        $this->assertSame(['C', '487', '1140.00', $v2['cliente_id'], $v2['numero_documento']], [$linha->tipo_dc, $linha->codigo_conta, $linha->valor, $linha->terceiro_id, $linha->numero_documento]);
        $this->assertSame(['TRF-77', $v2['numero_documento'], substr($v2['data_emissao'], 0, 10)], [$lote['referencia'], $lote['numero_documento'], substr($lote['data'], 0, 10)]);
        $this->getJson("/api/pos/sessoes/{$sessao}", $this->s)->assertJsonPath('dados.estado_liquidacao', 'LIQUIDADA');
        $this->postJson("/api/pos/sessoes/{$sessao}/prestacao/transferencias", [], $this->g)->assertStatus(422)->assertJsonPath('codigo', 'SEM_TRANSFERENCIAS');

        // integrados os recebimentos e a folha de caixa, as três transitórias ficam saldadas
        foreach ([$tpa->json('dados.documento_tesouraria_id'), $lote['documento_tesouraria_id']] as $d) {
            $this->postJson("/api/tesouraria/documentos/{$d}/integrar", [], $this->g)->assertOk();
        }
        $this->postJson("/api/tesouraria/caixa/sessoes/{$caixa}/fechar", ['saldo_fisico' => 1050, 'data' => now()->toDateString()], $this->g)->assertOk();
        $this->postJson("/api/tesouraria/caixa/sessoes/{$caixa}/contabilizar", [], $this->g)->assertOk();
        $this->assertSame(['0.00', '0.00', '0.00', '1050.00', '2129.90', '10.10'],
            [$this->saldo('489'), $this->saldo('488'), $this->saldo('487'), $this->saldo('4511'), $this->saldo('43101'), $this->saldo('7671')]);

        // integrado: a anulação exige desintegrar / descontabilizar primeiro; a sessão POS não se descontabiliza
        $this->postJson("/api/pos/liquidacoes/{$tpa->json('dados.id')}/anular", ['motivo' => 'Erro'], $this->g)->assertStatus(422)->assertJsonPath('codigo', 'DOCUMENTO_INTEGRADO');
        $this->postJson("/api/pos/liquidacoes/{$num['id']}/anular", ['motivo' => 'Erro'], $this->g)->assertStatus(422)->assertJsonPath('codigo', 'MOVIMENTO_CAIXA_CONTABILIZADO');
        $this->postJson("/api/pos/sessoes/{$sessao}/descontabilizar", ['motivo' => 'x'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'SESSAO_COM_LIQUIDACOES');
        $this->postJson("/api/tesouraria/documentos/{$tpa->json('dados.documento_tesouraria_id')}/desintegrar", ['motivo' => 'Banco errado'], $this->g)->assertOk();
        $this->postJson("/api/pos/liquidacoes/{$tpa->json('dados.id')}/anular", ['motivo' => 'Erro'], $this->g)->assertOk()->assertJsonPath('dados.estado', 'ANULADO');
        $this->assertSame('ANULADO', $this->na(fn () => DocumentoTesouraria::query()->find($tpa->json('dados.documento_tesouraria_id'))->estado));
        $this->getJson("/api/pos/sessoes/{$sessao}", $this->s)->assertJsonPath('dados.estado_liquidacao', 'PARCIAL');
    }

    #[Test]
    public function desvio_pendente_bloqueia_numerario_comissao_a_parte_e_anulacao(): void
    {
        // TPA 1 140 + numerário 1 140, sem fundo; contados 1 000 → falta de 140 acima da tolerância (PENDENTE)
        $sessao = $this->abrir(0);
        $this->vender($sessao, [['meio_id' => 'pm_tpa', 'valor' => 1140]]);
        $this->vender($sessao, [['meio_id' => 'pm_num', 'valor' => 1140]]);
        $this->postJson("/api/pos/sessoes/{$sessao}/fechar", ['numerario_contado' => 1000, 'justificacao' => 'Falta por apurar',
            'fechos_tpa' => [['meio_id' => 'pm_tpa', 'valor_talao' => 1140]]], $this->s)->assertOk()->assertJsonPath('dados.estado_desvio', 'PENDENTE');
        $this->postJson("/api/pos/sessoes/{$sessao}/contabilizar", [], $this->s)->assertOk();
        $this->assertSame('DESVIO_PENDENTE', $this->itens($sessao)['NUM:pm_num']['bloqueio']['codigo']);
        $this->postJson("/api/pos/sessoes/{$sessao}/prestacao", ['chave_item' => 'NUM:pm_num'], $this->g)->assertStatus(422)->assertJsonPath('codigo', 'DESVIO_PENDENTE');

        // TPA com a comissão cobrada à parte: recebimento pelo bruto + pagamento da comissão
        $tpa = $this->postJson("/api/pos/sessoes/{$sessao}/prestacao", ['chave_item' => 'TPA:pm_tpa', 'comissao' => 5, 'comissao_deduzida' => false, 'data' => now()->toDateString()], $this->g)
            ->assertCreated()->assertJsonPath('dados.montante_liquido', '1140.00')->assertJsonPath('dados.comissao', '5.00')->json('dados');
        [$rec, $pag] = $this->na(fn () => [DocumentoTesouraria::query()->find($tpa['documento_tesouraria_id']), DocumentoTesouraria::query()->find($tpa['documento_comissao_id'])]);
        $this->assertSame(['RECEBIMENTO', '1140.00', 'PAGAMENTO', '5.00'], [$rec->tipo, $rec->valor_total, $pag->tipo, $pag->valor_total]);
        $this->getJson("/api/pos/sessoes/{$sessao}", $this->s)->assertJsonPath('dados.estado_liquidacao', 'PARCIAL');

        // deliberada a falta (custo), o numerário a prestar é o contado
        $this->postJson("/api/pos/sessoes/{$sessao}/deliberacao", ['decisao' => 'FALTA_CUSTO', 'nota' => 'Assumida'], $this->g)->assertOk();
        $this->assertSame('1000.00', $this->itens($sessao)['NUM:pm_num']['valor']);
        $caixa = $this->abrirCaixa();
        $num = $this->postJson("/api/pos/sessoes/{$sessao}/prestacao", ['chave_item' => 'NUM:pm_num'], $this->g)->assertCreated()->json('dados');
        $this->getJson("/api/pos/sessoes/{$sessao}", $this->s)->assertJsonPath('dados.estado_liquidacao', 'LIQUIDADA');
        $this->postJson("/api/pos/sessoes/{$sessao}/deliberacao/anular", ['motivo' => 'x'], $this->g)->assertStatus(422)->assertJsonPath('codigo', 'NUMERARIO_PRESTADO');

        // anular: sem permissão → 403; o movimento sai da folha aberta; a liquidação fica ANULADO (não se apaga)
        [$sem] = $this->sessao(['pos_prestacao_view']);
        $this->postJson("/api/pos/liquidacoes/{$num['id']}/anular", ['motivo' => 'Contagem revista'], $sem)->assertForbidden();
        $this->postJson("/api/pos/liquidacoes/{$num['id']}/anular", ['motivo' => 'Contagem revista'], $this->g)->assertOk()
            ->assertJsonPath('dados.estado', 'ANULADO')->assertJsonPath('dados.cancelado_por', fn ($v) => $v !== null);
        $this->assertSame(0, $this->na(fn () => MovimentoCaixa::query()->where('sessao_caixa_id', $caixa)->count()));
        $this->postJson("/api/pos/liquidacoes/{$num['id']}/anular", ['motivo' => 'Repetida'], $this->g)->assertStatus(422)->assertJsonPath('codigo', 'LIQUIDACAO_JA_ANULADA');
        $this->getJson("/api/pos/sessoes/{$sessao}", $this->s)->assertJsonPath('dados.estado_liquidacao', 'PARCIAL');
        // volta a prestar-se (o índice único só abrange as REGISTADO)
        $this->postJson("/api/pos/sessoes/{$sessao}/prestacao", ['chave_item' => 'NUM:pm_num'], $this->g)->assertCreated();

        // anular o TPA anula os dois documentos pendentes
        $this->postJson("/api/pos/liquidacoes/{$tpa['id']}/anular", ['motivo' => 'Banco errado'], $this->g)->assertOk();
        $this->assertSame(['ANULADO', 'ANULADO'], $this->na(fn () => DocumentoTesouraria::query()->whereIn('id', [$tpa['documento_tesouraria_id'], $tpa['documento_comissao_id']])
            ->pluck('estado')->all()));
        $this->getJson("/api/pos/liquidacoes?sessao_pos_id={$sessao}&estado=ANULADO", $this->g)->assertOk()->assertJsonCount(2, 'dados');
        $this->getJson("/api/pos/liquidacoes?sessao_pos_id={$sessao}&estado=REGISTADO", $this->g)->assertOk()->assertJsonCount(1, 'dados');
    }

    #[Test]
    public function numerario_negativo_e_sessao_sem_vendas_com_desvio(): void
    {
        $caixa = $this->abrirCaixa(5000);

        // sem vendas: contados 5 003 com fundo 5 000 → sobra de 3 automática, lançada no fecho; presta-se só o desvio
        $vazia = $this->abrir();
        $this->postJson("/api/pos/sessoes/{$vazia}/fechar", ['numerario_contado' => 5003], $this->s)->assertOk()
            ->assertJsonPath('dados.estado_contabilizacao', 'SEM_MOVIMENTO')->assertJsonPath('dados.estado_liquidacao', 'PENDENTE');
        $this->assertSame(['NUM:pm_num' => '3.00'], array_map(fn ($i) => $i['valor'], $this->itens($vazia)));
        $this->postJson("/api/pos/sessoes/{$vazia}/prestacao", ['chave_item' => 'NUM:pm_num'], $this->g)->assertCreated()->assertJsonPath('dados.montante_bruto', '3.00');
        $this->getJson("/api/pos/sessoes/{$vazia}", $this->s)->assertJsonPath('dados.estado_liquidacao', 'LIQUIDADA');

        // falta de 3 140 (maior do que as vendas em numerário, 1 140), deliberada como custo → sai 2 000 da caixa (PAG)
        $sessao = $this->abrir();
        $this->vender($sessao, [['meio_id' => 'pm_num', 'valor' => 1140]]);
        $this->postJson("/api/pos/sessoes/{$sessao}/fechar", ['numerario_contado' => 3000, 'justificacao' => 'Fundo incompleto'], $this->s)->assertOk();
        $this->postJson("/api/pos/sessoes/{$sessao}/contabilizar", [], $this->s)->assertOk();
        $this->postJson("/api/pos/sessoes/{$sessao}/deliberacao", ['decisao' => 'FALTA_CUSTO', 'nota' => 'Assumida'], $this->g)->assertOk();
        $item = $this->itens($sessao)['NUM:pm_num'];
        $this->assertSame(['-2000.00', 'PAG'], [$item['valor'], $item['movimento']]);
        $num = $this->postJson("/api/pos/sessoes/{$sessao}/prestacao", ['chave_item' => 'NUM:pm_num'], $this->g)->assertCreated()->json('dados');
        $m = $this->na(fn () => MovimentoCaixa::query()->findOrFail($num['movimento_caixa_id']));
        $this->assertSame(['PAG', '489', '4511', '2000.00'], [$m->tipo, $m->conta_debito, $m->conta_credito, $m->valor]);
        $this->assertSame('-2000.00', $num['montante_bruto']);
        $this->postJson("/api/tesouraria/caixa/sessoes/{$caixa}/fechar", ['saldo_fisico' => 3003, 'data' => now()->toDateString()], $this->g)->assertOk();
        $this->postJson("/api/tesouraria/caixa/sessoes/{$caixa}/contabilizar", [], $this->g)->assertOk();
        $this->assertSame('0.00', $this->saldo('489'));
    }

    #[Test]
    public function relatorios_com_filtros_e_bloco_pos_e_servicos(): void
    {
        [$sessao] = $this->sessaoCompleta();
        $aberta = $this->abrir();
        $this->vender($aberta, [['meio_id' => 'pm_num', 'valor' => 1140]]);
        $hoje = now()->toDateString();

        $r = $this->getJson("/api/pos/relatorios?data_inicio={$hoje}&data_fim={$hoje}", $this->g)->assertOk()->json('dados');
        $this->assertSame(['4332.00', 3, '1444.00', '-2.00', 1, 0, 1, 1, 1], [$r['kpis']['facturacao'], $r['kpis']['documentos'], $r['kpis']['ticket_medio'], $r['kpis']['desvios'],
            $r['kpis']['sessoes_abertas'], $r['kpis']['desvios_por_deliberar'], $r['kpis']['sessoes_por_integrar'], $r['kpis']['sessoes_por_prestar'], $r['kpis']['diferencas_tpa']]);
        $this->assertSame(['Numerário' => '2192.00', 'Transferência' => '1140.00', 'Multicaixa' => '1000.00'], collect($r['por_meio'])->pluck('valor', 'nome')->all());
        $this->assertSame([['P1', '4.0', '3800.00', '4332.00']], array_map(fn ($p) => [$p['codigo'], number_format((float) $p['quantidade'], 1), $p['total_liquido'], $p['total_bruto']], $r['por_produto']));
        $this->assertSame([[$this->ids['t'], 'T01', 3, '4332.00']], array_map(fn ($t) => [$t['terminal_pos_id'], $t['codigo_terminal'], $t['documentos'], $t['total']], $r['por_terminal']));
        $this->assertCount(1, $r['por_operador']);
        $this->assertSame([$sessao], array_column($r['zs'], 'id'));
        $this->assertSame([['pm_tpa', '10.00']], array_map(fn ($d) => [$d['meio_id'], $d['diferenca']], $r['diferencas_tpa']));

        // filtros: outro terminal / outro operador / período sem vendas
        $this->getJson('/api/pos/relatorios?terminal_pos_id=999999', $this->g)->assertOk()->assertJsonPath('dados.kpis.documentos', 0)->assertJsonPath('dados.kpis.sessoes_abertas', 0);
        $this->getJson("/api/pos/relatorios?operador_id={$this->ids['operador']}", $this->g)->assertOk()->assertJsonPath('dados.kpis.documentos', 3);
        $this->getJson('/api/pos/relatorios?data_inicio=2020-01-01&data_fim=2020-12-31', $this->g)->assertOk()->assertJsonPath('dados.kpis.facturacao', '0.00')
            ->assertJsonCount(0, 'dados.zs');
        $this->getJson('/api/pos/relatorios?data_inicio=2020-02-01&data_fim=2020-01-01', $this->g)->assertStatus(422);

        // «POS e Serviços»: bloco POS; lavandaria e hotelaria a null até esses módulos existirem
        $k = collect($this->getJson("/api/pos/relatorios/servicos?data_inicio={$hoje}&data_fim={$hoje}", $this->g)->assertOk()->json('dados.kpis'))->pluck('valor', 'id');
        $this->assertSame(['4332.00', 3, '1444.00', 2, '-2.00', null, null], [$k['pos_vendas'], $k['pos_tickets'], $k['pos_ticket'], $k['pos_sessoes'], $k['pos_desvios'],
            $k['lav_ordens'], $k['hot_ocupacao']]);

        [$sem] = $this->sessao(['pos_prestacao_view']);
        $this->getJson('/api/pos/relatorios', $sem)->assertForbidden();
        $this->getJson('/api/pos/prestacao', $sem)->assertOk();
    }
}
