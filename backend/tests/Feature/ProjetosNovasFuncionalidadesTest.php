<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Empresa;
use App\Models\RequisicaoMaterialProjeto;
use App\Models\UnidadeNegocio;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Melhorias do módulo Projectos & Obras: reordenar marcos (↑/↓, tarefas acompanham por FK), bandeiras de atenção
 * (tarefas e subtarefas), catálogo de etiquetas e sinalizações do projecto e o Backlog (requisições
 * pendentes/fechadas, estimativa de activos).
 */
final class ProjetosNovasFuncionalidadesTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000099']);
        $this->naEmpresa(function () {
            $this->ids['un'] = UnidadeNegocio::create(['codigo' => 'UN9', 'nome' => 'Obras'])->id;
            $this->ids['cc'] = CentroCusto::create(['codigo' => 'CC9', 'descricao' => 'Estaleiro'])->id;
        });
        $this->s = $this->sessao(['projectos_carteira_view', 'proj_gerir', 'proj_requisitar']);
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

    private function projeto(array $extra = []): int
    {
        return $this->postJson('/api/projetos', ['nome' => 'Armazém novo', 'tipo' => 'INTERNO', 'unidade_negocio_id' => $this->ids['un'], 'centro_custo_id' => $this->ids['cc']] + $extra, $this->s)
            ->assertCreated()->json('dados.id');
    }

    #[Test]
    public function reordenar_marcos_leva_as_tarefas_consigo_pela_fk(): void
    {
        $s = $this->s;
        $p = $this->projeto();
        $m1 = $this->postJson("/api/projetos/{$p}/marcos", ['nome' => 'Fase 1'], $s)->assertCreated()->json('dados.id');
        $m2 = $this->postJson("/api/projetos/{$p}/marcos", ['nome' => 'Fase 2'], $s)->assertCreated()->json('dados.id');
        $m3 = $this->postJson("/api/projetos/{$p}/marcos", ['nome' => 'Fase 3'], $s)->assertCreated()->json('dados.id');
        $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'Tarefa da fase 2', 'marco_projeto_id' => $m2], $s)->assertCreated();

        $nomes = fn (array $grupos) => array_map(fn ($g) => $g['marco']['nome'] ?? null, array_filter($grupos, fn ($g) => $g['marco'] !== null));

        // ordem inicial (sem «ordem» definida ainda): pela criação — Fase 1, 2, 3
        $w = $this->getJson("/api/projetos/{$p}/wbs", $s)->assertOk()->json('dados');
        $this->assertSame(['Fase 1', 'Fase 2', 'Fase 3'], array_values($nomes($w['grupos'])));

        // subir a Fase 3 duas vezes: fica Fase 3, Fase 1, Fase 2 — a tarefa da Fase 2 continua sob «Fase 2» (vai consigo, sem a tocar)
        $this->postJson("/api/projetos/{$p}/marcos/{$m3}/mover", ['direcao' => 'CIMA'], $s)->assertOk();
        $this->postJson("/api/projetos/{$p}/marcos/{$m3}/mover", ['direcao' => 'CIMA'], $s)->assertOk();
        $w = $this->getJson("/api/projetos/{$p}/wbs", $s)->assertOk()->json('dados');
        $this->assertSame(['Fase 3', 'Fase 1', 'Fase 2'], array_values($nomes($w['grupos'])));
        $fase2 = collect($w['grupos'])->first(fn ($g) => ($g['marco']['nome'] ?? null) === 'Fase 2');
        $this->assertSame(['Tarefa da fase 2'], array_column($fase2['tarefas'], 'nome'));

        // já no topo: subir outra vez não faz nada (nem erro)
        $this->postJson("/api/projetos/{$p}/marcos/{$m3}/mover", ['direcao' => 'CIMA'], $s)->assertOk();
        $this->assertSame(['Fase 3', 'Fase 1', 'Fase 2'], array_values($nomes($this->getJson("/api/projetos/{$p}/wbs", $s)->json('dados.grupos'))));

        // descer a Fase 1: volta a Fase 3, Fase 2, Fase 1
        $this->postJson("/api/projetos/{$p}/marcos/{$m1}/mover", ['direcao' => 'BAIXO'], $s)->assertOk();
        $this->assertSame(['Fase 3', 'Fase 2', 'Fase 1'], array_values($nomes($this->getJson("/api/projetos/{$p}/wbs", $s)->json('dados.grupos'))));
    }

    #[Test]
    public function bandeira_de_atencao_em_tarefas_e_subtarefas(): void
    {
        $s = $this->s;
        $p = $this->projeto();
        $t = $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'Fundações'], $s)->assertCreated()->json('dados.id');
        $sub = $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'Escavação', 'tarefa_pai_id' => $t], $s)->assertCreated()->json('dados.id');

        $this->putJson("/api/projetos/{$p}/tarefas/{$t}", ['flag' => 'laranja'], $s)->assertStatus(422);
        $this->putJson("/api/projetos/{$p}/tarefas/{$t}", ['flag' => 'vermelho'], $s)->assertOk()->assertJsonPath('dados.flag', 'vermelho');
        $this->putJson("/api/projetos/{$p}/tarefas/{$sub}", ['flag' => 'preto'], $s)->assertOk()->assertJsonPath('dados.flag', 'preto');

        // outro campo não apaga a bandeira (actualização parcial)
        $this->putJson("/api/projetos/{$p}/tarefas/{$t}", ['percentagem_execucao' => 50], $s)->assertOk()->assertJsonPath('dados.flag', 'vermelho');

        // limpar a bandeira
        $this->putJson("/api/projetos/{$p}/tarefas/{$t}", ['flag' => null], $s)->assertOk()->assertJsonPath('dados.flag', null);
    }

    #[Test]
    public function etiquetas_catalogo_partilhado_associadas_ao_projecto_e_eliminar_retira_de_todos(): void
    {
        $s = $this->s;
        $obra = $this->postJson('/api/projetos/etiquetas', ['nome' => 'Obra pública', 'cor' => '#16a34a'], $s)->assertCreated()->json('dados.id');
        $urgente = $this->postJson('/api/projetos/etiquetas', ['nome' => 'Urgente'], $s)->assertCreated()->json('dados.id');

        // nome duplicado (mesmo sem distinguir maiúsculas) é recusado
        $this->postJson('/api/projetos/etiquetas', ['nome' => 'urgente'], $s)->assertStatus(422);

        $p = $this->projeto(['etiquetas_ids' => [$obra, $urgente]]);
        $nomes = fn ($r) => collect($r->json('dados.etiquetas'))->pluck('nome')->sort()->values()->all();
        $this->assertSame(['Obra pública', 'Urgente'], $nomes($this->getJson("/api/projetos/{$p}", $s)->assertOk()));

        // actualizar substitui o conjunto (não acrescenta)
        $this->putJson("/api/projetos/{$p}", ['etiquetas_ids' => [$urgente]], $s)->assertOk();
        $this->assertSame(['Urgente'], $nomes($this->getJson("/api/projetos/{$p}", $s)->assertOk()));

        // eliminar do catálogo retira-a de todos os projectos que a tinham
        $this->deleteJson("/api/projetos/etiquetas/{$urgente}", [], $s)->assertOk();
        $this->assertSame([], $nomes($this->getJson("/api/projetos/{$p}", $s)->assertOk()));
    }

    #[Test]
    public function sinalizar_o_projecto_guarda_motivo_quem_e_quando_e_pode_ser_removida(): void
    {
        $s = $this->s;
        $risco = $this->postJson('/api/projetos/sinalizacoes', ['nome' => 'Em risco', 'cor' => '#dc2626'], $s)->assertCreated()->json('dados.id');
        $p = $this->projeto();

        $r = $this->postJson("/api/projetos/{$p}/sinalizacoes", ['sinalizacao_id' => $risco, 'motivo' => 'Atraso do fornecedor'], $s)->assertOk();
        $sinal = collect($r->json('dados.sinalizacoes'))->firstWhere('id', $risco);
        $this->assertSame('Atraso do fornecedor', $sinal['pivot']['motivo']);
        $this->assertNotEmpty($sinal['pivot']['levantada_por']);
        $this->assertNotEmpty($sinal['pivot']['levantada_em']);

        // assinalar outra vez actualiza o motivo em vez de duplicar
        $r = $this->postJson("/api/projetos/{$p}/sinalizacoes", ['sinalizacao_id' => $risco, 'motivo' => 'Resolvido com outro fornecedor'], $s)->assertOk();
        $this->assertCount(1, $r->json('dados.sinalizacoes'));
        $this->assertSame('Resolvido com outro fornecedor', collect($r->json('dados.sinalizacoes'))->firstWhere('id', $risco)['pivot']['motivo']);

        $r = $this->deleteJson("/api/projetos/{$p}/sinalizacoes/{$risco}", [], $s)->assertOk();
        $this->assertSame([], $r->json('dados.sinalizacoes'));
    }

    #[Test]
    public function backlog_conta_requisicoes_pendentes_e_fechadas_e_soma_o_valor_contrato(): void
    {
        $s = $this->s;
        $p = $this->projeto();
        $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'A', 'valor_contrato' => 1000], $s)->assertCreated();
        $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'B', 'valor_contrato' => 2500.50], $s)->assertCreated();
        $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'C'], $s)->assertCreated();   // sem valor: não soma

        $this->naEmpresa(function () use ($p) {
            RequisicaoMaterialProjeto::create(['projeto_id' => $p, 'nome_requerente' => 'Ana', 'data' => now(), 'estado' => 'PENDENTE']);
            RequisicaoMaterialProjeto::create(['projeto_id' => $p, 'nome_requerente' => 'Rui', 'data' => now(), 'estado' => 'COMPRAS']);
            RequisicaoMaterialProjeto::create(['projeto_id' => $p, 'nome_requerente' => 'Zé', 'data' => now(), 'estado' => 'STOCK']);
        });

        $b = $this->getJson("/api/projetos/{$p}/backlog", $s)->assertOk()->json('dados');
        $this->assertSame(1, $b['requisicoes_pendentes']);
        $this->assertSame(2, $b['requisicoes_fechadas']);
        $this->assertSame(3, $b['requisicoes_total']);
        $this->assertSame('3500.50', $b['estimativa_activos']);
        $this->assertCount(3, $b['requisicoes']);
    }

    #[Test]
    public function sem_nenhuma_permissao_de_projectos_nao_ve_o_backlog(): void
    {
        $p = $this->projeto();
        $this->getJson("/api/projetos/{$p}/backlog", $this->sessao(['vendas_fat_emitir']))->assertForbidden();
    }
}
