<?php

namespace Tests\Feature;

use App\Models\AusenciaFaltaColaborador;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\PedidoPortalColaborador;
use App\Models\PeriodoFeriasEmpresa;
use App\Models\PlanoFeriasColaborador;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeOrganica;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase J (ADR-078) — afinação das férias: confirmar_antiguidade chega ao serviço; revalidação (período, sobreposição, saldo) ao
 * aprovar o pedido do portal; o Fluxo de Processos usa o direito da LGT (com transporte); filtro por unidade orgânica.
 */
final class FjFeriasAfinacaoTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    /** @var array<string, array<string, mixed>> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa FJF', 'nif' => '5000888841']);
        $ano = (int) now()->format('Y');
        $this->em(function () use ($ano) {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $mk = fn (string $n, string $admissao) => Colaborador::create(['nome_completo' => $n, 'nif' => $n, 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22, 'data_admissao' => $admissao])->id;
            $this->ids = ['ana' => $mk('Ana', '2020-01-06'), 'novo' => $mk('Novo', now()->subMonths(2)->toDateString()), 'chefe' => $mk('Chefe', '2019-01-07'), 'outro' => $mk('Outro', '2019-01-07')];
            $dir = UnidadeOrganica::create(['nome' => 'Direcção', 'codigo' => 'DIR', 'tipo' => 'DIRECCAO', 'colaborador_responsavel_id' => $this->ids['chefe'], 'ativo' => true]);
            $dep = UnidadeOrganica::create(['nome' => 'Departamento', 'codigo' => 'DEP', 'tipo' => 'DEPARTAMENTO', 'unidade_organica_pai_id' => $dir->id, 'ativo' => true]);
            $outra = UnidadeOrganica::create(['nome' => 'Outra', 'codigo' => 'OUT', 'tipo' => 'DEPARTAMENTO', 'ativo' => true]);
            Colaborador::query()->whereKey([$this->ids['ana'], $this->ids['novo']])->update(['unidade_organica_id' => $dep->id]);
            Colaborador::query()->whereKey($this->ids['chefe'])->update(['unidade_organica_id' => $dir->id]);
            Colaborador::query()->whereKey($this->ids['outro'])->update(['unidade_organica_id' => $outra->id]);
            foreach ([$ano, $ano + 1] as $a) {
                PeriodoFeriasEmpresa::create(['ano' => $a, 'data_inicio' => "{$a}-01-01", 'data_fim' => "{$a}-12-31"]);
            }
            $this->ids += ['dir' => $dir->id, 'dep' => $dep->id, 'outra' => $outra->id];
        });
        $this->s = ['rh' => $this->sessao(['rh_ferias_view', 'rh_ferias_edit', 'rh_portal_aprovar', 'rh_portal_gestao_view', 'fluxo_processos_view', 'rh_ferias_periodo_gerir']),
            'ana' => $this->sessao(['rh_portal_usar'], $this->ids['ana']), 'chefe' => $this->sessao(['rh_portal_usar'], $this->ids['chefe'])];
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
    public function rh_confirma_a_antiguidade_e_filtra_por_unidade(): void
    {
        $rh = $this->s['rh'];
        $ini = now()->addMonth()->startOfMonth()->addDays(7)->toDateString();
        $fim = now()->addMonth()->startOfMonth()->addDays(9)->toDateString();
        $d = ['colaborador_id' => $this->ids['novo'], 'data_inicio' => $ini, 'data_fim' => $fim];
        $this->postJson('/api/rh/ferias', $d, $rh)->assertStatus(422)->assertJsonPath('codigo', 'ANTIGUIDADE_INSUFICIENTE');
        $this->postJson('/api/rh/ferias', $d + ['confirmar_antiguidade' => true], $rh)->assertCreated()->assertJsonPath('dados.estado', 'PLANEADO');   // antes: o controller descartava a confirmação
        $ano = (int) substr($ini, 0, 4);
        $todos = $this->getJson("/api/rh/ferias?ano={$ano}", $rh)->assertOk()->json('dados');
        $this->assertCount(4, $todos['resumo']);
        $this->assertSame('Departamento', collect($todos['resumo'])->firstWhere('colaborador_id', $this->ids['ana'])['unidade']);
        // filtro por unidade: a Direcção inclui o Departamento (Ana, Novo) e o Chefe; a Outra só o Outro
        $dir = $this->getJson("/api/rh/ferias?ano={$ano}&unidade_id={$this->ids['dir']}", $rh)->assertOk()->json('dados');
        $this->assertSame(['Ana', 'Chefe', 'Novo'], collect($dir['resumo'])->pluck('nome')->sort()->values()->all());
        $this->assertCount(1, $dir['periodos']);
        $outra = $this->getJson("/api/rh/ferias?ano={$ano}&unidade_id={$this->ids['outra']}", $rh)->json('dados');
        $this->assertSame([['Outro'], 0], [array_column($outra['resumo'], 'nome'), count($outra['periodos'])]);
    }

    #[Test]
    public function aprovar_o_pedido_do_portal_revalida_sobreposicao_e_saldo(): void
    {
        $ini = now()->addMonths(2)->startOfMonth()->addDays(7);
        $fim = $ini->copy()->addDays(9);
        $p = $this->postJson('/api/rh/portal/pedidos', ['tipo' => 'FERIAS', 'data_inicio' => $ini->toDateString(), 'data_fim' => $fim->toDateString()], $this->s['ana'])->assertCreated()->json('dados');
        $this->assertSame('PENDENTE_CHEFIA', $p['estado']);
        // entretanto o RH marca férias sobrepostas directamente (o plano PEDIDO não bloqueia a marcação de outro período do mesmo colaborador? bloqueia: sobreposição) → usa-se uma ausência
        $this->em(fn () => AusenciaFaltaColaborador::create(['colaborador_id' => $this->ids['ana'], 'tipo' => 'OUTRA', 'motivo' => 'Formação', 'data_inicio' => $ini->copy()->addDays(2)->toDateString(),
            'data_fim' => $ini->copy()->addDays(3)->toDateString(), 'dias' => 2, 'dias_uteis' => 2, 'estado' => 'APROVADO', 'mes' => $ini->format('Y-m')]));
        $this->postJson("/api/rh/portal/pedidos/{$p['id']}/decidir", ['decisao' => 'APROVADO'], $this->s['chefe'])->assertStatus(422)->assertJsonPath('codigo', 'PEDIDO_INVALIDO')->assertJsonPath('erros.motivo', 'SOBREPOSICAO');
        $this->assertSame('PENDENTE_CHEFIA', $this->em(fn () => PedidoPortalColaborador::query()->find($p['id'])->estado));   // não decidiu
        $this->em(fn () => AusenciaFaltaColaborador::query()->delete());
        $this->postJson("/api/rh/portal/pedidos/{$p['id']}/decidir", ['decisao' => 'APROVADO'], $this->s['chefe'])->assertOk()->assertJsonPath('dados.estado', 'PENDENTE_RH');
        // na etapa do RH o saldo já não chega (o direito foi reduzido): recusa-se sem decidir
        $this->em(fn () => PlanoFeriasColaborador::query()->where('colaborador_id', $this->ids['ana'])->update(['direito' => 5]));
        $this->postJson("/api/rh/portal/pedidos/{$p['id']}/decidir", ['decisao' => 'APROVADO'], $this->s['rh'])->assertStatus(422)->assertJsonPath('codigo', 'PEDIDO_INVALIDO')->assertJsonPath('erros.motivo', 'SALDO_EXCEDIDO');
        $this->em(fn () => PlanoFeriasColaborador::query()->where('colaborador_id', $this->ids['ana'])->update(['direito' => 22]));
        $this->postJson("/api/rh/portal/pedidos/{$p['id']}/decidir", ['decisao' => 'APROVADO'], $this->s['rh'])->assertOk()->assertJsonPath('dados.estado', 'APROVADO');
        $this->assertSame('APROVADO', $this->em(fn () => PlanoFeriasColaborador::query()->find($p['plano_ferias_colaborador_id'])->estado));
    }

    #[Test]
    public function fluxo_de_ferias_usa_o_direito_da_lgt_com_transporte(): void
    {
        $ano = (int) now()->format('Y');
        // ano anterior gerido: 22 de direito e 15 gozados → transporta 7; o fluxo mostrava «22» (valor padrão) em vez de 29
        $this->em(fn () => PlanoFeriasColaborador::create(['colaborador_id' => $this->ids['ana'], 'ano' => $ano - 1, 'data_inicio' => ($ano - 1).'-08-04', 'data_fim' => ($ano - 1).'-08-22', 'dias' => 15, 'direito' => 22, 'estado' => 'GOZADO']));
        $ana = $this->getJson("/api/gestao/fluxos/ferias/processos/ferias-{$this->ids['ana']}-{$ano}", $this->s['rh'])->assertOk()->json('dados');
        $this->assertSame('29 dias úteis', $ana['etapas']['direito']['resumo']);
        $this->assertSame('Lei Geral do Trabalho (com transporte)', $ana['etapas']['direito']['factos'][1]['valor']);
        $this->assertStringContainsString('29', $ana['linha_data']);
    }
}
