<?php

namespace Tests\Feature;

use App\Models\AbateVendaAtivo;
use App\Models\AtivoImobilizado;
use App\Models\ContratoArrendamento;
use App\Models\Fracao;
use App\Models\Imovel;
use App\Models\Venda;
use App\Services\Sistema\CatalogoPermissoes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FixturesImobiliarioF6;
use Tests\TestCase;

/**
 * Gestão Imobiliária, Onda 5 (F6): negócios de compra e venda — fases com bloqueios, impostos da transmissão (calculados à mão: preço 30 000 000, VPT 35 000 000,
 * IP 700 000, selo 105 000, escritura 2 000), aprovação, encargos, pagamentos, conclusão da compra (cria o bem em Activos sem duplicar e lança a aquisição),
 * venda de uma fracção com contrato (transmitir termina-o), factura em Vendas, fracções vendidas com trilho, abate do bem à parte e lançamento da alienação
 * equilibrado e repetível com mais e menos-valia, isolamento por empresa, permissões e restrições/idempotência da migração. A data é fixada em 2026-03-20.
 */
final class ImobiliarioNegociosTest extends TestCase
{
    use FixturesImobiliarioF6;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarNegocios();
    }

    /** @return array{0: float, 1: float} soma a débito e a crédito das linhas do lançamento */
    private function somas(string $documento): array
    {
        $l = DB::table('lancamentos_contabeis')->where('numero_documento', $documento)->get();

        return [round((float) $l->where('tipo_dc', 'D')->sum('valor'), 2), round((float) $l->where('tipo_dc', 'C')->sum('valor'), 2)];
    }

    /** @return array<string, float> valor por «D conta» e «C conta» do documento */
    private function linhas(string $documento): array
    {
        $r = [];
        foreach (DB::table('lancamentos_contabeis')->where('numero_documento', $documento)->get() as $l) {
            $r["{$l->tipo_dc} {$l->codigo_conta}"] = round((float) $l->valor, 2);
        }

        return $r;
    }

    // ---------------------------------------------------------------- compra

    #[Test]
    public function compra_fases_impostos_encargos_pagamentos_e_conclusao_criam_o_bem_e_lancam_a_aquisicao(): void
    {
        $im = $this->imovel('Moradia Talatona');
        $n = $this->negocio(['tipo' => 'COMPRA', 'imovel_id' => $im, 'terceiro_id' => $this->cliente, 'preco' => 30000000, 'vpt' => 35000000, 'sinal' => 3000000]);
        $id = $n['id'];
        $this->assertSame('CP 2026/0001', $n['numero']);
        $this->assertSame('NEGOCIACAO', $n['estado']);
        // impostos à mão: base = o maior entre o preço e o VPT
        $this->assertEquals([35000000, 700000, 105000, 2000, 807000], [$n['base'], $n['ip_valor'], $n['selo_valor'], $n['escritura_valor'], $n['total_impostos']]);
        $this->assertTrue($n['base_e_vpt']);
        // um só negócio aberto do mesmo tipo por imóvel (o legado deixava vários)
        $this->postJson('/api/imobiliario/negocios', ['tipo' => 'COMPRA', 'imovel_id' => $im, 'terceiro_id' => $this->cliente, 'preco' => 1000], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_JA_ABERTO');

        // sem aprovação não se avança; sem data não há promessa
        $this->postJson("/api/imobiliario/negocios/{$id}/avancar", ['fase' => 'PROMESSA'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_FALTAS');
        $this->aprovarNeg($id);
        $this->aprovarNeg($id);   // repetir não muda nada
        $this->postJson("/api/imobiliario/negocios/{$id}/avancar", ['fase' => 'PROMESSA'], $this->s)->assertStatus(422)->assertJsonPath('mensagem', 'Indique a data da promessa.');
        $this->putJson("/api/imobiliario/negocios/{$id}", ['data_promessa' => '2026-03-01'], $this->s)->assertOk()->assertJsonPath('dados.aprovado', true);
        $this->postJson("/api/imobiliario/negocios/{$id}/avancar", ['fase' => 'PROMESSA'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'PROMESSA');
        $this->postJson("/api/imobiliario/negocios/{$id}/avancar", ['fase' => 'PROMESSA'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_RECUO');
        // mudar o preço retira a aprovação (aprova-se outra vez); aqui volta ao mesmo valor
        $this->putJson("/api/imobiliario/negocios/{$id}", ['preco' => 31000000], $this->s)->assertOk()->assertJsonPath('dados.aprovado', false);
        $this->putJson("/api/imobiliario/negocios/{$id}", ['preco' => 30000000], $this->s)->assertOk();
        $this->aprovarNeg($id);
        // escritura: falta a data e o notário
        $this->postJson("/api/imobiliario/negocios/{$id}/avancar", ['fase' => 'ESCRITURA'], $this->s)->assertStatus(422)->assertJsonPath('mensagem', 'Indique a data da escritura. Indique o cartório ou notário.');
        $this->putJson("/api/imobiliario/negocios/{$id}", ['data_escritura' => '2026-02-01'], $this->s)->assertStatus(422)->assertJsonPath('mensagem', 'A escritura não pode ser anterior à promessa.');
        $this->putJson("/api/imobiliario/negocios/{$id}", ['data_escritura' => '2026-03-18', 'notario' => 'Cartório Notarial n.º 1'], $this->s)->assertOk();
        $e = $this->postJson("/api/imobiliario/negocios/{$id}/avancar", ['fase' => 'ESCRITURA'], $this->s)->assertOk()->json('dados');
        $this->assertSame('2026-03-20', $e['impostos_fixados_em']);
        $this->assertEquals(807000, $e['total_impostos']);
        // depois da escritura o preço e as partes não mudam; as notas sim; o cancelamento e a eliminação recusam-se
        $this->putJson("/api/imobiliario/negocios/{$id}", ['preco' => 29000000], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_FIXO');
        $this->putJson("/api/imobiliario/negocios/{$id}", ['notas' => 'Chaves entregues na escritura'], $this->s)->assertOk()->assertJsonPath('dados.notas', 'Chaves entregues na escritura');
        $this->postJson("/api/imobiliario/negocios/{$id}/cancelar", ['motivo' => 'x'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_NAO_CANCELA');
        $this->deleteJson("/api/imobiliario/negocios/{$id}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_NAO_APAGA');

        // encargos: 300 000 de mediação capitalizam; 50 000 «outro» é gasto do período
        $this->putJson("/api/imobiliario/negocios/{$id}/custos", ['linhas' => [['tipo' => 'MEDIACAO', 'designacao' => 'Comissão da mediadora', 'valor' => 300000],
            ['tipo' => 'OUTRO', 'designacao' => 'Deslocações', 'valor' => 50000]]], $this->s)->assertOk()
            ->assertJsonPath('dados.custo_aquisicao_calculado.total', '31107000.00')->assertJsonPath('dados.custo_aquisicao_calculado.gasto_periodo', '50000.00');
        $this->putJson("/api/imobiliario/negocios/{$id}/custos", ['linhas' => [['tipo' => 'MEDIACAO', 'designacao' => 'Sem valor', 'valor' => 0]]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_CUSTO_VALOR');
        $this->putJson("/api/imobiliario/negocios/{$id}/custos", ['linhas' => [['tipo' => 'MEDIACAO', 'valor' => 10]]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_CUSTO_DESIGNACAO');
        $this->assertSame(2, DB::table('custos_negocio_imo')->where('negocio_id', $id)->count());   // a gravação recusada não apagou nada

        // pagamentos: o sinal acordado é 3 000 000; repetir a mesma chave não duplica; o total não passa do preço
        $p = $this->postJson("/api/imobiliario/negocios/{$id}/pagamentos", ['tipo' => 'SINAL', 'valor' => 3000000, 'chave' => 'sinal-1'], $this->s)->assertCreated()->json('dados');
        $this->assertEquals(3000000, $p['pago_total']);
        $this->postJson("/api/imobiliario/negocios/{$id}/pagamentos", ['tipo' => 'SINAL', 'valor' => 3000000, 'chave' => 'sinal-1'], $this->s)->assertCreated();
        $this->assertSame(1, DB::table('pagamentos_negocio_imo')->where('negocio_id', $id)->count());
        $this->postJson("/api/imobiliario/negocios/{$id}/pagamentos", ['tipo' => 'SINAL', 'valor' => 3000000, 'chave' => 'sinal-1b'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_PAG_SINAL');
        $this->postJson("/api/imobiliario/negocios/{$id}/pagamentos", ['tipo' => 'PRESTACAO', 'valor' => 27000001], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_PAG_EXCEDE');
        $this->assertEquals(0, $this->postJson("/api/imobiliario/negocios/{$id}/pagamentos", ['tipo' => 'SALDO', 'valor' => 27000000, 'chave' => 'saldo'], $this->s)->assertCreated()->json('dados.por_liquidar'));
        $this->postJson("/api/imobiliario/negocios/{$id}/pagamentos/{$p['pagamentos'][0]['id']}/anular", ['motivo' => ''], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_MOTIVO');
        $this->postJson("/api/imobiliario/negocios/{$id}/pagamentos/{$p['pagamentos'][0]['id']}/anular", ['motivo' => 'Cheque devolvido'], $this->s)->assertOk();
        $this->assertEquals(27000000, $this->getJson("/api/imobiliario/negocios/{$id}", $this->s)->json('dados.pago_total'));

        // concluir: falta a categoria e a conta de contrapartida; nada fica a meio
        $this->postJson("/api/imobiliario/negocios/{$id}/concluir", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_SEM_CATEGORIA');
        $this->postJson("/api/imobiliario/negocios/{$id}/concluir", ['categoria_ativo_id' => $this->categoria], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_SEM_CONTA_COMPRA');
        $this->assertSame(0, $this->ctx(fn () => AtivoImobilizado::query()->count()));
        $this->assertSame('ESCRITURA', $this->getJson("/api/imobiliario/negocios/{$id}", $this->s)->json('dados.estado'));
        $this->configurarNegocios();
        $r = $this->postJson("/api/imobiliario/negocios/{$id}/concluir", ['categoria_ativo_id' => $this->categoria], $this->s)->assertOk()->json('dados');
        $this->assertFalse($r['repetida']);
        $this->assertSame('CONCLUIDO', $r['negocio']['estado']);
        $this->assertEquals(31107000, $r['negocio']['custo_aquisicao']);
        $this->assertTrue($r['ativo']['criado']);
        // o bem nasce em Activos ao custo, ligado ao imóvel, e a aquisição lança-se equilibrada
        $a = $this->ctx(fn () => AtivoImobilizado::query()->firstOrFail());
        $this->assertSame(['EDIFICIO', 'PROPRIO'], [$a->tipo_ativo, $a->propriedade]);
        $this->assertEquals(31107000, (float) $a->valor_aquisicao);
        $this->assertSame($a->id, $this->ctx(fn () => Imovel::query()->findOrFail($im)->ativo_edificio_id));
        $this->assertEquals(31107000, (float) $this->ctx(fn () => Imovel::query()->findOrFail($im)->valor_aquisicao));
        $this->assertSame(['D 121' => 31107000.0, 'C 3791' => 31107000.0], $this->linhas('CP 2026/0001'));
        $this->assertSame([31107000.0, 31107000.0], $this->somas('CP 2026/0001'));
        $this->assertSame((int) DB::table('lancamentos_contabeis')->where('numero_documento', 'CP 2026/0001')->where('tipo_dc', 'D')->value('id'), (int) $a->lancamento_contabil_id);
        // repetir não cria outro bem nem outro lançamento
        $this->postJson("/api/imobiliario/negocios/{$id}/concluir", ['categoria_ativo_id' => $this->categoria], $this->s)->assertOk()->assertJsonPath('dados.repetida', true);
        $this->assertSame(1, $this->ctx(fn () => AtivoImobilizado::query()->count()));
        $this->assertSame(2, DB::table('lancamentos_contabeis')->where('numero_documento', 'CP 2026/0001')->count());
        $this->postJson("/api/imobiliario/negocios/{$id}/avancar", ['fase' => 'REGISTADO'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_FECHADO');
        // indicadores do ano (calculados à mão)
        $res = $this->getJson('/api/imobiliario/negocios/resumo?ano=2026', $this->s)->assertOk()->json('dados');
        $this->assertEquals([1, 31107000, 807000, 0], [$res['compras'], $res['investido'], $res['impostos'], $res['abates_pendentes']]);
        $this->assertSame([], array_filter($this->getJson('/api/imobiliario/negocios/avisos', $this->s)->json('dados'), fn ($x) => $x['negocio_id'] === $id));
    }

    #[Test]
    public function compra_de_imovel_ja_ligado_a_um_bem_liga_sem_duplicar_nem_lancar_de_novo(): void
    {
        $x = $this->imovelComActivo();
        $n = $this->negocio(['tipo' => 'COMPRA', 'fracao_id' => $x['f1'], 'terceiro_id' => $this->cliente, 'preco' => 10000000, 'vpt' => 10000000]);
        $this->ateEscritura($n['id'], ['vpt' => 10000000]);
        $this->configurarNegocios();
        $r = $this->postJson("/api/imobiliario/negocios/{$n['id']}/concluir", [], $this->s)->assertOk()->json('dados');
        $this->assertFalse($r['ativo']['criado']);
        $this->assertSame($x['ativo'], $r['ativo']['id']);
        $this->assertSame(1, $this->ctx(fn () => AtivoImobilizado::query()->count()));
        $this->assertSame(0, DB::table('lancamentos_contabeis')->where('numero_documento', $n['numero'])->count());
        $this->assertNotEmpty($r['avisos']);
    }

    // ---------------------------------------------------------------- venda

    #[Test]
    public function venda_de_fraccao_com_contrato_transmite_factura_marca_vendida_e_o_abate_e_a_mais_valia_sao_um_passo_a_parte(): void
    {
        $x = $this->imovelComActivo();
        $this->configurar();   // artigo da renda para o contrato
        $contrato = $this->contrato($this->corpo($x['f1']));
        $this->assertSame('ARRENDADA', $this->ctx(fn () => Fracao::query()->findOrFail($x['f1'])->estado));
        $n = $this->negocio(['tipo' => 'VENDA', 'fracao_id' => $x['f1'], 'terceiro_id' => $this->cliente2, 'preco' => 50000000, 'vpt' => 45000000]);
        $id = $n['id'];
        $this->assertSame('VD 2026/0001', $n['numero']);
        $this->assertSame($x['imovel'], $n['imovel_id']);
        $this->assertEquals(1000000, $n['ip_valor']);   // base 50 000 000 (o preço é maior) × 2 %
        $this->putJson("/api/imobiliario/negocios/{$id}/custos", ['linhas' => [['tipo' => 'MEDIACAO', 'designacao' => 'Comissão', 'valor' => 300000]]], $this->s)->assertOk();
        $this->aprovarNeg($id);
        $this->putJson("/api/imobiliario/negocios/{$id}", ['data_escritura' => '2026-03-18', 'notario' => 'Cartório n.º 2'], $this->s)->assertOk();
        // o contrato em vigor bloqueia a escritura até haver decisão
        $this->postJson("/api/imobiliario/negocios/{$id}/avancar", ['fase' => 'ESCRITURA'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_FALTAS');
        $this->assertStringContainsString('contrato(s) em vigor', $this->postJson("/api/imobiliario/negocios/{$id}/avancar", ['fase' => 'ESCRITURA'], $this->s)->json('mensagem'));
        // transmitir termina o contrato: exige também a permissão de terminar contratos
        $semTerminar = $this->sessao(['imo_negocios_view', 'imo_neg_fase']);
        $this->postJson("/api/imobiliario/negocios/{$id}/contratos", ['destino' => 'TRANSMITE'], $semTerminar)->assertForbidden();
        $this->postJson("/api/imobiliario/negocios/{$id}/contratos", ['destino' => 'MANTEM'], $semTerminar)->assertOk();
        $this->postJson("/api/imobiliario/negocios/{$id}/avancar", ['fase' => 'ESCRITURA'], $this->s)->assertOk();
        // manter deixa o contrato vivo: a venda não se conclui
        $this->postJson("/api/imobiliario/negocios/{$id}/concluir", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_CONTRATOS_EM_VIGOR');
        $t = $this->postJson("/api/imobiliario/negocios/{$id}/contratos", ['destino' => 'TRANSMITE'], $this->s)->assertOk()->json('dados');
        $this->assertSame(1, $t['terminados']);
        $c = $this->ctx(fn () => ContratoArrendamento::query()->findOrFail($contrato['id']));
        $this->assertSame('TERMINADO', $c->estado);
        $this->assertSame('2026-03-18', $c->fim_real->format('Y-m-d'));
        $this->assertStringContainsString('venda VD 2026/0001', (string) $c->motivo_saida);

        // sem o artigo da venda a conclusão recusa-se e não deixa nada a meio
        $this->postJson("/api/imobiliario/negocios/{$id}/concluir", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_SEM_ARTIGO_VENDA');
        $this->assertSame('ESCRITURA', $this->getJson("/api/imobiliario/negocios/{$id}", $this->s)->json('dados.estado'));
        $this->assertSame(0, $this->ctx(fn () => Venda::query()->count()));
        $this->configurarNegocios();
        $r = $this->postJson("/api/imobiliario/negocios/{$id}/concluir", [], $this->s)->assertOk()->json('dados');
        $this->assertFalse($r['repetida']);
        $this->assertSame(1, $r['fracoes']);
        $this->assertSame(1, $r['restam']);   // a 102 fica por vender
        // a factura (FT) em Vendas: 50 000 000 sem IVA
        $v = $this->ctx(fn () => Venda::query()->findOrFail($r['negocio']['venda_id']));
        $this->assertSame('FT', $v->tipo_documento);
        $this->assertEquals(50000000, (float) $v->total_bruto);
        // a fracção passa a vendida com trilho; o imóvel continua activo; o bem ainda não foi abatido
        $this->assertSame('VENDIDA', $this->ctx(fn () => Fracao::query()->findOrFail($x['f1'])->estado));
        $this->assertSame(1, DB::table('historico_estado_fracao')->where('fracao_id', $x['f1'])->where('estado_para', 'VENDIDA')->count());
        $this->assertSame('ACTIVO', $this->ctx(fn () => Imovel::query()->findOrFail($x['imovel'])->estado));
        // resultado à mão: quota 60/100 = 0,6 → custo 60 000 000, amortizado 24 000 000, líquido 36 000 000; resultado 50 000 000 − 36 000 000 = 14 000 000;
        // o do legado desconta os encargos da venda: 14 000 000 − 300 000 = 13 700 000
        $neg = $r['negocio'];
        $this->assertEquals([0.6, 36000000, 14000000, 13700000], [$neg['quota'], $neg['valor_contabilistico'], $neg['resultado_contabilistico'], $neg['mais_valia']]);
        $this->assertNull($neg['abatido_em']);
        $this->assertSame(0, $this->ctx(fn () => AbateVendaAtivo::query()->count()));
        $this->assertSame(1, $this->getJson('/api/imobiliario/negocios/resumo?ano=2026', $this->s)->json('dados.abates_pendentes'));
        $this->postJson("/api/imobiliario/negocios/{$id}/concluir", [], $this->s)->assertOk()->assertJsonPath('dados.repetida', true);
        $this->assertSame(1, $this->ctx(fn () => Venda::query()->count()));   // repetir não factura outra vez

        // quem conclui não abate: a tarefa é outra
        $semAbater = $this->sessao(['imo_negocios_view', 'imo_neg_concluir']);
        $this->postJson("/api/imobiliario/negocios/{$id}/abater", [], $semAbater)->assertForbidden();
        $a1 = $this->postJson("/api/imobiliario/negocios/{$id}/abater", [], $this->s)->assertOk()->json('dados');
        $this->assertFalse($a1['repetida']);
        $this->assertTrue($a1['negocio']['abate_parcial']);
        $this->assertSame(0, $this->ctx(fn () => AbateVendaAtivo::query()->count()));   // venda parcial: o bem continua
        $this->assertSame('ACTIVO', $this->ctx(fn () => AtivoImobilizado::query()->findOrFail($x['ativo'])->estado));
        // alienação lançada sobre a quota: D 181 24 000 000 + D 611 50 000 000 (estorna o proveito da factura) = C 121 60 000 000 + C 7921 14 000 000
        $doc = 'ALI-VD 2026/0001';
        $this->assertSame(['C 121' => 60000000.0, 'D 181' => 24000000.0, 'D 611' => 50000000.0, 'C 7921' => 14000000.0], $this->linhas($doc));
        $this->assertSame([74000000.0, 74000000.0], $this->somas($doc));
        $n1 = DB::table('lancamentos_contabeis')->where('numero_documento', $doc)->count();
        $this->postJson("/api/imobiliario/negocios/{$id}/abater", [], $this->s)->assertOk()->assertJsonPath('dados.repetida', true);
        $this->assertSame($n1, DB::table('lancamentos_contabeis')->where('numero_documento', $doc)->count());

        // segunda venda: a 102 (40 %), a última quota; menos-valia: 20 000 000 − (40 000 000 − 16 000 000) = −4 000 000
        $n2 = $this->negocio(['tipo' => 'VENDA', 'fracao_id' => $x['f2'], 'terceiro_id' => $this->cliente2, 'preco' => 20000000, 'vpt' => 20000000]);
        $this->ateEscritura($n2['id'], ['vpt' => 20000000]);
        $c2 = $this->postJson("/api/imobiliario/negocios/{$n2['id']}/concluir", [], $this->s)->assertOk()->json('dados');
        $this->assertSame(0, $c2['restam']);
        $this->assertSame('ALIENADO', $this->ctx(fn () => Imovel::query()->findOrFail($x['imovel'])->estado));
        $this->assertSame('2026-03-18', $this->ctx(fn () => Imovel::query()->findOrFail($x['imovel'])->data_alienacao->format('Y-m-d')));
        $a2 = $this->postJson("/api/imobiliario/negocios/{$n2['id']}/abater", [], $this->s)->assertOk()->json('dados');
        $this->assertFalse($a2['negocio']['abate_parcial']);
        $this->assertEquals([0.4, -4000000], [$a2['negocio']['quota'], $a2['negocio']['resultado_contabilistico']]);
        $this->assertSame(1, $this->ctx(fn () => AbateVendaAtivo::query()->count()));
        $this->assertSame('ABATIDO', $this->ctx(fn () => AtivoImobilizado::query()->findOrFail($x['ativo'])->estado));
        $doc2 = 'ALI-VD 2026/0002';
        $this->assertSame(['C 121' => 40000000.0, 'D 181' => 16000000.0, 'D 611' => 20000000.0, 'D 6921' => 4000000.0], $this->linhas($doc2));
        $this->assertSame([40000000.0, 40000000.0], $this->somas($doc2));
        $res = $this->getJson('/api/imobiliario/negocios/resumo?ano=2026', $this->s)->assertOk()->json('dados');
        $this->assertEquals([2, 70000000, 13700000, 0], [$res['vendas'], $res['encaixado'], $res['mais_valias'], $res['abates_pendentes']]);
        $this->assertEquals(-4000000, $res['menos_valias']);
    }

    #[Test]
    public function venda_sem_activo_ligado_conclui_sem_resultado_e_so_se_vende_o_que_e_proprio(): void
    {
        $this->configurarNegocios();
        $im = $this->imovel('Loja sem activo');
        $f = $this->fracao($im, 'L1');
        $n = $this->negocio(['tipo' => 'VENDA', 'fracao_id' => $f, 'terceiro_id' => $this->cliente2, 'preco' => 5000000, 'vpt' => 5000000]);
        $this->ateEscritura($n['id'], ['vpt' => 5000000]);
        $r = $this->postJson("/api/imobiliario/negocios/{$n['id']}/concluir", [], $this->s)->assertOk()->json('dados');
        $this->assertNull($r['negocio']['mais_valia']);
        $this->assertSame('ALIENADO', $this->ctx(fn () => Imovel::query()->findOrFail($im)->estado));
        $this->postJson("/api/imobiliario/negocios/{$n['id']}/abater", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_SEM_ATIVO');
        // um imóvel arrendado a terceiros não se vende; uma fracção já vendida também não
        $alheio = $this->postJson('/api/imobiliario/imoveis', ['nome' => 'De terceiro', 'papel' => 'ARRENDADO', 'proprietario_terceiro_id' => $this->cliente], $this->s)->assertCreated()->json('dados.id');
        $this->postJson('/api/imobiliario/negocios', ['tipo' => 'VENDA', 'imovel_id' => $alheio, 'terceiro_id' => $this->cliente2, 'preco' => 1000], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_NAO_PROPRIO');
        $this->postJson('/api/imobiliario/negocios', ['tipo' => 'VENDA', 'fracao_id' => $f, 'terceiro_id' => $this->cliente2, 'preco' => 1000], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_FRACCAO_VENDIDA');
    }

    #[Test]
    public function venda_do_imovel_inteiro_trata_os_contratos_de_todas_as_fraccoes_e_abate_o_bem_todo(): void
    {
        $x = $this->imovelComActivo();
        $this->configurar();
        $this->contrato($this->corpo($x['f2']));   // o contrato está noutra fracção: a venda do prédio inteiro também o apanha (o legado só olhava a fracção)
        $n = $this->negocio(['tipo' => 'VENDA', 'imovel_id' => $x['imovel'], 'terceiro_id' => $this->cliente2, 'preco' => 80000000, 'vpt' => 80000000]);
        $this->aprovarNeg($n['id']);
        $this->putJson("/api/imobiliario/negocios/{$n['id']}", ['data_escritura' => '2026-03-18', 'notario' => 'Cartório n.º 3'], $this->s)->assertOk();
        $this->postJson("/api/imobiliario/negocios/{$n['id']}/avancar", ['fase' => 'ESCRITURA'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_FALTAS');
        $this->assertSame(1, $this->getJson("/api/imobiliario/negocios/{$n['id']}", $this->s)->assertOk()->json('dados.contratos_em_vigor.0.id') > 0 ? 1 : 0);
        $this->postJson("/api/imobiliario/negocios/{$n['id']}/contratos", ['destino' => 'TRANSMITE'], $this->s)->assertOk()->assertJsonPath('dados.terminados', 1);
        $this->postJson("/api/imobiliario/negocios/{$n['id']}/avancar", ['fase' => 'ESCRITURA'], $this->s)->assertOk();
        $this->configurarNegocios();
        $c = $this->postJson("/api/imobiliario/negocios/{$n['id']}/concluir", [], $this->s)->assertOk()->json('dados');
        $this->assertSame(2, $c['fracoes']);
        $this->assertSame(0, $c['restam']);
        $this->assertSame('ALIENADO', $this->ctx(fn () => Imovel::query()->findOrFail($x['imovel'])->estado));
        // quota 1: líquido 60 000 000 → mais-valia 20 000 000; o bem sai por inteiro
        $a = $this->postJson("/api/imobiliario/negocios/{$n['id']}/abater", [], $this->s)->assertOk()->json('dados');
        $this->assertEquals([1, 20000000], [$a['negocio']['quota'], $a['negocio']['resultado_contabilistico']]);
        $this->assertFalse($a['negocio']['abate_parcial']);
        $this->assertSame('ABATIDO', $this->ctx(fn () => AtivoImobilizado::query()->findOrFail($x['ativo'])->estado));
        $this->assertSame(['C 121' => 100000000.0, 'D 181' => 40000000.0, 'D 611' => 80000000.0, 'C 7921' => 20000000.0], $this->linhas('ALI-VD 2026/0001'));
    }

    #[Test]
    public function cancelar_com_motivo_so_antes_da_escritura_e_sem_pagamentos_validos_e_eliminar_so_o_que_nao_foi_escriturado(): void
    {
        $im = $this->imovel('Terreno');
        $n = $this->negocio(['tipo' => 'COMPRA', 'imovel_id' => $im, 'terceiro_id' => $this->cliente, 'preco' => 8000000, 'sinal' => 800000]);
        $id = $n['id'];
        $this->postJson("/api/imobiliario/negocios/{$id}/cancelar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_MOTIVO');
        $this->postJson("/api/imobiliario/negocios/{$id}/pagamentos", ['tipo' => 'SINAL', 'valor' => 800000], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_NAO_APROVADO');
        $this->aprovarNeg($id);
        $p = $this->postJson("/api/imobiliario/negocios/{$id}/pagamentos", ['tipo' => 'SINAL', 'valor' => 800000], $this->s)->assertCreated()->json('dados.pagamentos.0.id');
        $this->putJson("/api/imobiliario/negocios/{$id}", ['preco' => 9000000], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_COM_PAGAMENTOS');
        $this->postJson("/api/imobiliario/negocios/{$id}/cancelar", ['motivo' => 'Desistência'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_COM_PAGAMENTOS');
        $this->deleteJson("/api/imobiliario/negocios/{$id}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_COM_PAGAMENTOS');
        $this->postJson("/api/imobiliario/negocios/{$id}/pagamentos/{$p}/anular", ['motivo' => 'Devolvido ao comprador'], $this->s)->assertOk();
        $this->postJson("/api/imobiliario/negocios/{$id}/cancelar", ['motivo' => 'Desistência'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'CANCELADO');
        $this->putJson("/api/imobiliario/negocios/{$id}", ['notas' => 'x'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_FECHADO');
        // depois de cancelado o imóvel pode ter outro negócio aberto
        $this->negocio(['tipo' => 'COMPRA', 'imovel_id' => $im, 'terceiro_id' => $this->cliente, 'preco' => 7000000]);
        // um negócio sem pagamentos e por escriturar apaga-se
        $solto = $this->negocio(['tipo' => 'COMPRA', 'terceiro_id' => $this->cliente, 'preco' => 1000000]);
        $this->deleteJson("/api/imobiliario/negocios/{$solto['id']}", [], $this->s)->assertOk();
        $this->assertSame(0, DB::table('negocios_imobiliarios')->where('id', $solto['id'])->count());
        // validações da gravação
        $this->postJson('/api/imobiliario/negocios', ['tipo' => 'COMPRA', 'terceiro_id' => $this->cliente, 'preco' => 0], $this->s)->assertStatus(422)->assertJsonPath('mensagem', 'O preço tem de ser maior do que zero.');
        $this->postJson('/api/imobiliario/negocios', ['tipo' => 'COMPRA', 'terceiro_id' => $this->cliente, 'preco' => 100, 'sinal' => 101], $this->s)->assertStatus(422)->assertJsonPath('mensagem', 'O sinal não pode passar do preço.');
        $this->postJson('/api/imobiliario/negocios', ['tipo' => 'COMPRA', 'preco' => 100], $this->s)->assertStatus(422)->assertJsonPath('mensagem', 'Indique o vendedor.');
        $this->postJson('/api/imobiliario/negocios', ['tipo' => 'VENDA', 'terceiro_id' => $this->cliente, 'preco' => 100], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NEG_ALVO');
        $this->postJson('/api/imobiliario/negocios', ['tipo' => 'COMPRA', 'terceiro_id' => 999999, 'preco' => 100], $this->s)->assertStatus(422)->assertJsonPath('mensagem', 'Vendedor não encontrado.');
        $this->getJson('/api/imobiliario/negocios/simular?preco=40000000&vpt=0&finalidade=HABITACAO&isencao=METADE&data_escritura=2026-05-01', $this->s)->assertOk()
            ->assertJsonPath('dados.base', '40000000.00')->assertJsonPath('dados.ip_valor', '400000.00')->assertJsonPath('dados.selo_valor', '120000.00')->assertJsonPath('dados.total_impostos', '522000.00');
    }

    // ---------------------------------------------------------------- isolamento, permissões, base de dados

    #[Test]
    public function isolamento_por_empresa_e_403_antes_de_422_em_todas_as_rotas_novas(): void
    {
        $im = $this->imovel('Prédio A');
        $n = $this->negocio(['tipo' => 'COMPRA', 'imovel_id' => $im, 'terceiro_id' => $this->cliente, 'preco' => 1000000]);
        $outra = $this->criarEmpresa(['nif' => '5417000777']);
        $so = $this->sessao(array_merge($this->perms, self::PERMS_NEGOCIOS), $outra);
        $this->assertSame([], $this->getJson('/api/imobiliario/negocios', $so)->assertOk()->json('dados'));
        $id = $n['id'];
        foreach ([['get', ''], ['put', ''], ['delete', ''], ['put', '/custos'], ['post', '/aprovar'], ['post', '/avancar'], ['post', '/cancelar'], ['post', '/contratos'], ['post', '/concluir'],
            ['post', '/abater'], ['post', '/pagamentos'], ['post', '/pagamentos/1/anular']] as [$metodo, $sufixo]) {
            $corpo = ['/custos' => ['linhas' => []], '/avancar' => ['fase' => 'ESCRITURA'], '/contratos' => ['destino' => 'MANTEM'], '/pagamentos' => ['tipo' => 'SINAL', 'valor' => 1]][$sufixo] ?? [];
            ($metodo === 'get' ? $this->getJson("/api/imobiliario/negocios/{$id}{$sufixo}", $so) : $this->{$metodo.'Json'}("/api/imobiliario/negocios/{$id}{$sufixo}", $corpo, $so))->assertNotFound();
        }
        $leitor = $this->sessao(['imo_negocios_view']);
        $semNada = $this->sessao(['dashboard_view']);
        foreach ([['post', '/api/imobiliario/negocios'], ['put', "/api/imobiliario/negocios/{$id}"], ['delete', "/api/imobiliario/negocios/{$id}"], ['put', "/api/imobiliario/negocios/{$id}/custos"],
            ['post', "/api/imobiliario/negocios/{$id}/aprovar"], ['post', "/api/imobiliario/negocios/{$id}/avancar"], ['post', "/api/imobiliario/negocios/{$id}/cancelar"],
            ['post', "/api/imobiliario/negocios/{$id}/contratos"], ['post', "/api/imobiliario/negocios/{$id}/concluir"], ['post', "/api/imobiliario/negocios/{$id}/abater"],
            ['post', "/api/imobiliario/negocios/{$id}/pagamentos"], ['post', "/api/imobiliario/negocios/{$id}/pagamentos/1/anular"]] as [$metodo, $url]) {
            $this->{$metodo.'Json'}($url, [], $leitor)->assertForbidden();   // 403 e não 422
        }
        foreach (['/listas', '/resumo', '/avisos', '/simular', '', "/{$id}", '/categorias'] as $s) {
            $this->getJson("/api/imobiliario/negocios{$s}", $semNada)->assertForbidden();
        }
        // os pares de segregação novos existem e nenhum perfil os acumula
        $pares = collect(CatalogoPermissoes::segregacao());
        foreach ([['imo_neg_aprovar', 'imo_neg_pagamento'], ['imo_neg_concluir', 'imo_neg_abater'], ['imo_neg_fase', 'imo_neg_concluir']] as [$a, $b]) {
            $this->assertNotNull($pares->first(fn ($p) => $p['a'] === $a && $p['b'] === $b), "{$a} × {$b}");
        }
    }

    #[Test]
    public function restricoes_da_base_de_dados_e_idempotencia_da_migracao(): void
    {
        $im = $this->imovel('Prédio R');
        $n = $this->negocio(['tipo' => 'COMPRA', 'imovel_id' => $im, 'terceiro_id' => $this->cliente, 'preco' => 1000000]);
        $linha = (array) DB::table('negocios_imobiliarios')->where('id', $n['id'])->first();
        unset($linha['id']);
        $tenta = function (array $troca, string $esperado) use ($linha) {
            try {
                DB::transaction(fn () => DB::table('negocios_imobiliarios')->insert($troca + $linha));
                $this->fail("Devia ter sido recusado: {$esperado}");
            } catch (QueryException $e) {
                $this->assertStringContainsString($esperado, $e->getMessage());
            }
        };
        $tenta([], 'uq_negocios_imo_numero');
        $tenta(['numero' => 'CP 2026/9001'], 'uq_negocios_imo_aberto');   // dois abertos do mesmo tipo no mesmo imóvel
        $tenta(['numero' => 'CP 2026/9002', 'imovel_id' => null, 'estado' => 'ARQUIVADO'], 'ck_negocios_imo_enums');
        $tenta(['numero' => 'CP 2026/9003', 'imovel_id' => null, 'preco' => 0], 'ck_negocios_imo_valores');
        $tenta(['numero' => 'CP 2026/9004', 'imovel_id' => null, 'preco' => 100, 'sinal' => 101], 'ck_negocios_imo_valores');
        $tenta(['numero' => 'CP 2026/9005', 'imovel_id' => null, 'data_promessa' => '2026-03-10', 'data_escritura' => '2026-03-01'], 'ck_negocios_imo_datas');
        $tenta(['numero' => 'VD 2026/9006', 'tipo' => 'VENDA', 'imovel_id' => null, 'fracao_id' => null], 'ck_negocios_imo_alvo');
        $tenta(['numero' => 'CP 2026/9007', 'imovel_id' => null, 'quota' => 1.5], 'ck_negocios_imo_quota');
        foreach (['negocios_imobiliarios', 'custos_negocio_imo', 'pagamentos_negocio_imo', 'historico_negocio_imo'] as $t) {
            $this->assertTrue((bool) DB::selectOne('select relrowsecurity as r from pg_class where relname = ?', [$t])->r, "RLS em {$t}");
        }
        $this->aprovarNeg($n['id']);
        $this->postJson("/api/imobiliario/negocios/{$n['id']}/pagamentos", ['tipo' => 'SALDO', 'valor' => 100, 'chave' => 'k1'], $this->s)->assertCreated();
        try {
            DB::transaction(fn () => DB::table('pagamentos_negocio_imo')->insert(['negocio_id' => $n['id'], 'tipo' => 'SALDO', 'valor' => 1, 'data' => '2026-03-20', 'estado' => 'VALIDO', 'chave' => 'k1', 'empresa_id' => $this->empresa->id]));
            $this->fail('A chave repetida devia ser recusada.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('uq_pag_negocio_chave', $e->getMessage());
        }
        // a migração corre duas vezes sem erro nem duplicados
        $migracao = require base_path('database/migrations/2026_12_20_090000_imobiliario_onda5_compra_venda.php');
        $migracao->up();
        $migracao->up();
        $this->assertTrue(Schema::hasColumn('imoveis', 'data_alienacao'));
        $this->assertTrue(Schema::hasColumn('configuracao_imobiliario', 'conta_mais_valia'));
    }
}
