<?php

namespace Tests\Feature;

use App\Models\AssuntoMetodoTrabalho;
use App\Models\AtivoImobilizado;
use App\Models\CategoriaAtivo;
use App\Models\CentroCusto;
use App\Models\Empresa;
use App\Models\MarcoProjeto;
use App\Models\TarefaProjeto;
use App\Models\UnidadeNegocio;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fecho de Projectos (ADR-071, Versão Actual): ligação de tarefas a partir do Gantt (só os campos da dependência),
 * arrastar fases (mudar de nível), origem «Método de Trabalho» no detalhe do projecto (isolada por empresa) e
 * equipamentos alocados com categoria e estado do activo. Dados fictícios.
 */
final class FiProjectosFechoTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000091']);
        $this->naEmpresa(function () {
            $this->ids['un'] = UnidadeNegocio::create(['codigo' => 'UN1', 'nome' => 'Obras'])->id;
            $this->ids['cc'] = CentroCusto::create(['codigo' => 'CC1', 'descricao' => 'Estaleiro'])->id;
        });
        $this->s = $this->sessao(['projectos_carteira_view', 'proj_gerir', 'proj_execucao', 'proj_eliminar']);
        $this->ids['p'] = $this->postJson('/api/projetos', ['nome' => 'Edifício', 'tipo' => 'INTERNO', 'unidade_negocio_id' => $this->ids['un'],
            'centro_custo_id' => $this->ids['cc']], $this->s)->assertCreated()->json('dados.id');
    }

    private function naEmpresa(callable $f, ?Empresa $e = null): mixed
    {
        return app(ContextoEmpresa::class)->executarComo(($e ?? $this->empresa)->id, $f);
    }

    private function sessao(array $permissoes, ?Empresa $e = null): array
    {
        $e ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($e->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $e->id];
    }

    private function tarefa(array $d): int
    {
        return $this->postJson("/api/projetos/{$this->ids['p']}/tarefas", $d, $this->s)->assertCreated()->json('dados.id');
    }

    #[Test]
    public function ligar_tarefas_no_gantt_grava_so_a_dependencia_e_remove_a_ligacao(): void
    {
        $p = $this->ids['p'];
        $a = $this->tarefa(['nome' => 'Escavação', 'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-10']);
        $b = $this->tarefa(['nome' => 'Betão', 'codigo' => 'B-1', 'data_inicio' => '2026-09-05', 'data_fim' => '2026-09-08', 'meta_unidade' => 'm³', 'meta_quantidade' => 40]);
        // o início de B não cumpre FS: o servidor devolve a data mínima (o ecrã oferece ajustar)
        $this->putJson("/api/projetos/{$p}/tarefas/{$b}", ['tarefa_predecessora_id' => $a, 'tipo_dependencia' => 'FS', 'desfasamento_dias' => 0], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'DEPENDENCIA_DATA')->assertJsonPath('erros.data_minima', '2026-09-11');
        // SS cumpre: grava a dependência e mantém o resto (nome, código, datas, meta)
        $this->putJson("/api/projetos/{$p}/tarefas/{$b}", ['tarefa_predecessora_id' => $a, 'tipo_dependencia' => 'SS', 'desfasamento_dias' => 2], $this->s)->assertOk();
        $t = TarefaProjeto::query()->withoutGlobalScopes()->findOrFail($b);
        $this->assertSame([$a, 'SS', 2, 'Betão', 'B-1', '2026-09-05', '2026-09-08', 'm³'],
            [$t->tarefa_predecessora_id, $t->tipo_dependencia, $t->desfasamento_dias, $t->nome, $t->codigo, $t->data_inicio->toDateString(), $t->data_fim->toDateString(), $t->meta_unidade]);
        // ciclo pedido a partir do Gantt é recusado no servidor
        $this->putJson("/api/projetos/{$p}/tarefas/{$a}", ['tarefa_predecessora_id' => $b, 'tipo_dependencia' => 'FS', 'desfasamento_dias' => 0], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'DEPENDENCIA_CIRCULAR');
        // remover a ligação (clique na seta)
        $this->putJson("/api/projetos/{$p}/tarefas/{$b}", ['tarefa_predecessora_id' => null, 'tipo_dependencia' => null, 'desfasamento_dias' => null], $this->s)->assertOk();
        $t->refresh();
        $this->assertNull($t->tarefa_predecessora_id);
        $this->assertNull($t->tipo_dependencia);
        $this->assertSame('Betão', $t->nome);
        // sem proj_gerir não liga (autorização antes da validação)
        $this->putJson("/api/projetos/{$p}/tarefas/{$b}", ['tarefa_predecessora_id' => 'x'], $this->sessao(['projectos_carteira_view']))->assertForbidden();
    }

    #[Test]
    public function arrastar_uma_fase_para_outra_converte_a_em_tarefa_principal(): void
    {
        $p = $this->ids['p'];
        $f1 = $this->postJson("/api/projetos/{$p}/marcos", ['nome' => 'Fundações', 'data_inicio' => '2026-09-01', 'data' => '2026-09-30'], $this->s)->assertCreated()->json('dados.id');
        $f2 = $this->postJson("/api/projetos/{$p}/marcos", ['nome' => 'Acabamentos', 'data_inicio' => '2026-10-01', 'data' => '2026-10-31'], $this->s)->assertCreated()->json('dados.id');
        $t = $this->tarefa(['nome' => 'Pintura', 'marco_projeto_id' => $f2, 'data_inicio' => '2026-10-05', 'data_fim' => '2026-10-10']);
        // largar «Acabamentos» sobre «Fundações»: RAIZ na fase de destino, com encaixe das datas
        $r = $this->postJson("/api/projetos/{$p}/marcos/{$f2}/nivel", ['destino' => 'RAIZ', 'alvo_id' => null, 'marco_projeto_id' => $f1, 'encaixar' => true], $this->s)->assertOk()->json('dados');
        $nova = TarefaProjeto::query()->withoutGlobalScopes()->findOrFail($r['tarefa_id']);
        $this->assertSame([$f1, 'Acabamentos'], [$nova->marco_projeto_id, $nova->nome]);
        $this->assertNull(MarcoProjeto::query()->withoutGlobalScopes()->find($f2));
        $this->assertSame($nova->id, TarefaProjeto::query()->withoutGlobalScopes()->findOrFail($t)->tarefa_pai_id);
        // largar uma fase sobre ela própria não é um destino válido (o ecrã nem envia); converter para a própria fase é recusado
        $f3 = $this->postJson("/api/projetos/{$p}/marcos", ['nome' => 'Recepção'], $this->s)->assertCreated()->json('dados.id');
        $this->postJson("/api/projetos/{$p}/marcos/{$f3}/nivel", ['destino' => 'RAIZ', 'marco_projeto_id' => $f3], $this->s)->assertStatus(422);
        // sem proj_gerir não muda de nível
        $this->postJson("/api/projetos/{$p}/marcos/{$f3}/nivel", ['destino' => 'RAIZ'], $this->sessao(['projectos_carteira_view', 'proj_execucao']))->assertForbidden();
    }

    #[Test]
    public function detalhe_mostra_a_origem_metodo_de_trabalho_so_da_propria_empresa(): void
    {
        $p = $this->ids['p'];
        $this->getJson("/api/projetos/{$p}", $this->s)->assertOk()->assertJsonPath('dados.assunto_metodo', null);
        $assunto = $this->naEmpresa(fn () => AssuntoMetodoTrabalho::create(['numero' => 'MT-2026/0007', 'titulo' => 'Fuga de água no piso 2', 'estado' => 'EM_CURSO'])->id);
        DB::table('projetos')->where('id', $p)->update(['origem' => 'METODO_TRABALHO', 'assunto_metodo_id' => $assunto]);
        $this->getJson("/api/projetos/{$p}", $this->s)->assertOk()
            ->assertJsonPath('dados.origem', 'METODO_TRABALHO')->assertJsonPath('dados.assunto_metodo_id', $assunto)
            ->assertJsonPath('dados.assunto_metodo.numero', 'MT-2026/0007')->assertJsonPath('dados.assunto_metodo.titulo', 'Fuga de água no piso 2');

        // um assunto de outra empresa nunca aparece (isolamento por empresa)
        $outra = $this->criarEmpresa(['nif' => '5417000092']);
        $alheio = $this->naEmpresa(fn () => AssuntoMetodoTrabalho::create(['numero' => 'MT-OUTRA', 'titulo' => 'Confidencial'])->id, $outra);
        DB::table('projetos')->where('id', $p)->update(['assunto_metodo_id' => $alheio]);
        $this->getJson("/api/projetos/{$p}", $this->s)->assertOk()->assertJsonPath('dados.assunto_metodo', null);
        // e o projecto não se vê a partir da outra empresa
        $this->getJson("/api/projetos/{$p}", $this->sessao(['projectos_carteira_view'], $outra))->assertNotFound();
    }

    #[Test]
    public function equipamentos_alocados_trazem_categoria_estado_e_custo_do_projecto(): void
    {
        $p = $this->ids['p'];
        $t = $this->tarefa(['nome' => 'Terraplenagem', 'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-30']);
        $ativo = $this->naEmpresa(function () {
            $cat = CategoriaAtivo::create(['nome' => 'Máquinas pesadas', 'taxa_anual' => 20, 'vida_util_padrao' => 5])->id;

            return AtivoImobilizado::create(['codigo' => 'RETRO9', 'descricao' => 'Retroescavadora', 'categoria_ativo_id' => $cat, 'estado' => 'ACTIVO'])->id;
        });
        $this->postJson("/api/projetos/{$p}/equipamentos", ['tarefa_projeto_id' => $t, 'ativo_imobilizado_id' => $ativo, 'data' => '2026-09-15', 'horas' => 8, 'custo_hora' => 5000], $this->s)
            ->assertCreated();
        $e = $this->getJson("/api/projetos/{$p}/equipamentos", $this->s)->assertOk()->json('dados');
        $this->assertSame(['RETRO9', 'Máquinas pesadas', 'ACTIVO'], [$e['por_equipamento'][0]['ativo_codigo'], $e['por_equipamento'][0]['ativo_categoria'], $e['por_equipamento'][0]['ativo_estado']]);
        $this->assertSame('Máquinas pesadas', $e['usos'][0]['ativo_categoria']);
        $this->assertArrayHasKey('custo_projeto', $e);
        $this->getJson("/api/projetos/{$p}/equipamentos", $this->sessao(['vendas_view']))->assertForbidden();
    }
}
