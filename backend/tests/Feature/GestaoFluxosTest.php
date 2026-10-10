<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\DiarioContabil;
use App\Models\DocumentoTesouraria;
use App\Models\Empresa;
use App\Models\FaturaCompra;
use App\Models\InfotipoSalarial;
use App\Models\ItemDocumentoTesouraria;
use App\Models\LancamentoContabil;
use App\Models\LinhaExtratoBancario;
use App\Models\LinhaFolhaSalarial;
use App\Models\MovimentoCaixa;
use App\Models\OrcamentoAnual;
use App\Models\PedidoCompra;
use App\Models\PedidoLavandaria;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\PlanoFeriasColaborador;
use App\Models\SessaoCaixa;
use App\Models\Terceiro;
use App\Models\TipoOrganizacaoRH;
use App\Models\Venda;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fluxo de Processos (ADR-060, js/fluxo_*.js).
 *
 * Vendas: A = OR 1 → NE 1 → FT 1 (1 140, contabilizada; recebidos 500 por crédito na conta do cliente com o n.º da factura);
 * B = OR 2 por aceitar; C = FT 3 de cliente sem conta (sem conta por omissão) por contabilizar; D = FR 4 do mesmo cliente por
 * contabilizar (a FR não lança na conta do cliente: não bloqueia); E = FT 5 (100) contabilizada e compensada.
 * Compras: pedido pendente no nível «Direcção»; FC 9 paga por débito na conta do fornecedor; FC 10 com pagamento pendente na
 * Tesouraria. Bancos 2025: Julho sem extracto, Agosto reconciliado, Setembro por conciliar. Salários: 03/2025 aberto e
 * 05/2025 validado sem mapeamentos (04/2025 em falta).
 */
final class GestaoFluxosTest extends TestCase
{
    private Empresa $empresa;

    private array $h;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->h = $this->sessao(['fluxo_processos_view', 'vendas_faturacao_view', 'compras_pedidos_view', 'teso_gestao_conciliacao_view', 'calcular_view']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => $this->cenario());
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function cenario(): void
    {
        $od = DiarioContabil::create(['codigo' => 'OD', 'descricao' => 'Diversos', 'nome' => 'Diversos'])->id;
        $l = fn (string $data, string $conta, string $dc, $valor, array $extra = []) => LancamentoContabil::create($extra + ['diario_id' => $od, 'data_documento' => $data,
            'codigo_conta' => $conta, 'tipo_dc' => $dc, 'valor' => $valor]);
        $c1 = Terceiro::create(['nif' => '5417000011', 'nome' => 'Cliente com conta', 'tipo' => 'CLIENTE', 'codigo_conta' => '311'])->id;
        $c2 = Terceiro::create(['nif' => '5417000012', 'nome' => 'Cliente sem conta', 'tipo' => 'CLIENTE'])->id;
        $v = fn (string $tipo, string $num, string $data, $bruto, int $cliente, array $extra = []) => Venda::create($extra + ['tipo_documento' => $tipo, 'numero_documento' => $num,
            'data_emissao' => "{$data} 10:00:00", 'total_liquido' => round($bruto / 1.14, 2), 'total_imposto' => round($bruto - $bruto / 1.14, 2), 'total_bruto' => $bruto, 'cliente_id' => $cliente]);
        $or1 = $v('OR', 'OR 1', '2025-06-01', 1140, $c1);
        $ne1 = $v('NE', 'NE 1', '2025-06-02', 1140, $c1, ['estado' => 'CONCLUIDO']);
        $ft1 = $v('FT', 'FT 1', '2025-06-03', 1140, $c1, ['contabilizado' => true, 'estado' => 'PENDENTE']);
        DB::table('vendas_documentos_relacionados')->insert([['empresa_id' => $this->empresa->id, 'venda_id' => $ne1->id, 'venda_relacionada_id' => $or1->id],
            ['empresa_id' => $this->empresa->id, 'venda_id' => $ft1->id, 'venda_relacionada_id' => $ne1->id]]);
        $l('2025-06-03', '311', 'D', 1140, ['numero_documento' => 'FT 1', 'terceiro_id' => $c1, 'numero_lan' => 'VD2025000001']);
        $l('2025-06-20', '311', 'C', 500, ['numero_documento' => 'FT 1', 'terceiro_id' => $c1, 'numero_lan' => 'RC2025000001']);
        $this->ids['a'] = $or1->id;
        $v('OR', 'OR 2', '2025-06-10', 570, $c1);
        $v('FT', 'FT 3', '2025-06-11', 228, $c2, ['contabilizado' => false]);
        $v('FR', 'FR 4', '2025-06-12', 114, $c2, ['contabilizado' => false, 'estado' => 'PAGO']);
        $v('FT', 'FT 5', '2025-06-13', 100, $c1, ['contabilizado' => true]);
        $l('2025-06-13', '311', 'D', 100, ['numero_documento' => 'FT 5', 'terceiro_id' => $c1, 'reconciliacao_codigo' => 'C1']);

        $forn = Terceiro::create(['nif' => '5417000013', 'nome' => 'Fornecedor', 'tipo' => 'FORNECEDOR', 'codigo_conta' => '3211'])->id;
        PedidoCompra::create(['numero_pedido' => 'PI 1', 'data' => '2025-06-01', 'estado' => 'PENDENTE', 'nome_requerente' => 'Requerente',
            'deliberacao' => ['valor' => 5000, 'etapas' => [['ordem' => 1, 'nome' => 'Direcção', 'estado' => 'PENDENTE', 'nome_aprovador' => 'Administração']]]]);
        FaturaCompra::create(['numero_fatura' => 'FC 9', 'data' => '2025-06-05', 'fornecedor_id' => $forn, 'montante_total' => 1000, 'total_imposto' => 0, 'estado' => 'PENDENTE',
            'contabilizado' => true, 'numero_lan_contabilizacao' => 'FF2025000001']);
        $l('2025-06-05', '75', 'D', 1000, ['numero_documento' => 'FC 9', 'numero_lan' => 'FF2025000001']);
        $l('2025-06-05', '3211', 'C', 1000, ['numero_documento' => 'FC 9', 'numero_lan' => 'FF2025000001', 'terceiro_id' => $forn]);
        $l('2025-06-25', '3211', 'D', 1000, ['numero_documento' => 'FC 9', 'numero_lan' => 'PG2025000001', 'terceiro_id' => $forn]);
        FaturaCompra::create(['numero_fatura' => 'FC 10', 'data' => '2025-06-06', 'fornecedor_id' => $forn, 'montante_total' => 1500, 'total_imposto' => 0, 'estado' => 'PENDENTE',
            'contabilizado' => true, 'numero_lan_contabilizacao' => 'FF2025000002']);
        $l('2025-06-06', '75', 'D', 1500, ['numero_documento' => 'FC 10', 'numero_lan' => 'FF2025000002']);
        $l('2025-06-06', '3211', 'C', 1500, ['numero_documento' => 'FC 10', 'numero_lan' => 'FF2025000002', 'terceiro_id' => $forn]);
        $doc = DocumentoTesouraria::create(['tipo' => 'PAGAMENTO', 'data_documento' => '2025-06-30', 'conta_financeira' => '43101', 'valor_total' => 1500, 'estado' => 'PENDENTE', 'referencia' => 'PAG-7']);
        ItemDocumentoTesouraria::create(['documento_tesouraria_id' => $doc->id, 'codigo_conta' => '3211', 'tipo_dc' => 'D', 'valor' => 1500, 'numero_documento' => 'FC 10']);

        $l('2025-07-10', '43101', 'D', 50, ['numero_documento' => 'X1']);
        $l('2025-08-10', '43101', 'D', 100, ['numero_documento' => 'X2', 'reconciliacao_codigo' => 'R1']);
        LinhaExtratoBancario::create(['codigo_conta' => '43101', 'data' => '2025-08-10', 'valor' => 100, 'tipo_dc' => 'D', 'reconciliacao_codigo' => 'R1', 'estado' => 'CONCILIADO']);
        $l('2025-09-10', '43101', 'D', 200, ['numero_documento' => 'X3']);
        LinhaExtratoBancario::create(['codigo_conta' => '43101', 'data' => '2025-09-11', 'valor' => 200, 'tipo_dc' => 'D', 'estado' => 'PENDENTE', 'referencia' => 'TRF 1']);

        $org = TipoOrganizacaoRH::create(['nome' => 'Sede'])->id;
        $colab = Colaborador::create(['nome_completo' => 'Colaborador Um', 'tipo_organizacao_id' => $org, 'estado' => 'ACTIVO'])->id;
        $base = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base'])->id;
        PeriodoProcessamentoSalarial::create(['mes_ano' => '03/2025', 'estado' => 'ABERTO', 'contabilizado' => false]);
        $p5 = PeriodoProcessamentoSalarial::create(['mes_ano' => '05/2025', 'estado' => 'VALIDADO', 'contabilizado' => false])->id;
        LinhaFolhaSalarial::create(['periodo_processamento_salarial_id' => $p5, 'colaborador_id' => $colab, 'infotipo_salarial_id' => $base, 'valor' => 100000]);
    }

    #[Test]
    public function lista_so_os_fluxos_do_modulo_permitido_e_recusa_os_outros(): void
    {
        $limitado = $this->sessao(['fluxo_processos_view', 'vendas_faturacao_view']);
        $r = $this->getJson('/api/gestao/fluxos', $limitado)->assertOk()->assertJsonStructure($this->envelope());
        $this->assertSame(['vendas'], array_column($r->json('dados'), 'id'));
        $this->assertTrue($r->json('dados.0.tem_actividade'));
        $this->assertCount(6, $r->json('dados.0.etapas'));
        $this->getJson('/api/gestao/fluxos/compras', $limitado)->assertForbidden();
        $this->getJson('/api/gestao/fluxos', $this->sessao(['vendas_faturacao_view']))->assertForbidden();
        $this->getJson('/api/gestao/fluxos/inexistente', $this->h)->assertNotFound()->assertJsonPath('codigo', 'FLUXO_INVALIDO');
        $todos = array_column($this->getJson('/api/gestao/fluxos', $this->h)->assertOk()->json('dados'), 'id');
        $this->assertSame(['rh', 'vendas', 'compras', 'bancos'], $todos);
    }

    #[Test]
    public function vendas_funil_por_etapa_com_documentos_parados_e_valores(): void
    {
        $r = $this->getJson('/api/gestao/fluxos/vendas', $this->h)->assertOk();
        $this->assertSame(5, $r->json('dados.total'));
        $funil = collect($r->json('dados.funil'))->keyBy('etapa');
        $this->assertSame(1, $funil['orc']['parados']);
        $this->assertSame(2, $funil['contab']['parados']);
        $this->assertSame(1, $funil['contab']['bloqueados']);
        $this->assertSame(1, $funil['rec']['parados']);
        $this->assertSame('640.00', $funil['rec']['valor_pendente']);
        $this->assertSame(1, $funil['concluidos']['parados']);
        $this->assertSame(['concluida' => 1, 'curso' => 1, 'fazer' => 3, 'bloqueada' => 0], $funil['rec']['estados']);
        $k = collect($r->json('dados.kpis'))->pluck('valor', 'chave');
        $this->assertSame([3, 1, 1, '640.00'], [$k['em_curso'], $k['bloqueados'], $k['concluidos'], $k['por_receber']]);
        $this->assertSame('Processo de venda', $r->json('dados.fluxo.narrativa.titulo'));

        $lista = $this->getJson('/api/gestao/fluxos/vendas/processos', $this->h)->assertOk();
        $this->assertSame('FT 3', $lista->json('dados.0.titulo'));
        $this->assertSame(1, $lista->json('dados.0.n_erros'));
        $this->assertSame(5, $lista->json('metadados.paginacao.total'));
        $contab = $this->getJson('/api/gestao/fluxos/vendas/processos?etapa=contab', $this->h)->assertOk()->json('dados');
        $this->assertEqualsCanonicalizing(['FT 3', 'FR 4'], array_column($contab, 'titulo'));
        $fr = collect($contab)->firstWhere('titulo', 'FR 4');
        $this->assertSame('curso', $fr['etapa_actual_estado']);
        $this->assertFalse($fr['bloqueado']);
        $this->assertSame(['FT 3'], array_column($this->getJson('/api/gestao/fluxos/vendas/processos?estado=bloqueado', $this->h)->json('dados'), 'titulo'));
        $this->assertSame(['FT 5'], array_column($this->getJson('/api/gestao/fluxos/vendas/processos?etapa=concluidos', $this->h)->json('dados'), 'titulo'));
        $this->assertSame(1, $this->getJson('/api/gestao/fluxos/vendas/processos?pesquisa=ft%201&por_pagina=1', $this->h)->json('metadados.paginacao.total'));
        $this->getJson('/api/gestao/fluxos/vendas/processos?etapa=xpto', $this->h)->assertStatus(422);

        $p = $this->getJson('/api/gestao/fluxos/vendas/processos/venda-'.$this->ids['a'], $this->h)->assertOk()->json('dados');
        $this->assertSame('FT 1', $p['titulo']);
        $this->assertSame('rec', $p['etapa_actual']);
        $this->assertSame('Recebido parcialmente', $p['etapas']['rec']['resumo']);
        $this->assertSame('640.00', $p['valor_pendente']);
        $this->assertSame(['Convertido', 'NE 1'], [$p['etapas']['orc']['resumo'], $p['etapas']['enc']['resumo']]);
        $this->assertNotEmpty($p['etapas']['rec']['narrativa']['descricao']);
        $this->assertSame(['OR', 'NE', 'FT'], array_column($p['documentos'], 'tipo_documento'));
        $this->getJson('/api/gestao/fluxos/vendas/processos/venda-999999', $this->h)->assertNotFound();
    }

    #[Test]
    public function compras_deliberacao_pagamento_por_debito_e_pagamento_por_integrar(): void
    {
        $p = collect($this->getJson('/api/gestao/fluxos/compras/processos', $this->h)->assertOk()->json('dados'))->keyBy('titulo');
        $this->assertSame('ped', $p['PI 1']['etapa_actual']);
        $this->assertSame('Aguarda nível 1: Direcção', $p['PI 1']['etapa_actual_resumo']);
        $this->assertNull($p['FC 9']['etapa_actual']);
        $this->assertSame('pag', $p['FC 10']['etapa_actual']);
        $this->assertSame('Pagamento por integrar', $p['FC 10']['etapa_actual_resumo']);
        $this->assertSame('1500.00', $p['FC 10']['valor_pendente']);
        $d = $this->getJson('/api/gestao/fluxos/compras/processos/'.$p['FC 9']['chave'], $this->h)->assertOk()->json('dados');
        $this->assertSame('Liquidado por pagamento', $d['etapas']['pag']['resumo']);
    }

    #[Test]
    public function bancos_mes_a_mes_com_extracto_e_reconciliacao_em_sequencia(): void
    {
        $p = collect($this->getJson('/api/gestao/fluxos/bancos/processos', $this->h)->assertOk()->json('dados'))->keyBy('titulo');
        $this->assertSame('integ', $p['06/2025']['etapa_actual']);
        $this->assertSame('ext', $p['07/2025']['etapa_actual']);
        $this->assertNull($p['08/2025']['etapa_actual']);
        $this->assertSame(1, $p['08/2025']['n_avisos']);
        $this->assertSame('conc', $p['09/2025']['etapa_actual']);
        $this->assertSame('2 por conciliar', $p['09/2025']['etapa_actual_resumo']);
        $k = collect($this->getJson('/api/gestao/fluxos/bancos', $this->h)->json('dados.kpis'))->pluck('valor', 'chave');
        $this->assertSame([1, 2, 3], [$k['contas'], $k['sem_extracto'], $k['por_conciliar']]);
    }

    #[Test]
    public function todos_os_fluxos_respondem_com_a_mesma_forma_e_as_regras_de_cada_modulo(): void
    {
        $ano = (int) now()->format('Y');
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () use ($ano) {
            $s = SessaoCaixa::create(['codigo_conta' => '4511', 'operador' => 'op', 'data_abertura' => '2025-06-01', 'data_fecho' => '2025-06-01', 'saldo_abertura' => 0, 'saldo_fecho' => 100,
                'saldo_fisico' => 100, 'estado' => 'FECHADA', 'codigo_moeda' => 'AOA']);
            MovimentoCaixa::create(['sessao_caixa_id' => $s->id, 'tipo' => 'REC', 'data_documento' => '2025-06-01', 'valor' => 100, 'conta_debito' => '4511', 'contabilizado' => false]);
            PedidoLavandaria::create(['numero_encomenda' => 'OS/9', 'recebido_em' => '2025-06-01 09:00:00', 'data_prometida' => '2025-06-03 18:00:00', 'estado' => 'RECEBIDA',
                'itens' => [['linha_id' => 1, 'estado' => 'RECEBIDA', 'quantidade' => 1, 'preco' => 1000, 'valor' => '1000.00', 'nome' => 'Camisa']], 'extras' => []]);
            $c = Colaborador::query()->value('id');
            PlanoFeriasColaborador::create(['colaborador_id' => $c, 'ano' => $ano, 'data_inicio' => "{$ano}-02-01", 'data_fim' => "{$ano}-03-07", 'dias' => 25, 'direito' => 22, 'estado' => 'PLANEADO']);
            OrcamentoAnual::create(['ano' => $ano, 'tipo' => 'EXPLORACAO', 'nome' => 'Orçamento', 'versao' => 1, 'estado' => 'RASCUNHO']);
        });
        $admin = $this->sessao(['all']);
        $ids = array_column($this->getJson('/api/gestao/fluxos', $admin)->assertOk()->json('dados'), 'id');
        $this->assertCount(14, $ids);
        foreach ($ids as $id) {
            $r = $this->getJson("/api/gestao/fluxos/{$id}", $admin)->assertOk()->assertJsonStructure(['dados' => ['fluxo' => ['id', 'nome', 'etapas', 'narrativa'], 'total', 'kpis', 'funil']]);
            $this->assertCount(count($r->json('dados.fluxo.etapas')) + 1, $r->json('dados.funil'), $id);
            // ícones do legado (separador e cada nó do diagrama)
            $this->assertNotEmpty($r->json('dados.fluxo.icone'), $id);
            $this->assertNotContains(null, array_column($r->json('dados.fluxo.etapas'), 'icone'), $id);
            $this->getJson("/api/gestao/fluxos/{$id}/processos", $admin)->assertOk();
        }
        $caixa = $this->getJson('/api/gestao/fluxos/caixa/processos', $admin)->json('dados.0');
        $this->assertSame(['integ', true, 2], [$caixa['etapa_actual'], $caixa['bloqueado'], $caixa['n_erros']]);
        $lav = $this->getJson('/api/gestao/fluxos/lavandaria/processos', $admin)->json('dados.0');
        $this->assertSame(['exec', true], [$lav['etapa_actual'], $lav['atrasada']]);
        $ferias = collect($this->getJson('/api/gestao/fluxos/ferias/processos?estado=bloqueado', $admin)->json('dados'));
        $this->assertSame('Colaborador Um', $ferias->first()['titulo']);
        $d = $this->getJson('/api/gestao/fluxos/ferias/processos/'.$ferias->first()['chave'], $admin)->json('dados');
        $this->assertSame(['bloqueada', '3 dia(s) acima do direito'], [$d['etapas']['saldo']['estado'], $d['etapas']['saldo']['resumo']]);
        $orc = $this->getJson('/api/gestao/fluxos/orcamento/processos', $admin)->json('dados.0');
        $this->assertSame(['prep', 'Sem valores'], [$orc['etapa_actual'], $orc['etapa_actual_resumo']]);
    }

    /**
     * Vista de volume do legado (fluxo_tabela.js) nos quatro fluxos em tabela: indicadores, funil «Por etapa actual» com as
     * colunas de cada fluxo, filtros rápidos (com contagens, filtrados no servidor por lista branca) e colunas da tabela.
     * Os fluxos com cartões não têm vista nem filtros rápidos.
     */
    #[Test]
    public function fluxos_em_tabela_devolvem_a_vista_do_legado_e_filtram_pelos_chips(): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $item = fn (string $estado, array $extra = []) => $extra + ['linha_id' => 1, 'estado' => $estado, 'quantidade' => 1, 'preco' => 1000, 'valor' => '1000.00', 'nome' => 'Camisa'];
            PedidoLavandaria::create(['numero_encomenda' => 'OS/1', 'recebido_em' => '2025-06-01 09:00:00', 'data_prometida' => '2025-06-03 18:00:00', 'estado' => 'RECEBIDA',
                'itens' => [$item('RECEBIDA')], 'extras' => [], 'urgente' => true]);
            PedidoLavandaria::create(['numero_encomenda' => 'OS/2', 'recebido_em' => '2025-06-02 09:00:00', 'data_prometida' => now()->addDays(5)->toDateString().' 18:00:00', 'estado' => 'PRONTA',
                'itens' => [$item('PRONTA', ['pronta_em' => now()->subDays(40)->toIso8601String()])], 'extras' => []]);
            $s = SessaoCaixa::create(['codigo_conta' => '4511', 'operador' => 'Ana Lúcia', 'data_abertura' => '2025-06-01', 'data_fecho' => '2025-06-01', 'saldo_abertura' => 0, 'saldo_fecho' => 100,
                'saldo_fisico' => 90, 'estado' => 'FECHADA', 'codigo_moeda' => 'AOA']);
            MovimentoCaixa::create(['sessao_caixa_id' => $s->id, 'tipo' => 'REC', 'data_documento' => '2025-06-01', 'valor' => 100, 'conta_debito' => '4511', 'conta_credito' => '62', 'contabilizado' => false]);
        });
        $admin = $this->sessao(['all']);

        $lav = $this->getJson('/api/gestao/fluxos/lavandaria', $admin)->assertOk()->json('dados');
        $this->assertSame(['Ordens em curso', 'Atrasadas', 'Prontas por levantar', 'Fora do prazo de levantamento', 'Valor por receber'], array_column($lav['kpis'], 'rotulo'));
        $k = collect($lav['kpis'])->keyBy('chave');
        $this->assertSame([1, true], [$k['fora_prazo']['valor'], $k['fora_prazo']['alerta']]);
        $v = $lav['vista'];
        $this->assertSame(['Aguardam orçamento', 'Em execução', 'Por entregar', 'Por facturar ou contabilizar', 'Por receber', 'Concluídas'], array_column($v['funil'], 'rotulo'));
        $funil = collect($v['funil'])->keyBy('id');
        $this->assertSame([1, '1000.00', 1], [$funil['exec']['parados'], $funil['exec']['valor'], $funil['exec']['com_pendencias']]);
        $this->assertSame(1, $funil['ent']['parados']);
        $this->assertNull($funil['concluidos']['valor']);
        $this->assertSame(['Por concluir', 'Com pendências', 'Atrasadas', 'Por levantar', 'Fora do prazo de levantamento', 'Com valor por receber', 'Todas'], array_column($v['filtros'], 'rotulo'));
        $this->assertSame([2, 2, 1, 1, 1, 0, 2], array_column($v['filtros'], 'total'));
        $this->assertSame('curso', $v['filtro_inicial']);
        $this->assertSame(['Ordem', 'Cliente', 'Recebida', 'Prazo', 'Etapa actual', 'Progresso', 'Pendências', 'Valor', 'Por receber'], array_column($v['colunas'], 'rotulo'));
        $this->assertSame([25, 'ordens'], [$v['por_pagina'], $v['nome_itens']]);
        $fora = $this->getJson('/api/gestao/fluxos/lavandaria/processos?filtro=fora_prazo', $admin)->assertOk()->json('dados');
        $this->assertSame(['OS/2'], array_column($fora, 'titulo'));
        $this->assertSame([true, false, true], [$fora[0]['fora_prazo'], $fora[0]['atrasada'], $fora[0]['por_levantar']]);
        $this->assertSame(['OS/1'], array_column($this->getJson('/api/gestao/fluxos/lavandaria/processos?filtro=atrasadas', $admin)->json('dados'), 'titulo'));

        $b = $this->getJson('/api/gestao/fluxos/bancos', $admin)->assertOk()->json('dados.vista');
        $this->assertSame(['Por integrar', 'Extracto por carregar', 'Por reconciliar', 'Reconciliados'], array_column($b['funil'], 'rotulo'));
        $this->assertSame([1, 1, 1, 1], array_column($b['funil'], 'parados'));
        $this->assertSame(['Por concluir', 'Documentos por integrar', 'Sem extracto', 'Movimentos por conciliar', 'Todos'], array_column($b['filtros'], 'rotulo'));
        $this->assertSame([3, 1, 2, 2, 4], array_column($b['filtros'], 'total'));
        $this->assertSame(['Mês', 'Conta', 'Etapa actual', 'Progresso', 'Pendências', 'Documentos', 'Por conciliar', 'Variação do mês'], array_column($b['colunas'], 'rotulo'));
        $sem = $this->getJson('/api/gestao/fluxos/bancos/processos?filtro=extracto', $admin)->assertOk()->json('dados');
        $this->assertEqualsCanonicalizing(['06/2025', '07/2025'], array_column($sem, 'titulo'));
        $junho = collect($sem)->firstWhere('titulo', '06/2025');
        $this->assertSame([1, 1, '43101'], [$junho['n_docs'], $junho['por_integrar'], $junho['conta']]);
        // filtro + etapa: os concluídos só aparecem em «Todos»
        $this->assertSame([], $this->getJson('/api/gestao/fluxos/bancos/processos?filtro=curso&etapa=concluidos', $admin)->json('dados'));
        $this->assertSame(['08/2025'], array_column($this->getJson('/api/gestao/fluxos/bancos/processos?filtro=todas&etapa=concluidos', $admin)->json('dados'), 'titulo'));

        $c = $this->getJson('/api/gestao/fluxos/caixa', $admin)->assertOk()->json('dados.vista');
        $this->assertSame(['Caixas abertas', 'Diferença por regularizar', 'Por contabilizar', 'Contabilizadas'], array_column($c['funil'], 'rotulo'));
        $this->assertSame(['Por concluir', 'Com pendências', 'Abertas', 'Com diferença', 'Por contabilizar', 'Todas'], array_column($c['filtros'], 'rotulo'));
        $this->assertSame(['Abertura', 'Conta', 'Operador', 'Etapa actual', 'Progresso', 'Pendências', 'Entradas', 'Saídas', 'Diferença'], array_column($c['colunas'], 'rotulo'));
        $sessao = $this->getJson('/api/gestao/fluxos/caixa/processos?filtro=diferenca', $admin)->assertOk()->json('dados.0');
        $this->assertSame(['dif', '-10.00', '100.00', '0.00', 'Ana Lúcia'], [$sessao['etapa_actual'], $sessao['diferenca'], $sessao['entradas'], $sessao['saidas'], $sessao['operador']]);
        // pesquisa sem acentos nem maiúsculas, como o legado
        $this->assertSame(1, $this->getJson('/api/gestao/fluxos/caixa/processos?pesquisa=ana%20lucia', $admin)->json('metadados.paginacao.total'));

        $p = $this->getJson('/api/gestao/fluxos/projetos', $admin)->assertOk()->json('dados');
        $this->assertSame(['Projectos activos', 'Com tarefas atrasadas', 'Acima do orçamento', 'Carteira activa (sem IVA)', 'Valor por receber'], array_column($p['kpis'], 'rotulo'));
        $this->assertSame(['Abertura incompleta', 'Por planear', 'Sem orçamento/equipa', 'Em execução', 'Por facturar', 'Por receber', 'Por encerrar', 'Encerrados'], array_column($p['vista']['funil'], 'rotulo'));
        $this->assertSame(['Activos', 'Com pendências', 'Com tarefas atrasadas', 'Acima do orçamento', 'Com valor por receber', 'Todos'], array_column($p['vista']['filtros'], 'rotulo'));
        $this->assertSame(['Projecto', 'Cliente', 'Executado', 'Etapa actual', 'Progresso', 'Pendências', 'Venda s/ IVA', 'Custo', 'Por receber'], array_column($p['vista']['colunas'], 'rotulo'));

        // lista branca: filtro desconhecido no fluxo, formato inválido e fluxos com cartões (sem filtros rápidos)
        $this->getJson('/api/gestao/fluxos/lavandaria/processos?filtro=xpto', $admin)->assertStatus(422)->assertJsonPath('codigo', 'FILTRO_INVALIDO');
        $this->getJson('/api/gestao/fluxos/lavandaria/processos?filtro=1%27%20OR', $admin)->assertStatus(422);
        $this->getJson('/api/gestao/fluxos/vendas/processos?filtro=curso', $admin)->assertStatus(422)->assertJsonPath('codigo', 'FILTRO_INVALIDO');
        $this->assertNull($this->getJson('/api/gestao/fluxos/vendas', $admin)->json('dados.vista'));
    }

    #[Test]
    public function salarios_bloqueia_a_integracao_sem_mapeamentos_e_assinala_periodos_em_falta(): void
    {
        $r = $this->getJson('/api/gestao/fluxos/rh', $this->h)->assertOk();
        $this->assertSame(['04/2025'], $r->json('dados.extra.periodos_em_falta.meses'));
        $p = collect($this->getJson('/api/gestao/fluxos/rh/processos', $this->h)->json('dados'))->keyBy('titulo');
        $this->assertSame('integ', $p['05/2025']['etapa_actual']);
        $this->assertTrue($p['05/2025']['bloqueado']);
        $this->assertSame('calc', $p['03/2025']['etapa_actual']);
        $d = $this->getJson('/api/gestao/fluxos/rh/processos/'.$p['05/2025']['chave'], $this->h)->json('dados');
        $textos = array_column($d['etapas']['integ']['problemas'], 'texto');
        $this->assertContains('Rubrica "Salário Base" sem conta contabilística para Sede.', $textos);
        $this->assertContains('Conta de sistema "Salários a pagar" não mapeada para Sede.', $textos);
        $this->assertSame('aviso', $d['etapas']['base']['problemas'][0]['nivel']);
    }

    /**
     * Verificação visual 1.3.2: o aviso «n sem IBAN» é da empresa (igual em todos os períodos). Continua no detalhe dos dados
     * base, como no legado, mas não conta nas pendências do período: sem outros problemas, a lista não mostra «▲1» e o funil
     * não diz «com pendências».
     */
    #[Test]
    public function salarios_o_aviso_de_iban_da_empresa_nao_conta_nas_pendencias_do_periodo(): void
    {
        $p = collect($this->getJson('/api/gestao/fluxos/rh/processos', $this->h)->assertOk()->json('dados'))->keyBy('titulo');
        $this->assertSame([0, 0], [$p['03/2025']['n_erros'], $p['03/2025']['n_avisos']]);
        $comPendencias = array_column($this->getJson('/api/gestao/fluxos/rh/processos?estado=com_pendencias', $this->h)->assertOk()->json('dados'), 'titulo');
        $this->assertSame(['05/2025'], $comPendencias);
        $funil = collect($this->getJson('/api/gestao/fluxos/rh', $this->h)->assertOk()->json('dados.funil'))->keyBy('etapa');
        $this->assertSame(0, $funil['calc']['com_pendencias']);
        $d = $this->getJson('/api/gestao/fluxos/rh/processos/'.$p['03/2025']['chave'], $this->h)->assertOk()->json('dados');
        $this->assertStringContainsString('sem IBAN', $d['etapas']['base']['problemas'][0]['texto']);
        $this->assertSame('empresa', $d['etapas']['base']['problemas'][0]['ambito']);
        // o período bloqueado continua com os erros dos mapeamentos em falta
        $this->assertGreaterThan(0, $p['05/2025']['n_erros']);
        // cartões do topo: mais recentes primeiro (?ordem=recentes); valor desconhecido é recusado
        $recentes = $this->getJson('/api/gestao/fluxos/vendas/processos?ordem=recentes', $this->h)->assertOk()->json('dados');
        $datas = array_map('strval', array_column($recentes, 'data'));
        $ordenadas = $datas;
        rsort($ordenadas);
        $this->assertCount(5, $datas);
        $this->assertSame($ordenadas, $datas);
        $this->getJson('/api/gestao/fluxos/vendas/processos?ordem=xpto', $this->h)->assertStatus(422);
    }
}
