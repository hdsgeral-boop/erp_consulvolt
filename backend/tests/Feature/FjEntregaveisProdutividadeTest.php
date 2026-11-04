<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\ElegibilidadeProdutividadeRH;
use App\Models\Empresa;
use App\Models\EntregavelTarefaUnidade;
use App\Models\ExecucaoEntregavel;
use App\Models\InfotipoSalarial;
use App\Models\ItemProdutividadeRH;
use App\Models\PeriodoProdutividadeRH;
use App\Models\RegistoProdutividadeRH;
use App\Models\TarefaUnidadeOrganica;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeOrganica;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase J (ADR-078) — ponte entregáveis → produtividade: analisar (exclusões com motivo), converter (idempotente, torna elegível,
 * soma ao registo existente), anular; elegibilidade = contrato ∪ tabela.
 */
final class FjEntregaveisProdutividadeTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10 10:00:00');
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa FJP', 'nif' => '5000888811']);
        $this->em(function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $mk = fn (string $n) => Colaborador::create(['nome_completo' => $n, 'nif' => $n, 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22, 'data_admissao' => '2020-01-06'])->id;
            $this->ids = ['ana' => $mk('Ana'), 'rui' => $mk('Rui'), 'chefe' => $mk('Chefe')];
            $dep = UnidadeOrganica::create(['nome' => 'Departamento', 'codigo' => 'DEP', 'tipo' => 'DEPARTAMENTO', 'colaborador_responsavel_id' => $this->ids['chefe'], 'ativo' => true]);
            Colaborador::query()->whereKey([$this->ids['ana'], $this->ids['rui'], $this->ids['chefe']])->update(['unidade_organica_id' => $dep->id]);
            $base = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $prod = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Subsídio de produtividade', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $item = ItemProdutividadeRH::create(['codigo' => 'REL', 'descricao' => 'Relatórios', 'metrica' => 'QUANTIDADE', 'unidade' => 'un', 'preco_unitario' => 1000, 'infotipo_salarial_id' => $prod, 'ativo' => true])->id;
            $outro = ItemProdutividadeRH::create(['codigo' => 'OUT', 'descricao' => 'Outro', 'metrica' => 'QUANTIDADE', 'unidade' => 'un', 'preco_unitario' => 10, 'infotipo_salarial_id' => $prod, 'ativo' => true])->id;
            // só o Rui tem o item REL na elegibilidade (migrada do contrato, origem CONTRATO, a 1500); a Ana ganha a elegibilidade por executar o entregável
            foreach (['ana', 'rui'] as $k) {
                ContratoTrabalho::create(['colaborador_id' => $this->ids[$k], 'estado' => 'ACTIVO', 'dias_contrato_mes' => 22, 'horas_por_dia' => 8, 'data_inicio' => '2020-01-06',
                    'remuneracoes' => [['infotipo_id' => $base, 'valor_mes' => 100000]]]);
            }
            ElegibilidadeProdutividadeRH::create(['colaborador_id' => $this->ids['rui'], 'item_produtividade_id' => $item, 'ativo' => true, 'origem' => 'CONTRATO', 'preco_unitario' => 1500]);
            $t = TarefaUnidadeOrganica::create(['unidade_organica_id' => $dep->id, 'nome' => 'Relatórios', 'periodicidade' => 'MENSAL', 'ativo' => true]);
            $e = EntregavelTarefaUnidade::create(['tarefa_unidade_id' => $t->id, 'nome' => 'Relatório', 'metrica' => 'QUANTIDADE', 'unidade' => 'unidades', 'meta' => 2, 'item_produtividade_id' => $item,
                'prazo_dia' => 5, 'prazo_mes' => 2, 'paga_fora_prazo' => false, 'ativo' => true]);
            $semItem = EntregavelTarefaUnidade::create(['tarefa_unidade_id' => $t->id, 'nome' => 'Sem item', 'metrica' => 'QUANTIDADE', 'unidade' => 'un', 'ativo' => true]);
            $per = PeriodoProdutividadeRH::create(['mes' => '2026-02', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-02-28', 'estado' => 'ABERTO']);
            $this->ids += ['dep' => $dep->id, 'prod' => $prod, 'item' => $item, 'outro' => $outro, 'tarefa' => $t->id, 'ent' => $e->id, 'sem_item' => $semItem->id, 'periodo' => $per->id];
        });
        $this->s = $this->sessao(['est_estrutura_view', 'est_editar', 'est_validar_entregas', 'rh_produtividade_view', 'rh_prod_registar', 'rh_prod_config', 'rh_prod_periodo']);
    }

    private function em(\Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes, ?int $colaborador = null): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id, ['colaborador_id' => $colaborador]);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function exec(string $quem, string $data, float $q, int $ent = 0, string $validacao = 'VALIDADA', ?string $url = null): int
    {
        return $this->em(fn () => ExecucaoEntregavel::create(['entregavel_id' => $ent ?: $this->ids['ent'], 'tarefa_unidade_id' => $this->ids['tarefa'], 'unidade_organica_id' => $this->ids['dep'],
            'colaborador_id' => $this->ids[$quem], 'quantidade' => $q, 'data' => $data, 'validacao' => $validacao, 'origem' => 'MANUAL', 'url_evidencia' => $url])->id);
    }

    #[Test]
    public function analisar_exclui_com_motivo_e_converter_e_idempotente_e_torna_elegivel(): void
    {
        $p = $this->ids['periodo'];
        // Fevereiro: o entregável «dia 5 do mês seguinte» serve Janeiro até 05/02 → entregas depois de 05/02 estão fora do prazo e o entregável não paga fora de prazo
        $a1 = $this->exec('ana', '2026-02-03', 1, 0, 'VALIDADA', 'https://drive.x/a');
        $a2 = $this->exec('ana', '2026-02-04', 2);
        $this->exec('ana', '2026-02-20', 5);                        // fora do prazo
        $this->exec('rui', '2026-02-04', 3, 0, 'PENDENTE');          // não validada
        $r1 = $this->exec('rui', '2026-02-05', 4);                   // Rui: no limite → conta; preço do contrato (1500)
        $this->exec('rui', '2026-02-04', 7, $this->ids['sem_item']); // sem item
        $this->exec('ana', '2026-03-02', 9);                          // fora da janela do período

        $an = $this->getJson("/api/rh/produtividade/periodos/{$p}/entregaveis/analise", $this->s)->assertOk()->json('dados');
        $this->assertSame(['FORA_DO_PRAZO', 'NAO_VALIDADA', 'SEM_ITEM'], collect($an['excluidas'])->pluck('codigo')->sort()->values()->all());
        $validas = collect($an['validas'])->keyBy('colaborador_id');
        $this->assertSame([3.0, '3000.00', false], [$validas[$this->ids['ana']]['quantidade'], $validas[$this->ids['ana']]['valor'], $validas[$this->ids['ana']]['ja_elegivel']]);
        $this->assertSame([4.0, '6000.00', true], [$validas[$this->ids['rui']]['quantidade'], $validas[$this->ids['rui']]['valor'], $validas[$this->ids['rui']]['ja_elegivel']]);
        $this->assertSame("ORG|e{$this->ids['ent']}|c{$this->ids['ana']}|i{$this->ids['item']}|p{$p}", $validas[$this->ids['ana']]['origem_chave']);
        $this->assertSame([$this->ids['ana']], array_column($an['por_elegibilizar'], 'colaborador_id'));
        $this->assertSame('9000.00', $an['total']);
        // filtro por unidade: outra unidade → nada
        $this->assertCount(0, $this->getJson("/api/rh/produtividade/periodos/{$p}/entregaveis/analise?unidades[]=999999", $this->s)->json('dados.validas'));

        // converter: 2 registos ESTRUTURA, 3 execuções marcadas, Ana elegível (ENTREGAVEL)
        $r = $this->postJson("/api/rh/produtividade/periodos/{$p}/entregaveis/converter", [], $this->s)->assertOk()->json('dados');
        $this->assertSame([2, 0, 3, 1], [$r['criados'], $r['actualizados'], $r['marcadas'], $r['elegiveis']]);
        $regs = $this->em(fn () => RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p)->orderBy('colaborador_id')->get());
        $this->assertSame(['ESTRUTURA', 'ESTRUTURA'], $regs->pluck('origem')->all());
        $ana = $regs->firstWhere('colaborador_id', $this->ids['ana']);
        $this->assertSame(['3.000', '3000.00', [$a1, $a2], ['https://drive.x/a'], 1], [(string) $ana->quantidade, (string) $ana->valor, $ana->fonte['execucoes'], $ana->fonte['evidencias'], $ana->fonte['sem_evidencia']]);
        $this->assertSame('6000.00', (string) $regs->firstWhere('colaborador_id', $this->ids['rui'])->valor);
        $this->assertSame('ENTREGAVEL', $this->em(fn () => ElegibilidadeProdutividadeRH::query()->where('colaborador_id', $this->ids['ana'])->value('origem')));
        $this->assertSame($p, $this->em(fn () => ExecucaoEntregavel::query()->find($r1)->periodo_produtividade_id));
        // repetir: nada por converter
        $this->postJson("/api/rh/produtividade/periodos/{$p}/entregaveis/converter", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NADA_A_CONVERTER');
        // nova entrega validada da Ana → soma ao registo existente (chave idempotente), sem duplicar
        $this->exec('ana', '2026-02-02', 1);
        $an2 = $this->getJson("/api/rh/produtividade/periodos/{$p}/entregaveis/analise", $this->s)->json('dados');
        $this->assertSame(4.0, collect($an2['validas'])->firstWhere('colaborador_id', $this->ids['ana'])['quantidade_total']);
        $this->assertSame('1000.00', $an2['total']);   // só o acréscimo
        $r = $this->postJson("/api/rh/produtividade/periodos/{$p}/entregaveis/converter", [], $this->s)->assertOk()->json('dados');
        $this->assertSame([0, 1, 1], [$r['criados'], $r['actualizados'], $r['marcadas']]);
        $this->assertSame(2, $this->em(fn () => RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p)->count()));
        $this->assertSame('4.000', (string) $this->em(fn () => RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p)->where('colaborador_id', $this->ids['ana'])->value('quantidade')));

        // período fechado: não se analisa nem anula
        $this->postJson("/api/rh/produtividade/periodos/{$p}/fechar", [], $this->s)->assertOk();
        $this->getJson("/api/rh/produtividade/periodos/{$p}/entregaveis/analise", $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_FECHADO');
        $this->postJson("/api/rh/produtividade/periodos/{$p}/entregaveis/anular", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_FECHADO');
        $this->postJson("/api/rh/produtividade/periodos/{$p}/reabrir", ['motivo' => 'Refazer a conversão'], $this->s)->assertOk();
        // anular: execuções voltam a «por converter», registos ESTRUTURA apagados
        $this->postJson("/api/rh/produtividade/periodos/{$p}/entregaveis/anular", [], $this->s)->assertOk()->assertJsonPath('dados.execucoes', 4)->assertJsonPath('dados.registos', 2);
        $this->assertSame(0, $this->em(fn () => ExecucaoEntregavel::query()->whereNotNull('periodo_produtividade_id')->count()));
        $this->assertSame(0, $this->em(fn () => RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p)->count()));
        // sem rh_prod_registar não se converte
        $this->postJson("/api/rh/produtividade/periodos/{$p}/entregaveis/converter", [], $this->sessao(['rh_produtividade_view']))->assertForbidden();
    }

    #[Test]
    public function elegibilidade_e_a_uniao_do_contrato_com_a_tabela(): void
    {
        $p = $this->ids['periodo'];
        $elegiveis = fn () => collect($this->getJson("/api/rh/produtividade/periodos/{$p}", $this->s)->assertOk()->json('dados.elegiveis'))->keyBy('colaborador_id');
        $this->assertSame([$this->ids['rui']], $elegiveis()->keys()->all());
        // tabela: Ana ganha o item OUT ao preço do item (linha sem preço) e, depois, o REL a 1200
        $this->postJson('/api/rh/produtividade/elegibilidade', ['colaborador_id' => $this->ids['ana'], 'item_produtividade_id' => $this->ids['outro']], $this->s)->assertCreated()->assertJsonPath('dados.origem', 'MANUAL');
        $this->postJson('/api/rh/produtividade/elegibilidade', ['colaborador_id' => $this->ids['ana'], 'item_produtividade_id' => $this->ids['item'], 'preco_unitario' => 1200, 'nota' => 'Acordo'], $this->s)->assertCreated();
        $e = $elegiveis();
        $this->assertSame(['10.0000', 'MANUAL'], [$e[$this->ids['ana']]['itens'][$this->ids['outro']]['preco'], $e[$this->ids['ana']]['itens'][$this->ids['outro']]['origem']]);
        $this->assertSame('1200.0000', $e[$this->ids['ana']]['itens'][$this->ids['item']]['preco']);
        $this->assertSame([1500.0, 'CONTRATO'], [(float) $e[$this->ids['rui']]['itens'][$this->ids['item']]['preco'], $e[$this->ids['rui']]['itens'][$this->ids['item']]['origem']]);
        // idempotente: repetir reactiva/actualiza em vez de duplicar; desactivar retira
        $this->postJson('/api/rh/produtividade/elegibilidade', ['colaborador_id' => $this->ids['ana'], 'item_produtividade_id' => $this->ids['outro'], 'ativo' => false], $this->s)->assertOk()->assertJsonPath('dados.ativo', false);
        $this->assertSame(3, $this->em(fn () => ElegibilidadeProdutividadeRH::query()->count()));
        $this->assertArrayNotHasKey($this->ids['outro'], $elegiveis()[$this->ids['ana']]['itens']);
        // registo manual de produtividade para a Ana passa a ser aceite (elegível pela tabela)
        $this->postJson("/api/rh/produtividade/periodos/{$p}/registos", ['colaborador_id' => $this->ids['ana'], 'item_produtividade_id' => $this->ids['item'], 'quantidade' => 2, 'data' => '2026-02-10'], $this->s)
            ->assertCreated()->assertJsonPath('dados.preco_unitario', '1200.0000');
        $lista = $this->getJson("/api/rh/produtividade/elegibilidade?colaborador_id={$this->ids['rui']}", $this->s)->assertOk()->json('dados');
        $this->assertSame([1, 'CONTRATO'], [count($lista['linhas']), $lista['linhas'][0]['origem']]);
        $this->assertArrayNotHasKey('contrato', $lista);
        $linha = $this->em(fn () => ElegibilidadeProdutividadeRH::query()->where('item_produtividade_id', $this->ids['outro'])->value('id'));
        $this->deleteJson("/api/rh/produtividade/elegibilidade/{$linha}", [], $this->s)->assertOk();
        $this->postJson('/api/rh/produtividade/elegibilidade', ['colaborador_id' => $this->ids['ana'], 'item_produtividade_id' => $this->ids['outro']], $this->sessao(['rh_produtividade_view']))->assertForbidden();
        // item em uso na elegibilidade não se elimina
        $this->deleteJson("/api/rh/produtividade/itens/{$this->ids['item']}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');
    }
}
