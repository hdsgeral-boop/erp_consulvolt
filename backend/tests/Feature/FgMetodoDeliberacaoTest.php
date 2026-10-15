<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\ItemCompra;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\MarcoProjeto;
use App\Models\PedidoCompra;
use App\Models\Produto;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Models\UnidadeOrganica;
use App\Services\Projetos\ServicoEquipasProjetos;
use App\Services\Projetos\ServicoProjetos;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase G (Versão Actual) — deliberação do assunto 5W1H pelo nível acima no organigrama e integrações
 * (js/modules/metodo/assuntos.js:185-451): aprovador = chefia do colaborador ligado a quem submete; só ele (ou
 * `mt_aprovar`) decide, nunca o criador; aprovar põe em curso e cria o projecto (fase, tarefas, equipa, orçamento) e o
 * pedido de compra (pelo circuito de Compras); devolver / recusar com nota; aprovação automática sem chefia; projecto
 * existente; encerramento bloqueado pelo projecto aberto e pelo pedido por aprovar. Dados fictícios.
 */
final class FgMetodoDeliberacaoTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $autor;

    private array $chefe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417300011']);
        $this->naEmpresa(function () {
            $this->ids['un'] = UnidadeNegocio::create(['codigo' => 'UN1', 'nome' => 'Produção fictícia'])->id;
            $this->ids['cc'] = CentroCusto::create(['codigo' => 'CC1', 'descricao' => 'Fábrica fictícia'])->id;
            $mk = fn (string $n, string $nif) => Colaborador::create(['nome_completo' => $n, 'nif' => $nif, 'estado' => 'ACTIVO', 'dias_uteis_mes' => 22])->id;
            $this->ids['dir'] = $mk('Directora Fictícia', 'MT101');
            $this->ids['tec'] = $mk('Técnico Fictício', 'MT102');
            // Direcção (responsável: directora) › Departamento (sem responsável) onde está o técnico
            $dir = UnidadeOrganica::create(['nome' => 'Direcção', 'codigo' => 'DIR', 'tipo' => 'DIRECCAO', 'colaborador_responsavel_id' => $this->ids['dir'], 'ativo' => true]);
            $dep = UnidadeOrganica::create(['nome' => 'Departamento técnico', 'codigo' => 'DEP', 'tipo' => 'DEPARTAMENTO', 'unidade_organica_pai_id' => $dir->id, 'ativo' => true]);
            Colaborador::query()->whereKey($this->ids['dir'])->update(['unidade_organica_id' => $dir->id]);
            Colaborador::query()->whereKey($this->ids['tec'])->update(['unidade_organica_id' => $dep->id]);
            $this->ids['forn'] = Terceiro::create(['nome' => 'Fornecedor Fictício', 'nif' => '5000000811', 'tipo' => Terceiro::FORNECEDOR])->id;
            $this->ids['artigo'] = Produto::create(['codigo' => 'MT1', 'nome' => 'Bomba de água fictícia', 'preco_unitario' => 0, 'movimenta_stock' => false])->id;
        });
        $this->autor = $this->sessao(['mt_assuntos_view', 'mt_criar'], $this->ids['tec']);
        $this->chefe = $this->sessao(['mt_assuntos_view'], $this->ids['dir']);
    }

    private function naEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes, ?int $colaborador = null): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id, ['colaborador_id' => $colaborador]);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function assunto(array $d = [], ?array $sessao = null): int
    {
        return $this->postJson('/api/metodo/assuntos', $d + [
            'titulo' => 'Bomba de água avariada na linha 2',
            'responsaveis' => [['tipo' => 'COLABORADOR', 'ref_id' => $this->ids['tec'], 'nome' => 'Técnico Fictício'],
                ['tipo' => 'FORNECEDOR', 'ref_id' => $this->ids['forn'], 'nome' => 'Fornecedor Fictício'], ['tipo' => 'EXTERNO', 'nome' => 'Canalizador Externo']],
            'responsavel_principal' => "COLABORADOR:{$this->ids['tec']}",
            'data_inicio' => now()->addDays(2)->toDateString(), 'data_fim' => now()->addDays(20)->toDateString(),
            'local_tipo' => 'INTERNO', 'unidade_negocio_id' => $this->ids['un'],
            'fundamentacao' => 'A linha 2 está parada há dois dias por falta de pressão de água; cada dia custa uma encomenda.',
            'recursos' => "- Bomba nova\n- Montagem e ensaio", 'custo_estimado' => 480000,
            'metodo_resolucao' => 'PROJECTO', 'projeto_modo' => 'NOVO', 'centro_custo_id' => $this->ids['cc'],
            'gerar_pedido_compra' => true, 'linhas_pedido_compra' => [['produto_id' => $this->ids['artigo'], 'quantidade' => 1, 'preco_unitario' => 480000]],
        ], $sessao ?? $this->autor)->assertCreated()->json('dados.assunto.id');
    }

    #[Test]
    public function chefia_aprova_e_nascem_o_projecto_e_o_pedido_de_compra(): void
    {
        $ano = now()->year;
        $id = $this->assunto();
        $this->getJson('/api/metodo/assuntos/aprovador', $this->autor)->assertOk()->assertJsonPath('dados.colaborador_id', $this->ids['dir'])
            ->assertJsonPath('dados.proprio', false);

        $r = $this->postJson("/api/metodo/assuntos/{$id}/submeter", [], $this->autor)->assertOk()->assertJsonPath('dados.aprovado', false)
            ->assertJsonPath('dados.assunto.deliberacao.estado', 'PENDENTE')->assertJsonPath('dados.assunto.deliberacao.aprovador_nome', 'Directora Fictícia')
            ->assertJsonPath('dados.assunto.estado', 'RASCUNHO');
        $this->assertStringContainsString('Directora Fictícia', $r->json('mensagem'));
        $this->postJson("/api/metodo/assuntos/{$id}/submeter", [], $this->autor)->assertForbidden();

        // à espera de decisão: o autor não altera nem decide; um terceiro não vê nem decide
        $this->putJson("/api/metodo/assuntos/{$id}", ['titulo' => 'Mudado'], $this->autor)->assertForbidden();
        $this->postJson("/api/metodo/assuntos/{$id}/decidir", ['accao' => 'APROVAR'], $this->autor)->assertForbidden();
        $outro = $this->sessao(['mt_assuntos_view']);
        $this->getJson("/api/metodo/assuntos/{$id}", $outro)->assertForbidden();
        $this->postJson("/api/metodo/assuntos/{$id}/decidir", ['accao' => 'APROVAR'], $outro)->assertForbidden();

        // a chefia vê-o na lista, com a decisão a seu cargo
        $this->getJson('/api/metodo/assuntos', $this->chefe)->assertOk()->assertJsonCount(1, 'dados.assuntos')->assertJsonPath('dados.assuntos.0.pode_decidir', true)
            ->assertJsonPath('dados.alertas.aguardam_minha_decisao', ["AS {$ano}/001"]);
        $det = $this->postJson("/api/metodo/assuntos/{$id}/decidir", ['accao' => 'APROVAR'], $this->chefe)->assertOk()
            ->assertJsonPath('dados.estado', 'EM_CURSO')->assertJsonPath('dados.deliberacao.estado', 'APROVADO')->json('dados');

        // projecto: código PRJ-<nº>, origem, fase, 2 tarefas (linhas dos recursos), equipa (3), orçamento DIVERSOS
        $p = $this->naEmpresa(fn () => Projeto::query()->findOrFail($det['projeto_id']));
        $this->assertSame("PRJ-AS{$ano}/001", $p->codigo);
        $this->assertSame(['INTERNO', 'ACTIVO', 'METODO_TRABALHO', $id, $this->ids['un'], $this->ids['cc']],
            [$p->tipo, $p->estado, $p->origem, (int) $p->assunto_metodo_id, (int) $p->unidade_negocio_id, (int) $p->centro_custo_id]);
        $this->naEmpresa(function () use ($p) {
            $this->assertSame(1, MarcoProjeto::query()->where('projeto_id', $p->id)->count());
            $this->assertSame(['Bomba nova', 'Montagem e ensaio'], TarefaProjeto::query()->where('projeto_id', $p->id)->orderBy('codigo')->pluck('nome')->all());
            $membros = collect(app(ServicoEquipasProjetos::class)->membros($p));
            $this->assertCount(3, $membros);
            $this->assertSame('Responsável principal', $membros->firstWhere('colaborador_id', $this->ids['tec'])['papel']);
            $this->assertSame('480000.00', (string) LinhaOrcamentoProjeto::query()->where('projeto_id', $p->id)->where('rubrica', 'DIVERSOS')->value('montante'));
        });
        // pedido de compra: pelo circuito de Compras (PENDENTE, deliberação por valor), ligado ao projecto e ao assunto
        $pc = $this->naEmpresa(fn () => PedidoCompra::query()->findOrFail($det['pedido_compra_id']));
        $this->assertSame('PENDENTE', $pc->estado);
        $this->assertSame($p->id, (int) $pc->projeto_id);
        $this->assertStringStartsWith("AS {$ano}/001 — ", (string) $pc->descricao);
        $this->assertNotEmpty($pc->deliberacao['etapas'] ?? []);
        $this->assertSame(1, $this->naEmpresa(fn () => ItemCompra::query()->where('pedido_compra_id', $pc->id)->count()));
        $this->assertSame($pc->numero_pedido, $det['pedido_compra']['numero']);

        // em curso: método e pedido ficam fixos; o responsável edita o resto
        $this->getJson('/api/metodo/assuntos', $this->autor)->assertJsonPath('dados.alertas.bloqueados', ["AS {$ano}/001"]);
        $this->putJson("/api/metodo/assuntos/{$id}", ['titulo' => 'Bomba de água avariada na linha 2 (urgente)', 'metodo_resolucao' => 'ACCAO', 'gerar_pedido_compra' => false]
            + $det, $this->autor)->assertOk()->assertJsonPath('dados.assunto.metodo_resolucao', 'PROJECTO')->assertJsonPath('dados.assunto.gerar_pedido_compra', true);

        // encerrar: bloqueado pelo projecto aberto e depois pelo pedido por aprovar
        $this->postJson("/api/metodo/assuntos/{$id}/estado", ['estado' => 'CONCLUIDO'], $this->autor)->assertStatus(422)->assertJsonPath('codigo', 'PROJETO_ABERTO');
        $this->naEmpresa(fn () => app(ServicoProjetos::class)->mudarEstado($p, 'ENCERRADO'));
        $this->postJson("/api/metodo/assuntos/{$id}/estado", ['estado' => 'CONCLUIDO'], $this->autor)->assertStatus(422)->assertJsonPath('codigo', 'PEDIDO_NAO_APROVADO');
        $this->naEmpresa(fn () => PedidoCompra::query()->whereKey($pc->id)->update(['estado' => 'APROVADO']));
        $this->postJson("/api/metodo/assuntos/{$id}/estado", ['estado' => 'CONCLUIDO'], $this->chefe)->assertForbidden();   // a chefia não é responsável nem gestora
        $this->postJson("/api/metodo/assuntos/{$id}/estado", ['estado' => 'CONCLUIDO'], $this->autor)->assertOk()->assertJsonPath('dados.estado', 'CONCLUIDO');

        $acoes = array_column($this->getJson("/api/metodo/assuntos/{$id}/historico", $this->autor)->json('dados.registos'), 'acao');
        foreach (['Criado', 'Submetido a aprovação', 'Aprovado', 'Projecto de resolução', 'Pedido de compra', 'Estado: Em curso', 'Estado: Concluído'] as $a) {
            $this->assertContains($a, $acoes);
        }
    }

    #[Test]
    public function devolver_e_recusar_com_nota_e_reabrir(): void
    {
        $id = $this->assunto(['metodo_resolucao' => 'ACCAO', 'gerar_pedido_compra' => false, 'linhas_pedido_compra' => []]);
        // submeter exige a validação completa
        $this->putJson("/api/metodo/assuntos/{$id}", ['titulo' => 'Incompleto'], $this->autor)->assertOk();
        $this->postJson("/api/metodo/assuntos/{$id}/submeter", [], $this->autor)->assertStatus(422)->assertJsonPath('codigo', 'ASSUNTO_INCOMPLETO');
        $this->putJson("/api/metodo/assuntos/{$id}", $this->dados($id, ['titulo' => 'Rever a escala de turnos']), $this->autor)->assertOk();
        $this->postJson("/api/metodo/assuntos/{$id}/submeter", [], $this->autor)->assertOk();

        $this->postJson("/api/metodo/assuntos/{$id}/decidir", ['accao' => 'DEVOLVER'], $this->chefe)->assertStatus(422)->assertJsonPath('codigo', 'NOTA_OBRIGATORIA');
        $this->postJson("/api/metodo/assuntos/{$id}/decidir", ['accao' => 'DEVOLVER', 'nota' => 'Detalhe o custo por turno.'], $this->chefe)->assertOk()
            ->assertJsonPath('dados.estado', 'RASCUNHO')->assertJsonPath('dados.deliberacao.estado', 'DEVOLVIDO');
        $this->postJson("/api/metodo/assuntos/{$id}/decidir", ['accao' => 'APROVAR'], $this->chefe)->assertStatus(422)->assertJsonPath('codigo', 'SEM_APROVACAO_PENDENTE');
        // corrigido e ressubmetido; agora recusado → cancelado com o motivo
        $this->putJson("/api/metodo/assuntos/{$id}", $this->dados($id, ['custo_estimado' => 90000]), $this->autor)->assertOk();
        $this->postJson("/api/metodo/assuntos/{$id}/submeter", [], $this->autor)->assertOk()->assertJsonPath('dados.assunto.deliberacao.estado', 'PENDENTE');
        $this->postJson("/api/metodo/assuntos/{$id}/decidir", ['accao' => 'RECUSAR', 'nota' => 'Fora do orçamento deste ano.'], $this->chefe)->assertOk()
            ->assertJsonPath('dados.estado', 'CANCELADO')->assertJsonPath('dados.motivo_cancelamento', 'Recusado: Fora do orçamento deste ano.');
        // reabrir limpa a deliberação: volta a precisar de aprovação
        $this->postJson("/api/metodo/assuntos/{$id}/estado", ['estado' => 'RASCUNHO'], $this->autor)->assertOk()->assertJsonPath('dados.deliberacao', null)
            ->assertJsonPath('dados.permissoes.submeter', true);
    }

    #[Test]
    public function aprovacao_automatica_gestor_e_projecto_existente(): void
    {
        // sem colaborador ligado e sem a tarefa de aprovação: fica a aguardar quem a tem (ADR-071 — sem auto-aprovação)
        $solto = $this->sessao(['mt_assuntos_view', 'mt_criar']);
        $id0 = $this->assunto(['metodo_resolucao' => 'ACCAO', 'gerar_pedido_compra' => false, 'linhas_pedido_compra' => []], $solto);
        $this->postJson("/api/metodo/assuntos/{$id0}/submeter", [], $solto)->assertOk()->assertJsonPath('dados.aprovado', false)
            ->assertJsonPath('dados.assunto.deliberacao.estado', 'PENDENTE');

        // sem colaborador ligado mas com a tarefa de aprovação: aprovação automática e registada como tal
        $solto = $this->sessao(['mt_assuntos_view', 'mt_criar', 'mt_aprovar']);
        $id = $this->assunto(['metodo_resolucao' => 'ACCAO', 'gerar_pedido_compra' => false, 'linhas_pedido_compra' => []], $solto);
        $this->postJson("/api/metodo/assuntos/{$id}/submeter", [], $solto)->assertOk()->assertJsonPath('dados.aprovado', true)
            ->assertJsonPath('dados.assunto.estado', 'EM_CURSO')->assertJsonPath('dados.assunto.deliberacao.automatica', true);
        $this->assertContains('Aprovado automaticamente', array_column($this->getJson("/api/metodo/assuntos/{$id}/historico", $solto)->json('dados.registos'), 'acao'));

        // a directora (topo do organigrama) também é aprovada automaticamente
        $dir = $this->sessao(['mt_assuntos_view', 'mt_criar'], $this->ids['dir']);
        $this->getJson('/api/metodo/assuntos/aprovador', $dir)->assertOk()->assertJsonPath('dados.proprio', true);

        // projecto existente: liga-se sem criar outro; um projecto preso a outro assunto não se escolhe
        $existente = $this->naEmpresa(fn () => app(ServicoProjetos::class)->guardar(['nome' => 'Obra de manutenção fictícia', 'tipo' => 'INTERNO',
            'unidade_negocio_id' => $this->ids['un'], 'centro_custo_id' => $this->ids['cc']])->id);
        $this->getJson('/api/metodo/assuntos/projetos-disponiveis', $this->autor)->assertOk()->assertJsonFragment(['id' => $existente]);
        $id2 = $this->assunto(['metodo_resolucao' => 'PROJECTO', 'projeto_modo' => 'EXISTENTE', 'projeto_id' => $existente, 'centro_custo_id' => null]);
        $this->postJson("/api/metodo/assuntos/{$id2}/submeter", [], $this->autor)->assertOk();
        // o gestor (mt_aprovar) decide mesmo não sendo a chefia
        $gestor = $this->sessao(['mt_aprovar']);
        $this->postJson("/api/metodo/assuntos/{$id2}/decidir", ['accao' => 'APROVAR'], $gestor)->assertOk()->assertJsonPath('dados.projeto_id', $existente);
        $this->assertSame($id2, (int) $this->naEmpresa(fn () => Projeto::query()->whereKey($existente)->value('assunto_metodo_id')));
        $this->assertSame(1, $this->naEmpresa(fn () => Projeto::query()->count()));
        $this->postJson('/api/metodo/assuntos', ['titulo' => 'Outro', 'metodo_resolucao' => 'PROJECTO', 'projeto_modo' => 'EXISTENTE', 'projeto_id' => $existente], $this->autor)
            ->assertStatus(422)->assertJsonPath('codigo', 'REFERENCIA_INVALIDA');
        $this->getJson('/api/metodo/assuntos/projetos-disponiveis', $this->autor)->assertOk()->assertJsonMissing(['id' => $existente]);
    }

    private function dados(int $id, array $mais): array
    {
        $a = $this->getJson("/api/metodo/assuntos/{$id}", $this->autor)->json('dados');

        return $mais + [
            'titulo' => $a['titulo'], 'responsaveis' => [['tipo' => 'COLABORADOR', 'ref_id' => $this->ids['tec'], 'nome' => 'Técnico Fictício']],
            'responsavel_principal' => "COLABORADOR:{$this->ids['tec']}", 'data_inicio' => now()->addDays(2)->toDateString(), 'data_fim' => now()->addDays(20)->toDateString(),
            'local_tipo' => 'INTERNO', 'unidade_negocio_id' => $this->ids['un'],
            'fundamentacao' => 'A escala actual deixa o turno da noite com uma só pessoa, o que viola as regras de segurança.',
            'recursos' => 'Horas extra durante a transição', 'custo_estimado' => 80000, 'metodo_resolucao' => 'ACCAO',
        ];
    }
}
