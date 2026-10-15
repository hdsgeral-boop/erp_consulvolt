<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Empresa;
use App\Models\FolhaHorasProjeto;
use App\Models\MarcoProjeto;
use App\Models\NoOrganigramaProjeto;
use App\Models\TarefaProjeto;
use App\Models\UnidadeNegocio;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase E (Versão Actual): dependências FS/SS/FF com desfasamento e anti-ciclo, datas dentro do nível acima (com encaixe),
 * ordenação automática por data e precedência, duplicar fase/tarefa, mudar de nível e converter fase ↔ tarefa,
 * posição do organigrama no modal da tarefa e eliminação que solta as dependentes. Dados fictícios.
 */
final class FeProjectosHierarquiaTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000071']);
        $this->naEmpresa(function () {
            $this->ids['un'] = UnidadeNegocio::create(['codigo' => 'UN1', 'nome' => 'Obras'])->id;
            $this->ids['cc'] = CentroCusto::create(['codigo' => 'CC1', 'descricao' => 'Estaleiro'])->id;
        });
        $this->s = $this->sessao(['projectos_carteira_view', 'proj_gerir', 'proj_execucao', 'proj_eliminar']);
        $this->ids['p'] = $this->postJson('/api/projetos', ['nome' => 'Edifício', 'tipo' => 'INTERNO', 'unidade_negocio_id' => $this->ids['un'],
            'centro_custo_id' => $this->ids['cc']], $this->s)->assertCreated()->json('dados.id');
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

    private function tarefa(array $d): int
    {
        return $this->postJson("/api/projetos/{$this->ids['p']}/tarefas", $d, $this->s)->assertCreated()->json('dados.id');
    }

    #[Test]
    public function dependencias_fs_ss_ff_desfasamento_e_ciclos(): void
    {
        $p = $this->ids['p'];
        $s = $this->s;
        $a = $this->tarefa(['nome' => 'Fundações', 'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-10']);
        // FS: só começa no dia seguinte ao fim (+ desfasamento)
        $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'Estrutura', 'data_inicio' => '2026-09-10', 'tarefa_predecessora_id' => $a], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'DEPENDENCIA_DATA')->assertJsonPath('erros.data_minima', '2026-09-11');
        $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'Estrutura', 'data_inicio' => '2026-09-12', 'tarefa_predecessora_id' => $a, 'desfasamento_dias' => 2], $s)
            ->assertStatus(422)->assertJsonPath('erros.data_minima', '2026-09-13');
        $b = $this->tarefa(['nome' => 'Estrutura', 'data_inicio' => '2026-09-13', 'data_fim' => '2026-09-20', 'tarefa_predecessora_id' => $a, 'desfasamento_dias' => 2]);
        // SS: começa com a outra; FF: não acaba antes da outra
        $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'Andaimes', 'data_inicio' => '2026-08-31', 'tarefa_predecessora_id' => $a, 'tipo_dependencia' => 'SS'], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'DEPENDENCIA_DATA');
        $andaimes = $this->tarefa(['nome' => 'Andaimes', 'data_inicio' => '2026-09-01', 'tarefa_predecessora_id' => $a, 'tipo_dependencia' => 'SS']);
        $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'Limpeza', 'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-15', 'tarefa_predecessora_id' => $b, 'tipo_dependencia' => 'FF'], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'DEPENDENCIA_DATA');
        $c = $this->tarefa(['nome' => 'Limpeza', 'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-20', 'tarefa_predecessora_id' => $b, 'tipo_dependencia' => 'FF']);
        // ciclo: A não pode depender de C (C → B → A)
        $this->putJson("/api/projetos/{$p}/tarefas/{$a}", ['tarefa_predecessora_id' => $c], $s)->assertStatus(422)->assertJsonPath('codigo', 'DEPENDENCIA_CIRCULAR');
        $this->putJson("/api/projetos/{$p}/tarefas/{$a}", ['tarefa_predecessora_id' => $a], $s)->assertStatus(422)->assertJsonPath('codigo', 'DEPENDENCIA_CIRCULAR');
        $cand = array_column($this->getJson("/api/projetos/{$p}/tarefas/candidatas-predecessora?tarefa_id={$a}", $s)->assertOk()->json('dados'), 'id');
        $this->assertNotContains($a, $cand);
        $this->assertNotContains($c, $cand);
        $this->assertNotContains($b, $cand);

        // mover A para mais tarde avisa que B deixa de cumprir a precedência
        $this->putJson("/api/projetos/{$p}/tarefas/{$a}", ['data_fim' => '2026-09-15'], $s)->assertOk()
            ->assertJsonPath('dados.avisos.0', '1 tarefa(s) que dependem desta deixam de cumprir a precedência: Estrutura.');

        // WBS: precedência e ordem automática (A antes de B mesmo com a ordem manual ao contrário)
        $this->naEmpresa(fn () => TarefaProjeto::query()->whereKey($a)->update(['ordem' => 2_000_000]));
        $w = $this->getJson("/api/projetos/{$p}/wbs", $s)->assertOk()->json('dados.grupos.0.tarefas');
        $this->assertSame([$a, $andaimes, $b, $c], array_column($w, 'id'));
        $this->assertSame(2, $w[0]['precedencia']['n_seguintes']);
        $this->assertSame('Fim → Início +2d', $w[2]['precedencia']['predecessora']['rotulo']);
        $manual = $this->getJson("/api/projetos/{$p}/wbs?ordenacao=manual", $s)->json('dados.grupos.0.tarefas');
        $this->assertSame($a, end($manual)['id']);

        // eliminar A solta as dependentes (a FK é RESTRICT)
        $this->deleteJson("/api/projetos/{$p}/tarefas/{$a}", ['confirmacao' => 'ELIMINAR'], $s)->assertOk();
        $this->assertNull($this->naEmpresa(fn () => TarefaProjeto::query()->find($b)->tarefa_predecessora_id));
    }

    #[Test]
    public function datas_dentro_do_nivel_acima_e_encaixe(): void
    {
        $p = $this->ids['p'];
        $s = $this->s;
        $m = $this->postJson("/api/projetos/{$p}/marcos", ['nome' => 'Fase 1', 'data_inicio' => '2026-09-01', 'data' => '2026-09-30'], $s)->json('dados.id');
        $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'X', 'marco_projeto_id' => $m, 'data_inicio' => '2026-08-25', 'data_fim' => '2026-09-05'], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'DATAS_FORA_DO_NIVEL');
        $t = $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'X', 'marco_projeto_id' => $m, 'data_inicio' => '2026-08-25', 'data_fim' => '2026-09-05', 'encaixar_datas' => true], $s)
            ->assertCreated()->json('dados');
        $this->assertSame('2026-09-01', substr($t['data_inicio'], 0, 10));
        $sub = $this->tarefa(['nome' => 'Sub', 'tarefa_pai_id' => $t['id'], 'data_inicio' => '2026-09-02', 'data_fim' => '2026-09-05']);
        $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'Sub2', 'tarefa_pai_id' => $t['id'], 'data_inicio' => '2026-09-02', 'data_fim' => '2026-09-08'], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'DATAS_FORA_DO_NIVEL');
        // apertar o pai avisa sobre a subtarefa que fica fora; apertar a fase avisa sobre a tarefa
        $this->putJson("/api/projetos/{$p}/tarefas/{$t['id']}", ['data_fim' => '2026-09-03'], $s)->assertOk()
            ->assertJsonPath('dados.avisos.0', '1 subtarefa(s) ficam com datas fora desta tarefa: Sub.');
        $this->putJson("/api/projetos/{$p}/marcos/{$m}", ['data_inicio' => '2026-09-02'], $s)->assertOk()
            ->assertJsonPath('dados.avisos.0', '1 tarefa(s) ficam com datas fora da fase: X. Reveja as datas.');
        // mover para outra tarefa encaixa as datas (ou recusa, sem encaixe)
        $o = $this->tarefa(['nome' => 'Outra', 'data_inicio' => '2026-10-01', 'data_fim' => '2026-10-10']);
        $this->postJson("/api/projetos/{$p}/tarefas/{$sub}/mover", ['tipo' => 'DENTRO', 'alvo_id' => $o, 'encaixar_datas' => false], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'DATAS_FORA_DO_NIVEL');
        $r = $this->postJson("/api/projetos/{$p}/tarefas/{$sub}/mover", ['tipo' => 'DENTRO', 'alvo_id' => $o], $s)->assertOk()->json('dados');
        $this->assertSame($o, $r['tarefa_pai_id']);
        $this->assertSame('2026-10-01', substr($r['data_inicio'], 0, 10));
        $this->assertCount(1, $r['avisos']);
    }

    #[Test]
    public function duplicar_fase_e_tarefa_com_deslocamento(): void
    {
        $p = $this->ids['p'];
        $s = $this->s;
        $m = $this->postJson("/api/projetos/{$p}/marcos", ['nome' => 'Piso 1', 'data_inicio' => '2026-09-01', 'data' => '2026-09-30', 'meta_unidade' => 'm²', 'meta_quantidade' => 50], $s)->json('dados.id');
        $a = $this->tarefa(['nome' => 'Alvenaria', 'marco_projeto_id' => $m, 'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-10', 'meta_unidade' => 'm²', 'meta_quantidade' => 50]);
        $a1 = $this->tarefa(['nome' => 'Parede norte', 'tarefa_pai_id' => $a, 'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-05']);
        $a2 = $this->tarefa(['nome' => 'Parede sul', 'tarefa_pai_id' => $a, 'data_inicio' => '2026-09-06', 'data_fim' => '2026-09-10', 'tarefa_predecessora_id' => $a1]);
        $this->postJson("/api/projetos/{$p}/tarefas/{$a}/medicoes", ['quantidade' => 20, 'data' => '2026-09-05'], $s)->assertCreated();
        $no = $this->postJson("/api/projetos/{$p}/organigrama/posicoes", ['titulo' => 'Pedreiros'], $s)->json('dados.id');
        $this->postJson("/api/projetos/{$p}/organigrama/posicoes/{$no}/tarefas", ['tarefas' => [$a]], $s)->assertOk();

        $r = $this->postJson("/api/projetos/{$p}/marcos/{$m}/duplicar", ['nome' => 'Piso 2', 'deslocar_dias' => 30], $s)->assertCreated()->json('dados');
        $this->assertSame(4, $r['criados']);
        $nova = $this->naEmpresa(fn () => MarcoProjeto::query()->find($r['marco_id']));
        $this->assertSame(['Piso 2', '2026-10-01', '2026-10-30', '50.000'], [$nova->nome, $nova->data_inicio->toDateString(), $nova->data->toDateString(), $nova->meta_quantidade]);
        $copias = $this->naEmpresa(fn () => TarefaProjeto::query()->where('marco_projeto_id', $nova->id)->orderBy('id')->get());
        $this->assertSame(['Alvenaria', 'Parede norte', 'Parede sul'], $copias->pluck('nome')->all());
        // a cópia nasce a 0 % e PENDENTE, sem medições; a precedência interna liga as cópias
        $this->assertSame(['0.000', [], 'PENDENTE'], [$copias[0]->meta_realizado, $copias[0]->meta_execucoes, $copias[0]->estadoEfetivo()]);
        $this->assertSame($copias[1]->id, $copias[2]->tarefa_predecessora_id);
        $this->assertSame('2026-10-06', $copias[2]->data_inicio->toDateString());
        $this->assertContains($copias[0]->id, $this->naEmpresa(fn () => NoOrganigramaProjeto::query()->find($no)->tarefas));

        $r2 = $this->postJson("/api/projetos/{$p}/tarefas/{$a2}/duplicar", ['nome' => 'Parede sul (2)'], $s)->assertCreated()->json('dados');
        $copia = $this->naEmpresa(fn () => TarefaProjeto::query()->find($r2['tarefa_id']));
        $this->assertSame([$a, $a1, 'Parede sul (2)'], [$copia->tarefa_pai_id, $copia->tarefa_predecessora_id, $copia->nome]);
        $this->postJson("/api/projetos/{$p}/tarefas/{$a2}/duplicar", [], $this->sessao(['proj_execucao']))->assertForbidden();
    }

    #[Test]
    public function mudar_de_nivel_e_converter_fase_e_tarefa(): void
    {
        $p = $this->ids['p'];
        $s = $this->s;
        $m = $this->postJson("/api/projetos/{$p}/marcos", ['nome' => 'Acabamentos', 'data_inicio' => '2026-09-01', 'data' => '2026-09-30'], $s)->json('dados.id');
        $t1 = $this->tarefa(['nome' => 'Pintura', 'marco_projeto_id' => $m, 'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-10']);
        $t2 = $this->tarefa(['nome' => 'Pavimento', 'marco_projeto_id' => $m, 'data_inicio' => '2026-09-11', 'data_fim' => '2026-09-20']);
        $raiz = $this->tarefa(['nome' => 'Obra geral', 'data_inicio' => '2026-08-01', 'data_fim' => '2026-12-31']);

        // fase → subtarefa de «Obra geral»: a fase passa a tarefa e as tarefas dela a subtarefas
        $r = $this->postJson("/api/projetos/{$p}/marcos/{$m}/nivel", ['destino' => 'SUB', 'alvo_id' => $raiz], $s)->assertOk()->json('dados');
        $nova = $this->naEmpresa(fn () => TarefaProjeto::query()->find($r['tarefa_id']));
        $this->assertSame([$raiz, 'Acabamentos', '2026-09-01', '2026-09-30'], [$nova->tarefa_pai_id, $nova->nome, $nova->data_inicio->toDateString(), $nova->data_fim->toDateString()]);
        $this->assertSame([$nova->id, $nova->id], $this->naEmpresa(fn () => TarefaProjeto::query()->whereIn('id', [$t1, $t2])->orderBy('id')->pluck('tarefa_pai_id')->all()));
        $this->assertNull($this->naEmpresa(fn () => MarcoProjeto::query()->find($m)));

        // tarefa → fase: as subtarefas passam a tarefas principais da fase nova; recusado com registos
        $this->naEmpresa(fn () => FolhaHorasProjeto::create(['projeto_id' => $p, 'tarefa_projeto_id' => $nova->id, 'data' => '2026-09-02', 'horas' => 2, 'estado' => 'REGISTADO']));
        $this->postJson("/api/projetos/{$p}/tarefas/{$nova->id}/nivel", ['destino' => 'FASE'], $s)->assertStatus(422)->assertJsonPath('codigo', 'TAREFA_COM_REGISTOS');
        $this->naEmpresa(fn () => FolhaHorasProjeto::query()->where('tarefa_projeto_id', $nova->id)->delete());
        $this->tarefa(['nome' => 'Depende', 'data_inicio' => '2026-10-01', 'tarefa_predecessora_id' => $nova->id]);
        $r2 = $this->postJson("/api/projetos/{$p}/tarefas/{$nova->id}/nivel", ['destino' => 'FASE'], $s)->assertOk()->json('dados');
        $this->assertSame(['1 tarefa(s) dependiam de «Acabamentos» e ficaram sem predecessora.'], $r2['avisos']);
        $this->assertSame([[null, $r2['marco_id']], [null, $r2['marco_id']]], $this->naEmpresa(fn () => TarefaProjeto::query()->whereIn('id', [$t1, $t2])->orderBy('id')
            ->get()->map(fn ($t) => [$t->tarefa_pai_id, $t->marco_projeto_id])->all()));

        // tarefa → subtarefa de outra, com encaixe das datas
        $r3 = $this->postJson("/api/projetos/{$p}/tarefas/{$t2}/nivel", ['destino' => 'SUB', 'alvo_id' => $t1], $s)->assertOk()->json('dados');
        $this->assertNotEmpty($r3['avisos']);
        $this->assertSame('2026-09-10', $this->naEmpresa(fn () => TarefaProjeto::query()->find($t2)->data_inicio->toDateString()));
    }

    #[Test]
    public function posicao_do_organigrama_no_modal_da_tarefa(): void
    {
        $p = $this->ids['p'];
        $s = $this->s;
        $n1 = $this->postJson("/api/projetos/{$p}/organigrama/posicoes", ['titulo' => 'Direcção de obra'], $s)->json('dados.id');
        $n2 = $this->postJson("/api/projetos/{$p}/organigrama/posicoes", ['titulo' => 'Topografia', 'area' => 'Técnica'], $s)->json('dados.id');
        $t = $this->tarefa(['nome' => 'Implantação', 'no_organigrama_projeto_id' => $n1]);
        $this->putJson("/api/projetos/{$p}/tarefas/{$t}", ['no_organigrama_projeto_id' => $n2], $s)->assertOk();
        $this->assertSame([[], [$t]], $this->naEmpresa(fn () => [NoOrganigramaProjeto::query()->find($n1)->tarefas, NoOrganigramaProjeto::query()->find($n2)->tarefas]));
        $w = $this->getJson("/api/projetos/{$p}/wbs", $s)->json('dados.grupos.0.tarefas.0');
        $this->assertSame([$n2], $w['posicoes_ids']);
        $this->assertSame(['Topografia (Técnica)'], $w['posicoes']);
        $this->putJson("/api/projetos/{$p}/tarefas/{$t}", ['no_organigrama_projeto_id' => 999999], $s)->assertStatus(422)->assertJsonPath('codigo', 'POSICAO_INVALIDA');
    }
}
