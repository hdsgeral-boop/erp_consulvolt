<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\EntregavelTarefaUnidade;
use App\Models\PeriodoFeriasEmpresa;
use App\Models\PlanoConta;
use App\Models\PlanoFeriasColaborador;
use App\Models\Produto;
use App\Models\TarefaUnidadeOrganica;
use App\Models\Terceiro;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeOrganica;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase J (ADR-078) — portal: os meus entregáveis, registar pelo portal, validar/impedimentos/atrasos do responsável, resumo com
 * aprovacoes_detalhe, equipa directa e férias da equipa, análise do pedido, decisão de compras pelo portal.
 * Árvore: Direcção (Director) › Departamento (Chefe) ‹ Ana, Rui.
 */
final class FjPortalEntregaveisTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    /** @var array<string, array<string, mixed>> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10 10:00:00');
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa FJPortal', 'nif' => '5000888831']);
        $this->em(function () {
            PlanoConta::create(['codigo' => '3211', 'descricao' => 'Fornecedores', 'tipo' => 'M']);
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $mk = fn (string $n) => Colaborador::create(['nome_completo' => $n, 'nif' => $n, 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22, 'data_admissao' => '2020-01-06'])->id;
            $this->ids = ['ana' => $mk('Ana'), 'rui' => $mk('Rui'), 'chefe' => $mk('Chefe'), 'director' => $mk('Director')];
            $dir = UnidadeOrganica::create(['nome' => 'Direcção', 'codigo' => 'DIR', 'tipo' => 'DIRECCAO', 'colaborador_responsavel_id' => $this->ids['director'], 'ativo' => true]);
            $dep = UnidadeOrganica::create(['nome' => 'Departamento', 'codigo' => 'DEP', 'tipo' => 'DEPARTAMENTO', 'unidade_organica_pai_id' => $dir->id, 'colaborador_responsavel_id' => $this->ids['chefe'], 'ativo' => true]);
            Colaborador::query()->whereKey([$this->ids['ana'], $this->ids['rui'], $this->ids['chefe']])->update(['unidade_organica_id' => $dep->id]);
            Colaborador::query()->whereKey($this->ids['director'])->update(['unidade_organica_id' => $dir->id]);
            $t = TarefaUnidadeOrganica::create(['unidade_organica_id' => $dep->id, 'nome' => 'Relatórios', 'periodicidade' => 'MENSAL', 'ativo' => true]);
            $meu = EntregavelTarefaUnidade::create(['tarefa_unidade_id' => $t->id, 'nome' => 'Relatório da Ana', 'metrica' => 'QUANTIDADE', 'unidade' => 'un', 'meta' => 2, 'prazo_dia' => 5, 'prazo_mes' => 2, 'colaborador_id' => $this->ids['ana'], 'ativo' => true]);
            $unidade = EntregavelTarefaUnidade::create(['tarefa_unidade_id' => $t->id, 'nome' => 'Da unidade', 'metrica' => 'QUANTIDADE', 'unidade' => 'un', 'prazo_dia' => 5, 'prazo_mes' => 2, 'alerta' => true, 'ativo' => true]);
            foreach ([2026, 2027] as $ano) {
                PeriodoFeriasEmpresa::create(['ano' => $ano, 'data_inicio' => "{$ano}-01-01", 'data_fim' => "{$ano}-12-31"]);
            }
            $cc = CentroCusto::create(['codigo' => 'CCX', 'descricao' => 'Centro'])->id;
            $produto = Produto::create(['codigo' => 'P1', 'nome' => 'Artigo', 'preco_unitario' => 10, 'taxa_imposto' => 14, 'movimenta_stock' => false])->id;
            Terceiro::create(['nome' => 'Fornecedor', 'nif' => '5000000011', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '3211']);
            $this->ids += ['dir' => $dir->id, 'dep' => $dep->id, 'tarefa' => $t->id, 'meu' => $meu->id, 'unidade' => $unidade->id, 'cc' => $cc, 'produto' => $produto];
        });
        $this->s = ['ana' => $this->sessao(['rh_portal_usar', 'compras_pedidos_view', 'compras_ped_criar'], $this->ids['ana']), 'rui' => $this->sessao(['rh_portal_usar'], $this->ids['rui']),
            'chefe' => $this->sessao(['rh_portal_usar', 'compras_pedidos_view'], $this->ids['chefe']), 'director' => $this->sessao(['rh_portal_usar'], $this->ids['director']),
            'rh' => $this->sessao(['rh_portal_aprovar', 'rh_portal_gestao_view'])];
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

    #[Test]
    public function meus_entregaveis_registar_validar_impedimentos_atrasos_e_resumo(): void
    {
        $ana = $this->s['ana'];
        $chefe = $this->s['chefe'];
        // Ana: dona de um; Chefe: responsável da unidade vê os dois; Rui: nenhum (não tem separador)
        $meus = $this->getJson('/api/rh/portal/entregaveis', $ana)->assertOk()->json('dados');
        $this->assertSame(['Relatório da Ana'], array_column($meus['entregaveis'], 'nome'));
        $this->assertSame(['DONO', 'Departamento', 'EM_ATRASO'], [$meus['entregaveis'][0]['papel'], $meus['entregaveis'][0]['unidade_organica']['nome'], $meus['entregaveis'][0]['resumo']['estado']]);
        $this->assertSame(['RESPONSAVEL', 'RESPONSAVEL'], array_column($this->getJson('/api/rh/portal/entregaveis', $chefe)->json('dados.entregaveis'), 'papel'));
        $this->assertCount(0, $this->getJson('/api/rh/portal/entregaveis', $this->s['rui'])->json('dados.entregaveis'));
        $this->assertSame([true, false], [$this->getJson('/api/rh/portal/resumo', $ana)->json('dados.tem_entregaveis'), $this->getJson('/api/rh/portal/resumo', $this->s['rui'])->json('dados.tem_entregaveis')]);
        $this->getJson('/api/rh/portal/entregaveis', $this->s['rh'])->assertForbidden();   // sem colaborador ligado

        // registar pelo portal: a Ana só por conta própria; o Rui não toca; o Chefe regista pela Ana (membro da unidade), não por estranhos
        $reg = fn (array $h, int $e, array $d = []) => $this->postJson("/api/rh/portal/entregaveis/{$e}/execucoes", $d + ['quantidade' => 1, 'data' => '2026-03-02'], $h);
        $x = $reg($ana, $this->ids['meu'])->assertCreated()->assertJsonPath('dados.origem', 'PORTAL')->assertJsonPath('dados.colaborador_id', $this->ids['ana'])->json('dados');
        $reg($ana, $this->ids['meu'], ['colaborador_id' => $this->ids['rui']])->assertForbidden();
        $reg($ana, $this->ids['unidade'])->assertForbidden();
        $reg($this->s['rui'], $this->ids['meu'])->assertForbidden();
        $reg($chefe, $this->ids['unidade'], ['colaborador_id' => $this->ids['ana']])->assertCreated()->assertJsonPath('dados.registado_por_colaborador_id', $this->ids['chefe']);
        $reg($chefe, $this->ids['unidade'], ['colaborador_id' => $this->ids['director']])->assertStatus(422)->assertJsonPath('codigo', 'COLABORADOR_FORA_DA_UNIDADE');

        // aprovações: o Chefe vê a entrega da Ana (não a que ele lançou); o Director vê as duas (unidade acima)
        $this->assertSame([$x['id']], array_column($this->getJson('/api/rh/portal/aprovacoes/entregas', $chefe)->assertOk()->json('dados'), 'id'));
        $this->assertCount(2, $this->getJson('/api/rh/portal/aprovacoes/entregas', $this->s['director'])->json('dados'));
        $this->assertCount(0, $this->getJson('/api/rh/portal/aprovacoes/entregas', $ana)->json('dados'));
        $this->postJson("/api/rh/portal/execucoes/{$x['id']}/validar", ['decisao' => 'VALIDADA'], $ana)->assertForbidden();
        $this->postJson("/api/rh/portal/execucoes/{$x['id']}/validar", ['decisao' => 'DEVOLVIDA', 'motivo' => 'Falta o anexo'], $chefe)->assertOk()->assertJsonPath('dados.validacao', 'DEVOLVIDA');
        $this->assertSame(['recusadas' => 0, 'devolvidas' => 1], $this->getJson('/api/rh/portal/entregaveis', $ana)->json('dados.contadores'));
        $this->assertSame(1, $this->getJson('/api/rh/portal/resumo', $ana)->json('dados.entregaveis_pendentes'));
        $this->postJson("/api/rh/portal/execucoes/{$x['id']}/reenviar", [], $this->s['rui'])->assertForbidden();
        $this->postJson("/api/rh/portal/execucoes/{$x['id']}/reenviar", [], $ana)->assertOk()->assertJsonPath('dados.validacao', 'PENDENTE');

        // impedimento pela Ana (dona) → o Chefe decide; o resumo do Chefe conta entregas + impedimentos + atrasos
        $i = $this->postJson("/api/rh/portal/entregaveis/{$this->ids['meu']}/impedimentos", ['periodo_de' => '2026-02-01', 'motivo' => 'Sem acesso ao sistema'], $ana)->assertCreated()->json('dados');
        $this->postJson("/api/rh/portal/entregaveis/{$this->ids['meu']}/impedimentos", ['periodo_de' => '2026-02-01', 'motivo' => 'Outra vez'], $this->s['rui'])->assertForbidden();
        $this->assertSame([$i['id']], array_column($this->getJson('/api/rh/portal/aprovacoes/impedimentos', $chefe)->assertOk()->json('dados'), 'id'));
        $atrasos = $this->getJson('/api/rh/portal/aprovacoes/atrasos', $chefe)->assertOk()->json('dados');
        // o da Ana (1 de 2) está em atraso; o da unidade (sem meta) ficou cumprido com a entrega do Chefe a 02/03 (antes do limite 05/03)
        $this->assertSame([$this->ids['meu']], array_column($atrasos, 'entregavel_id'));
        $this->assertSame([1.0, 2.0, 1.0], [$atrasos[0]['realizado'], $atrasos[0]['meta'], $atrasos[0]['falta']]);
        $this->assertCount(0, $this->getJson('/api/rh/portal/aprovacoes/atrasos', $ana)->json('dados'));
        $resumo = $this->getJson('/api/rh/portal/resumo', $chefe)->assertOk()->json('dados');
        $this->assertSame(['pedidos' => 0, 'compras' => 0, 'entregas' => 1, 'impedimentos' => 1, 'atrasos' => 1], $resumo['aprovacoes_detalhe']);
        $this->assertSame(3, $resumo['aprovacoes_para_mim']);
        $this->postJson("/api/rh/portal/impedimentos/{$i['id']}/decidir", ['estado' => 'ACEITE'], $ana)->assertForbidden();
        $this->postJson("/api/rh/portal/impedimentos/{$i['id']}/decidir", ['estado' => 'ACEITE'], $chefe)->assertOk()->assertJsonPath('dados.estado', 'ACEITE');
        $this->assertCount(0, $this->getJson('/api/rh/portal/aprovacoes/atrasos', $chefe)->json('dados'));
        $this->assertSame('JUSTIFICADO', $this->getJson('/api/rh/portal/entregaveis', $ana)->json('dados.entregaveis.0.resumo.estado'));
        // o Início conta as entregas por validar do responsável
        $pend = collect($this->getJson('/api/gestao/inicio', $this->s['director'])->assertOk()->json('dados.pendentes'))->keyBy('id');
        $this->assertSame(2, $pend['estrutura_entregas_validar']['quantidade']);
    }

    #[Test]
    public function equipa_ferias_da_equipa_analise_do_pedido_e_compras_pelo_portal(): void
    {
        $chefe = $this->s['chefe'];
        $ana = $this->s['ana'];
        // férias do Rui já marcadas em Junho; a Ana pede férias sobrepostas → a análise avisa
        $this->em(fn () => PlanoFeriasColaborador::create(['colaborador_id' => $this->ids['rui'], 'ano' => 2026, 'data_inicio' => '2026-06-08', 'data_fim' => '2026-06-12', 'dias' => 5, 'direito' => 22, 'estado' => 'APROVADO']));
        $equipa = $this->getJson('/api/rh/portal/equipa', $chefe)->assertOk()->json('dados');
        $this->assertSame(['Ana', 'Rui'], array_column($equipa, 'nome_completo'));
        $this->assertSame(['Departamento', 5, 17], [$equipa[1]['unidade'], $equipa[1]['ferias']['marcados'], $equipa[1]['ferias']['saldo']]);
        $this->assertCount(0, $this->getJson('/api/rh/portal/equipa', $ana)->json('dados'));
        $ferias = $this->getJson('/api/rh/portal/equipa/ferias?ano=2026', $chefe)->assertOk()->json('dados');
        $this->assertSame([2026, 'Rui', '2026-06-08'], [$ferias['ano'], $ferias['ferias'][0]['colaborador_nome'], substr($ferias['ferias'][0]['data_inicio'], 0, 10)]);

        $p = $this->postJson('/api/rh/portal/pedidos', ['tipo' => 'FERIAS', 'data_inicio' => '2026-06-10', 'data_fim' => '2026-06-16'], $ana)->assertCreated()->json('dados');
        $this->assertSame('PENDENTE_CHEFIA', $p['estado']);
        $this->getJson("/api/rh/portal/pedidos/{$p['id']}/analise", $ana)->assertForbidden();        // a própria não decide
        $this->getJson("/api/rh/portal/pedidos/{$p['id']}/analise", $this->s['rh'])->assertForbidden();   // é a vez da chefia
        $an = $this->getJson("/api/rh/portal/pedidos/{$p['id']}/analise", $chefe)->assertOk()->json('dados');
        $this->assertSame([22, 5, 17, 5], [$an['saldo_ferias']['direito'], $an['saldo_ferias']['marcados'], $an['saldo_ferias']['saldo'], $an['saldo_ferias']['dias_pedidos']]);
        $this->assertSame(['Rui'], array_column($an['equipa_ausente'], 'nome'));
        $this->assertSame(['Aprovar deixa a equipa com 2 pessoa(s) ausente(s) no mesmo período.'], $an['avisos']);
        $this->assertSame([], $an['ultimos_pedidos']);
        $this->assertSame($this->ids['chefe'], $an['colaborador']['chefia_colaborador_id']);
        $this->assertSame(1, $this->getJson('/api/rh/portal/resumo', $chefe)->json('dados.aprovacoes_detalhe.pedidos'));

        // compras: pedido da Ana → 1.º nível = responsável da unidade (Chefe); decide pelo portal com as regras de Compras
        $pc = $this->postJson('/api/compras/pedidos', ['nome_requerente' => 'Ana', 'linhas' => [['produto_id' => $this->ids['produto'], 'quantidade' => 10, 'preco_unitario' => 10]]], $ana)->assertCreated()->json('dados');
        $this->assertSame('RESPONSAVEL', $pc['deliberacao']['etapas'][0]['tipo']);
        $this->assertSame([$pc['id']], array_column($this->getJson('/api/rh/portal/compras/aprovacoes', $chefe)->json('dados'), 'id'));
        $this->assertSame(['pedidos' => 1, 'compras' => 1], array_intersect_key($this->getJson('/api/rh/portal/resumo', $chefe)->json('dados.aprovacoes_detalhe'), ['pedidos' => 1, 'compras' => 1]));
        $this->postJson("/api/rh/portal/compras/{$pc['id']}/decidir", ['decisao' => 'APROVAR'], $ana)->assertForbidden();
        $this->postJson("/api/rh/portal/compras/{$pc['id']}/decidir", ['decisao' => 'APROVAR'], $this->s['director'])->assertForbidden();
        $this->postJson("/api/rh/portal/compras/{$pc['id']}/decidir", ['decisao' => 'APROVAR'], $chefe)->assertOk()->assertJsonPath('dados.estado', 'APROVADO');
        $this->postJson("/api/rh/portal/compras/{$pc['id']}/decidir", ['decisao' => 'APROVAR'], $chefe)->assertStatus(422)->assertJsonPath('codigo', 'SEM_ETAPA_PENDENTE');
    }
}
