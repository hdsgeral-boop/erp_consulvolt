<?php

namespace Tests\Feature;

use App\Models\DocumentoTesouraria;
use App\Models\ItemDocumentoTesouraria;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\ReciboVenda;
use App\Models\ReciboVendaRetencao;
use App\Models\Terceiro;
use App\Services\Vendas\ServicoRetencaoFonte;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Retenção na fonte nos recibos de clientes: dados, regras, contabilização (líquido/retenção/bruto), estorno, anulação, Tesouraria. */
final class RetencaoFonteReciboTest extends TestCase
{
    private int $empresa;

    private Terceiro $cliente;

    private Produto $produto;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000411'])->id;
        app(ContextoEmpresa::class)->executarComo($this->empresa, function () {
            foreach ([['311', 'Clientes'], ['3452', 'IVA liquidado'], ['451', 'Depósitos à ordem'], ['611', 'Vendas'], ['319', 'Adiantamentos de clientes']] as [$c, $d]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->cliente = Terceiro::create(['nome' => 'Cliente Retenção', 'nif' => '5000000411', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311']);
            $this->produto = Produto::create(['codigo' => 'P1', 'nome' => 'Serviço', 'preco_unitario' => 1000, 'taxa_imposto' => 14,
                'codigo_conta' => '611', 'conta_iva_liquidado' => '3452', 'movimenta_stock' => false]);
        });
    }

    private function contaImposto(): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa, fn () => PlanoConta::create(['codigo' => '341', 'descricao' => 'Imposto sobre os lucros', 'tipo' => 'M']));
    }

    private function sessao(array $extra = []): array
    {
        $p = ['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fat_contabilizar', 'vendas_recibos', 'vendas_fat_unpost', 'vendas_config', ...$extra];
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($p, true))->id]);
        $u->empresas()->attach($this->empresa);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa];
    }

    /** FT de 1 140,00 contabilizada. */
    private function factura(array $s): int
    {
        $id = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $this->cliente->id, 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 1]]], $s)->assertCreated()->json('dados.id');
        $this->postJson("/api/vendas/documentos/{$id}/contabilizar", [], $s)->assertOk();

        return $id;
    }

    private function recibo(int $ft, array $s, array $extra = [], float $montante = 1140): TestResponse
    {
        return $this->postJson('/api/vendas/recibos', ['cliente_id' => $this->cliente->id, 'data' => $this->hoje, 'codigo_conta' => '451',
            'alocacoes' => [['venda_id' => $ft, 'montante' => $montante]]] + $extra, $s);
    }

    private function linhas(string $numeroLan): array
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa, fn () => LancamentoContabil::query()->where('numero_lan', $numeroLan)
            ->orderBy('id')->get()->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->all());
    }

    private function doc(int $reciboId): ?DocumentoTesouraria
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa, fn () => DocumentoTesouraria::query()->where('recibo_venda_id', $reciboId)->first());
    }

    #[Test]
    public function recibo_sem_retencao_fica_igual_ao_actual(): void
    {
        $s = $this->sessao();
        $r = $this->recibo($this->factura($s), $s, [], 500)->assertCreated()
            ->assertJsonPath('dados.valor_retido', '0.00')->assertJsonPath('dados.valor_liquido', '500.00')->assertJsonPath('dados.retencao', null)->json('dados');
        $this->assertSame(['D 451 500.00', 'C 311 500.00'], $this->linhas($r['numero_lan_contabilizacao']));
        $this->assertSame('500.00', number_format((float) $this->doc($r['id'])->valor_total, 2, '.', ''));
        $this->assertSame(0, app(ContextoEmpresa::class)->executarComo($this->empresa, fn () => ReciboVendaRetencao::query()->count()));
        // retenção a 0 % = sem retenção
        $r2 = $this->recibo($this->factura($s), $s, ['retencao' => ['percentagem' => 0]], 500)->assertCreated()->assertJsonPath('dados.retencao', null)->json('dados');
        $this->assertSame(['D 451 500.00', 'C 311 500.00'], $this->linhas($r2['numero_lan_contabilizacao']));
    }

    #[Test]
    public function recibo_com_retencao_liquida_pelo_bruto_e_lanca_liquido_mais_retencao_contra_bruto(): void
    {
        $this->contaImposto();
        $s = $this->sessao();
        $ft = $this->factura($s);
        $tipo = $this->getJson('/api/vendas/configuracao/retencoes', $s)->assertOk()->json('dados.tipos.0');
        $this->assertSame('IND_SERV', $tipo['codigo']);
        $this->assertSame('6.5000', $tipo['percentagem']);

        $r = $this->recibo($ft, $s, ['retencao' => ['tipo_retencao_fonte_id' => $tipo['id'], 'percentagem' => 6.5, 'base_incidencia' => 1000,
            'referencia_comprovativo' => 'GR-2026/77', 'data_comprovativo' => $this->hoje]])->assertCreated()
            ->assertJsonPath('dados.montante_total', '1140.00')->assertJsonPath('dados.valor_retido', '65.00')->assertJsonPath('dados.valor_liquido', '1075.00')
            ->assertJsonPath('dados.retencao.base_incidencia', '1000.00')->assertJsonPath('dados.retencao.valor_calculado', '65.00')
            ->assertJsonPath('dados.retencao.referencia_comprovativo', 'GR-2026/77')->assertJsonPath('dados.retencao.conta_imposto', '341')
            ->assertJsonPath('dados.retencao.codigo_tipo', 'IND_SERV')->json('dados');
        // facturas saldadas pelo BRUTO
        $this->getJson("/api/vendas/documentos/{$ft}", $s)->assertJsonPath('dados.estado', 'PAGO')->assertJsonPath('dados.valor_pendente', '0.00');
        // D líquido (caixa/banco) + D retenção = C bruto (cliente)
        $linhas = $this->linhas($r['numero_lan_contabilizacao']);
        $this->assertSame(['D 451 1075.00', 'D 341 65.00', 'C 311 1140.00'], $linhas);
        // Tesouraria: o valor que entra na conta é o líquido; as linhas somam-no (C cliente bruto − D retenção)
        $doc = $this->doc($r['id']);
        $this->assertSame('1075.00', number_format((float) $doc->valor_total, 2, '.', ''));
        $this->assertSame('INTEGRADO', $doc->estado);
        $itens = app(ContextoEmpresa::class)->executarComo($this->empresa, fn () => ItemDocumentoTesouraria::query()->where('documento_tesouraria_id', $doc->id)->orderBy('id')->get());
        $this->assertSame(['C 1140.00', 'D 65.00'], $itens->map(fn ($i) => $i->tipo_dc.' '.number_format((float) $i->valor, 2, '.', ''))->all());
        // lista e detalhe trazem a retenção
        $this->getJson('/api/vendas/recibos', $s)->assertJsonPath('dados.0.valor_retido', '65.00')->assertJsonPath('dados.0.valor_liquido', '1075.00')
            ->assertJsonPath('dados.0.retencao.descricao_tipo', 'Imposto Industrial — prestação de serviços');
        $this->getJson("/api/vendas/recibos/{$r['id']}", $s)->assertJsonPath('dados.retencao.percentagem', '6.5000');
    }

    #[Test]
    public function valor_retido_editavel_mas_nunca_acima_da_base_e_percentagem_valida(): void
    {
        $this->contaImposto();
        $s = $this->sessao();
        $ft = $this->factura($s);
        $recusar = fn (array $ret, string $codigo) => $this->recibo($ft, $s, ['retencao' => $ret], 500)->assertStatus(422)->assertJsonPath('codigo', $codigo);
        $recusar(['percentagem' => 6.5, 'base_incidencia' => 500, 'valor_retido' => 600], 'RETENCAO_EXCEDE_BASE');
        $recusar(['percentagem' => 150], 'RETENCAO_PERCENTAGEM_INVALIDA');
        $recusar(['percentagem' => -1], 'RETENCAO_PERCENTAGEM_INVALIDA');
        $recusar(['percentagem' => 6.5, 'base_incidencia' => 600], 'RETENCAO_BASE_INVALIDA');
        $recusar(['percentagem' => 100], 'RETENCAO_EXCEDE_BASE');   // não entraria dinheiro
        $this->assertSame(0, app(ContextoEmpresa::class)->executarComo($this->empresa, fn () => ReciboVenda::query()->count()));   // nada ficou gravado

        // o comprovativo do cliente manda: valor editado (63,40 em vez de 65,00)
        $this->recibo($ft, $s, ['retencao' => ['percentagem' => 6.5, 'base_incidencia' => 1000, 'valor_retido' => 63.4]], 1000)->assertCreated()
            ->assertJsonPath('dados.valor_retido', '63.40')->assertJsonPath('dados.retencao.valor_calculado', '65.00')->assertJsonPath('dados.valor_liquido', '936.60');
        $this->assertSame('0.01', ServicoRetencaoFonte::calcular('0.10', '6.5'));
        $this->assertSame('1.23', ServicoRetencaoFonte::calcular('12.345', '10'));   // 1,2345 → 1,23
    }

    #[Test]
    public function sem_conta_do_imposto_o_recibo_com_retencao_nao_se_contabiliza_e_diz_porque(): void
    {
        $s = $this->sessao();   // o plano da empresa não tem a conta 341
        $ft = $this->factura($s);
        $ret = ['retencao' => ['percentagem' => 6.5]];
        $this->recibo($ft, $s, $ret, 500)->assertStatus(422)->assertJsonPath('codigo', 'RETENCAO_CONTA_EM_FALTA');
        $this->assertSame(0, app(ContextoEmpresa::class)->executarComo($this->empresa, fn () => ReciboVenda::query()->count()));

        $id = $this->recibo($ft, $s, $ret + ['contabilizar' => false], 500)->assertCreated()->assertJsonPath('dados.contabilizado', false)->json('dados.id');
        $this->postJson("/api/vendas/recibos/{$id}/contabilizar", [], $s)->assertStatus(422)->assertJsonPath('codigo', 'RETENCAO_CONTA_EM_FALTA');
        $this->contaImposto();
        $this->postJson("/api/vendas/recibos/{$id}/contabilizar", [], $s)->assertOk()->assertJsonPath('dados.contabilizado', true);
    }

    #[Test]
    public function estorno_e_anulacao_revertem_a_retencao_e_ficam_no_historico(): void
    {
        $this->contaImposto();
        $s = $this->sessao();
        $ft = $this->factura($s);
        $r = $this->recibo($ft, $s, ['retencao' => ['percentagem' => 10]], 1000)->assertCreated()->assertJsonPath('dados.valor_retido', '100.00')->json('dados');
        $this->postJson("/api/vendas/recibos/{$r['id']}/descontabilizar", ['motivo' => 'Correcção do comprovativo'], $s)->assertOk()->assertJsonPath('dados.contabilizado', false);
        // o lançamento original tem o estorno com as linhas invertidas (D líquido/D retenção/C bruto → C/C/D)
        $estorno = app(ContextoEmpresa::class)->executarComo($this->empresa, fn () => LancamentoContabil::query()->where('descricao', 'like', "ESTORNO de {$r['numero_lan_contabilizacao']}%")->orderBy('id')
            ->get()->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->all());
        $this->assertSame(['C 451 900.00', 'C 341 100.00', 'D 311 1000.00'], $estorno);
        $this->assertSame('PENDENTE', $this->doc($r['id'])->estado);
        $this->assertSame('900.00', number_format((float) $this->doc($r['id'])->valor_total, 2, '.', ''));

        $this->postJson("/api/vendas/recibos/{$r['id']}/anular", ['motivo' => 'Recibo emitido por engano'], $s)->assertOk()->assertJsonPath('dados.estado', 'ANULADO')
            ->assertJsonPath('dados.retencao.valor_retido', '100.00');   // fica no histórico
        $this->getJson("/api/vendas/documentos/{$ft}", $s)->assertJsonPath('dados.valor_pendente', '1140.00');
        $this->assertSame('ANULADO', $this->doc($r['id'])->estado);
        $this->postJson("/api/vendas/recibos/{$r['id']}/contabilizar", [], $s)->assertStatus(422)->assertJsonPath('codigo', 'DOCUMENTO_ANULADO');
    }

    #[Test]
    public function adiantamento_com_retencao(): void
    {
        $this->contaImposto();
        $s = $this->sessao();
        $this->putJson('/api/vendas/configuracao/contas', ['contas' => ['adiantamentos_clientes' => '319']], $s)->assertOk();
        $r = $this->postJson('/api/vendas/recibos', ['tipo_recibo' => 'ADIANTAMENTO', 'cliente_id' => $this->cliente->id, 'data' => $this->hoje, 'codigo_conta' => '451',
            'montante' => 2000, 'retencao' => ['percentagem' => 6.5, 'base_incidencia' => 2000]], $s)->assertCreated()
            ->assertJsonPath('dados.valor_retido', '130.00')->assertJsonPath('dados.valor_liquido', '1870.00')->assertJsonPath('dados.saldo_adiantamento', '2000.00')->json('dados');
        $this->assertSame(['D 451 1870.00', 'D 341 130.00', 'C 319 2000.00'], $this->linhas($r['numero_lan_contabilizacao']));
        $this->assertSame('1870.00', number_format((float) $this->doc($r['id'])->valor_total, 2, '.', ''));
    }

    #[Test]
    public function catalogo_configuravel_permissao_e_isolamento_por_empresa(): void
    {
        $s = $this->sessao();
        $this->getJson('/api/vendas/configuracao/retencoes', $s)->assertOk()->assertJsonPath('dados.conta_sugerida', '341')->assertJsonCount(1, 'dados.tipos');
        $novo = $this->postJson('/api/vendas/configuracao/retencoes', ['codigo' => 'iva', 'descricao' => 'Retenção de IVA', 'percentagem' => 0], $s)->assertCreated()
            ->assertJsonPath('dados.codigo', 'IVA')->json('dados');
        $this->postJson('/api/vendas/configuracao/retencoes', ['codigo' => 'IVA', 'descricao' => 'Repetido', 'percentagem' => 5], $s)->assertStatus(422)->assertJsonPath('codigo', 'RETENCAO_TIPO_DUPLICADO');
        $this->postJson('/api/vendas/configuracao/retencoes', ['codigo' => 'X', 'descricao' => 'Inválido', 'percentagem' => 101], $s)->assertStatus(422)->assertJsonPath('codigo', 'RETENCAO_PERCENTAGEM_INVALIDA');
        $this->postJson('/api/vendas/configuracao/retencoes', ['codigo' => 'Y', 'descricao' => 'Conta inexistente', 'percentagem' => 5, 'conta_imposto' => '9999'], $s)->assertStatus(422);
        $this->putJson("/api/vendas/configuracao/retencoes/{$novo['id']}", ['codigo' => 'IVA', 'descricao' => 'Retenção de IVA 7 %', 'percentagem' => 7, 'ativo' => false], $s)->assertOk()
            ->assertJsonPath('dados.percentagem', '7.0000');
        $this->getJson('/api/vendas/configuracao/retencoes?so_activos=1', $s)->assertJsonCount(1, 'dados.tipos');

        // sem a permissão de configuração: só leitura
        $leitor = $this->sessao();
        $semConfig = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'vendas_faturacao_view' => true, 'vendas_recibos' => true])->id]);
        $semConfig->empresas()->attach($this->empresa);
        $t = $this->entrar($semConfig) + ['X-Empresa-Id' => $this->empresa];
        $this->getJson('/api/vendas/configuracao/retencoes', $t)->assertOk();
        $this->postJson('/api/vendas/configuracao/retencoes', ['codigo' => 'Z', 'descricao' => 'Z', 'percentagem' => 1], $t)->assertForbidden();
        $this->deleteJson("/api/vendas/configuracao/retencoes/{$novo['id']}", [], $t)->assertForbidden();
        unset($leitor);

        // outra empresa: não vê os tipos desta nem pode usar o id de um tipo alheio no recibo
        $outra = $this->criarEmpresa(['nif' => '5417000412'])->id;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'vendas_faturacao_view' => true, 'vendas_recibos' => true, 'vendas_config' => true])->id]);
        $u->empresas()->attach($outra);
        $h = $this->entrar($u) + ['X-Empresa-Id' => $outra];
        $this->getJson('/api/vendas/configuracao/retencoes', $h)->assertOk()->assertJsonCount(1, 'dados.tipos')->assertJsonPath('dados.tipos.0.codigo', 'IND_SERV');
        $this->assertNotSame($novo['id'], $this->getJson('/api/vendas/configuracao/retencoes', $h)->json('dados.tipos.0.id'));
        $this->putJson("/api/vendas/configuracao/retencoes/{$novo['id']}", ['codigo' => 'IVA', 'descricao' => 'x', 'percentagem' => 1], $h)->assertNotFound();
        $this->postJson('/api/vendas/recibos', ['cliente_id' => $this->cliente->id, 'data' => $this->hoje, 'codigo_conta' => '451', 'tipo_recibo' => 'ADIANTAMENTO', 'montante' => 10,
            'retencao' => ['tipo_retencao_fonte_id' => $novo['id'], 'percentagem' => 5]], $h)->assertStatus(422);
    }
}
