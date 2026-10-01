<?php

namespace Tests\Feature;

use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\SaldoHistorico;
use App\Models\TaxaCambio;
use App\Models\Terceiro;
use App\Models\Utilizador;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Sistema\ServicoEmpresas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Consolidação de empresas (ADR-057): holding, membros, agregação linha a linha, eliminações intragrupo por NIF com mapa de
 * divergências, conversão cambial com reservas, substituição só das linhas geradas, saldos históricos e Mapa de Consolidação.
 * A vende 1 000 a B (B regista o custo) e 500 a um cliente externo.
 */
final class ConsolidacaoEmpresasTest extends TestCase
{
    private Empresa $a;

    private Empresa $b;

    private Utilizador $u;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa(['nif' => '5417000571']);
        $this->b = $this->criarEmpresa(['nif' => '5417000572']);
        foreach ([[$this->a, '5417000572'], [$this->b, '5417000571']] as [$e, $nifOutra]) {
            app(ContextoEmpresa::class)->executarComo($e->id, function () use ($nifOutra) {
                foreach (['3111' => 'Clientes', '3211' => 'Fornecedores', '4511' => 'Caixa', '5111' => 'Capital', '6111' => 'Vendas', '7111' => 'Compras'] as $c => $d) {
                    PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
                }
                DiarioContabil::create(['codigo' => 'OD', 'nome' => 'Diversos']);
                Terceiro::create(['nome' => 'Empresa do grupo', 'nif' => $nifOutra]);
                Terceiro::create(['nome' => 'Cliente externo', 'nif' => '9999999']);
            });
        }
        $this->lancar($this->a, '2026-02-01', [['3111', 'D', 1000, 'grupo'], ['6111', 'C', 1000]]);
        $this->lancar($this->a, '2026-02-02', [['4511', 'D', 500], ['6111', 'C', 500]]);
        $this->lancar($this->b, '2026-02-03', [['7111', 'D', 1000], ['3211', 'C', 1000, 'grupo']]);
        $this->s = $this->sessao(['consolidacao_view', 'consol_gerir', 'consol_executar', 'consol_eliminar'], [$this->a, $this->b]);
    }

    private function sessao(array $permissoes, array $empresas): array
    {
        $this->u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $this->u->empresas()->attach(array_map(fn ($e) => $e->id, $empresas));

        return $this->entrar($this->u) + ['X-Empresa-Id' => $empresas[0]->id];
    }

    private function lancar(Empresa $e, string $data, array $linhas): void
    {
        app(ContextoEmpresa::class)->executarComo($e->id, function () use ($data, $linhas) {
            $grupo = Terceiro::query()->where('nome', 'Empresa do grupo')->value('id');
            app(ServicoLancamentos::class)->criar(['diario_id' => DiarioContabil::query()->where('codigo', 'OD')->value('id'), 'data_documento' => $data,
                'linhas' => array_map(fn ($l) => ['codigo_conta' => $l[0], 'tipo_dc' => $l[1], 'valor' => $l[2], 'terceiro_id' => ($l[3] ?? null) === 'grupo' ? $grupo : null], $linhas)]);
        });
    }

    private function criarGrupo(array $extra = []): array
    {
        return $this->postJson('/api/consolidacao/grupos', $extra + ['nome' => 'Grupo Teste — Consolidado', 'nif' => '5417000579', 'membros' => [$this->a->id, $this->b->id]], $this->s)
            ->assertCreated()->json('dados');
    }

    private function linhasHolding(int $holding): Collection
    {
        return DB::table('lancamentos_contabeis')->where('empresa_id', $holding)->orderBy('id')->get();
    }

    #[Test]
    public function cria_a_holding_com_os_membros_e_respeita_o_acesso(): void
    {
        $g = $this->criarGrupo();
        $holding = Empresa::query()->findOrFail($g['holding']['id']);
        $this->assertTrue($holding->e_consolidacao);
        $this->assertSame([$this->a->id, $this->b->id], array_column($g['membros'], 'empresa_id'));
        $this->assertSame(['AOA', '5.9.9', true, '4, 34', '5.9.8'], [$g['moeda_apresentacao'], $g['conta_reserva_cambial'], $g['eliminacao_ativa'],
            $g['prefixos_excluidos_eliminacao'], $g['conta_diferenca_eliminacao']]);
        $this->assertTrue($this->u->empresas()->where('empresas.id', $holding->id)->exists());
        $this->assertCount(1, $this->getJson('/api/consolidacao/grupos', $this->s)->assertOk()->json('dados'));

        $c = $this->criarEmpresa();
        $this->postJson('/api/consolidacao/grupos', ['nome' => 'Outro', 'nif' => '5417000580', 'membros' => [$this->a->id, $c->id]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'MEMBROS_INVALIDOS')->assertJsonPath('erros.empresas', [$c->id]);
        $this->postJson('/api/consolidacao/grupos', ['nome' => 'Outro', 'nif' => '5417000581', 'membros' => [$holding->id]], $this->s)->assertStatus(422);
        $this->postJson('/api/consolidacao/grupos', ['nome' => 'Outro', 'nif' => '5417000571', 'membros' => [$this->a->id]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NIF_DUPLICADO');

        $soA = $this->sessao(['consolidacao_view', 'consol_executar'], [$this->a]);
        $this->assertCount(0, $this->getJson('/api/consolidacao/grupos', $soA)->assertOk()->json('dados'));
        $this->u->empresas()->attach($holding->id);
        app(ServicoEmpresas::class)->invalidarUtilizador($this->u->id);
        $this->postJson("/api/consolidacao/grupos/{$g['id']}/executar", ['data_fim' => '2026-12-31'], $soA)->assertStatus(403)->assertJsonPath('codigo', 'SEM_ACESSO_EMPRESAS_GRUPO');

        $g2 = $this->putJson("/api/consolidacao/grupos/{$g['id']}", ['nome' => 'Grupo Renomeado', 'nif' => '5417000579', 'membros' => [$this->a->id], 'prefixos_excluidos_eliminacao' => '4'], $this->s)
            ->assertOk()->json('dados');
        $this->assertSame(['Grupo Renomeado', [$this->a->id], '4'], [$g2['holding']['nome'], array_column($g2['membros'], 'empresa_id'), $g2['prefixos_excluidos_eliminacao']]);
    }

    #[Test]
    public function consolida_em_aoa_com_eliminacoes_intragrupo_e_mapa(): void
    {
        $g = $this->criarGrupo();
        $holding = $g['holding']['id'];
        app(ContextoEmpresa::class)->executarComo($this->a->id, fn () => SaldoHistorico::create(['ano' => 2025, 'tipo' => 'DEMONSTRACAO_RESULTADOS', 'codigo' => '4', 'valor' => 100]));
        app(ContextoEmpresa::class)->executarComo($this->b->id, fn () => SaldoHistorico::create(['ano' => 2025, 'tipo' => 'DEMONSTRACAO_RESULTADOS', 'codigo' => '4', 'valor' => 50]));

        $r = $this->postJson("/api/consolidacao/grupos/{$g['id']}/executar", ['data_fim' => '2026-12-31'], $this->s)->assertOk()->json('dados');
        $t = $r['totais'];
        $this->assertTrue($r['equilibrado']);
        $this->assertSame([10, 0, '0.00', 4], [$t['linhas'], $t['linhas_apagadas'], $t['reserva'], $t['eliminacoes']['linhas']]);
        $this->assertSame([4, 2], array_column($t['empresas'], 'linhas'));
        $this->assertSame(['0.00', []], [$t['eliminacoes']['diferenca'], $t['eliminacoes']['sem_nif']]);
        $div = $t['eliminacoes']['divergencias'][0];
        $this->assertSame(['1000.00', '-1000.00', '0.00', '1000.00', '1000.00', '0.00'],
            [$div['saldo_ab'], $div['saldo_ba'], $div['dif_saldo'], $div['proveitos_ab'], $div['custos_ba'], $div['dif_ab']]);

        $linhas = $this->linhasHolding($holding);
        $agregadas = $linhas->where('tipo_consolidacao', 'AGREGACAO');
        $this->assertSame(['E'.$this->a->id.'-OD2026000001'], $agregadas->where('codigo_conta', '3111')->pluck('numero_lan')->values()->all());
        $this->assertSame((float) 1000, (float) $agregadas->where('codigo_conta', '3111')->first()->valor_kz_origem);
        $this->assertNotNull($agregadas->where('codigo_conta', '3111')->first()->terceiro_id);
        $this->assertSame(3, DB::table('terceiros')->where('empresa_id', $holding)->count());
        $this->assertSame(1, DB::table('diarios_contabeis')->where('empresa_id', $holding)->where('codigo', 'CONS')->count());
        $this->assertSame('150.00', DB::table('saldos_historicos')->where('empresa_id', $holding)->value('valor'));
        $holdingE = Empresa::query()->find($holding);
        $this->assertSame(['AOA', '2026-12-31', $r['execucao_id']], [$holdingE->moeda_consolidacao, $holdingE->data_fim_consolidacao->toDateString(), DB::table('empresas')->where('id', $holding)->value('execucao_consolidacao_id')]);

        $m = $this->getJson("/api/consolidacao/grupos/{$g['id']}/mapa?ocultar_zeros=1", $this->s)->assertOk()->json('dados');
        $this->assertSame(['E'.$this->a->id, 'E'.$this->b->id, 'SOMA', 'ELIM', 'TOTAL'], array_column($m['colunas'], 'chave'));
        $vendas = collect($m['linhas'])->firstWhere('codigo', '6111');
        $this->assertSame(['-1500.00', '-1500.00', '1000.00', '-500.00'], [$vendas['valores']['E'.$this->a->id], $vendas['valores']['SOMA'], $vendas['valores']['ELIM'], $vendas['valores']['TOTAL']]);
        $this->assertSame('500.00', $m['resultado']['TOTAL']);
        $this->assertSame('0.00', $m['total']['TOTAL']);
        $classes = $this->getJson("/api/consolidacao/grupos/{$g['id']}/mapa?nivel=classe&contas=6-7", $this->s)->assertOk()->json('dados.linhas');
        $this->assertSame(['6', '7'], array_column($classes, 'codigo'));

        $this->assertSame(10, $this->getJson("/api/consolidacao/execucoes/{$r['execucao_id']}", $this->s)->assertOk()->json('dados.totais.linhas'));
    }

    #[Test]
    public function nova_execucao_substitui_so_as_linhas_geradas(): void
    {
        $g = $this->criarGrupo();
        $holding = $g['holding']['id'];
        $this->postJson("/api/consolidacao/grupos/{$g['id']}/executar", ['data_fim' => '2026-12-31'], $this->s)->assertOk();
        app(ContextoEmpresa::class)->executarComo($holding, function () {
            PlanoConta::query()->where('codigo', '4511')->exists() ?: PlanoConta::create(['codigo' => '4511', 'descricao' => 'Caixa', 'tipo' => 'M']);
            app(ServicoLancamentos::class)->criar(['diario_id' => DiarioContabil::query()->where('codigo', 'OD')->value('id'), 'data_documento' => '2026-06-30',
                'linhas' => [['codigo_conta' => '4511', 'tipo_dc' => 'D', 'valor' => 7], ['codigo_conta' => '5111', 'tipo_dc' => 'C', 'valor' => 7]]]);
        });
        $this->lancar($this->a, '2026-03-01', [['4511', 'D', 20], ['6111', 'C', 20]]);
        $r = $this->postJson("/api/consolidacao/grupos/{$g['id']}/executar", ['data_fim' => '2026-12-31'], $this->s)->assertOk()->json('dados');
        $this->assertSame([12, 10], [$r['totais']['linhas'], $r['totais']['linhas_apagadas']]);
        $this->assertSame(14, $this->linhasHolding($holding)->count());
        $m = $this->getJson("/api/consolidacao/grupos/{$g['id']}/mapa", $this->s)->assertOk()->json('dados');
        $this->assertContains('OUTROS', array_column($m['colunas'], 'chave'));
        $this->assertSame(2, count($this->getJson("/api/consolidacao/grupos/{$g['id']}", $this->s)->json('dados.execucoes')));

        $this->deleteJson("/api/consolidacao/grupos/{$g['id']}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'HOLDING_COM_LANCAMENTOS');
    }

    #[Test]
    public function consolida_noutra_moeda_com_conversao_e_reservas(): void
    {
        $g = $this->criarGrupo(['membros' => [$this->a->id], 'eliminacao_ativa' => false]);
        $this->lancar($this->a, '2026-03-01', [['4511', 'D', 8000], ['5111', 'C', 8000]]);
        $this->postJson("/api/consolidacao/grupos/{$g['id']}/executar", ['data_fim' => '2026-06-30', 'moeda' => 'USD'], $this->s)->assertStatus(422)
            ->assertJsonPath('codigo', 'CAMBIOS_EM_FALTA');
        app(ContextoEmpresa::class)->executarComo($this->a->id, function () {
            TaxaCambio::create(['empresa_id' => null, 'codigo_moeda' => 'USD', 'data_taxa' => '2026-01-01', 'taxa' => '800']);
            TaxaCambio::create(['empresa_id' => null, 'codigo_moeda' => 'USD', 'data_taxa' => '2026-06-30', 'taxa' => '1000']);
        });

        // 1 000 e 500 a 800 (fevereiro) = 1,25 e 0,63; 8 000 a 800 (março) = 10,00; fecho a 1 000:
        // 3111 → 1,00 (ajuste C 0,25), 4511 → 8,50 (ajuste C 2,13); a classe 5 não é ajustada → reservas D 2,38
        $r = $this->postJson("/api/consolidacao/grupos/{$g['id']}/executar", ['data_fim' => '2026-06-30', 'moeda' => 'USD'], $this->s)->assertOk()->json('dados');
        $this->assertTrue($r['equilibrado']);
        $linhas = $this->linhasHolding($g['holding']['id']);
        $caixa = $linhas->where('tipo_consolidacao', 'AGREGACAO')->where('codigo_conta', '4511')->map(fn ($l) => "{$l->valor_kz_origem}>{$l->valor}")->sort()->values()->all();
        $this->assertSame(['500.00>0.63', '8000.00>10.00'], $caixa);
        $ajustes = $linhas->where('tipo_consolidacao', 'CONVERSAO')->map(fn ($l) => "{$l->codigo_conta} {$l->tipo_dc} {$l->valor}")->values()->all();
        $this->assertSame(['3111 C 0.25', '4511 C 2.13', '5.9.9 D 2.38'], $ajustes);
        $this->assertSame('2.38', $r['totais']['reserva']);
        $reserva = $linhas->where('tipo_consolidacao', 'CONVERSAO')->where('codigo_conta', '5.9.9')->first();
        $this->assertSame('Reservas de conversão cambial (USD)', $reserva->descricao);
        $this->assertSame(1, DB::table('plano_contas')->where('empresa_id', $g['holding']['id'])->where('codigo', '5.9.9')->count());
        $this->assertSame([0, 'USD'], [$r['totais']['eliminacoes']['linhas'], $this->getJson("/api/consolidacao/grupos/{$g['id']}", $this->s)->json('dados.moeda_apresentacao')]);
    }

    #[Test]
    public function eliminar_o_grupo_apaga_a_projeccao_e_a_holding(): void
    {
        $g = $this->criarGrupo();
        $holding = $g['holding']['id'];
        $this->postJson("/api/consolidacao/grupos/{$g['id']}/executar", ['data_fim' => '2026-12-31'], $this->s)->assertOk();
        $this->deleteJson("/api/consolidacao/grupos/{$g['id']}", [], $this->sessao(['consolidacao_view', 'consol_gerir'], [$this->a, $this->b]))->assertForbidden();
        $this->deleteJson("/api/consolidacao/grupos/{$g['id']}", [], $this->s)->assertOk();
        $this->assertSame(0, $this->linhasHolding($holding)->count());
        $this->assertSame(0, DB::table('grupos_consolidacao')->where('id', $g['id'])->count());
        $this->assertSame(0, DB::table('execucoes_consolidacao')->where('grupo_consolidacao_id', $g['id'])->count());
        $this->assertNotNull(DB::table('empresas')->where('id', $holding)->value('eliminado_em'));
        $this->assertSame(4, DB::table('lancamentos_contabeis')->where('empresa_id', $this->a->id)->count());
    }
}
