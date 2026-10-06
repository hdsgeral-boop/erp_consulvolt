<?php

namespace Tests\Feature;

use App\Models\AtivoImobilizado;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\PlanoFeriasColaborador;
use App\Services\Ativos\CalculadoraAmortizacoes;
use App\Services\Ativos\ServicoAmortizacoes;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 6 — revisão de desempenho (ADR-065): N+1 e ciclos desnecessários corrigidos sem mudar resultados.
 */
final class DesempenhoTest extends TestCase
{
    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417006521']);
    }

    private function emEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    #[Test]
    public function resumo_de_ferias_usa_um_numero_fixo_de_consultas_e_mantem_o_direito_mais_recente(): void
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'rh_ferias_view' => true])->id]);
        $u->empresas()->attach($this->empresa->id);
        $s = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];

        $ids = $this->emEmpresa(function () {
            $ids = [];
            for ($i = 1; $i <= 12; $i++) {
                $ids[] = Colaborador::create(['nome_completo' => sprintf('Colaborador %02d', $i), 'estado' => 'ACTIVO'])->id;
            }
            // o direito vem do registo mais recente do ano (mesmo cancelado); sem registo, 22
            PlanoFeriasColaborador::create(['colaborador_id' => $ids[0], 'ano' => 2026, 'data_inicio' => '2026-02-02', 'data_fim' => '2026-02-06', 'dias' => 5,
                'direito' => 20, 'estado' => 'PLANEADO']);
            PlanoFeriasColaborador::create(['colaborador_id' => $ids[0], 'ano' => 2026, 'data_inicio' => '2026-03-02', 'data_fim' => '2026-03-03', 'dias' => 2,
                'direito' => 25, 'estado' => 'CANCELADO']);
            PlanoFeriasColaborador::create(['colaborador_id' => $ids[1], 'ano' => 2025, 'data_inicio' => '2025-03-02', 'data_fim' => '2025-03-03', 'dias' => 2,
                'direito' => 30, 'estado' => 'PLANEADO']);

            return $ids;
        });

        DB::enableQueryLog();
        $resumo = collect($this->getJson('/api/rh/ferias?ano=2026', $s)->assertOk()->json('dados.resumo'))->keyBy('colaborador_id');
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(12, $resumo);
        $this->assertSame(25, $resumo[$ids[0]]['direito']);
        // o direito gravado em 2025 não passa a ser o de 2026: 2026 é calculado (22) + o saldo de 2025 transportado (decisão 7 do
        // utilizador, LGT): 30 − 2 marcados = 28, limitado a 22 (máximo transportável por omissão) → 44
        $this->assertSame(44, $resumo[$ids[1]]['direito']);
        $this->assertSame(22, $resumo[$ids[1]]['transporte']);
        $this->assertSame(22, $resumo[$ids[2]]['direito']);   // sem plano no ano anterior, nada transita
        $this->assertLessThan(12, $consultas, 'O resumo de férias não pode fazer uma consulta por colaborador.');
    }

    #[Test]
    public function meses_por_calcular_iguais_a_percorrer_todos_os_meses(): void
    {
        $servico = app(ServicoAmortizacoes::class);
        $ultimo = 2026 * 12 + 8;
        [$obtido, $esperado] = $this->emEmpresa(function () use ($servico, $ultimo) {
            $base = ['estado' => AtivoImobilizado::ESTADO_ATIVO, 'valor_residual' => 0, 'quota_fixa' => 0];
            foreach ([
                ['codigo' => 'A1', 'valor_aquisicao' => 1200, 'vida_util' => 12, 'data_aquisicao' => '2020-07-01'],                                   // esgotado há muito
                ['codigo' => 'A2', 'valor_aquisicao' => '759717.42', 'vida_util' => 84, 'data_aquisicao' => '2020-10-01', 'acumulado_fim_ano' => 2025,
                    'amortizacao_acumulada_inicial' => 500000],                                                                                         // amortização inicial migrada
                ['codigo' => 'A3', 'valor_aquisicao' => 5000, 'vida_util' => 48, 'data_aquisicao' => '2025-01-01', 'quota_fixa' => 250],              // quota fixa
                ['codigo' => 'A4', 'valor_aquisicao' => 12000, 'vida_util' => 0, 'data_aquisicao' => '2024-01-01'],                                   // sem vida útil: taxa
                ['codigo' => 'A5', 'valor_aquisicao' => 900, 'valor_residual' => 900, 'vida_util' => 10, 'data_aquisicao' => '2026-01-01'],          // base zero
                ['codigo' => 'A6', 'valor_aquisicao' => 3000, 'vida_util' => 36, 'data_aquisicao' => '2026-05-01', 'acumulado_fim_ano' => 2027],      // ano futuro
            ] as $a) {
                AtivoImobilizado::create(array_merge($base, ['descricao' => $a['codigo']], $a));
            }
            $obtido = $servico->mesesPorCalcular($ultimo);

            // referência: o ciclo original, mês a mês desde a aquisição até $ultimo
            $esperado = [];
            foreach (AtivoImobilizado::query()->where('estado', AtivoImobilizado::ESTADO_ATIVO)->orderBy('codigo')->get() as $a) {
                $acumulado = CalculadoraAmortizacoes::d($a->amortizacao_acumulada_inicial);
                $inicio = (int) $a->data_aquisicao->format('Y') * 12 + (int) $a->data_aquisicao->format('n') - 1;
                for ($n = $inicio; $n <= $ultimo; $n++) {
                    $q = CalculadoraAmortizacoes::devida($a, null, intdiv($n, 12), $n % 12 + 1, $acumulado);
                    if ($q !== null) {
                        $acumulado = bcadd($acumulado, $q, 2);
                        $esperado[] = ['ativo_imobilizado_id' => $a->id, 'codigo' => $a->codigo, 'periodo' => CalculadoraAmortizacoes::codigo(intdiv($n, 12), $n % 12 + 1), 'quota' => $q];
                    }
                }
            }

            return [$obtido, $esperado];
        });

        $this->assertNotEmpty($esperado);
        $this->assertSame($esperado, $obtido);
    }
}
