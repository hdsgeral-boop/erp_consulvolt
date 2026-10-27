<?php

namespace Tests\Feature;

use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verificação de ponta a ponta do PDF «Conta-cartão de tesouraria» (secção 8): com 1 000 000 no banco, 200 000 na
 * caixa e 150 000 de dívida no cartão, todos os ecrãs de disponibilidades devem dar 1 050 000 — o cartão entra
 * abatido sozinho (soma com sinal: D − C), sem lógica especial de subtracção.
 */
final class TesourariaCartaoDisponibilidadesTest extends TestCase
{
    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['4311' => 'Banco Ficticio', '4511' => 'Caixa', '3282' => 'Cartão de crédito BAI', '611' => 'Vendas', '622' => 'Despesas diversas'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            PlanoConta::query()->where('codigo', '3282')->update(['cartao_tesouraria' => true, 'cartao_banco' => 'BAI']);
            $diario = DiarioContabil::create(['codigo' => 'OD', 'descricao' => 'Operações diversas', 'nome' => 'Operações diversas'])->id;
            $l = fn (string $lan, array $linhas) => array_map(fn ($x) => LancamentoContabil::create(['diario_id' => $diario, 'data_documento' => '2026-03-15',
                'numero_lan' => $lan, 'numero_documento' => $lan, 'codigo_conta' => $x[0], 'tipo_dc' => $x[1], 'valor' => $x[2]]), $linhas);
            $l('OD2026000001', [['4311', 'D', 1000000], ['611', 'C', 1000000]]);   // banco: 1 000 000
            $l('OD2026000002', [['4511', 'D', 200000], ['611', 'C', 200000]]);     // caixa: 200 000
            $l('OD2026000003', [['622', 'D', 150000], ['3282', 'C', 150000]]);     // cartão: dívida de 150 000 (saldo credor)
        });
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private static function kpi(array $painel, string $id): mixed
    {
        $k = collect($painel['kpis'])->firstWhere('id', $id);

        return $k === null ? 'AUSENTE' : $k['valor'];
    }

    #[Test]
    public function disponibilidades_1050000_no_dashboard_visao_geral(): void
    {
        $s = $this->sessao(['all']);
        $g = $this->getJson('/api/gestao/paineis/geral?ano=2026&mes=3', $s)->assertOk()->json('dados');
        $this->assertSame('1050000.00', self::kpi($g, 'disponibilidades'));
        $this->assertSame('Bancos (43), Caixa (45) e cartões abatidos', collect($g['kpis'])->firstWhere('id', 'disponibilidades')['subtitulo']);
    }

    #[Test]
    public function dashboard_tesouraria_mostra_bancos_caixa_e_cartoes_a_abater(): void
    {
        $s = $this->sessao(['all']);
        $t = $this->getJson('/api/gestao/paineis/tesouraria?ano=2026&mes=3', $s)->assertOk()->json('dados');
        $this->assertSame('1000000.00', self::kpi($t, 'saldo_bancos'));
        $this->assertSame('200000.00', self::kpi($t, 'saldo_caixa'));
        $this->assertSame('-150000.00', self::kpi($t, 'saldo_cartoes'));
    }

    #[Test]
    public function relatorio_de_gestao_financas_dá_1050000_em_disponibilidades(): void
    {
        $s = $this->sessao(['relatorios_gestao_view']);
        $r = $this->getJson('/api/gestao/relatorios/financas?a_inicio=2026-03-01&a_fim=2026-03-31&comparacao=nenhum', $s)->assertOk()->json('dados');
        $k = collect($r['kpis'])->firstWhere('chave', 'disponib');
        $this->assertSame('1050000.00', $k['a']);
    }

    #[Test]
    public function relatorio_de_gestao_tesouraria_tambem_abate_o_cartao(): void
    {
        $s = $this->sessao(['relatorios_gestao_view']);
        $r = $this->getJson('/api/gestao/relatorios/tesouraria?a_inicio=2026-03-01&a_fim=2026-03-31&comparacao=nenhum', $s)->assertOk()->json('dados');
        $kpis = collect($r['kpis'])->pluck('a', 'chave');
        $this->assertSame('1050000.00', $kpis['saldo_fim']);
        $this->assertSame('-150000.00', $kpis['cartoes'] ?? null);   // saldo credor (dívida): negativo, como saldo_fim/saldo_ini
    }
}
