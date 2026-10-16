<?php

namespace Tests\Feature;

use App\Models\AutoavaliacaoColaborador;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\PedidoPortalColaborador;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Portal do Colaborador — indicadores novos do resumo (portal_ui.js:179-180): «Documentos emitidos» (pedidos de
 * documentos do próprio no estado EMITIDO) e «Autoavaliação {ano}» (a ANUAL do ano corrente: Submetida / Rascunho /
 * Por fazer). Só contam os dados do próprio colaborador e da empresa activa. Dados fictícios.
 */
final class GpPortalTest extends TestCase
{
    private Empresa $a;

    private Empresa $b;

    private int $ana;

    private int $bruno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa(['nome' => 'Alfa GP, Lda', 'nif' => '5419100001']);
        $this->b = $this->criarEmpresa(['nome' => 'Beta GP, Lda', 'nif' => '5419100002']);
        [$this->ana, $this->bruno] = $this->naEmpresa($this->a, fn () => [
            Colaborador::create(['nome_completo' => 'Ana Fictícia', 'estado' => 'ACTIVO'])->id,
            Colaborador::create(['nome_completo' => 'Bruno Reservado', 'estado' => 'ACTIVO'])->id,
        ]);
    }

    private function naEmpresa(Empresa $e, callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($e->id, $f);
    }

    private function pedido(Empresa $e, int $colaborador, string $estado, string $tipo = 'DOCUMENTO'): void
    {
        $this->naEmpresa($e, fn () => PedidoPortalColaborador::create(['colaborador_id' => $colaborador, 'tipo' => $tipo, 'estado' => $estado,
            'dados' => ['documento' => 'DECLARACAO', 'finalidade' => 'Banco'], 'etapas' => [['nivel' => 'RH', 'estado' => str_starts_with($estado, 'PENDENTE') ? 'PENDENTE' : 'APROVADO']]]));
    }

    private function autoavaliacao(Empresa $e, int $colaborador, string $estado, ?int $ano = null, string $periodo = 'ANUAL'): void
    {
        $this->naEmpresa($e, fn () => AutoavaliacaoColaborador::query()->updateOrCreate(['colaborador_id' => $colaborador, 'ano' => $ano ?? (int) now()->format('Y'), 'periodo' => $periodo],
            ['estado' => $estado, 'criterios' => [], 'objetivos' => [], 'submetida_em' => $estado === 'SUBMETIDA' ? now() : null]));
    }

    /** @return array<string, mixed> */
    private function cabecalhos(?Empresa $e = null): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'rh_portal_usar' => true])->id]);
        $u->empresas()->attach($this->a->id, ['colaborador_id' => $this->ana]);
        $u->empresas()->attach($this->b->id, ['colaborador_id' => null]);

        return $this->entrar($u) + ['X-Empresa-Id' => ($e ?? $this->a)->id];
    }

    #[Test]
    public function documentos_emitidos_contam_so_os_do_proprio_na_empresa_activa(): void
    {
        $this->pedido($this->a, $this->ana, 'EMITIDO');
        $this->pedido($this->a, $this->ana, 'EMITIDO');
        $this->pedido($this->a, $this->ana, 'PENDENTE_RH');           // ainda não emitido
        $this->pedido($this->a, $this->ana, 'RECUSADO');
        $this->pedido($this->a, $this->bruno, 'EMITIDO');             // de outro colaborador
        $this->pedido($this->a, $this->bruno, 'EMITIDO');
        $this->pedido($this->b, $this->ana, 'EMITIDO');               // outra empresa (mesmo id de colaborador)

        $r = $this->getJson('/api/rh/portal/resumo', $this->cabecalhos())->assertOk();
        $this->assertSame(2, $r->json('dados.documentos_emitidos'));
        $this->assertSame(1, $r->json('dados.pedidos_pendentes'));
        $this->assertStringNotContainsString('Bruno Reservado', $r->getContent());
    }

    #[Test]
    public function autoavaliacao_anual_do_ano_por_fazer_rascunho_e_submetida(): void
    {
        $h = $this->cabecalhos();
        $ano = (int) now()->format('Y');
        // nada do próprio: a do colega, a de outro período e a do ano anterior não contam
        $this->autoavaliacao($this->a, $this->bruno, 'SUBMETIDA');
        $this->autoavaliacao($this->a, $this->ana, 'SUBMETIDA', $ano, 'S1');
        $this->autoavaliacao($this->a, $this->ana, 'SUBMETIDA', $ano - 1);
        $this->autoavaliacao($this->b, $this->ana, 'SUBMETIDA');     // outra empresa
        $this->getJson('/api/rh/portal/resumo', $h)->assertOk()
            ->assertJsonPath('dados.autoavaliacao', ['ano' => $ano, 'periodo' => 'ANUAL', 'estado' => 'POR_FAZER']);

        $this->autoavaliacao($this->a, $this->ana, 'RASCUNHO');
        $this->getJson('/api/rh/portal/resumo', $h)->assertOk()->assertJsonPath('dados.autoavaliacao.estado', 'RASCUNHO');

        $this->autoavaliacao($this->a, $this->ana, 'SUBMETIDA');
        $this->getJson('/api/rh/portal/resumo', $h)->assertOk()->assertJsonPath('dados.autoavaliacao.estado', 'SUBMETIDA');
    }

    #[Test]
    public function sem_colaborador_na_empresa_nao_ha_resumo_nem_fuga_de_dados(): void
    {
        $this->pedido($this->b, $this->ana, 'EMITIDO');
        $r = $this->getJson('/api/rh/portal/resumo', $this->cabecalhos($this->b))->assertForbidden()->assertJsonPath('codigo', 'SEM_COLABORADOR');
        $this->assertStringNotContainsString('documentos_emitidos', $r->getContent());
    }

    #[Test]
    public function zeros_quando_nao_ha_nada(): void
    {
        $this->getJson('/api/rh/portal/resumo', $this->cabecalhos())->assertOk()
            ->assertJsonPath('dados.documentos_emitidos', 0)
            ->assertJsonPath('dados.faltas_por_justificar', 0)
            ->assertJsonPath('dados.aprovacoes_para_mim', 0)
            ->assertJsonPath('dados.autoavaliacao.estado', 'POR_FAZER');
    }

    #[Test]
    public function carteira_de_projectos_traz_os_indicadores_do_semaforo(): void
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'projectos_carteira_view' => true])->id]);
        $u->empresas()->attach($this->a->id);
        $h = $this->entrar($u) + ['X-Empresa-Id' => $this->a->id];
        $p = $this->naEmpresa($this->a, function () {
            $p = Projeto::create(['codigo' => 'GP-1', 'nome' => 'Obra fictícia', 'estado' => 'ACTIVO', 'tipo' => 'INTERNO']);
            TarefaProjeto::create(['projeto_id' => $p->id, 'nome' => 'Atrasada', 'data_inicio' => now()->subDays(20)->toDateString(),
                'data_fim' => now()->subDays(2)->toDateString(), 'percentagem_execucao' => 30, 'estado' => 'EM_CURSO']);

            return $p;
        });
        $r = $this->getJson('/api/projetos', $h)->assertOk();
        $linha = collect($r->json('dados'))->firstWhere('id', $p->id);
        $this->assertSame(1, $linha['semaforo']['tarefas']);
        $this->assertSame(1, $linha['semaforo']['tarefas_atrasadas']);
        $this->assertSame(0, $linha['semaforo']['rubricas_excedidas']);
        $this->assertArrayHasKey('consumo_pct', $linha['semaforo']);
        $this->assertArrayHasKey('desempenho', $linha['semaforo']);
        // isolamento: noutra empresa a carteira não mostra o projecto
        $u->empresas()->attach($this->b->id);
        $this->assertEmpty($this->getJson('/api/projetos', ['X-Empresa-Id' => $this->b->id] + $h)->assertOk()->json('dados'));
    }
}
