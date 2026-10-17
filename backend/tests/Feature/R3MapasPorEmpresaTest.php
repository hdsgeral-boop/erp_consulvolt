<?php

namespace Tests\Feature;

use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\NotaDemonstracao;
use App\Models\PlanoConta;
use App\Models\Terceiro;
use App\Models\Utilizador;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Sistema\ServicoEmpresas;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ronda 3 — mapas legais da holding «Por empresa» (legado renderPorEmpresa): uma coluna por empresa do grupo, eliminações e
 * consolidado, calculados no servidor a partir da última consolidação; a soma das colunas é sempre o consolidado.
 * A vende 1 000 a B (intragrupo, eliminado) e 500 a um cliente externo; B regista a compra de 1 000.
 */
final class R3MapasPorEmpresaTest extends TestCase
{
    private Empresa $a;

    private Empresa $b;

    private Utilizador $u;

    private array $s;

    private const PERMISSOES = ['consolidacao_view', 'consol_gerir', 'consol_executar', 'contab_mapa_balancete_view', 'contab_mapa_balanco_view', 'contab_mapa_dr_view', 'contab_mapa_fluxo_view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa(['nif' => '5417000671']);
        $this->b = $this->criarEmpresa(['nif' => '5417000672']);
        foreach ([[$this->a, '5417000672'], [$this->b, '5417000671']] as [$e, $nifOutra]) {
            app(ContextoEmpresa::class)->executarComo($e->id, function () use ($nifOutra) {
                foreach (['3111' => 'Clientes', '3211' => 'Fornecedores', '4511' => 'Caixa', '6111' => 'Vendas', '7111' => 'Compras'] as $c => $d) {
                    PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
                }
                PlanoConta::create(['codigo' => '31', 'descricao' => 'Terceiros', 'tipo' => 'T']);
                foreach (['9' => 'Contas a receber', '10' => 'Disponibilidades', '19' => 'Contas a pagar', '22' => 'Vendas', '27' => 'Custo das mercadorias'] as $c => $d) {
                    NotaDemonstracao::create(['codigo' => (string) $c, 'descricao' => $d]);
                }
                DiarioContabil::create(['codigo' => 'OD', 'nome' => 'Diversos']);
                Terceiro::create(['nome' => 'Empresa do grupo', 'nif' => $nifOutra]);
            });
        }
        $this->lancar($this->a, '2026-02-01', [['3111', 'D', 1000, '9', true], ['6111', 'C', 1000, '22']]);
        $this->lancar($this->a, '2026-02-02', [['4511', 'D', 500, '10'], ['6111', 'C', 500, '22']]);
        $this->lancar($this->b, '2026-02-03', [['7111', 'D', 1000, '27'], ['3211', 'C', 1000, '19', true]]);
        $this->s = $this->sessao(self::PERMISSOES, [$this->a, $this->b]);
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
                'linhas' => array_map(fn ($l) => ['codigo_conta' => $l[0], 'tipo_dc' => $l[1], 'valor' => $l[2],
                    'nota_demonstracao_id' => NotaDemonstracao::query()->where('codigo', $l[3])->value('id'), 'terceiro_id' => ($l[4] ?? false) ? $grupo : null], $linhas)]);
        });
    }

    /** Soma das colunas que não são o consolidado, por chave. */
    private function somaColunas(array $r, string $k): string
    {
        $s = '0.00';
        foreach ($r['colunas'] as $c) {
            if ($c['chave'] !== 'TOTAL') {
                $s = bcadd($s, $r['valores'][$c['chave']][$k] ?? '0.00', 2);
            }
        }

        return $s;
    }

    #[Test]
    public function mapas_da_holding_por_empresa_somam_o_consolidado(): void
    {
        $g = $this->postJson('/api/consolidacao/grupos', ['nome' => 'Grupo R3', 'nif' => '5417000679', 'membros' => [$this->a->id, $this->b->id]], $this->s)->assertCreated()->json('dados');
        $holding = (int) $g['holding']['id'];
        $h = ['X-Empresa-Id' => $holding] + $this->s;
        $periodo = 'data_inicio=2026-01-01&data_fim=2026-12-31';

        // antes de consolidar: nada a mostrar
        $this->getJson("/api/contabilidade/relatorios/por-empresa/balancete?{$periodo}", $h)->assertStatus(422)->assertJsonPath('codigo', 'SEM_CONSOLIDACAO');
        $this->postJson("/api/consolidacao/grupos/{$g['id']}/executar", ['data_fim' => '2026-12-31'], $h)->assertOk();

        $bal = $this->getJson("/api/contabilidade/relatorios/por-empresa/balancete?{$periodo}&totalizadoras=1", $h)->assertOk()->json('dados');
        $chaves = array_column($bal['colunas'], 'chave');
        $this->assertSame(["E{$this->a->id}", "E{$this->b->id}", 'ELIM', 'TOTAL'], $chaves);   // sem conversão nem lançamentos na holding
        $this->assertSame([(string) $this->a->id, (string) $this->b->id], [(string) $bal['colunas'][0]['empresa_id'], (string) $bal['colunas'][1]['empresa_id']]);
        $contas = array_column($bal['linhas'], 'codigo_conta');
        foreach (['3111', '3211', '4511', '6111', '7111'] as $c) {
            $this->assertContains($c, $contas);
        }
        foreach ($bal['linhas'] as $l) {
            $this->assertSame($bal['valores']['TOTAL'][$l['codigo_conta']] ?? '0.00', $this->somaColunas($bal, $l['codigo_conta']), "balancete {$l['codigo_conta']}");
        }
        $this->assertSame(['-1500.00', '0.00'], [$bal['valores']["E{$this->a->id}"]['6111'], $bal['valores']["E{$this->b->id}"]['6111'] ?? '0.00']);
        $this->assertSame('-500.00', $bal['valores']['TOTAL']['6111']);   // a venda intragrupo é eliminada
        $this->assertSame('1000.00', $bal['valores']['ELIM']['6111']);

        foreach (['balanco' => 'data_fim=2026-12-31', 'dr' => $periodo, 'fluxo' => $periodo] as $mapa => $q) {
            $r = $this->getJson("/api/contabilidade/relatorios/por-empresa/{$mapa}?{$q}", $h)->assertOk()->json('dados');
            $this->assertSame('TOTAL', end($r['colunas'])['chave']);
            foreach (array_keys($r['valores']['TOTAL']) as $k) {
                if (in_array($k, ['caixa_inicial', 'caixa_final'], true)) {
                    continue;   // só no consolidado
                }
                $this->assertSame($r['valores']['TOTAL'][$k], $this->somaColunas($r, $k), "{$mapa} {$k}");
            }
        }
        $dr = $this->getJson("/api/contabilidade/relatorios/por-empresa/dr?{$periodo}", $h)->assertOk()->json('dados');
        $this->assertSame('500.00', $dr['valores']['TOTAL']['resultado_liquido']);

        // empresa que não é holding; utilizador sem acesso a todas as empresas do grupo
        $this->getJson("/api/contabilidade/relatorios/por-empresa/dr?{$periodo}", $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NAO_E_HOLDING');
        $this->u->empresas()->detach($this->b->id);
        app(ServicoEmpresas::class)->invalidarUtilizador($this->u->id);
        $this->getJson("/api/contabilidade/relatorios/por-empresa/dr?{$periodo}", $h)->assertForbidden();
        // sem a permissão do mapa
        $soBalancete = $this->sessao(['contab_mapa_balancete_view'], [$this->a, $this->b]);
        $this->getJson("/api/contabilidade/relatorios/por-empresa/dr?{$periodo}", $soBalancete)->assertForbidden();
    }
}
