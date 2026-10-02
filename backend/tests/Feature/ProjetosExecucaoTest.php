<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\AtivoImobilizado;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\Empresa;
use App\Models\EncomendaCompra;
use App\Models\FaturaCompra;
use App\Models\FolhaHorasProjeto;
use App\Models\InfotipoSalarial;
use App\Models\ItemCompra;
use App\Models\LinhaFolhaSalarial;
use App\Models\LinhaRevisaoProjeto;
use App\Models\PedidoCompra;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\RazaoAnaliticoProjeto;
use App\Models\ResultadoFolhaSalarial;
use App\Models\Terceiro;
use App\Services\Compras\ServicoFaturasCompra;
use App\Services\Logistica\ServicoStock;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Projectos (ADR-052), parte de execução: autos de medição e revisão mensal (mão de obra pelo recibo e pelo contrato,
 * subempreitadas com factura de fornecedor), facturação do auto em Vendas, razão analítico com a regra única, resumo,
 * folhas de horas, equipamentos, imputação do processamento salarial, requisições de material, fluxo e rentabilidade.
 * Venda: NE de 10 × 1 000 = 10 000 sem IVA. Ana: recibo de 244 200 em vencimentos, 2 h/dia → 61 050.
 * Rui: sem recibo, contrato de 10 000/dia, 4 h/dia → 10 000 / 8 × 4 × 22 = 110 000.
 */
final class ProjetosExecucaoTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000053']);
        $this->naEmpresa(function () {
            foreach (['311' => 'Clientes', '3211' => 'Fornecedores', '611' => 'Vendas', '621' => 'Serviços', '3452' => 'IVA liquidado', '451' => 'Caixa'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->ids['cliente'] = Terceiro::create(['nome' => 'Cliente A', 'nif' => '5000000001', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
            $this->ids['empreiteiro'] = Terceiro::create(['nome' => 'Empreiteiro', 'nif' => '5000000003', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '3211'])->id;
            $this->ids['servico'] = Produto::create(['codigo' => 'S1', 'nome' => 'Serviço de obra', 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'codigo_conta' => '621',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => false])->id;
            $this->ids['cimento'] = Produto::create(['codigo' => 'M1', 'nome' => 'Cimento', 'preco_unitario' => 50, 'taxa_imposto' => 14, 'movimenta_stock' => true])->id;
            $this->ids['ferro'] = Produto::create(['codigo' => 'M2', 'nome' => 'Ferro', 'preco_unitario' => 80, 'taxa_imposto' => 14, 'movimenta_stock' => true])->id;
            $this->ids['armazem'] = Armazem::create(['nome' => 'Central', 'predefinido' => true])->id;
            app(ServicoStock::class)->entrada($this->ids['cimento'], $this->ids['armazem'], '10', '40', '2026-09-01', 'Stock inicial');
            $base = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $sub = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Subsídio', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $desc = InfotipoSalarial::create(['tipo' => 'DESCONTO', 'nome' => 'Adiantamento', 'sujeito_inss' => false, 'irt' => 'false'])->id;
            $this->ids['ana'] = Colaborador::create(['nome_completo' => 'Ana', 'nif' => 'A1', 'estado' => 'ACTIVO', 'dias_uteis_mes' => 22])->id;
            $this->ids['rui'] = Colaborador::create(['nome_completo' => 'Rui', 'nif' => 'R1', 'estado' => 'ACTIVO', 'dias_uteis_mes' => 22])->id;
            $this->ids['eva'] = Colaborador::create(['nome_completo' => 'Eva', 'nif' => 'E1', 'estado' => 'ACTIVO', 'dias_uteis_mes' => 22])->id;
            ContratoTrabalho::create(['colaborador_id' => $this->ids['rui'], 'estado' => 'ACTIVO', 'dias_contrato_mes' => 22, 'horas_por_dia' => 8, 'data_inicio' => '2020-01-01',
                'codigo_moeda' => 'AOA', 'remuneracoes' => [['infotipo_id' => $base, 'valor_mes' => 220000, 'valor_dia' => 10000], ['infotype_id' => $desc, 'value_per_day' => 500]]]);
            $per = PeriodoProcessamentoSalarial::create(['mes_ano' => '09/2026', 'estado' => 'VALIDADO', 'contabilizado' => true]);
            $this->ids['periodo'] = $per->id;
            foreach ([[$base, 200000], [$sub, 44200], [$desc, 10000]] as [$inf, $v]) {
                LinhaFolhaSalarial::create(['periodo_processamento_salarial_id' => $per->id, 'colaborador_id' => $this->ids['ana'], 'infotipo_salarial_id' => $inf, 'valor' => $v]);
            }
            ResultadoFolhaSalarial::create(['periodo_processamento_salarial_id' => $per->id, 'colaborador_id' => $this->ids['rui'], 'dias_contrato' => 22, 'bruto' => 176000,
                'inss_patronal' => 14080, 'liquido' => 150000]);
            $this->ids['ativo'] = AtivoImobilizado::create(['codigo' => 'RETRO1', 'descricao' => 'Retroescavadora'])->id;
        });
        $this->s = $this->sessao(['projectos_carteira_view', 'projectos_extracto_view', 'proj_gerir', 'proj_execucao', 'proj_requisitar', 'proj_revisao', 'proj_eliminar',
            'vendas_fat_emitir']);
        $ne = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'NE', 'cliente_id' => $this->ids['cliente'], 'data_emissao' => now()->toDateString(),
            'linhas' => [['produto_id' => $this->ids['servico'], 'quantidade' => 10]]], $this->s)->assertCreated()->json('dados');
        $this->ids['ne'] = $ne['id'];
        $p = $this->postJson('/api/projetos', ['codigo' => 'OBRA-1', 'nome' => 'Obra do cliente', 'tipo' => 'EXTERNO', 'cliente_id' => $this->ids['cliente'],
            'encomenda_venda_id' => $ne['id']], $this->s)->assertCreated()->json('dados.id');
        $this->ids['p'] = $p;
        $m = fn (array $d) => $this->postJson("/api/projetos/{$p}/equipa/membros", $d, $this->s)->assertCreated()->json('dados.id');
        $this->ids['m_ana'] = $m(['tipo' => 'INTERNO', 'colaborador_id' => $this->ids['ana'], 'horas_alocadas' => 2]);
        $this->ids['m_rui'] = $m(['tipo' => 'INTERNO', 'colaborador_id' => $this->ids['rui'], 'horas_alocadas' => 4]);
        $this->ids['m_emp'] = $m(['tipo' => 'TERCEIRO', 'terceiro_id' => $this->ids['empreiteiro'], 'papel' => 'Empreiteiro']);
        $this->ids['m_topo'] = $m(['tipo' => 'LIVRE', 'nome_externo' => 'Topógrafo']);
        $t = fn (array $d) => $this->postJson("/api/projetos/{$p}/tarefas", $d, $this->s)->assertCreated()->json('dados.id');
        $this->ids['t1'] = $t(['nome' => 'Estrutura', 'atribuido_a_id' => $this->ids['m_emp'], 'valor_contrato' => 100000, 'percentagem_execucao' => 30]);
        $this->ids['t2'] = $t(['nome' => 'Levantamento', 'atribuido_a_id' => $this->ids['m_topo'], 'valor_contrato' => 20000, 'percentagem_execucao' => 50]);
        $this->ids['t3'] = $t(['nome' => 'Instalações', 'atribuido_a_id' => $this->ids['m_rui']]);
    }

    private function naEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function revisao_mensal_mao_de_obra_subempreitadas_e_aditamento(): void
    {
        $s = $this->s;
        $p = $this->ids['p'];
        $sim = $this->getJson("/api/projetos/{$p}/revisoes/simulacao?mes=9&ano=2026", $s)->assertOk()->json('dados');
        $this->assertSame([['Ana', 'RECIBO', '61050.00'], ['Rui', 'CONTRATO', '110000.00']], array_map(fn ($i) => [$i['nome'], $i['fonte'], $i['custo']], $sim['internos']));
        $this->assertSame([[$this->ids['t1'], 30.0, '30000.00'], [$this->ids['t2'], 50.0, '10000.00']],
            array_map(fn ($e) => [$e['tarefa_projeto_id'], $e['percentagem_faturavel'], $e['valor']], $sim['externos']));
        $this->getJson("/api/projetos/{$p}/revisoes/simulacao?mes=9&ano=2026", $this->sessao(['proj_gerir']))->assertForbidden();

        // a factura do empreiteiro precisa do artigo de serviço
        $this->postJson("/api/projetos/{$p}/revisoes", ['mes' => 9, 'ano' => 2026], $s)->assertStatus(422)->assertJsonPath('codigo', 'PRODUTO_AUTOS_EM_FALTA');
        $this->putJson('/api/projetos/configuracao', ['produto_subempreitada_id' => $this->ids['servico'], 'produto_faturacao_id' => $this->ids['servico']], $s)->assertOk();
        $r1 = $this->postJson("/api/projetos/{$p}/revisoes", ['mes' => 9, 'ano' => 2026], $s)->assertCreated()->json('dados');
        $this->assertSame(['40000.00', '171050.00'], [$r1['totais']['subempreitadas'], $r1['totais']['mao_obra']]);
        $rev1 = $r1['revisao']['id'];
        $this->naEmpresa(function () use ($p, $rev1) {
            $f = FaturaCompra::query()->where('projeto_id', $p)->sole();
            $this->assertSame(["AUTO-{$p}-202609-{$this->ids['m_emp']}-{$this->ids['t1']}-R{$rev1}", $this->ids['empreiteiro'], 'PENDENTE'], [$f->numero_fatura, $f->fornecedor_id, $f->estado]);
            $this->assertSame([$this->ids['t1'], '30000.00'], [ItemCompra::query()->where('fatura_compra_id', $f->id)->value('tarefa_projeto_id'),
                ItemCompra::query()->where('fatura_compra_id', $f->id)->value('total_kz')]);
            $sub = LinhaRevisaoProjeto::query()->where('revisao_mensal_projeto_id', $rev1)->where('tipo', 'SUBEMPREITADA')->orderBy('id')->get();
            $this->assertSame([[$this->ids['empreiteiro'], (string) $f->id], [null, null]], $sub->map(fn ($l) => [$l->terceiro_id, $l->documento_gerado_id])->all());
            $mo = LinhaRevisaoProjeto::query()->where('revisao_mensal_projeto_id', $rev1)->where('tipo', 'MAO_OBRA')->orderBy('id')->get();
            $this->assertSame(['61050.00', '110000.00'], RazaoAnaliticoProjeto::query()->whereIn('id', $mo->pluck('documento_gerado_id'))->orderBy('id')->pluck('montante')->all());
        });

        // sem evolução: nada a processar; com evolução só entra o diferencial, como aditamento confirmado
        $this->postJson("/api/projetos/{$p}/revisoes", ['mes' => 9, 'ano' => 2026], $s)->assertStatus(422)->assertJsonPath('codigo', 'SEM_DIFERENCIAIS');
        $this->putJson("/api/projetos/{$p}/tarefas/{$this->ids['t1']}", ['percentagem_execucao' => 50], $s)->assertOk();
        $this->postJson("/api/projetos/{$p}/revisoes", ['mes' => 9, 'ano' => 2026], $s)->assertStatus(422)->assertJsonPath('codigo', 'REVISAO_EXISTENTE');
        $r2 = $this->postJson("/api/projetos/{$p}/revisoes", ['mes' => 9, 'ano' => 2026, 'confirmar_aditamento' => true], $s)->assertCreated()->json('dados');
        $this->assertSame(['20000.00', '0.00'], [$r2['totais']['subempreitadas'], $r2['totais']['mao_obra']]);
        $this->assertSame([30.0, 50.0], [(float) $r2['subempreitadas'][0]['percentagem_anterior'], (float) $r2['subempreitadas'][0]['percentagem_atual']]);
        $lista = $this->getJson("/api/projetos/{$p}/revisoes", $s)->assertOk()->json('dados');
        $this->assertSame([["REV-{$r2['revisao']['id']}", '20000.00', '0.00'], ["REV-{$rev1}", '40000.00', '171050.00']],
            array_map(fn ($r) => [$r['referencia'], $r['total_subempreitadas'], $r['total_mao_obra']], $lista));

        // extracto: mão de obra 171 050 + facturas 30 000 + 20 000 + auto sem documento 10 000 = 231 050
        $this->getJson("/api/projetos/extracto?projeto_id={$p}", $s)->assertOk()->assertJsonPath('dados.totais.custos', '231050.00')->assertJsonPath('dados.totais.proveitos', '0.00');
        $this->getJson("/api/projetos/extracto?projeto_id={$p}", $this->sessao(['projectos_carteira_view']))->assertForbidden();
        // factura anulada deixa de contar
        $this->naEmpresa(fn () => app(ServicoFaturasCompra::class)->anular(FaturaCompra::query()->where('numero_fatura', 'like', "%-R{$r2['revisao']['id']}")->sole(), 'Teste'));
        $this->getJson("/api/projetos/extracto?projeto_id={$p}", $s)->assertJsonPath('dados.totais.custos', '211050.00');
    }

    #[Test]
    public function facturacao_do_auto_sem_duplicar_e_proveitos_sem_iva(): void
    {
        $s = $this->s;
        $p = $this->ids['p'];
        $this->putJson('/api/projetos/configuracao', ['produto_subempreitada_id' => $this->ids['servico']], $s)->assertOk();
        $rev = $this->postJson("/api/projetos/{$p}/revisoes", ['mes' => 9, 'ano' => 2026], $s)->assertCreated()->json('dados.revisao.id');

        // execução global = (30 + 50 + 0) / 3 = 27 % → devido 2 700 sem IVA
        $prop = $this->getJson("/api/projetos/{$p}/revisoes/{$rev}/faturacao", $s)->assertOk()->json('dados');
        $this->assertSame([27, '10000.00', '0.00', '2700.00', 14.0], [$prop['execucao_global'], $prop['venda'], $prop['faturado'], $prop['sugerido'], $prop['taxa_iva_encomenda']]);
        $this->postJson("/api/projetos/{$p}/revisoes/{$rev}/faturar", ['tipo_documento' => 'FT', 'valor' => 2700], $s)->assertStatus(422)->assertJsonPath('codigo', 'PRODUTO_FATURACAO_EM_FALTA');
        $ft = $this->postJson("/api/projetos/{$p}/revisoes/{$rev}/faturar", ['tipo_documento' => 'FT', 'valor' => 2700, 'produto_id' => $this->ids['servico']], $s)
            ->assertCreated()->assertJsonPath('dados.total_liquido', '2700.00')->assertJsonPath('dados.total_bruto', '3078.00')->assertJsonPath('dados.projeto_id', $p)->json('dados');
        $this->postJson("/api/projetos/{$p}/revisoes/{$rev}/faturar", ['tipo_documento' => 'FT', 'valor' => 100, 'produto_id' => $this->ids['servico']], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'AUTO_JA_FATURADO');
        $this->postJson("/api/projetos/{$p}/revisoes/{$rev}/faturar", ['tipo_documento' => 'PF', 'valor' => 100, 'produto_id' => $this->ids['servico']], $s)->assertCreated();
        $this->assertSame($ft['numero_documento'], $this->getJson("/api/projetos/{$p}/revisoes", $s)->json('dados.0.faturada'));

        // FT da encomenda (conversão) + FT do auto − NC da FT do auto; proveito analítico da mesma factura não se conta duas vezes
        $this->postJson("/api/vendas/documentos/{$this->ids['ne']}/converter", ['tipo_destino' => 'FT'], $s)->assertCreated();
        $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'NC', 'cliente_id' => $this->ids['cliente'], 'data_emissao' => now()->toDateString(), 'venda_origem_id' => $ft['id'],
            'motivo_nota_credito' => 'Erro no auto (metade)', 'linhas' => [['produto_id' => $this->ids['servico'], 'quantidade' => 0.5]]], $s)->assertCreated();
        $this->naEmpresa(function () use ($p, $ft) {
            RazaoAnaliticoProjeto::create(['projeto_id' => $p, 'rubrica' => 'PROVEITOS_FATURACAO', 'modulo_origem' => 'VENDAS', 'natureza' => 'PROVEITO', 'data' => now()->toDateString(),
                'montante' => 2700, 'documento_origem_id' => $ft['numero_documento']]);
            RazaoAnaliticoProjeto::create(['projeto_id' => $p, 'rubrica' => 'GERAL', 'modulo_origem' => 'OUTRO', 'natureza_original' => 'compromisso', 'data' => now()->toDateString(), 'montante' => 999]);
            // encomenda de compra do projecto: metade por facturar é compromisso; a anulada não conta
            foreach ([['EM_PROCESSAMENTO', 1000], ['ANULADA', 5000]] as [$estado, $total]) {
                $e = EncomendaCompra::create(['fornecedor_id' => $this->ids['empreiteiro'], 'numero_encomenda' => "EC-{$estado}", 'data' => now()->toDateString(), 'estado' => $estado, 'projeto_id' => $p]);
                ItemCompra::create(['tipo_documento_origem' => 'ENCOMENDA', 'encomenda_compra_id' => $e->id, 'produto_id' => $this->ids['servico'], 'quantidade' => 2,
                    'quantidade_faturada' => 1, 'preco_unitario' => $total / 2, 'total_kz' => $total]);
            }
        });
        $x = $this->getJson("/api/projetos/extracto?projeto_id={$p}", $s)->assertOk()->json('dados');
        $this->assertSame(['11350.00', '1499.00'], [$x['totais']['proveitos'], $x['totais']['compromissos']]);
        $this->assertSame(['-1350.00', '2700.00', '10000.00'], collect($x['movimentos'])->where('fonte', 'VENDA')->pluck('valor')->sort()->values()->all());

        // resumo: mesmos custos do extracto, sem duplicar a subempreitada; margem sobre a facturação sem IVA
        $r = $this->getJson("/api/projetos/{$p}/resumo", $s)->assertOk()->json('dados');
        $this->assertSame([$x['totais']['custos'], '11350.00', '10000.00'], [$r['indicadores']['custo'], $r['indicadores']['faturado'], $r['indicadores']['venda']]);
        $this->assertSame(['40000.00', '171050.00'], [$r['origem_custos']['AUTOS_SUBEMPREITADAS'], $r['origem_custos']['AUTOS_MAO_OBRA']]);
        $this->assertSame(bcsub('11350.00', $x['totais']['custos'], 2), $r['indicadores']['margem_real']);
        $this->assertSame(['TAREFAS_SEM_ORCAMENTO'], array_column($r['alertas'], 'codigo'));
        $this->assertSame(['execucao' => 27, 'faturado_pct' => 113.5], ['execucao' => $r['indicadores']['execucao'], 'faturado_pct' => $r['indicadores']['faturado_pct']]);
        $fluxo = collect($this->getJson('/api/projetos/fluxo', $s)->assertOk()->json('dados'))->firstWhere('id', $p);
        $this->assertSame([$x['totais']['custos'], '11350.00', 'CONCLUIDA'], [$fluxo['custo'], $fluxo['faturado'], $fluxo['etapas']['faturacao']]);
    }

    #[Test]
    public function horas_equipamentos_imputacao_salarial_e_requisicoes(): void
    {
        $s = $this->s;
        $p = $this->ids['p'];
        $hora = fn (int $c, float $h = 8, string $d = '2026-09-10') => $this->postJson("/api/projetos/{$p}/horas", ['tarefa_projeto_id' => $this->ids['t3'], 'colaborador_id' => $c,
            'data' => $d, 'horas' => $h], $this->sessao(['proj_execucao']));
        $hora($this->ids['eva'])->assertStatus(422)->assertJsonPath('codigo', 'COLABORADOR_FORA_DA_EQUIPA');
        $hora($this->ids['rui'], 30)->assertStatus(422)->assertJsonPath('codigo', 'HORAS_INVALIDAS');
        $f = $hora($this->ids['rui'])->assertCreated()->assertJsonPath('dados.estado', 'REGISTADO')->json('dados.id');
        $this->postJson("/api/projetos/{$p}/equipamentos", ['tarefa_projeto_id' => $this->ids['t3'], 'ativo_imobilizado_id' => $this->ids['ativo'], 'data' => '2026-09-15',
            'horas' => 8, 'custo_hora' => 5000], $s)->assertCreated()->assertJsonPath('dados.montante', '40000.00')->assertJsonPath('dados.documento_origem_id', 'MAQ-RETRO1');

        // processamento de 09/2026: custo/hora do Rui = (176 000 + 14 080) / (22 × 8) = 1 080 → 8 h = 8 640; não repete; reverte
        $this->postJson("/api/projetos/folhas-horas/periodos/{$this->ids['periodo']}/imputar", [], $s)->assertOk()
            ->assertJsonPath('dados.imputadas', 1)->assertJsonPath('dados.valor', '8640.00');
        $this->postJson("/api/projetos/folhas-horas/periodos/{$this->ids['periodo']}/imputar", [], $s)->assertOk()->assertJsonPath('dados.imputadas', 0);
        $this->deleteJson("/api/projetos/{$p}/horas/{$f}", [], $s)->assertStatus(422)->assertJsonPath('codigo', 'HORAS_PROCESSADAS');
        $pt = $this->getJson("/api/projetos/{$p}/custos-tarefas", $s)->assertOk()->json("dados.{$this->ids['t3']}");
        $this->assertSame(['48640.00', 8.0], [$pt['executado'], $pt['horas']]);
        $this->postJson("/api/projetos/folhas-horas/periodos/{$this->ids['periodo']}/reverter", [], $s)->assertOk()->assertJsonPath('dados.removidos', 1);
        $this->assertSame('REGISTADO', $this->naEmpresa(fn () => FolhaHorasProjeto::query()->find($f)->estado));
        $this->deleteJson("/api/projetos/{$p}/horas/{$f}", [], $s)->assertOk();

        // requisição: serviço e artigo sem stock suficiente vão a Compras (pedido do projecto, tarefa na linha); o resto sai do stock
        $this->postJson("/api/projetos/{$p}/requisicoes", ['nome_requerente' => 'Encarregado', 'data' => '2026-09-20', 'linhas' => [
            ['produto_id' => $this->ids['servico'], 'quantidade' => 2, 'tarefa_projeto_id' => $this->ids['t3']], ['produto_id' => $this->ids['cimento'], 'quantidade' => 3],
            ['produto_id' => $this->ids['ferro'], 'quantidade' => 5]]], $this->sessao(['proj_requisitar']))->assertCreated()->assertJsonPath('dados.estado', 'PENDENTE');
        $req = $this->getJson("/api/projetos/{$p}/requisicoes", $s)->assertOk()->json('dados.0');
        $this->assertSame([['Serviço de obra', 'COMPRAS'], ['Ferro', 'COMPRAS'], ['Cimento', 'STOCK']], array_map(fn ($l) => [$l['descricao'], $l['estado']], $req['linhas']));
        $this->naEmpresa(function () use ($p) {
            $pedido = PedidoCompra::query()->where('projeto_id', $p)->sole();
            $this->assertSame('Encarregado (Obra: OBRA-1)', $pedido->nome_requerente);
            $this->assertSame([$this->ids['t3'], null], ItemCompra::query()->where('pedido_compra_id', $pedido->id)->orderBy('id')->pluck('tarefa_projeto_id')->all());
        });
        // as linhas de pedido não são custo; o equipamento sim
        $this->getJson("/api/projetos/extracto?projeto_id={$p}", $s)->assertJsonPath('dados.totais.custos', '40000.00');
        $rent = $this->getJson('/api/projetos/rentabilidade?inicio=2026-09-01&fim=2026-09-30', $s)->assertOk()->json('dados');
        $this->assertSame(['40000.00', '-40000.00'], [$rent['linhas'][0]['custos'], $rent['linhas'][0]['margem']]);
        $this->assertSame(0.0, $rent['kpis']['horas']);
    }

    /** Afinação da Fase 5 (ADR-064): nomes nas horas e no orçamento, GET dos equipamentos, cliente na carteira, mapas como objecto. */
    #[Test]
    public function afinacao_nomes_equipamentos_e_cliente_na_carteira(): void
    {
        $s = $this->s;
        $p = $this->ids['p'];
        $vazio = $this->getJson("/api/projetos/{$p}/resumo", $s)->assertOk();
        $this->assertStringContainsString('"orcamento_por_rubrica":{', $vazio->getContent());   // objecto, nunca []

        $this->postJson("/api/projetos/{$p}/horas", ['tarefa_projeto_id' => $this->ids['t3'], 'colaborador_id' => $this->ids['rui'], 'data' => '2026-09-10', 'horas' => 6], $s)->assertCreated();
        $h = $this->getJson("/api/projetos/{$p}/horas", $s)->assertOk()->json('dados.0');
        $this->assertSame(['Rui', 'Instalações'], [$h['colaborador_nome'], $h['tarefa_nome']]);
        $this->assertSame([['colaborador_id' => $this->ids['rui'], 'nome' => 'Rui', 'horas' => 6.0]],
            $this->getJson("/api/projetos/{$p}/resumo", $s)->json('dados.horas_por_colaborador'));

        $this->postJson("/api/projetos/{$p}/orcamento", ['tarefa_projeto_id' => $this->ids['t3'], 'rubrica' => 'MAO_DE_OBRA', 'montante' => 1500], $s)->assertCreated();
        $l = collect($this->getJson("/api/projetos/{$p}/orcamento", $s)->assertOk()->json('dados.linhas'))->firstWhere('tarefa_projeto_id', $this->ids['t3']);
        $this->assertSame('Instalações', $l['tarefa_nome']);

        $this->getJson("/api/projetos/{$p}/equipamentos", $s)->assertOk()->assertJsonPath('dados.usos', [])->assertJsonPath('dados.total', '0.00');
        $this->postJson("/api/projetos/{$p}/equipamentos", ['tarefa_projeto_id' => $this->ids['t3'], 'ativo_imobilizado_id' => $this->ids['ativo'], 'data' => '2026-09-15',
            'horas' => 2, 'custo_hora' => 1000.5], $s)->assertCreated();
        $e = $this->getJson("/api/projetos/{$p}/equipamentos", $s)->assertOk()->json('dados');
        $this->assertSame([$this->ids['ativo'], 'RETRO1', 'Retroescavadora', 'Instalações', '2001.00', '2001.00'],
            [$e['usos'][0]['ativo_imobilizado_id'], $e['usos'][0]['ativo_codigo'], $e['usos'][0]['ativo_descricao'], $e['usos'][0]['tarefa_nome'], $e['usos'][0]['montante'], $e['total']]);
        $this->getJson("/api/projetos/{$p}/equipamentos", $this->sessao(['vendas_view']))->assertForbidden();

        $linha = collect($this->getJson('/api/projetos', $s)->assertOk()->json('dados'))->firstWhere('id', $p);
        $this->assertSame(['id' => $this->ids['cliente'], 'nome' => 'Cliente A', 'nif' => '5000000001'], $linha['cliente']);
    }
}
