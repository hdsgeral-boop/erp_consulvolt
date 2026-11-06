<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Services\Logistica\ServicoStock;
use App\Services\Vendas\ServicoConfigVendas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F6 do POS (Q17): NC sobre factura-recibo de sessão aberta, fechada por integrar e integrada (estorno na transitória), com números
 * calculados à mão. P1: 1 140 Kz com IVA 14 % (base 1 000), custo 600; fundo 5 000. Meios: numerário (489) e TPA (488).
 */
final class POSNcSessaoTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000081']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['311' => 'Clientes', '3452' => 'IVA liquidado', '611' => 'Vendas', '2611' => 'Mercadorias', '7111' => 'CMV', '489' => 'Transitória numerário',
                '4511' => 'Caixa', '43101' => 'Banco', '488' => 'Transitória TPA', '6881' => 'Sobras', '7881' => 'Quebras', '3791' => 'Operadores'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            app(ServicoConfigVendas::class)->definir(['clientes_default' => '311']);
            $this->ids['p'] = Produto::create(['codigo' => 'P1', 'nome' => 'Produto Um', 'preco_unitario' => 1140, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => true, 'conta_inventario' => '2611', 'conta_custo' => '7111', 'e_servico' => false])->id;
            $this->ids['a'] = Armazem::create(['nome' => 'Loja', 'predefinido' => true])->id;
            app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['a'], '10', '600', now()->toDateString(), 'Stock inicial');
        });
        $this->s = $this->sessao(['pos_view', 'pos_venda', 'pos_fecho', 'pos_terminais_gerir', 'pos_movimento_caixa', 'pos_integrar', 'pos_descontabilizar']);
        $this->putJson('/api/pos/definicoes', ['conta_sobra' => '6881', 'conta_quebra' => '7881', 'conta_operador' => '3791', 'tolerancia_desvio' => 5], $this->s)->assertOk();
        $this->ids['t'] = $this->postJson('/api/pos/terminais', ['codigo' => 'T01', 'nome' => 'Loja 1', 'armazem_id' => $this->ids['a'], 'fundo_maneio_padrao' => 5000,
            'meios_pagamento' => [['id' => 'pm_num', 'tipo' => 'NUMERARIO', 'nome' => 'Numerário', 'conta_transitoria' => '489', 'conta_liquidacao' => '4511'],
                ['id' => 'pm_tpa', 'tipo' => 'TPA', 'nome' => 'Multicaixa', 'conta_transitoria' => '488', 'conta_liquidacao' => '43101']]], $this->s)
            ->assertCreated()->json('dados.id');
        $this->ids['sessao'] = $this->postJson("/api/pos/terminais/{$this->ids['t']}/sessoes", [], $this->s)->assertCreated()->json('dados.id');
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function sid(): int
    {
        return $this->ids['sessao'];
    }

    /** FR de 1 140: 640 em numerário e 500 por TPA (ou tudo em numerário). */
    private function vender(bool $misto = true): int
    {
        $pag = $misto ? [['meio_id' => 'pm_num', 'valor' => 640], ['meio_id' => 'pm_tpa', 'valor' => 500]] : [['meio_id' => 'pm_num', 'valor' => 1140]];

        return $this->postJson("/api/pos/sessoes/{$this->sid()}/vendas", ['linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 1]], 'pagamentos' => $pag], $this->s)
            ->assertCreated()->json('dados.id');
    }

    private function nc(int $venda, array $http): TestResponse
    {
        $cliente = $this->getJson("/api/pos/sessoes/{$this->sid()}/vendas-consulta", $this->s)->json('dados.0.cliente_id');

        return $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'NC', 'cliente_id' => $cliente, 'venda_origem_id' => $venda, 'motivo_nota_credito' => 'Devolução',
            'data_emissao' => now()->toDateString(), 'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 1]]], $http);
    }

    private function saldo(string $conta): string
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () use ($conta) {
            $l = LancamentoContabil::query()->where('codigo_conta', $conta)->get();
            $soma = fn ($dc) => $l->where('tipo_dc', $dc)->reduce(fn ($c, $x) => bcadd($c, (string) $x->valor, 2), '0.00');

            return bcsub($soma('D'), $soma('C'), 2);
        });
    }

    /** Diferença D − C de todos os lançamentos (têm de estar equilibrados). */
    private function desequilibrio(): string
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => bcsub((string) LancamentoContabil::query()->where('tipo_dc', 'D')->sum('valor'),
            (string) LancamentoContabil::query()->where('tipo_dc', 'C')->sum('valor'), 2));
    }

    #[Test]
    public function nc_em_sessao_aberta_abate_os_totais_e_estorna_a_transitoria_na_integracao(): void
    {
        $v1 = $this->vender();
        $this->vender(false);
        $vendas = $this->sessao(['vendas_fat_emitir', 'vendas_faturacao_view']);
        $this->nc($v1, $vendas)->assertCreated();
        // totais líquidos: vendas 2 280 − 1 140; numerário 1 780 − 640 = 1 140; TPA 500 − 500 = 0; esperado 5 000 + 1 140
        $x = $this->getJson("/api/pos/sessoes/{$this->sid()}/relatorio-x", $this->s)->assertOk()->json('dados');
        $this->assertSame(['1140.00', '1140.00', '6140.00'], [$x['total_vendas'], $x['vendas_numerario'], $x['numerario_esperado']]);
        $this->assertSame('0.00', collect($x['totais_por_metodo'])->firstWhere('tipo', 'TPA')['valor']);

        $this->postJson("/api/pos/sessoes/{$this->sid()}/fechar", ['numerario_contado' => 6140, 'fechos_tpa' => [['meio_id' => 'pm_tpa', 'valor_talao' => 0]]], $this->s)
            ->assertOk()->assertJsonPath('dados.desvio', '0.00');
        $this->postJson("/api/pos/sessoes/{$this->sid()}/contabilizar", [], $this->s)->assertOk();
        // numerário: D 1 780 − C 640 = 1 140; TPA: D 500 − C 500 = 0; tudo equilibrado
        $this->assertSame(['1140.00', '0.00', '0.00'], [$this->saldo('489'), $this->saldo('488'), $this->desequilibrio()]);
        $g = $this->sessao(['pos_prestacao_view', 'pos_prestar', 'pos_relatorios_view']);
        $itens = collect($this->getJson("/api/pos/sessoes/{$this->sid()}/prestacao", $g)->assertOk()->json('dados.itens'))->keyBy('chave_item');
        $this->assertSame('1140.00', $itens['NUM:pm_num']['valor']);
        $this->assertArrayNotHasKey('TPA:pm_tpa', $itens->all());

        // reintegrar não duplica: estorna tudo e integra de novo; mesmos saldos
        $this->postJson("/api/pos/sessoes/{$this->sid()}/descontabilizar", ['motivo' => 'Repetir'], $this->s)->assertOk();
        $this->assertSame(['0.00', '0.00'], [$this->saldo('489'), $this->saldo('488')]);
        $this->postJson("/api/pos/sessoes/{$this->sid()}/contabilizar", [], $this->s)->assertOk();
        $this->assertSame(['1140.00', '0.00', '0.00'], [$this->saldo('489'), $this->saldo('488'), $this->desequilibrio()]);
    }

    #[Test]
    public function nc_em_sessao_integrada_exige_permissao_estorna_e_ajusta_o_z_e_a_prestacao(): void
    {
        $v1 = $this->vender();
        $this->vender(false);
        $this->postJson("/api/pos/sessoes/{$this->sid()}/fechar", ['numerario_contado' => 6780, 'fechos_tpa' => [['meio_id' => 'pm_tpa', 'valor_talao' => 500]]], $this->s)->assertOk();
        $this->postJson("/api/pos/sessoes/{$this->sid()}/contabilizar", [], $this->s)->assertOk();
        $this->assertSame(['1780.00', '500.00'], [$this->saldo('489'), $this->saldo('488')]);

        $semPermissao = $this->sessao(['vendas_fat_emitir', 'vendas_faturacao_view']);
        $this->nc($v1, $semPermissao)->assertStatus(422)->assertJsonPath('codigo', 'NC_SESSAO_INTEGRADA');
        $com = $this->sessao(['vendas_fat_emitir', 'vendas_faturacao_view', 'pos_nc_estornar']);
        $this->nc($v1, $com)->assertCreated();

        // estorno: D 311 1 140 / C 489 640 / C 488 500 → transitórias 1 140 e 0
        $this->assertSame(['1140.00', '0.00', '0.00'], [$this->saldo('489'), $this->saldo('488'), $this->desequilibrio()]);
        $g = $this->sessao(['pos_prestacao_view', 'pos_prestar', 'pos_relatorios_view']);
        $itens = collect($this->getJson("/api/pos/sessoes/{$this->sid()}/prestacao", $g)->assertOk()->json('dados.itens'))->keyBy('chave_item');
        $this->assertSame('1140.00', $itens['NUM:pm_num']['valor']);
        $this->assertArrayNotHasKey('TPA:pm_tpa', $itens->all());

        // descontabilizar estorna as vendas e o estorno da NC; reintegrar repõe os mesmos saldos sem duplicar
        $this->postJson("/api/pos/sessoes/{$this->sid()}/descontabilizar", ['motivo' => 'Repetir'], $this->s)->assertOk();
        $this->assertSame(['0.00', '0.00'], [$this->saldo('489'), $this->saldo('488')]);
        $this->postJson("/api/pos/sessoes/{$this->sid()}/contabilizar", [], $this->s)->assertOk();
        $this->assertSame(['1140.00', '0.00', '0.00'], [$this->saldo('489'), $this->saldo('488'), $this->desequilibrio()]);
    }

    #[Test]
    public function fecho_sem_talao_do_tpa_exige_confirmacao_e_justificacao_e_fica_registado(): void
    {
        $this->vender();
        $url = "/api/pos/sessoes/{$this->sid()}/fechar";
        $this->postJson($url, ['numerario_contado' => 5640], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'FECHO_TPA_EM_FALTA')->assertJsonPath('erros.permite_sem_talao', true);
        $this->postJson($url, ['numerario_contado' => 5640, 'confirmar_sem_talao' => true], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'JUSTIFICACAO_OBRIGATORIA');
        $r = $this->postJson($url, ['numerario_contado' => 5640, 'confirmar_sem_talao' => true, 'justificacao' => 'Terminal sem papel'], $this->s)->assertOk();
        $this->assertSame([true, null, '500.00'], [$r->json('dados.fechos_tpa.0.sem_talao'), $r->json('dados.fechos_tpa.0.valor_talao'), $r->json('dados.fechos_tpa.0.valor_sistema')]);
        $this->postJson("/api/pos/sessoes/{$this->sid()}/contabilizar", [], $this->s)->assertOk();
        $this->assertSame(['640.00', '500.00', '0.00'], [$this->saldo('489'), $this->saldo('488'), $this->desequilibrio()]);
        $g = $this->sessao(['pos_prestacao_view', 'pos_prestar', 'pos_relatorios_view']);
        $itens = collect($this->getJson("/api/pos/sessoes/{$this->sid()}/prestacao", $g)->assertOk()->json('dados.itens'))->keyBy('chave_item');
        $this->assertSame([null, '500.00'], [$itens['TPA:pm_tpa']['diferenca'], $itens['TPA:pm_tpa']['valor']]);
    }

    #[Test]
    public function pendentes_notificacoes_e_kpis_do_pos_respeitam_as_permissoes(): void
    {
        $this->vender();
        $gestor = $this->sessao(['pos_view', 'pos_desvios_view', 'pos_integracao_view', 'pos_prestacao_view', 'vendas_faturacao_view', 'pos_relatorios_view', 'dashboard_view']);
        $pend = fn (array $h) => collect($this->getJson('/api/gestao/inicio', $h)->assertOk()->json('dados.pendentes'))->pluck('quantidade', 'id')->all();
        $kpi = fn (array $h, string $id) => collect($this->getJson('/api/gestao/paineis/vendas?actualizar=1', $h)->assertOk()->json('dados.kpis'))->firstWhere('id', $id)['valor'] ?? 'AUSENTE';

        $this->assertArrayNotHasKey('pos_sessoes_antigas', $pend($gestor));   // aberta hoje
        $this->assertSame(['1140.00', 1], [$kpi($gestor, 'pos_vendas_hoje'), $kpi($gestor, 'pos_sessoes_abertas')]);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => \DB::table('sessoes_pos')->where('id', $this->sid())->update(['aberto_em' => now()->subDay()]));
        $this->assertSame(1, $pend($gestor)['pos_sessoes_antigas']);

        // sem permissões do POS não há pendentes nem KPIs do POS
        $vendasSo = $this->sessao(['vendas_faturacao_view', 'dashboard_view']);
        $this->assertSame([], array_filter(array_keys($pend($vendasSo)), fn ($k) => str_starts_with($k, 'pos_')));
        $this->assertSame('AUSENTE', $kpi($vendasSo, 'pos_vendas_hoje'));

        // desvio de −640 (acima da tolerância de 5): por deliberar e por integrar
        $this->postJson("/api/pos/sessoes/{$this->sid()}/fechar", ['numerario_contado' => 5000, 'fechos_tpa' => [['meio_id' => 'pm_tpa', 'valor_talao' => 500]], 'justificacao' => 'Falta'], $this->s)->assertOk();
        $p = $pend($gestor);
        $this->assertSame([1, 1], [$p['pos_desvios'], $p['pos_por_integrar']]);
        $this->assertSame([1, 1, 0], [$kpi($gestor, 'pos_desvios'), $kpi($gestor, 'pos_por_integrar'), $kpi($gestor, 'pos_sessoes_abertas')]);
        $this->postJson("/api/pos/sessoes/{$this->sid()}/contabilizar", [], $this->s)->assertOk();
        $p = $pend($gestor);
        $this->assertSame([1, 1], [$p['pos_por_liquidar'], $p['pos_desvios']]);
        $this->assertArrayNotHasKey('pos_por_integrar', $p);

        // o sino de notificações mostra os alertas do POS (alerta operacional) com a ligação ao ecrã
        $n = collect($this->getJson('/api/sistema/notificacoes', $gestor)->assertOk()->json('dados.notificacoes'))->firstWhere('titulo', '1 Desvios de caixa por deliberar');
        $this->assertSame(['vendas', 'URGENTE', '/m/pos/pos_desvios'], [$n['modulo'], $n['tipo'], $n['link']]);
    }

    #[Test]
    public function iva_em_falta_na_integracao_lista_os_artigos_e_grava_a_conta_so_nos_que_nao_a_tem(): void
    {
        $p2 = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Produto::create(['codigo' => 'S2', 'nome' => 'Serviço Dois', 'preco_unitario' => 114,
            'taxa_imposto' => 14, 'codigo_conta' => '611', 'e_servico' => true])->id);
        $this->postJson("/api/pos/sessoes/{$this->sid()}/vendas", ['linhas' => [['produto_id' => $p2, 'quantidade' => 1]], 'pagamentos' => [['meio_id' => 'pm_num', 'valor' => 114]]], $this->s)->assertCreated();
        $this->postJson("/api/pos/sessoes/{$this->sid()}/fechar", ['numerario_contado' => 5114], $this->s)->assertOk();

        // pré-visualização: impedimento com a lista de artigos; integração recusada com o mesmo código
        $pre = $this->postJson('/api/pos/integracao/pre-visualizacao', ['ids' => [$this->sid()]], $this->s)->assertOk()->json('dados.sessoes.0');
        $this->assertSame('IVA_EM_FALTA', $pre['impedimentos'][0]['codigo']);
        $this->assertSame([[$p2, 'S2', 1]], array_map(fn ($a) => [$a['id'], $a['codigo'], $a['linhas']], $pre['iva_em_falta']));
        $this->postJson("/api/pos/sessoes/{$this->sid()}/contabilizar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IVA_EM_FALTA');

        // validações: conta que não é de IVA / inexistente; sem permissão
        $this->postJson('/api/pos/integracao/iva-em-falta', ['conta_iva' => '611', 'produto_ids' => [$p2]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_IVA_INVALIDA');
        $this->postJson('/api/pos/integracao/iva-em-falta', ['conta_iva' => '34999', 'produto_ids' => [$p2]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_INEXISTENTE');
        $this->postJson('/api/pos/integracao/iva-em-falta', ['conta_iva' => '3452', 'produto_ids' => [$p2]], $this->sessao(['pos_view']))->assertForbidden();

        // grava só no artigo sem conta (o P1 já tinha 3452 e não é tocado) e a integração passa
        $this->postJson('/api/pos/integracao/iva-em-falta', ['conta_iva' => '3452', 'produto_ids' => [$p2, $this->ids['p']]], $this->s)->assertOk()->assertJsonPath('dados.artigos_actualizados', 1);
        $this->assertSame('3452', app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Produto::find($p2)->conta_iva_liquidado));
        $this->postJson("/api/pos/sessoes/{$this->sid()}/contabilizar", [], $this->s)->assertOk();
        $this->assertSame(['-14.00', '114.00', '0.00'], [$this->saldo('3452'), $this->saldo('489'), $this->desequilibrio()]);
    }
}
