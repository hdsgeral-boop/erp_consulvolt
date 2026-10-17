<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Models\PlanoConta;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ronda 3 — Contabilidade: quebra dos lançamentos por terceiro / unidade de negócio / centro de custo, cálculo detalhado
 * das rubricas do Balanço, DR e Fluxo de caixa (soma das contas + ajustes = valor do mapa) e modelo/importação da
 * actualização em massa (pré-visualização sem gravar, tudo-ou-nada, só válidas).
 *
 * Cenário das demonstrações = o de ContabilidadeRelatoriosFinanceirosTest (2025 e 2026, com apuramento, estorno e linha sem nota).
 */
final class R3ContabilidadeTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private array $n = [];

    private array $f = [];

    private array $ids = [];

    private int $diario;

    private int $fornecedor;

    private int $cliente;

    private int $un;

    private int $cc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach ([['31', 'Terceiros', 'T'], ['311', 'Clientes', 'M'], ['321', 'Fornecedores', 'M'], ['3452', 'IVA dedutível', 'M'], ['3453', 'IVA liquidado', 'M'],
                ['4311', 'Banco', 'M'], ['511', 'Capital', 'M'], ['621', 'Prestações de serviços', 'M'], ['721', 'Remunerações', 'M'], ['75', 'Outros custos', 'M'],
                ['881', 'Resultado líquido', 'M'], ['91', 'Analítica A', 'M'], ['92', 'Analítica B', 'M'], ['7681', 'Diferenças', 'M']] as [$c, $d, $t]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => $t]);
            }
            foreach (['9' => 'Contas a receber', '10' => 'Disponibilidades', '12' => 'Capital', '14' => 'Resultados transitados', '14.1' => 'Transitados (sub)',
                '19' => 'Contas a pagar', '21' => 'Outros passivos correntes', '23' => 'Prestações de serviços', '28' => 'Custos com o pessoal', '30' => 'Outros custos'] as $c => $d) {
                $this->n[(string) $c] = NotaDemonstracao::create(['codigo' => (string) $c, 'descricao' => $d])->id;
            }
            foreach (['111' => 'Recebimentos de clientes', '112' => 'Pagamentos a fornecedores', '113' => 'Pagamentos ao pessoal'] as $c => $d) {
                $this->f[(string) $c] = NotaFluxoCaixa::create(['codigo' => (string) $c, 'descricao' => $d])->id;
            }
            $this->diario = DiarioContabil::create(['codigo' => 'OD', 'descricao' => 'Operações diversas'])->id;
            $this->fornecedor = Terceiro::create(['nif' => '5417009999', 'nome' => 'Zeta Fornecedor', 'tipo' => 'FORNECEDOR'])->id;
            $this->cliente = Terceiro::create(['nif' => '5417008888', 'nome' => 'Alfa Cliente', 'tipo' => 'CLIENTE'])->id;
            $this->un = UnidadeNegocio::create(['codigo' => 'UN1', 'nome' => 'Norte'])->id;
            $this->cc = CentroCusto::create(['codigo' => 'CC1', 'descricao' => 'Administração'])->id;

            $l = fn (string $data, string $lan, array $linhas, array $extra = []) => array_map(fn ($x) => LancamentoContabil::create($extra + [
                'diario_id' => $this->diario, 'data_documento' => $data, 'numero_lan' => $lan, 'numero_documento' => $x[5] ?? $lan,
                'codigo_conta' => $x[0], 'tipo_dc' => $x[1], 'valor' => $x[2], 'nota_demonstracao_id' => $x[3] ? $this->n[$x[3]] : null,
                'nota_fluxo_caixa_id' => isset($x[4]) && $x[4] ? $this->f[$x[4]] : null, 'terceiro_id' => $x[6] ?? null, 'unidade_negocio_id' => $x[7] ?? null]), $linhas);
            $l('2025-01-10', 'OD2025000001', [['4311', 'D', 1000, '10'], ['511', 'C', 1000, '12']]);
            $l('2025-06-01', 'OD2025000002', [['4311', 'D', 500, '10', '111'], ['621', 'C', 500, '23']]);
            $l('2025-07-01', 'OD2025000003', [['721', 'D', 200, '28'], ['4311', 'C', 200, '10', '113']]);
            $l('2025-12-31', 'AP2025000001', [['621', 'D', 500, '23'], ['721', 'C', 200, '28'], ['881', 'C', 300, '14']], ['periodo_id' => 13]);
            $ft = $l('2026-03-01', 'OD2026000001', [['311', 'D', 1130, '9', null, 'FT 1', $this->cliente, $this->un], ['621', 'C', 1000, '23', null, 'FT 1', null, $this->un], ['3453', 'C', 130, '21', null, 'FT 1']]);
            $l('2026-04-01', 'OD2026000002', [['4311', 'D', 1130, '10', '111', 'FT 1'], ['311', 'C', 1130, '9', null, 'FT 1', $this->cliente]]);
            $l('2026-05-01', 'OD2026000003', [['75', 'D', 400, '30', null, 'FC 9'], ['3452', 'D', 56, '9', null, 'FC 9'], ['321', 'C', 456, '19', null, 'FC 9', $this->fornecedor]]);
            $l('2026-05-10', 'OD2026000004', [['321', 'D', 456, '19', null, 'PG 9', $this->fornecedor], ['4311', 'C', 456, '10', '112', 'PG 9']]);
            $l('2026-06-01', 'OD2026000005', [['91', 'D', 100, null], ['92', 'C', 100, null]]);
            $l('2026-06-15', 'OD2026000006', [['4311', 'D', 50, null], ['621', 'C', 50, '23']]);
            $pag = $l('2026-08-01', 'OD2026000007', [['321', 'D', 100, '19', null, 'PG 10', $this->fornecedor], ['4311', 'C', 100, '10', '112', 'PG 10']]);
            app(ServicoLancamentos::class)->estornar($pag[0], 'Pagamento em duplicado');
            $this->ids = ['ft_cliente' => $ft[0]->id, 'ft_servico' => $ft[1]->id, 'ft_iva' => $ft[2]->id];
        });
        $this->s = $this->sessao(['lancamentos_view', 'contab_mapa_balanco_view', 'contab_mapa_dr_view', 'contab_mapa_fluxo_view',
            'contab_rotinas_view', 'contab_rotinas_exec', 'contab_rotinas_anular']);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    // ------------------------------------------------------------------ Quebra dos lançamentos

    #[Test]
    public function quebra_por_terceiro_com_subtotais_total_paginacao_e_detalhe(): void
    {
        $q = '/api/contabilidade/lancamentos/quebra?data_inicio=2026-01-01&data_fim=2026-12-31&por=terceiro';
        $r = $this->getJson($q.'&por_pagina=1', $this->s)->assertOk()->json('dados');
        // total = todas as linhas filtradas de 2026 (sem classe 9): 3 + 2 + 3 + 2 + 2 + 2 (pagamento) + 2 (estorno) = 16
        $this->assertSame(['grupos' => 3, 'linhas' => 16], ['grupos' => $r['total']['grupos'], 'linhas' => $r['total']['linhas']]);
        $this->assertSame($r['total']['debito'], $r['total']['credito']);
        $this->assertSame(['pagina_atual' => 1, 'por_pagina' => 1, 'total' => 3, 'ultima_pagina' => 3], $r['paginacao']);
        // ordem pelo nome (ordenação portuguesa), o grupo «sem terceiro» no fim
        $this->assertSame('Alfa Cliente', $r['grupos'][0]['nome']);
        $this->assertSame(['2', '1130.00', '1130.00', '0.00'], [(string) $r['grupos'][0]['linhas'], $r['grupos'][0]['debito'], $r['grupos'][0]['credito'], $r['grupos'][0]['saldo']]);
        $p3 = $this->getJson($q.'&por_pagina=1&pagina=3', $this->s)->assertOk()->json('dados.grupos.0');
        $this->assertNull($p3['chave']);
        $this->assertSame('Sem terceiro', $p3['nome']);

        // a soma dos grupos é o total geral
        $todos = $this->getJson($q, $this->s)->assertOk()->json('dados');
        $this->assertSame($todos['total']['debito'], number_format(array_sum(array_map(fn ($g) => (float) $g['debito'], $todos['grupos'])), 2, '.', ''));
        $this->assertSame(16, array_sum(array_column($todos['grupos'], 'linhas')));

        // detalhe para impressão/Excel: as linhas de cada grupo
        $det = $this->getJson($q.'&com_linhas=1', $this->s)->assertOk()->json('dados.grupos');
        $this->assertSame([2, 4, 10], array_map(fn ($g) => count($g['detalhe']), $det));
        $this->assertSame('OD', $det[0]['detalhe'][0]['diario']);

        // expandir o grupo «sem terceiro» na lista: filtro sem[]=terceiro
        $sem = $this->getJson('/api/contabilidade/lancamentos?data_inicio=2026-01-01&data_fim=2026-12-31&sem[]=terceiro&por_pagina=100', $this->s)->assertOk();
        $this->assertSame(10, $sem->json('metadados.paginacao.total'));

        $this->getJson($q.'&por=projecto', $this->s)->assertStatus(422);
        $this->getJson($q, $this->sessao(['contab_mapa_dr_view']))->assertForbidden();
    }

    #[Test]
    public function quebra_por_unidade_de_negocio_e_centro_de_custo_respeita_os_filtros(): void
    {
        $r = $this->getJson('/api/contabilidade/lancamentos/quebra?data_inicio=2026-01-01&data_fim=2026-12-31&por=unidade&filtro_contas=6', $this->s)->assertOk()->json('dados');
        $this->assertSame([['UN1', 'Norte', '0.00', '1000.00', '-1000.00'], [null, 'Sem unidade de negócio', '0.00', '50.00', '-50.00']],
            array_map(fn ($g) => [$g['codigo'], $g['nome'], $g['debito'], $g['credito'], $g['saldo']], $r['grupos']));
        $this->assertSame('-1050.00', $r['total']['saldo']);
        $c = $this->getJson('/api/contabilidade/lancamentos/quebra?data_inicio=2026-01-01&data_fim=2026-12-31&por=centro', $this->s)->assertOk()->json('dados');
        $this->assertSame([['Sem centro de custo', 16]], array_map(fn ($g) => [$g['nome'], $g['linhas']], $c['grupos']));
        // outra empresa não vê nada (isolamento)
        $outra = $this->criarEmpresa();
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'lancamentos_view' => true])->id]);
        $u->empresas()->attach($outra->id);
        $vazio = $this->getJson('/api/contabilidade/lancamentos/quebra?data_inicio=2026-01-01&data_fim=2026-12-31&por=unidade', $this->entrar($u) + ['X-Empresa-Id' => $outra->id])->assertOk()->json('dados');
        $this->assertSame([0, []], [$vazio['total']['linhas'], $vazio['grupos']]);
    }

    // ------------------------------------------------------------------ Cálculo das rubricas

    #[Test]
    public function cada_rubrica_das_demonstracoes_bate_com_o_mapa_e_com_as_suas_contas(): void
    {
        $base = 'data_inicio=2026-01-01&data_fim=2026-12-31';
        $balanco = $this->getJson('/api/contabilidade/relatorios/balanco?data_fim=2026-12-31&comparativo=1', $this->s)->assertOk()->json('dados');
        $dr = $this->getJson("/api/contabilidade/relatorios/demonstracao-resultados?{$base}&comparativo=1", $this->s)->assertOk()->json('dados');
        $fluxo = $this->getJson("/api/contabilidade/relatorios/fluxo-caixa?{$base}&comparativo=1", $this->s)->assertOk()->json('dados');

        $verificar = function (string $tipo, string $rubrica, ?string $esperado, string $periodo = 'atual') use ($base) {
            $c = $this->getJson("/api/contabilidade/relatorios/composicao/{$tipo}?{$base}&rubrica={$rubrica}&periodo={$periodo}", $this->s)->assertOk()->json('dados');
            if ($esperado !== null) {
                $this->assertSame($esperado, $c['valor'], "{$tipo} {$rubrica} {$periodo}: valor do mapa");
            }
            if ($c['componentes']) {
                $soma = array_reduce($c['componentes'], fn ($s, $x) => bcadd($s, bcmul($x['valor'], (string) $x['sinal'], 2), 2), '0.00');
            } else {
                $soma = array_reduce(array_merge($c['contas'], $c['ajustes']), fn ($s, $x) => bcadd($s, $x['valor'], 2), '0.00');
            }
            $this->assertSame($c['valor'], $soma, "{$tipo} {$rubrica} {$periodo}: soma das parcelas");

            return $c;
        };

        foreach (['atual', 'anterior'] as $p) {
            foreach ($balanco['seccoes'] as $seccao => $s) {
                foreach ($s['linhas'] as $l) {
                    $verificar('balanco', $l['codigo'], $l[$p], $p);
                }
                $verificar('balanco', $seccao, $s['total'][$p], $p);
            }
            foreach (['activo', 'passivo', 'capital_proprio_passivo'] as $t) {
                $verificar('balanco', $t, $balanco['totais'][$p][$t], $p);
            }
            foreach (array_merge($dr['proveitos_operacionais']['linhas'], $dr['custos_operacionais']['linhas'], $dr['outros_resultados'], [$dr['imposto'], $dr['resultados_extraordinarios']]) as $l) {
                $verificar('dr', $l['nota'], $l[$p], $p);
            }
            foreach (['resultados_operacionais', 'resultados_antes_impostos', 'resultado_actividades_correntes', 'resultado_liquido'] as $t) {
                $verificar('dr', $t, $dr[$t][$p], $p);
            }
            foreach ($fluxo['seccoes'] as $seccao => $s) {
                foreach ($s['linhas'] as $l) {
                    $verificar('fluxo', $l['codigo'], $l[$p], $p);
                }
                $verificar('fluxo', $seccao, $s['total'][$p], $p);
            }
            $verificar('fluxo', 'variacao', $fluxo['variacao_caixa'][$p], $p);
            $verificar('fluxo', 'caixa_inicial', $fluxo['caixa_inicial'][$p], $p);
            $verificar('fluxo', 'caixa_final', $fluxo['caixa_final'][$p], $p);
        }

        // contas que compõem rubricas concretas
        $n9 = $verificar('balanco', '9', '56.00');
        $this->assertSame([['311', '1130.00', '1130.00', '0.00'], ['3452', '56.00', '0.00', '56.00']], array_map(fn ($c) => [$c['codigo_conta'], $c['debito'], $c['credito'], $c['valor']], $n9['contas']));
        $n14 = $verificar('balanco', '14', '300.00');
        $this->assertContains('881', array_column($n14['contas'], 'codigo_conta'));
        $rl = $verificar('balanco', 'res_liq', '650.00');
        $this->assertNotNull($rl['formula']);
        $this->assertSame(['23' => 1, '30' => -1], array_intersect_key(array_column($rl['componentes'], 'sinal', 'rubrica'), ['23' => 0, '30' => 0]));
        $f112 = $verificar('fluxo', '112', '-456.00');
        $this->assertSame([['4311', '-456.00']], array_map(fn ($c) => [$c['codigo_conta'], $c['valor']], $f112['contas']));

        // movimentos de uma conta dentro da rubrica, com o id do lançamento (ligação ao lançamento)
        $m = $this->getJson("/api/contabilidade/relatorios/composicao/balanco?{$base}&rubrica=9&codigo_conta=311", $this->s)->assertOk()->json('dados');
        $this->assertSame(['D', 'C'], array_column($m['movimentos'], 'tipo_dc'));
        $this->assertSame($this->ids['ft_cliente'], $m['movimentos'][0]['id']);
        $this->getJson("/api/contabilidade/relatorios/composicao/balanco?{$base}&rubrica=activo&codigo_conta=311", $this->s)->assertStatus(422)->assertJsonPath('codigo', 'RUBRICA_SEM_CONTAS');
        $this->getJson("/api/contabilidade/relatorios/composicao/dr?{$base}&rubrica=4", $this->s)->assertStatus(422)->assertJsonPath('codigo', 'RUBRICA_INVALIDA');
        $this->getJson("/api/contabilidade/relatorios/composicao/dr?{$base}&rubrica=23", $this->sessao(['contab_mapa_balanco_view']))->assertForbidden();
    }

    #[Test]
    public function saldo_credor_das_existencias_receber_ou_disponibilidades_mostra_o_ajuste_para_a_nota_21(): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::create([
            'diario_id' => $this->diario, 'data_documento' => '2026-09-01', 'numero_lan' => 'OD2026000099', 'numero_documento' => 'ADI 1',
            'codigo_conta' => '311', 'tipo_dc' => 'C', 'valor' => 500, 'nota_demonstracao_id' => $this->n['9'],
        ]));
        $base = 'data_inicio=2026-01-01&data_fim=2026-12-31';
        $n9 = $this->getJson("/api/contabilidade/relatorios/composicao/balanco?{$base}&rubrica=9", $this->s)->assertOk()->json('dados');
        $this->assertSame(['0.00', '-444.00', '444.00'], [$n9['valor'], $n9['total_contas'], $n9['ajustes'][0]['valor']]);
        $n21 = $this->getJson("/api/contabilidade/relatorios/composicao/balanco?{$base}&rubrica=21", $this->s)->assertOk()->json('dados');
        $this->assertSame(['574.00', '130.00', '444.00'], [$n21['valor'], $n21['total_contas'], $n21['ajustes'][0]['valor']]);
    }

    // ------------------------------------------------------------------ Actualização em massa: modelo e importação

    #[Test]
    public function modelo_da_actualizacao_em_massa_tem_as_colunas_e_as_listas_de_referencia(): void
    {
        $r = $this->get('/api/contabilidade/rotinas/actualizacao-massa/modelo', $this->s)->assertOk();
        $ficheiro = $r->baseResponse->getFile()->getPathname();
        $livro = IOFactory::load($ficheiro);
        $this->assertSame(['Ajustes', 'Como_Usar', 'Diarios', 'Notas_DEMO', 'Notas_Fluxo', 'Unidades_Negocio', 'Centros_Custo', 'Terceiros'], $livro->getSheetNames());
        $this->assertSame([['lancamento_id', 'campo_a_modificar', 'novo_valor']], $livro->getSheetByName('Ajustes')->rangeToArray('A1:C1'));
        $this->assertSame([[(string) $this->un, 'UN1', 'Norte']], array_map(fn ($l) => array_map('strval', $l), $livro->getSheetByName('Unidades_Negocio')->rangeToArray('A2:C2')));
        $this->assertSame('Alfa Cliente', $livro->getSheetByName('Terceiros')->getCell('C2')->getValue());
        $this->get('/api/contabilidade/rotinas/actualizacao-massa/modelo', $this->sessao(['lancamentos_view']))->assertForbidden();
    }

    #[Test]
    public function importar_o_modelo_valida_linha_a_linha_e_so_grava_ao_aplicar(): void
    {
        $livro = new Spreadsheet;
        $livro->getActiveSheet()->setTitle('Ajustes')->fromArray([
            ['lancamento_id', 'campo_a_modificar', 'novo_valor'],
            [$this->ids['ft_servico'], 'unidade_negocio_id', null],
            [$this->ids['ft_iva'], 'centro_custo_id', $this->cc],
            [null, null, null],
            [$this->ids['ft_servico'], 'valor', '5'],
            [999999, 'descricao', 'x'],
            [$this->ids['ft_iva'], 'description', 'IVA liquidado FT 1'],
        ]);
        $caminho = tempnam(sys_get_temp_dir(), 'r3').'.xlsx';
        (new Xlsx($livro))->save($caminho);
        $ficheiro = new UploadedFile($caminho, 'ajustes.xlsx', null, null, true);

        $r = $this->post('/api/contabilidade/rotinas/actualizacao-massa/importar', ['ficheiro' => $ficheiro], $this->s + ['Accept' => 'application/json'])->assertOk()->json('dados');
        $this->assertTrue($r['simulacao']);
        $this->assertSame(3, $r['validas']);
        // erros com o n.º da linha do Excel (a linha vazia 4 não conta)
        $this->assertSame([[5, 'O campo "valor" não é editável ou não existe.'], [6, 'Lançamento não encontrado nesta empresa.']], array_map(fn ($e) => [$e['linha'], $e['motivo']], $r['erros']));
        $previa = collect($r['previa'])->keyBy('linha');
        $this->assertSame(['UN1 — Norte (id '.$this->un.')', null, 'OK'], [$previa[2]['anterior'], $previa[2]['novo'], $previa[2]['estado']]);
        $this->assertSame([null, 'CC1 — Administração (id '.$this->cc.')'], [$previa[3]['anterior'], $previa[3]['novo']]);
        $this->assertCount(5, $r['linhas']);
        // nada gravado na pré-visualização
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => $this->assertSame($this->un, LancamentoContabil::query()->find($this->ids['ft_servico'])->unidade_negocio_id));

        // tudo ou nada: com erros, nada é gravado
        $this->postJson('/api/contabilidade/rotinas/actualizacao-massa', ['linhas' => $r['linhas'], 'so_validas' => false], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'ACTUALIZACAO_COM_ERROS');
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => $this->assertNull(LancamentoContabil::query()->find($this->ids['ft_iva'])->centro_custo_id));

        // só as válidas: grava em transacção e regista no histórico (anulável)
        $a = $this->postJson('/api/contabilidade/rotinas/actualizacao-massa', ['linhas' => $r['linhas']], $this->s)->assertOk()->json('dados');
        $this->assertSame([3, 2], [$a['actualizadas'], count($a['erros'])]);
        $this->assertSame([5, 6], array_column($a['erros'], 'linha'));
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $this->assertNull(LancamentoContabil::query()->find($this->ids['ft_servico'])->unidade_negocio_id);
            $iva = LancamentoContabil::query()->find($this->ids['ft_iva']);
            $this->assertSame([$this->cc, 'IVA liquidado FT 1'], [$iva->centro_custo_id, $iva->descricao]);
        });
        $this->postJson('/api/contabilidade/rotinas/anular', ['codigo' => $a['codigo'], 'motivo' => 'teste'], $this->s)->assertOk();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => $this->assertSame($this->un, LancamentoContabil::query()->find($this->ids['ft_servico'])->unidade_negocio_id));

        // simulação de linhas coladas: mesma validação, nada gravado
        $s = $this->postJson('/api/contabilidade/rotinas/actualizacao-massa', ['simular' => true, 'linhas' => [['lancamento_id' => $this->ids['ft_iva'], 'campo_a_modificar' => 'descricao', 'novo_valor' => 'y']]], $this->s)
            ->assertOk()->json('dados');
        $this->assertSame([0, 1, null], [$s['actualizadas'], $s['validas'], $s['codigo']]);
        $this->post('/api/contabilidade/rotinas/actualizacao-massa/importar', ['ficheiro' => $ficheiro], $this->sessao(['contab_rotinas_view']) + ['Accept' => 'application/json'])->assertForbidden();
    }
}
