<?php

namespace Tests\Feature;

use App\Models\ContaCRM;
use App\Models\LancamentoContabil;
use App\Models\LogAuditoria;
use App\Models\NotaFluxoCaixa;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\TaxaCambio;
use App\Models\Terceiro;
use App\Services\Vendas\CalculadoraDocumento;
use App\Services\Vendas\ServicoDocumentosVenda;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Vendas — ronda 2 da paridade (R2-G3): decisões 8 (preço livre), 9 (câmbio manual), 10 (arredondamento do POS),
 * 11 (preços do SAF-T), 12 (FT sem linhas), 13 (nota de fluxo do recibo); M-06 (contabilizar em lote) e M-18
 * (recibo de adiantamento e factura a partir de várias guias).
 */
final class R2VendasTest extends TestCase
{
    private int $empresa;

    private Terceiro $cliente;

    private Produto $produto;

    private Produto $servico;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000300'])->id;
        app(ContextoEmpresa::class)->executarComo($this->empresa, function () {
            foreach ([['311', 'Clientes'], ['3452', 'IVA liquidado'], ['451', 'Depósitos à ordem'], ['611', 'Vendas'], ['621', 'Serviços'], ['319', 'Adiantamentos de clientes']] as [$c, $d]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->cliente = Terceiro::create(['nome' => 'Cliente R2', 'nif' => '5000000301', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311']);
            $this->produto = Produto::create(['codigo' => 'P1', 'nome' => 'Produto 1', 'preco_unitario' => 1000, 'taxa_imposto' => 14,
                'codigo_conta' => '611', 'conta_iva_liquidado' => '3452', 'movimenta_stock' => false]);
            $this->servico = Produto::create(['codigo' => 'S1', 'nome' => 'Serviço', 'preco_unitario' => 500, 'taxa_imposto' => 14,
                'codigo_conta' => '621', 'conta_iva_liquidado' => '3452', 'movimenta_stock' => false]);
        });
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa];
    }

    private function base(): array
    {
        return ['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fat_contabilizar', 'vendas_fat_descontab', 'vendas_recibos', 'vendas_fat_unpost', 'vendas_config'];
    }

    private function emitir(array $dados, array $s): TestResponse
    {
        return $this->postJson('/api/vendas/documentos', $dados + [
            'tipo_documento' => 'FT', 'cliente_id' => $this->cliente->id, 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 1]],
        ], $s);
    }

    private function linhas(string $numeroLan): array
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa, fn () => LancamentoContabil::query()->where('numero_lan', $numeroLan)
            ->orderBy('id')->get()->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}".($l->nota_fluxo_caixa_id ? " nf{$l->nota_fluxo_caixa_id}" : ''))->all());
    }

    #[Test]
    public function preco_diferente_da_ficha_exige_permissao_e_fica_na_auditoria(): void
    {
        $s = $this->sessao($this->base());
        // sem preço ou com o preço da ficha: aceite sem a permissão nova
        $this->emitir([], $s)->assertCreated()->assertJsonPath('dados.total_liquido', '1000.00');
        $this->emitir(['linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 1, 'preco_unitario' => 1000]]], $s)->assertCreated();
        // preço alterado: recusado sem vendas_alterar_preco
        $this->emitir(['linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 1, 'preco_unitario' => 900]]], $s)
            ->assertStatus(403)->assertJsonPath('codigo', 'PRECO_ALTERADO_SEM_PERMISSAO')->assertJsonPath('erros.preco_ficha', '1000.00');

        $comPermissao = $this->sessao([...$this->base(), 'vendas_alterar_preco']);
        $id = $this->emitir(['linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 1, 'preco_unitario' => 900]]], $comPermissao)
            ->assertCreated()->assertJsonPath('dados.total_liquido', '900.00')->json('dados.id');
        $log = app(ContextoEmpresa::class)->semIsolamento(fn () => LogAuditoria::query()->where('acao', 'Preço alterado')->where('registo_id', (string) $id)->first());
        $this->assertNotNull($log);
        $this->assertSame(['P1' => '1000.00'], $log->dados_anteriores['precos_ficha']);
        $this->assertSame(['P1' => '900.00'], $log->dados_novos['precos_documento']);

        // conversão e NC usam os preços de origem: não exigem a permissão
        $ft = $id;
        $this->postJson("/api/vendas/documentos/{$ft}/converter", ['tipo_destino' => 'NC', 'motivo_nota_credito' => 'Devolução'], $s)->assertCreated()
            ->assertJsonPath('dados.total_liquido', '900.00');
    }

    #[Test]
    public function cambio_manual_dentro_da_tolerancia_e_aceite_e_acima_exige_permissao(): void
    {
        TaxaCambio::create(['empresa_id' => null, 'codigo_moeda' => 'USD', 'data_taxa' => $this->hoje, 'taxa' => '900']);
        $s = $this->sessao([...$this->base(), 'vendas_alterar_preco']);
        $linhas = [['produto_id' => $this->produto->id, 'quantidade' => 1, 'preco_unitario' => 10]];

        $this->getJson('/api/sistema/cambios-manuais/tolerancia', $s)->assertOk()->assertJsonPath('dados.tolerancia_pct', '5')->assertJsonPath('dados.pode_exceder', false);
        $this->emitir(['codigo_moeda' => 'USD', 'taxa_cambio' => 940, 'linhas' => $linhas], $s)->assertCreated()->assertJsonPath('dados.total_liquido', '9400.00');
        $this->emitir(['codigo_moeda' => 'USD', 'taxa_cambio' => 1000, 'linhas' => $linhas], $s)->assertStatus(422)
            ->assertJsonPath('codigo', 'CAMBIO_FORA_TOLERANCIA')->assertJsonPath('erros.referencia', '900')->assertJsonPath('erros.desvio_pct', '11.11');
        $this->postJson('/api/sistema/cambios-manuais/validar', ['codigo_moeda' => 'USD', 'data' => $this->hoje, 'taxa_cambio' => 1000], $s)->assertOk()
            ->assertJsonPath('dados.fora_tolerancia', true)->assertJsonPath('dados.pode_exceder', false);

        // a tolerância é configurável por quem gere moedas
        $this->putJson('/api/sistema/cambios-manuais/tolerancia', ['tolerancia_pct' => 12], $s)->assertForbidden();
        $gestor = $this->sessao(['config_moedas_view', 'config_moedas_gerir']);
        $this->putJson('/api/sistema/cambios-manuais/tolerancia', ['tolerancia_pct' => 120], $gestor)->assertStatus(422);
        $this->putJson('/api/sistema/cambios-manuais/tolerancia', ['tolerancia_pct' => 12], $gestor)->assertOk()->assertJsonPath('dados.tolerancia_pct', '12');
        $this->emitir(['codigo_moeda' => 'USD', 'taxa_cambio' => 1000, 'linhas' => $linhas], $s)->assertCreated();
        $this->putJson('/api/sistema/cambios-manuais/tolerancia', ['tolerancia_pct' => 5], $gestor)->assertOk();

        // com a permissão: aceite e registado na auditoria
        $autorizado = $this->sessao([...$this->base(), 'vendas_alterar_preco', 'cambio_manual_fora_tolerancia']);
        $this->emitir(['codigo_moeda' => 'USD', 'taxa_cambio' => 1000, 'linhas' => $linhas], $autorizado)->assertCreated();
        $this->assertTrue(app(ContextoEmpresa::class)->semIsolamento(fn () => LogAuditoria::query()->where('acao', 'Câmbio manual fora da tolerância')->exists()));
    }

    #[Test]
    public function pos_separa_desconto_comercial_do_arredondamento_agt(): void
    {
        // 3 × 10,01 com IVA 14 %, sem desconto: bruto 30,03; o total do documento sai do motor AGT (base e IVA por excesso)
        $linhas = [['quantidade' => '3', 'preco_unitario' => '10.01', 'taxa_imposto' => '14']];
        $calc = CalculadoraDocumento::calcularComIva($linhas, 0);
        $r = ServicoDocumentosVenda::descontoEArredondamentoPos($linhas, '0', $calc['total_bruto']);
        $this->assertSame('0.00', $r['desconto']);
        $this->assertSame(bcsub('30.03', $calc['total_bruto'], 2), $r['arredondamento_agt']);

        // 2 × 1 140 com 10 %: desconto 228,00 e arredondamento 0
        $linhas = [['quantidade' => '2', 'preco_unitario' => '1140', 'taxa_imposto' => '14']];
        $calc = CalculadoraDocumento::calcularComIva($linhas, 10);
        $this->assertSame(['desconto' => '228.00', 'arredondamento_agt' => '0.00'], ServicoDocumentosVenda::descontoEArredondamentoPos($linhas, '10', $calc['total_bruto']));
    }

    #[Test]
    public function precos_fiscais_do_pos_com_seis_casas_e_desconto_da_linha(): void
    {
        // fora do POS: qtd × preço = valor → 2 casas, sem desconto
        $this->assertSame(['preco_base' => '1000.00', 'preco' => '1000.00', 'desconto' => '0.00'], CalculadoraDocumento::precosLinhaFiscal('2500.00', '2.5', '1000', null));
        // POS: 3 unidades, valor 26,34 (base com desconto de 10 %): preço 8,780000 e desconto da linha
        $r = CalculadoraDocumento::precosLinhaFiscal('26.34', '3', '8.78', '10');
        $this->assertSame('8.780000', $r['preco']);
        $this->assertSame('9.755556', $r['preco_base']);
        $this->assertSame('2.93', $r['desconto']);
        // linha migrada com qtd × preço ≠ valor: preço = valor ÷ quantidade
        $this->assertSame('10.003333', CalculadoraDocumento::precosLinhaFiscal('30.01', '3', '10', null)['preco']);
    }

    #[Test]
    public function recibo_leva_a_nota_de_fluxo_dos_recebimentos_de_clientes(): void
    {
        $nota = app(ContextoEmpresa::class)->executarComo($this->empresa, function () {
            NotaFluxoCaixa::create(['codigo' => '12', 'descricao' => 'Outros recebimentos']);

            return NotaFluxoCaixa::create(['codigo' => '111', 'descricao' => 'Recebimentos de clientes'])->id;
        });
        $s = $this->sessao($this->base());
        $ft = $this->emitir([], $s)->json('dados.id');
        $this->postJson("/api/vendas/documentos/{$ft}/contabilizar", [], $s)->assertOk();
        $lan = $this->postJson('/api/vendas/recibos', ['cliente_id' => $this->cliente->id, 'data' => $this->hoje, 'codigo_conta' => '451',
            'alocacoes' => [['venda_id' => $ft, 'montante' => 500]]], $s)->assertCreated()->json('dados.numero_lan_contabilizacao');
        $this->assertSame(['D 451 500.00', "C 311 500.00 nf{$nota}"], $this->linhas($lan));
    }

    #[Test]
    public function recibo_de_adiantamento_e_alocacao_posterior_a_facturas(): void
    {
        $s = $this->sessao($this->base());
        $adiantamento = fn () => $this->postJson('/api/vendas/recibos', ['tipo_recibo' => 'ADIANTAMENTO', 'cliente_id' => $this->cliente->id, 'data' => $this->hoje,
            'codigo_conta' => '451', 'montante' => 2000, 'observacoes' => 'Sinal da obra'], $s);
        $adiantamento()->assertStatus(422)->assertJsonPath('codigo', 'CONFIG_VENDAS_EM_FALTA');
        $this->putJson('/api/vendas/configuracao/contas', ['contas' => ['adiantamentos_clientes' => '319']], $s)->assertOk();
        $this->postJson('/api/vendas/recibos', ['tipo_recibo' => 'ADIANTAMENTO', 'cliente_id' => $this->cliente->id, 'data' => $this->hoje, 'codigo_conta' => '451',
            'montante' => 100, 'alocacoes' => [['venda_id' => 1, 'montante' => 1]]], $s)->assertStatus(422);   // adiantamento sem facturas

        $r = $adiantamento()->assertCreated()->assertJsonPath('dados.tipo_recibo', 'ADIANTAMENTO')->assertJsonPath('dados.saldo_adiantamento', '2000.00')->json('dados');
        $this->assertSame(['D 451 2000.00', 'C 319 2000.00'], $this->linhas($r['numero_lan_contabilizacao']));

        $ft = $this->emitir([], $s)->json('dados.id');   // 1 140,00
        $this->postJson("/api/vendas/documentos/{$ft}/contabilizar", [], $s)->assertOk();
        $this->postJson("/api/vendas/recibos/{$r['id']}/alocar", ['alocacoes' => [['venda_id' => $ft, 'montante' => 1500]]], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'MONTANTE_INVALIDO');
        $a = $this->postJson("/api/vendas/recibos/{$r['id']}/alocar", ['alocacoes' => [['venda_id' => $ft, 'montante' => 1140]]], $s)->assertOk()
            ->assertJsonPath('dados.saldo_adiantamento', '860.00')->json('dados');
        $this->assertSame(['D 319 1140.00', 'C 311 1140.00'], $this->linhas($a['alocacoes'][0]['numero_lan']));
        $this->getJson("/api/vendas/documentos/{$ft}", $s)->assertJsonPath('dados.estado', 'PAGO')->assertJsonPath('dados.valor_pendente', '0.00');

        $ft2 = $this->emitir([], $s)->json('dados.id');
        $this->postJson("/api/vendas/documentos/{$ft2}/contabilizar", [], $s)->assertOk();
        $this->postJson("/api/vendas/recibos/{$r['id']}/alocar", ['alocacoes' => [['venda_id' => $ft2, 'montante' => 900]]], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'ADIANTAMENTO_SALDO_INSUFICIENTE');
        $this->postJson("/api/vendas/recibos/{$r['id']}/descontabilizar", ['motivo' => 'Teste de bloqueio'], $s)->assertStatus(422)->assertJsonPath('codigo', 'ADIANTAMENTO_ALOCADO');
    }

    #[Test]
    public function factura_a_partir_de_varias_guias_do_mesmo_cliente(): void
    {
        $s = $this->sessao($this->base());
        $gr1 = $this->emitir(['tipo_documento' => 'GR', 'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 2]]], $s)->assertCreated()->json('dados.id');
        $gr2 = $this->emitir(['tipo_documento' => 'GR', 'linhas' => [['produto_id' => $this->servico->id, 'quantidade' => 1], ['produto_id' => $this->produto->id, 'quantidade' => 1]]], $s)->json('dados.id');
        $outro = app(ContextoEmpresa::class)->executarComo($this->empresa, fn () => Terceiro::create(['nome' => 'Outro', 'nif' => '5000000302', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id);
        $gr3 = $this->emitir(['tipo_documento' => 'GR', 'cliente_id' => $outro], $s)->json('dados.id');

        $this->postJson('/api/vendas/documentos/faturar-guias', ['guias' => [$gr1, $gr3]], $s)->assertStatus(422)->assertJsonPath('codigo', 'GUIAS_CLIENTES_DIFERENTES');
        $ft = $this->postJson('/api/vendas/documentos/faturar-guias', ['guias' => [$gr1, $gr2]], $s)->assertCreated()
            ->assertJsonPath('dados.tipo_documento', 'FT')->assertJsonPath('dados.total_liquido', '3500.00')->assertJsonCount(3, 'dados.linhas')
            ->assertJsonCount(2, 'dados.documentos_relacionados')->json('dados.id');
        $this->getJson("/api/vendas/documentos/{$gr1}", $s)->assertJsonPath('dados.estado', 'CONCLUIDO')->assertJsonPath('dados.linhas.0.quantidade_faturada', '2.000');
        $this->getJson("/api/vendas/documentos/{$gr2}", $s)->assertJsonPath('dados.estado', 'CONCLUIDO');
        $this->postJson('/api/vendas/documentos/faturar-guias', ['guias' => [$gr1]], $s)->assertStatus(422)->assertJsonPath('codigo', 'JA_CONVERTIDO');
        $this->assertNotNull($ft);
    }

    #[Test]
    public function contabiliza_e_descontabiliza_em_lote_cada_documento_na_sua_transaccao(): void
    {
        $s = $this->sessao($this->base());
        $ft1 = $this->emitir([], $s)->json('dados.id');
        $ft2 = $this->emitir([], $s)->json('dados.id');
        $or = $this->emitir(['tipo_documento' => 'OR'], $s)->json('dados.id');
        $r = $this->postJson('/api/vendas/documentos/contabilizar', ['ids' => [$ft1, $or, $ft2]], $s)->assertOk()
            ->assertJsonPath('dados.ok', 2)->assertJsonPath('dados.erros', 1)->json('dados.resultados');
        $this->assertSame([true, false, true], array_column($r, 'sucesso'));
        $this->assertSame('NAO_CONTABILIZAVEL', $r[1]['codigo']);

        $this->postJson('/api/vendas/documentos/descontabilizar', ['ids' => [$ft1]], $s)->assertStatus(422);   // motivo obrigatório
        $this->postJson('/api/vendas/documentos/descontabilizar', ['ids' => [$ft1, $ft2], 'motivo' => 'Correcção em lote'], $s)->assertOk()->assertJsonPath('dados.ok', 2);
        $this->postJson('/api/vendas/documentos/contabilizar', ['ids' => [$ft1]], $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir']))->assertForbidden();
    }

    #[Test]
    public function crm_filtra_contas_com_facturas_em_atraso(): void
    {
        $s = $this->sessao($this->base());
        $ft = $this->emitir([], $s)->json('dados.id');
        $outro = app(ContextoEmpresa::class)->executarComo($this->empresa, function () use ($ft) {
            DB::table('vendas')->where('id', $ft)->update(['data_vencimento' => now()->subDays(5)->toDateString()]);
            $c = Terceiro::create(['nome' => 'Sem dívida', 'nif' => '5000000309', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311']);
            ContaCRM::create(['nome' => 'Conta com atraso', 'tipo' => 'CLIENTE', 'terceiro_id' => $this->cliente->id]);

            return ContaCRM::create(['nome' => 'Conta em dia', 'tipo' => 'CLIENTE', 'terceiro_id' => $c->id])->id;
        });
        $crm = $this->sessao(['crm_contas_view']);
        $this->getJson('/api/crm/contas', $crm)->assertOk()->assertJsonCount(2, 'dados');
        $this->getJson('/api/crm/contas?em_atraso=1', $crm)->assertOk()->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.nome', 'Conta com atraso');
        $this->assertGreaterThan(0, $outro);
    }

    #[Test]
    public function validacao_lista_documentos_fiscais_sem_linhas(): void
    {
        $id = $this->emitir([], $this->sessao($this->base()))->json('dados.id');
        DB::table('itens_venda')->where('venda_id', $id)->delete();
        $this->getJson('/api/sistema/validacoes/vendas_fiscais_sem_linhas', $this->sessao(['config_manutencao_view']))->assertOk()
            ->assertJsonCount(1, 'dados.linhas')->assertJsonPath('dados.linhas.0.id', $id);
    }
}
