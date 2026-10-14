<?php

namespace Tests\Feature;

use App\Models\DocumentoTesouraria;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\ReciboVenda;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase A — factura paga: cancelar os recibos e os recebimentos de tesouraria antes da nota de crédito
 * (js/shared/anulacao_documentos.js: exigirRecebimentoCancelado / cancelarRecebimentos). Dados fictícios.
 */
final class FaVendasRecebimentosNcTest extends TestCase
{
    private Empresa $empresa;

    private Terceiro $cliente;

    private Produto $produto;

    private array $s;

    private string $hoje;

    private const PERMISSOES = ['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fat_del', 'vendas_fat_contabilizar', 'vendas_fat_descontab',
        'vendas_recibos', 'vendas_fat_unpost', 'teso_gestao_pagamentos_view', 'teso_doc_emitir', 'teso_doc_eliminar', 'teso_integrar', 'teso_desintegrar'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000097']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach ([['3111', 'Clientes'], ['3452', 'IVA liquidado'], ['4311', 'Banco fictício'], ['611', 'Vendas']] as [$c, $d]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->cliente = Terceiro::create(['nome' => 'Cliente Fictício', 'nif' => '5000000021', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '3111']);
            $this->produto = Produto::create(['codigo' => 'PY', 'nome' => 'Produto Y', 'preco_unitario' => 1000, 'taxa_imposto' => 14,
                'codigo_conta' => '611', 'conta_iva_liquidado' => '3452', 'movimenta_stock' => false]);
        });
        $this->s = $this->sessao(self::PERMISSOES);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id, 'nome_utilizador' => 'operador'.count($permissoes).random_int(100, 999)]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    /** FT de 2 280,00 (2 × 1 000 + 14 %), contabilizada. */
    private function factura(string $tipo = 'FT'): int
    {
        $id = $this->postJson('/api/vendas/documentos', ['tipo_documento' => $tipo, 'cliente_id' => $this->cliente->id, 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 2]]] + ($tipo === 'FR' ? ['conta_disponibilidade' => '4311'] : []), $this->s)
            ->assertCreated()->json('dados.id');
        $this->postJson("/api/vendas/documentos/{$id}/contabilizar", [], $this->s)->assertOk();

        return $id;
    }

    private function recibo(array $alocacoes): TestResponse
    {
        return $this->postJson('/api/vendas/recibos', ['cliente_id' => $this->cliente->id, 'data' => $this->hoje, 'codigo_conta' => '4311',
            'meio_pagamento' => 'TRANSFERENCIA', 'alocacoes' => $alocacoes], $this->s)->assertCreated();
    }

    private function converterNc(int $ft): TestResponse
    {
        return $this->postJson("/api/vendas/documentos/{$ft}/converter", ['tipo_destino' => 'NC', 'motivo_nota_credito' => 'Devolução fictícia'], $this->s);
    }

    #[Test]
    public function cancela_recibo_e_recebimento_de_tesouraria_com_estorno_e_depois_emite_a_nc(): void
    {
        $ft = $this->factura();
        $recibo = $this->recibo([['venda_id' => $ft, 'montante' => '1000']])->json('dados');
        $doc = $this->postJson('/api/tesouraria/documentos', ['tipo' => 'RECEBIMENTO', 'data_documento' => $this->hoje, 'conta_financeira' => '4311',
            'descricao' => 'Recebimento fictício', 'referencia' => 'TRF-FICT', 'linhas' => [['codigo_conta' => '3111', 'tipo_dc' => 'C', 'valor' => '500',
                'terceiro_id' => $this->cliente->id, 'venda_id' => $ft]]], $this->s)->assertCreated()->json('dados.id');
        $this->postJson("/api/tesouraria/documentos/{$doc}/integrar", [], $this->s)->assertOk();
        $this->getJson("/api/vendas/documentos/{$ft}", $this->s)->assertJsonPath('dados.valor_pago', '1500.00')->assertJsonPath('dados.estado', 'PARCIAL');

        // a NC continua recusada, mas o erro diz que se pode cancelar o recebimento
        $this->converterNc($ft)->assertStatus(422)->assertJsonPath('codigo', 'NC_FATURA_PAGA')
            ->assertJsonPath('erros.venda_id', $ft)->assertJsonPath('erros.cancelar_recebimentos', true);

        $this->getJson("/api/vendas/documentos/{$ft}/recebimentos", $this->s)->assertOk()
            ->assertJsonPath('dados.pode_cancelar', true)->assertJsonPath('dados.com_estorno', true)->assertJsonPath('dados.bloqueios', [])
            ->assertJsonPath('dados.recibos.0.numero', $recibo['numero_recibo'])->assertJsonPath('dados.recibos.0.montante_factura', '1000.00')
            ->assertJsonPath('dados.tesouraria.0.integrado', true)->assertJsonPath('dados.tesouraria.0.valor_factura', '500.00')
            ->assertJsonPath('dados.permissoes_em_falta', []);

        // motivo obrigatório (mín. 5); sem permissão de desintegrar a tesouraria → 403 e nada muda
        $this->postJson("/api/vendas/documentos/{$ft}/cancelar-recebimentos", ['motivo' => 'abc'], $this->s)->assertStatus(422);
        $semDesintegrar = $this->sessao(array_values(array_diff(self::PERMISSOES, ['teso_desintegrar'])));
        $this->postJson("/api/vendas/documentos/{$ft}/cancelar-recebimentos", ['motivo' => 'Cliente devolveu a mercadoria'], $semDesintegrar)->assertForbidden();
        $this->getJson("/api/vendas/documentos/{$ft}", $this->s)->assertJsonPath('dados.valor_pago', '1500.00');

        $this->postJson("/api/vendas/documentos/{$ft}/cancelar-recebimentos", ['motivo' => 'Cliente devolveu a mercadoria'], $this->s)->assertOk()
            ->assertJsonPath('dados.recibos.0', $recibo['numero_recibo'])->assertJsonPath('dados.tesouraria.0', 'REC A'.substr($this->hoje, 0, 4).'/1')
            ->assertJsonPath('dados.venda.estado', 'PENDENTE')->assertJsonPath('dados.venda.valor_pago', '0.00');

        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () use ($recibo, $doc) {
            $r = ReciboVenda::query()->findOrFail($recibo['id']);
            $this->assertSame('ANULADO', $r->estado);
            $this->assertFalse($r->contabilizado);
            $this->assertNotNull($r->getAttribute('anulado_por'));
            $this->assertStringContainsString('Cliente devolveu a mercadoria', $r->motivo_anulacao);
            $d = DocumentoTesouraria::query()->findOrFail($doc);
            $this->assertSame('ANULADO', $d->estado);
            $this->assertNotNull($d->anulado_por);
            // estorno: as linhas do recibo e da tesouraria ficaram marcadas como estornadas
            // (2 linhas do recibo + 2 do documento de tesouraria; a factura continua contabilizada)
            $this->assertSame(4, LancamentoContabil::query()->whereNotNull('estornado_por_id')->count());
        });

        $this->converterNc($ft)->assertCreated()->assertJsonPath('dados.tipo_documento', 'NC');
    }

    #[Test]
    public function recibo_com_varias_facturas_mostra_as_outras_que_voltam_a_pendentes(): void
    {
        $a = $this->factura();
        $b = $this->factura();
        $this->recibo([['venda_id' => $a, 'montante' => '2280'], ['venda_id' => $b, 'montante' => '100']]);
        $numeroB = $this->getJson("/api/vendas/documentos/{$b}", $this->s)->json('dados.numero_documento');
        $this->getJson("/api/vendas/documentos/{$a}/recebimentos", $this->s)->assertJsonPath('dados.recibos.0.outras_facturas', [$numeroB])
            ->assertJsonPath('dados.com_estorno', true);
        $this->postJson("/api/vendas/documentos/{$a}/cancelar-recebimentos", ['motivo' => 'Recebimento lançado por engano'], $this->s)->assertOk();
        $this->getJson("/api/vendas/documentos/{$b}", $this->s)->assertJsonPath('dados.valor_pago', '0.00')->assertJsonPath('dados.estado', 'PENDENTE');
    }

    #[Test]
    public function nota_de_credito_sobre_factura_recibo_credita_o_cliente_sem_cancelar_recebimentos(): void
    {
        $fr = $this->factura('FR');
        $nc = $this->converterNc($fr)->assertCreated()->json('dados.id');

        $this->getJson("/api/vendas/documentos/{$fr}", $this->s)->assertJsonPath('dados.estado', 'PAGO')->assertJsonPath('dados.valor_pendente', '0.00');
        $this->postJson("/api/vendas/documentos/{$nc}/contabilizar", [], $this->s)->assertOk();
        $credito = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()
            ->where('codigo_conta', '3111')->where('tipo_dc', 'C')->sum('valor'));
        $this->assertSame(2280.0, (float) $credito, 'a NC sobre a FR deixa o cliente com saldo a favor (devolução pela tesouraria)');
        // a FR já foi convertida: uma segunda conversão é recusada
        $this->converterNc($fr)->assertStatus(422)->assertJsonPath('codigo', 'JA_CONVERTIDO');
    }

    #[Test]
    public function factura_recibo_e_factura_sem_recebimentos_sao_recusadas_com_explicacao(): void
    {
        $fr = $this->factura('FR');
        $p = $this->getJson("/api/vendas/documentos/{$fr}/recebimentos", $this->s)->assertOk()->assertJsonPath('dados.pode_cancelar', false);
        $this->assertNotEmpty($p->json('dados.bloqueios'));
        $this->postJson("/api/vendas/documentos/{$fr}/cancelar-recebimentos", ['motivo' => 'Teste de recusa'], $this->s)->assertStatus(422)
            ->assertJsonPath('codigo', 'RECEBIMENTO_NAO_CANCELAVEL');

        $ft = $this->factura();
        $this->getJson("/api/vendas/documentos/{$ft}/recebimentos", $this->s)->assertJsonPath('dados.tem_recebimentos', false);
        $this->postJson("/api/vendas/documentos/{$ft}/cancelar-recebimentos", ['motivo' => 'Teste de recusa'], $this->s)->assertStatus(422)
            ->assertJsonPath('codigo', 'SEM_RECEBIMENTOS');
    }

    #[Test]
    public function autorizacao_antes_da_validacao_e_isolamento_por_empresa(): void
    {
        $ft = $this->factura();
        $this->recibo([['venda_id' => $ft, 'montante' => '100']]);
        // sem vendas_fat_del: 403 mesmo sem motivo
        $this->postJson("/api/vendas/documentos/{$ft}/cancelar-recebimentos", [], $this->sessao(['vendas_faturacao_view', 'vendas_recibos']))->assertForbidden();
        // sem descontabilizar recibos: a pré-visualização diz o que falta
        $this->getJson("/api/vendas/documentos/{$ft}/recebimentos", $this->sessao(['vendas_faturacao_view', 'vendas_fat_del', 'vendas_recibos']))
            ->assertJsonPath('dados.permissoes_em_falta', ['descontabilizar recibos']);

        $outra = $this->criarEmpresa(['nif' => '5417000096']);
        $s2 = $this->sessao(self::PERMISSOES, $outra);
        $this->getJson("/api/vendas/documentos/{$ft}/recebimentos", $s2)->assertNotFound();
        $this->postJson("/api/vendas/documentos/{$ft}/cancelar-recebimentos", ['motivo' => 'Tentativa de outra empresa'], $s2)->assertNotFound();
    }
}
