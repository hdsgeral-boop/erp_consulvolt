<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Colaborador;
use App\Models\ConfiguracaoProjeto;
use App\Models\Empresa;
use App\Models\EquipaProjeto;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\LinhaRevisaoProjeto;
use App\Models\MembroEquipaProjeto;
use App\Models\NoOrganigramaProjeto;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Projeto;
use App\Models\RevisaoMensalProjeto;
use App\Models\TarefaProjeto;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Services\Projetos\ServicoMigracaoProjetos;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Projectos (ADR-052), parte de gestão: ficha e estados, WBS (milestones, tarefas, execução, mover, eliminar), Kanban,
 * equipa, organigrama, orçamento base, aditamentos e normalização da migração.
 */
final class ProjetosTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000052']);
        $this->naEmpresa(function () {
            foreach (['311' => 'Clientes', '611' => 'Vendas', '3452' => 'IVA liquidado', '75219' => 'Materiais de obra', '7' => 'Custos'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => (string) $c === '7' ? 'T' : 'M']);
            }
            $this->ids['un'] = UnidadeNegocio::create(['codigo' => 'UN1', 'nome' => 'Obras'])->id;
            $this->ids['cc'] = CentroCusto::create(['codigo' => 'CC1', 'descricao' => 'Estaleiro'])->id;
            $this->ids['cliente'] = Terceiro::create(['nome' => 'Cliente A', 'nif' => '5000000001', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
            $this->ids['outro'] = Terceiro::create(['nome' => 'Cliente B', 'nif' => '5000000002', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
            $this->ids['empreiteiro'] = Terceiro::create(['nome' => 'Empreiteiro', 'nif' => '5000000003', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '3211'])->id;
            $this->ids['servico'] = Produto::create(['codigo' => 'S1', 'nome' => 'Serviço de obra', 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => false])->id;
            $this->ids['ana'] = Colaborador::create(['nome_completo' => 'Ana', 'nif' => 'A1', 'estado' => 'ACTIVO', 'dias_uteis_mes' => 22])->id;
            $this->ids['rui'] = Colaborador::create(['nome_completo' => 'Rui', 'nif' => 'R1', 'estado' => 'ACTIVO', 'dias_uteis_mes' => 22])->id;
        });
        $this->s = $this->sessao(['projectos_carteira_view', 'proj_gerir', 'proj_execucao', 'proj_eliminar', 'proj_estado', 'vendas_fat_emitir']);
        $this->ids['ne'] = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'NE', 'cliente_id' => $this->ids['cliente'], 'data_emissao' => now()->toDateString(),
            'linhas' => [['produto_id' => $this->ids['servico'], 'quantidade' => 10]]], $this->s)->assertCreated()->json('dados.id');
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

    private function projetoInterno(): int
    {
        return $this->postJson('/api/projetos', ['nome' => 'Armazém novo', 'tipo' => 'INTERNO', 'unidade_negocio_id' => $this->ids['un'], 'centro_custo_id' => $this->ids['cc']], $this->s)
            ->assertCreated()->json('dados.id');
    }

    #[Test]
    public function ficha_codigo_tipos_e_estados_com_permissao_sensivel(): void
    {
        $s = $this->s;
        $ano = now()->year;
        $this->postJson('/api/projetos', ['nome' => 'X', 'tipo' => 'INTERNO'], $s)->assertStatus(422)->assertJsonPath('codigo', 'DIMENSOES_OBRIGATORIAS');
        $this->postJson('/api/projetos', ['nome' => 'X', 'tipo' => 'EXTERNO', 'cliente_id' => $this->ids['outro'], 'encomenda_venda_id' => $this->ids['ne']], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'ENCOMENDA_OUTRO_CLIENTE');
        $this->postJson('/api/projetos', ['nome' => 'X', 'tipo' => 'EXTERNO', 'cliente_id' => $this->ids['empreiteiro'], 'encomenda_venda_id' => $this->ids['ne']], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CLIENTE_INVALIDO');

        $interno = $this->postJson('/api/projetos', ['nome' => 'Armazém novo', 'tipo' => 'INTERNO', 'unidade_negocio_id' => $this->ids['un'], 'centro_custo_id' => $this->ids['cc']], $s)
            ->assertCreated()->assertJsonPath('dados.codigo', "PRJ-{$ano}-0001")->assertJsonPath('dados.estado', 'ACTIVO')->json('dados.id');
        $externo = $this->postJson('/api/projetos', ['nome' => 'Obra cliente', 'tipo' => 'EXTERNO', 'cliente_id' => $this->ids['cliente'], 'encomenda_venda_id' => $this->ids['ne'],
            'estado' => 'PREPARACAO'], $s)->assertCreated()->assertJsonPath('dados.codigo', "PRJ-{$ano}-0002")->json('dados.id');
        $this->postJson('/api/projetos', ['codigo' => "prj-{$ano}-0001", 'nome' => 'Y', 'tipo' => 'INTERNO', 'unidade_negocio_id' => $this->ids['un'],
            'centro_custo_id' => $this->ids['cc']], $s)->assertStatus(422)->assertJsonPath('codigo', 'CODIGO_DUPLICADO');

        // proj_gerir não encerra (nem pela edição da ficha); proj_estado sim
        $gestor = $this->sessao(['projectos_carteira_view', 'proj_gerir']);
        $this->putJson("/api/projetos/{$interno}", ['estado' => 'ENCERRADO'], $gestor)->assertForbidden();
        $this->postJson("/api/projetos/{$interno}/estado", ['estado' => 'CANCELADO'], $gestor)->assertForbidden();
        $this->putJson("/api/projetos/{$externo}", ['estado' => 'ACTIVO', 'nome' => 'Obra do cliente A'], $gestor)->assertOk()->assertJsonPath('dados.nome', 'Obra do cliente A');
        $this->postJson("/api/projetos/{$interno}/estado", ['estado' => 'ENCERRADO'], $s)->assertOk()->assertJsonPath('dados.estado', 'ENCERRADO');
        $this->getJson('/api/projetos/ativos', $s)->assertOk()->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.id', $externo);
        $this->getJson('/api/projetos?estado=ENCERRADO', $s)->assertOk()->assertJsonPath('dados.0.id', $interno);

        // encerrado não aceita imputações
        $t = $this->postJson("/api/projetos/{$interno}/tarefas", ['nome' => 'Fundações'], $s)->assertCreated()->json('dados.id');
        $this->postJson("/api/projetos/{$interno}/horas", ['tarefa_projeto_id' => $t, 'colaborador_id' => $this->ids['ana'], 'data' => now()->toDateString(), 'horas' => 8], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'PROJETO_FECHADO');
        $this->getJson("/api/projetos/{$interno}/atividade", $s)->assertOk()->assertJsonPath('dados.0.acao', 'Mudar estado');
        $this->getJson('/api/projetos', $this->sessao(['vendas_fat_emitir']))->assertForbidden();
    }

    #[Test]
    public function wbs_execucao_mover_e_eliminar_com_dupla_validacao(): void
    {
        $s = $this->s;
        $p = $this->projetoInterno();
        $m1 = $this->postJson("/api/projetos/{$p}/marcos", ['nome' => 'Fase 1', 'data' => '2026-10-31'], $s)->assertCreated()->json('dados.id');
        $m2 = $this->postJson("/api/projetos/{$p}/marcos", ['nome' => 'Fase 2'], $s)->assertCreated()->json('dados.id');
        $a = $this->postJson("/api/projetos/{$p}/tarefas", ['codigo' => '1', 'nome' => 'Estrutura', 'marco_projeto_id' => $m1, 'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-30'], $s)->json('dados.id');
        $a1 = $this->postJson("/api/projetos/{$p}/tarefas", ['codigo' => '1.1', 'nome' => 'Pilares', 'tarefa_pai_id' => $a, 'marco_projeto_id' => $m1, 'percentagem_execucao' => 50], $s)->json('dados.id');
        $a2 = $this->postJson("/api/projetos/{$p}/tarefas", ['codigo' => '1.2', 'nome' => 'Lajes', 'tarefa_pai_id' => $a, 'marco_projeto_id' => $m1, 'estado' => 'CONCLUIDA', 'percentagem_execucao' => 10], $s)
            ->assertJsonPath('dados.percentagem_execucao', '100.0000')->json('dados.id');
        $b = $this->postJson("/api/projetos/{$p}/tarefas", ['codigo' => '2', 'nome' => 'Acabamentos', 'marco_projeto_id' => $m2, 'percentagem_execucao' => 25], $s)->json('dados.id');
        $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'X', 'data_inicio' => '2026-09-10', 'data_fim' => '2026-09-01'], $s)->assertStatus(422)->assertJsonPath('codigo', 'DATAS_INVALIDAS');
        $this->putJson("/api/projetos/{$p}/tarefas/{$a}", ['tarefa_pai_id' => $a1], $s)->assertStatus(422)->assertJsonPath('codigo', 'CICLO_TAREFAS');

        // execução: pai = média das subtarefas (50, 100 → 75); marco 1 = 75; marco 2 = 25; global = (75 + 25) / 2 = 50
        $w = $this->getJson("/api/projetos/{$p}/wbs", $s)->assertOk()->json('dados');
        $this->assertSame(50, $w['execucao_global']);
        $this->assertSame([75, 25, 0], array_column($w['grupos'], 'execucao'));
        $this->assertSame(75, $w['grupos'][0]['tarefas'][0]['execucao']);
        $this->postJson("/api/projetos/{$p}/tarefas/{$a1}/execucao", ['percentagem_execucao' => 100], $this->sessao(['proj_execucao']))->assertOk();
        $this->assertSame(100, $this->getJson("/api/projetos/{$p}/wbs", $s)->json('dados.grupos.0.execucao'));

        // mover «Acabamentos» para dentro de «Estrutura»: fica subtarefa e passa ao milestone 1; depois para antes de «Pilares»
        $this->postJson("/api/projetos/{$p}/tarefas/{$a}/mover", ['tipo' => 'DENTRO', 'alvo_id' => $a1], $s)->assertStatus(422)->assertJsonPath('codigo', 'CICLO_TAREFAS');
        $this->postJson("/api/projetos/{$p}/tarefas/{$b}/mover", ['tipo' => 'ANTES', 'alvo_id' => $a1], $s)->assertOk()
            ->assertJsonPath('dados.tarefa_pai_id', $a)->assertJsonPath('dados.marco_projeto_id', $m1);
        $ordem = $this->naEmpresa(fn () => TarefaProjeto::query()->where('tarefa_pai_id', $a)->orderBy('ordem')->pluck('id')->all());
        $this->assertSame([$b, $a1, $a2], $ordem);
        // mover a tarefa principal para o milestone 2: as subtarefas acompanham
        $this->postJson("/api/projetos/{$p}/tarefas/{$a}/mover", ['tipo' => 'MARCO', 'marco_projeto_id' => $m2], $s)->assertOk();
        $this->assertSame([$m2], $this->naEmpresa(fn () => TarefaProjeto::query()->where('projeto_id', $p)->distinct()->pluck('marco_projeto_id')->all()));

        // eliminar: confirmação obrigatória; bloqueada com registos; milestone deixa as tarefas sem milestone
        $this->deleteJson("/api/projetos/{$p}/tarefas/{$a}", [], $s)->assertStatus(422)->assertJsonPath('codigo', 'CONFIRMACAO_EM_FALTA');
        $this->naEmpresa(fn () => LinhaOrcamentoProjeto::create(['projeto_id' => $p, 'tarefa_projeto_id' => $a2, 'rubrica' => 'MATERIAIS', 'montante' => 10]));
        $this->deleteJson("/api/projetos/{$p}/tarefas/{$a}", ['confirmacao' => 'eliminar'], $s)->assertStatus(422)->assertJsonPath('codigo', 'TAREFA_COM_REGISTOS')
            ->assertJsonPath('erros.registos.linhas_orcamento', 1);
        $this->deleteJson("/api/projetos/{$p}/tarefas/{$b}", ['confirmacao' => 'ELIMINAR'], $this->sessao(['proj_gerir']))->assertForbidden();
        $this->deleteJson("/api/projetos/{$p}/tarefas/{$b}", ['confirmacao' => 'ELIMINAR'], $s)->assertOk()->assertJsonPath('dados.eliminadas', [$b]);
        $this->deleteJson("/api/projetos/{$p}/marcos/{$m2}", ['confirmacao' => 'ELIMINAR'], $s)->assertOk()->assertJsonPath('dados.tarefas_sem_marco', 3);
    }

    #[Test]
    public function kanban_com_colunas_personalizadas_e_estado_bloqueada(): void
    {
        $s = $this->s;
        $p = $this->projetoInterno();
        $t = $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'Betão'], $s)->json('dados.id');
        $this->putJson("/api/projetos/{$p}/kanban/colunas", ['colunas' => [['id' => 'PENDENTE', 'titulo' => 'A Fazer'], ['id' => 'pendente', 'titulo' => 'Outra']]], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'COLUNA_REPETIDA');
        $this->putJson("/api/projetos/{$p}/kanban/colunas", ['colunas' => [['id' => 'NOVA!', 'titulo' => 'X']]], $s)->assertStatus(422)->assertJsonPath('codigo', 'COLUNA_INVALIDA');
        $this->putJson("/api/projetos/{$p}/kanban/colunas", ['colunas' => [['id' => 'PENDENTE', 'titulo' => 'A Fazer'], ['id' => 'FAZENDO', 'titulo' => 'A fazer já', 'estado' => 'EM_CURSO'],
            ['id' => 'CONCLUIDA', 'titulo' => 'Feito'], ['id' => 'BLOQUEADA', 'titulo' => 'Parado', 'cor' => '#ef4444']]], $s)->assertOk()->assertJsonPath('dados.1.estado', 'EM_CURSO');
        $this->postJson("/api/projetos/{$p}/kanban/mover", ['tarefa_id' => $t, 'coluna' => 'FAZENDO'], $s)->assertOk()
            ->assertJsonPath('dados.estado', 'EM_CURSO')->assertJsonPath('dados.estado_original', 'FAZENDO');
        $k = $this->getJson("/api/projetos/{$p}/kanban", $s)->assertOk()->json('dados');
        $this->assertSame([$t], array_column($k['colunas'][1]['tarefas'], 'id'));

        // BLOQUEADA: com o esquema actual grava-se em estado_original (estado a NULL); o estado efectivo é BLOQUEADA
        $this->postJson("/api/projetos/{$p}/kanban/mover", ['tarefa_id' => $t, 'coluna' => 'BLOQUEADA'], $s)->assertOk()->assertJsonPath('dados.estado_original', 'BLOQUEADA');
        $this->assertSame('BLOQUEADA', $this->naEmpresa(fn () => TarefaProjeto::query()->find($t)->estadoEfetivo()));
        $this->assertSame([$t], array_column($this->getJson("/api/projetos/{$p}/kanban", $s)->json('dados.colunas.3.tarefas'), 'id'));
        $this->postJson("/api/projetos/{$p}/kanban/mover", ['tarefa_id' => $t, 'coluna' => 'CONCLUIDA'], $s)->assertOk()
            ->assertJsonPath('dados.estado', 'CONCLUIDA')->assertJsonPath('dados.percentagem_execucao', '100.0000');
    }

    #[Test]
    public function equipa_organigrama_e_mapeamento_do_orcamento(): void
    {
        $s = $this->s;
        $p = $this->projetoInterno();
        $ana = $this->postJson("/api/projetos/{$p}/equipa/membros", ['tipo' => 'INTERNO', 'colaborador_id' => $this->ids['ana'], 'papel' => 'Directora', 'horas_alocadas' => 4], $s)
            ->assertCreated()->json('dados.id');
        $this->postJson("/api/projetos/{$p}/equipa/membros", ['tipo' => 'INTERNO', 'colaborador_id' => $this->ids['ana']], $s)->assertStatus(422)->assertJsonPath('codigo', 'JA_NA_EQUIPA');
        $this->postJson("/api/projetos/{$p}/equipa/membros", ['tipo' => 'INTERNO', 'colaborador_id' => $this->ids['rui'], 'horas_alocadas' => 12], $s)->assertStatus(422)->assertJsonPath('codigo', 'HORAS_INVALIDAS');
        $this->postJson("/api/projetos/{$p}/equipa/membros/massa", ['tipo' => 'INTERNO', 'ids' => [$this->ids['ana'], $this->ids['rui']], 'horas_alocadas' => 8], $s)
            ->assertCreated()->assertJsonPath('dados.criados', 1)->assertJsonPath('dados.ignorados', 1);
        $emp = $this->postJson("/api/projetos/{$p}/equipa/membros", ['tipo' => 'TERCEIRO', 'terceiro_id' => $this->ids['empreiteiro'], 'papel' => 'Empreiteiro'], $s)->json('dados.id');
        $this->postJson("/api/projetos/{$p}/equipa/membros", ['tipo' => 'LIVRE', 'nome_externo' => 'Topógrafo'], $s)->assertCreated()->assertJsonPath('dados.horas_alocadas', null);
        $membros = $this->getJson("/api/projetos/{$p}/equipa", $s)->assertOk()->json('dados.membros');
        $this->assertSame(['INTERNO', 'INTERNO', 'TERCEIRO', 'LIVRE'], array_column($membros, 'tipo'));
        $this->assertSame(['Ana', 'Rui', 'Empreiteiro', 'Topógrafo'], array_column($membros, 'nome'));
        $rui = $membros[1]['id'];
        $this->postJson("/api/projetos/{$p}/equipa/membros/alterar", ['ids' => [$rui, $emp], 'papel' => 'Encarregado', 'horas_alocadas' => 6], $s)->assertOk()->assertJsonPath('dados.alterados', 2);
        $this->assertSame([null, '6.000'], $this->naEmpresa(fn () => MembroEquipaProjeto::query()->whereIn('id', [$emp, $rui])->orderByDesc('id')->pluck('horas_alocadas')->all()));

        // organigrama: modelo de obra, posições em lote sem repetidos, ciclos proibidos, vagas
        $this->postJson("/api/projetos/{$p}/organigrama/modelo", [], $s)->assertCreated()->assertJsonPath('dados.criadas', 8);
        $this->postJson("/api/projetos/{$p}/organigrama/modelo", [], $s)->assertStatus(422)->assertJsonPath('codigo', 'ORGANIGRAMA_EXISTENTE');
        $org = $this->getJson("/api/projetos/{$p}/organigrama", $s)->assertOk()->json('dados');
        $dir = $org['posicoes'][0]['id'];
        $eng = collect($org['posicoes'])->firstWhere('titulo', 'Engenheiro Residente')['id'];
        $this->postJson("/api/projetos/{$p}/organigrama/posicoes/lote", ['no_pai_id' => $eng, 'linhas' => [['titulo' => 'Chefe de frente', 'area' => 'Norte'],
            ['titulo' => 'Chefe de frente', 'area' => 'Norte']]], $s)->assertStatus(422)->assertJsonPath('codigo', 'POSICOES_REPETIDAS');
        $this->postJson("/api/projetos/{$p}/organigrama/posicoes/lote", ['no_pai_id' => $eng, 'linhas' => [['titulo' => 'Chefe de frente', 'area' => 'Norte', 'vagas' => 1],
            ['titulo' => 'Chefe de frente', 'area' => 'Sul', 'vagas' => 1]]], $s)->assertCreated()->assertJsonPath('dados.criadas', 2);
        $this->putJson("/api/projetos/{$p}/organigrama/posicoes/{$dir}", ['no_pai_id' => $eng], $s)->assertStatus(422)->assertJsonPath('codigo', 'CICLO_POSICOES');
        $norte = $this->naEmpresa(fn () => NoOrganigramaProjeto::query()->where('area', 'Norte')->value('id'));
        $this->postJson("/api/projetos/{$p}/organigrama/alocar", ['membros' => [$rui, $emp], 'no_organigrama_projeto_id' => $norte], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'VAGAS_EXCEDIDAS');
        $this->postJson("/api/projetos/{$p}/organigrama/alocar", ['membros' => [$rui, $emp], 'no_organigrama_projeto_id' => $norte, 'confirmar_excesso' => true], $s)->assertOk();
        $this->postJson("/api/projetos/{$p}/organigrama/alocar", ['membros' => [$ana], 'no_organigrama_projeto_id' => $dir], $s)->assertOk();
        $this->putJson("/api/projetos/{$p}/organigrama/posicoes/{$dir}", ['membro_responsavel_id' => $rui], $s)->assertStatus(422)->assertJsonPath('codigo', 'RESPONSAVEL_INVALIDO');
        $this->putJson("/api/projetos/{$p}/organigrama/posicoes/{$dir}", ['membro_responsavel_id' => $ana], $s)->assertOk()->assertJsonPath('dados.membro_responsavel_id', $ana);

        // orçamento: conta de movimento; mão de obra sem conta; mapear a posição e membro que a ocupa
        $t = $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'Betão'], $s)->json('dados.id');
        $this->postJson("/api/projetos/{$p}/orcamento", ['rubrica' => 'MATERIAIS', 'montante' => 100, 'numero_conta' => '7'], $s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_TOTALIZADORA');
        $this->postJson("/api/projetos/{$p}/orcamento", ['rubrica' => 'MATERIAIS', 'montante' => 0], $s)->assertStatus(422)->assertJsonPath('codigo', 'VALOR_INVALIDO');
        $l1 = $this->postJson("/api/projetos/{$p}/orcamento", ['tarefa_projeto_id' => $t, 'rubrica' => 'MATERIAIS', 'montante' => 1000.5, 'numero_conta' => '75219'], $s)
            ->assertCreated()->assertJsonPath('dados.montante', '1000.50')->json('dados.id');
        $l2 = $this->postJson("/api/projetos/{$p}/orcamento", ['rubrica' => 'MAO_DE_OBRA', 'montante' => 500, 'numero_conta' => '75219'], $s)
            ->assertCreated()->assertJsonPath('dados.numero_conta', null)->json('dados.id');
        $this->getJson("/api/projetos/{$p}/orcamento", $s)->assertOk()->assertJsonPath('dados.total', '1500.50');
        $this->postJson("/api/projetos/{$p}/organigrama/orcamento", ['alteracoes' => [['linha_id' => $l2, 'no_organigrama_projeto_id' => $dir, 'membro_equipa_projeto_id' => $rui]]], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'MEMBRO_INVALIDO');
        $this->postJson("/api/projetos/{$p}/organigrama/orcamento", ['alteracoes' => [['linha_id' => $l2, 'no_organigrama_projeto_id' => $dir, 'membro_equipa_projeto_id' => $ana]]], $s)->assertOk();
        $this->postJson("/api/projetos/{$p}/organigrama/posicoes/{$norte}/tarefas", ['tarefas' => [$t, $t, 999999]], $s)->assertOk()->assertJsonPath('dados.tarefas', [$t]);
        $org = collect($this->getJson("/api/projetos/{$p}/organigrama", $s)->json('dados.posicoes'))->keyBy('id');
        $this->assertSame(['500.00', '500.00'], [$org[$dir]['valores']['orcamento'], $org[$dir]['valores']['orcamento_direto']]);
        $this->assertSame('1000.50', $org[$norte]['valores']['orcamento']);

        // eliminar a posição da direcção: subposições sobem, membros ficam sem posição, orçamento fica sem responsável
        $this->deleteJson("/api/projetos/{$p}/organigrama/posicoes/{$dir}", [], $s)->assertOk();
        $this->naEmpresa(function () use ($eng, $ana, $l2) {
            $this->assertNull(NoOrganigramaProjeto::query()->find($eng)->no_pai_id);
            $this->assertNull(MembroEquipaProjeto::query()->find($ana)->no_organigrama_projeto_id);
            $this->assertNull(LinhaOrcamentoProjeto::query()->find($l2)->membro_equipa_projeto_id);
        });
        // remover membros: saem das tarefas
        $this->putJson("/api/projetos/{$p}/tarefas/{$t}", ['atribuido_a_id' => $emp], $s)->assertOk();
        $this->postJson("/api/projetos/{$p}/equipa/membros/remover", ['ids' => [$emp]], $s)->assertOk()->assertJsonPath('dados.removidos', 1);
        $this->assertNull($this->naEmpresa(fn () => TarefaProjeto::query()->find($t)->atribuido_a_id));
        $this->assertNotNull($l1);
    }

    #[Test]
    public function aditamentos_e_normalizacao_da_migracao(): void
    {
        $s = $this->s;
        $p = $this->projetoInterno();
        $a = $this->postJson("/api/projetos/{$p}/aditamentos", ['descricao' => 'Muro extra', 'montante' => 5000, 'estado' => 'APROVADO'], $s)->assertCreated()->json('dados.id');
        $this->postJson("/api/projetos/{$p}/aditamentos", ['descricao' => 'Redução', 'montante' => -1000], $s)->assertCreated()->assertJsonPath('dados.estado', 'PENDENTE');
        $this->getJson("/api/projetos/{$p}/aditamentos", $s)->assertOk()->assertJsonPath('dados.total_aprovado', '5000.00');
        $this->deleteJson("/api/projetos/{$p}/aditamentos/{$a}", [], $this->sessao(['proj_gerir']))->assertForbidden();
        $this->deleteJson("/api/projetos/{$p}/aditamentos/{$a}", [], $s)->assertOk();

        // migração: colunas do Kanban com chaves inglesas e linha de subempreitada com o id do membro em terceiro_id
        $ids = $this->naEmpresa(function () use ($p) {
            ConfiguracaoProjeto::create(['projeto_id' => $p, 'chave' => 'kanban_cols', 'valor' => json_encode([['id' => 'PENDENTE', 'title' => 'A Fazer', 'color' => '#64748b'],
                ['id' => 'FAZENDO', 'title' => 'Críticas', 'color' => '#94a3b8'], ['id' => 'OUTRA', 'title' => 'Outra', 'color' => '#000000']])]);
            $eq = EquipaProjeto::create(['projeto_id' => $p, 'nome' => 'Equipa Principal']);
            $m = MembroEquipaProjeto::create(['equipa_projeto_id' => $eq->id, 'terceiro_id' => $this->ids['empreiteiro'], 'papel' => 'Empreiteiro']);
            while ($m->id === $this->ids['empreiteiro']) {
                $m->delete();
                $m = MembroEquipaProjeto::create(['equipa_projeto_id' => $eq->id, 'terceiro_id' => $this->ids['empreiteiro'], 'papel' => 'Empreiteiro']);
            }
            $rev = RevisaoMensalProjeto::create(['projeto_id' => $p, 'mes' => 9, 'ano' => 2026, 'estado' => 'PROCESSADO']);
            if (! Terceiro::withoutGlobalScopes()->whereKey($m->id)->exists()) {
                (new Terceiro)->forceFill(['id' => $m->id, 'nome' => 'Outro', 'nif' => '5000000099', 'tipo' => Terceiro::CLIENTE])->save();
            }
            $l = LinhaRevisaoProjeto::create(['revisao_mensal_projeto_id' => $rev->id, 'tipo' => 'SUBEMPREITADA', 'terceiro_id' => $m->id, 'valor_calculado' => 10]);

            return ['linha' => $l->id, 'membro' => $m->id];
        });
        $servico = app(ServicoMigracaoProjetos::class);
        $r1 = $servico->normalizar();
        $r2 = $servico->normalizar();
        $this->assertSame(['kanban' => 1, 'linhas_revisao' => 1], $r1);
        $this->assertSame(['kanban' => 0, 'linhas_revisao' => 0], $r2);
        $this->naEmpresa(function () use ($p, $ids) {
            $cols = json_decode(ConfiguracaoProjeto::query()->where('projeto_id', $p)->value('valor'), true);
            $this->assertSame([['id' => 'PENDENTE', 'titulo' => 'A Fazer', 'cor' => '#64748b', 'estado' => 'PENDENTE'],
                ['id' => 'FAZENDO', 'titulo' => 'Críticas', 'cor' => '#94a3b8', 'estado' => 'EM_CURSO'], ['id' => 'OUTRA', 'titulo' => 'Outra', 'cor' => '#000000', 'estado' => null]], $cols);
            $this->assertSame($this->ids['empreiteiro'], LinhaRevisaoProjeto::query()->find($ids['linha'])->terceiro_id);
        });
        $this->assertNotNull(Projeto::class);
    }
}
