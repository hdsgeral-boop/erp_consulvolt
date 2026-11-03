<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\CriterioAvaliacaoRH;
use App\Models\Empresa;
use App\Models\EntregavelTarefaUnidade;
use App\Models\ExecucaoEntregavel;
use App\Models\TarefaUnidadeOrganica;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeOrganica;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase J (ADR-078) — ponte para a avaliação de desempenho: os entregáveis marcados para avaliação executados pela pessoa entram
 * como objectivos virtuais ENT-<id> (atingido = Σ entregas que contam; resultado até 200 %), sem se gravarem em
 * criterios_avaliacao_rh; um atingido manual prevalece.
 */
final class FjAvaliacaoEntregaveisTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $rh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa FJA', 'nif' => '5000888871']);
        $this->em(function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $mk = fn (string $n) => Colaborador::create(['nome_completo' => $n, 'nif' => $n, 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22])->id;
            $this->ids = ['ana' => $mk('Ana'), 'rui' => $mk('Rui')];
            $dep = UnidadeOrganica::create(['nome' => 'Departamento', 'codigo' => 'DEP', 'tipo' => 'DEPARTAMENTO', 'ativo' => true]);
            $t = TarefaUnidadeOrganica::create(['unidade_organica_id' => $dep->id, 'nome' => 'Relatórios', 'periodicidade' => 'MENSAL', 'ativo' => true]);
            $e = EntregavelTarefaUnidade::create(['tarefa_unidade_id' => $t->id, 'nome' => 'Relatório mensal', 'metrica' => 'QUANTIDADE', 'unidade' => 'un', 'meta' => 10, 'avaliacao' => true, 'peso_avaliacao' => 2, 'ativo' => true]);
            $sem = EntregavelTarefaUnidade::create(['tarefa_unidade_id' => $t->id, 'nome' => 'Sem avaliação', 'metrica' => 'QUANTIDADE', 'unidade' => 'un', 'meta' => 1, 'ativo' => true]);
            $ano = (int) now()->format('Y');
            $x = fn (int $ent, string $data, float $q, string $v = 'VALIDADA') => ExecucaoEntregavel::create(['entregavel_id' => $ent, 'tarefa_unidade_id' => $t->id, 'unidade_organica_id' => $dep->id,
                'colaborador_id' => $this->ids['ana'], 'quantidade' => $q, 'data' => $data, 'validacao' => $v, 'origem' => 'MANUAL']);
            $x($e->id, "{$ano}-02-10", 15);                   // conta
            $x($e->id, "{$ano}-03-05", 10, 'PENDENTE');       // conta (por validar)
            $x($e->id, "{$ano}-04-05", 7, 'RECUSADA');        // não conta
            $x($e->id, "{$ano}-08-05", 99);                   // fora do 1.º semestre
            $x($sem->id, "{$ano}-02-10", 1);                  // entregável sem avaliação
            $this->ids += ['ent' => $e->id, 'ano' => $ano];
        });
        $this->rh = $this->sessao(['rh_avaliacao_view', 'rh_avaliacao_edit', 'rh_avaliacao_itens', 'rh_avaliacao_config_view', 'rh_avaliacao_ciclo_view']);
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
    public function objectivos_virtuais_dos_entregaveis_entram_na_avaliacao_e_na_autoavaliacao(): void
    {
        $ano = $this->ids['ano'];
        $chave = "ENT-{$this->ids['ent']}";
        $base = ['colaborador_id' => $this->ids['ana'], 'ano' => $ano, 'periodo' => 'S1', 'criterios' => []];
        $a = $this->postJson('/api/rh/avaliacao/avaliacoes', $base, $this->rh)->assertOk()->json('dados');
        $o = collect($a['objetivos'])->firstWhere('chave', $chave);
        $this->assertNotNull($o);
        // 15 + 10 (pendente conta) = 25 de meta 10 → 250 %, limitado a 200 %; peso 2; não se grava como item
        $this->assertEquals(['ENTREGAVEL', 'QUANTITATIVO', 10, 25, 200, 2, 'MAIOR'], [$o['ambito'], $o['natureza'], $o['meta'], $o['atingido'], $o['resultado'], $o['peso'], $o['sentido']]);   // o cast json do modelo não preserva «.0»
        $this->assertStringContainsString('2 entrega(s) no período · 1 ainda por validar', $o['detalhe']);
        $this->assertSame(0, $this->em(fn () => CriterioAvaliacaoRH::query()->where('tipo', 'OBJECTIVO')->count()));
        $this->assertEquals(5, $a['pontuacao_objetivos']);   // 1 + 4 × min(100, 200)/100 = 5
        // o Rui não executou: não é avaliado por ele
        $this->assertCount(0, $this->postJson('/api/rh/avaliacao/avaliacoes', ['colaborador_id' => $this->ids['rui']] + $base, $this->rh)->assertOk()->json('dados.objetivos'));
        // atingido manual prevalece sobre a soma das entregas, e mantém-se ao regravar sem o indicar
        $a = $this->postJson('/api/rh/avaliacao/avaliacoes', $base + ['objetivos' => [['chave' => $chave, 'atingido' => 5, 'comentario' => 'Só 5 aceites']]], $this->rh)->assertOk()->json('dados');
        $o = collect($a['objetivos'])->firstWhere('chave', $chave);
        $this->assertEquals([5, 50, 25, 'Só 5 aceites'], [$o['atingido'], $o['resultado'], $o['atingido_entregas'], $o['comentario']]);
        $o = collect($this->postJson('/api/rh/avaliacao/avaliacoes', $base, $this->rh)->assertOk()->json('dados.objetivos'))->firstWhere('chave', $chave);
        $this->assertEquals(5, $o['atingido']);
        // ANUAL: as 3 entregas que contam (15 + 10 + 99)
        $o = collect($this->postJson('/api/rh/avaliacao/avaliacoes', ['periodo' => 'ANUAL'] + $base, $this->rh)->assertOk()->json('dados.objetivos'))->firstWhere('chave', $chave);
        $this->assertEquals(124, $o['atingido']);

        // autoavaliação da Ana: o objectivo virtual aparece nas opções e no rascunho com o resultado calculado
        $ana = $this->sessao(['rh_portal_usar'], $this->ids['ana']);
        $objs = $this->getJson("/api/rh/avaliacao/autoavaliacao?ano={$ano}&periodo=S1", $ana)->assertOk()->json('dados.objetivos');
        $this->assertSame([$chave], array_column(array_filter($objs, fn ($x) => $x['virtual'] ?? false), 'chave'));
        $auto = $this->putJson('/api/rh/avaliacao/autoavaliacao', ['ano' => $ano, 'periodo' => 'S1', 'criterios' => [], 'realizacoes' => 'Relatórios entregues todos os meses'], $ana)->assertOk()->json('dados');
        $this->assertEquals([$chave, 200], [$auto['objetivos'][0]['chave'], $auto['objetivos'][0]['resultado']]);
    }
}
