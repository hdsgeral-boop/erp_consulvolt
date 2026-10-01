<?php

namespace Tests\Feature;

use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Afinação da Fase 5 (ADR-064): terceiro {id, nome, nif} nos lançamentos e no razão, sem N+1. */
final class AfinacaoContabilidadeTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private int $diario;

    private int $terceiro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach ([['111', 'Caixa'], ['311', 'Clientes'], ['611', 'Vendas']] as [$c, $d]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->diario = DiarioContabil::create(['codigo' => 'VD', 'descricao' => 'VENDAS'])->id;
            $this->terceiro = Terceiro::create(['nome' => 'Cliente Afinação', 'nif' => '5000000901', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
        });
        $perfil = $this->criarPerfil(['_v2' => true, 'lancamentos_view' => true, 'lancamentos_post' => true, 'contab_mapa_extrato_view' => true]);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->s = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function lancar(string $valor): int
    {
        return $this->postJson('/api/contabilidade/lancamentos', ['diario_id' => $this->diario, 'data_documento' => '2026-03-15', 'descricao' => 'Venda',
            'linhas' => [['codigo_conta' => '311', 'tipo_dc' => 'D', 'valor' => $valor, 'terceiro_id' => $this->terceiro],
                ['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => $valor]]], $this->s)
            ->assertCreated()
            ->assertJsonPath('dados.linhas.0.terceiro', ['id' => $this->terceiro, 'nome' => 'Cliente Afinação', 'nif' => '5000000901'])
            ->assertJsonPath('dados.linhas.1.terceiro', null)
            ->json('dados.linhas.0.id');
    }

    #[Test]
    public function lancamentos_trazem_o_terceiro_por_nome_sem_n_mais_1(): void
    {
        $id = $this->lancar('100.00');
        $this->lancar('200.00');
        $this->lancar('300.00');

        DB::enableQueryLog();
        $r = $this->getJson('/api/contabilidade/lancamentos?codigo_conta=311', $this->s)->assertOk()->assertJsonCount(3, 'dados');
        $consultasTerceiros = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "terceiros"'))->count();
        DB::disableQueryLog();
        $this->assertSame(1, $consultasTerceiros, 'O terceiro deve vir numa única consulta (eager loading).');
        $this->assertSame('Cliente Afinação', $r->json('dados.0.terceiro.nome'));

        $this->getJson("/api/contabilidade/lancamentos/{$id}", $this->s)->assertOk()
            ->assertJsonPath('dados.linhas.0.terceiro.nif', '5000000901');

        // terceiro eliminado (soft delete): o nome continua a aparecer
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Terceiro::query()->findOrFail($this->terceiro)->delete());
        $this->getJson('/api/contabilidade/lancamentos?codigo_conta=311', $this->s)->assertOk()->assertJsonPath('dados.0.terceiro.nome', 'Cliente Afinação');
    }

    #[Test]
    public function razao_traz_o_terceiro_de_cada_movimento(): void
    {
        $this->lancar('150.00');
        $r = $this->getJson('/api/contabilidade/relatorios/razao?codigo_conta=311&data_inicio=2026-01-01&data_fim=2026-12-31', $this->s)->assertOk();
        $this->assertSame(['id' => $this->terceiro, 'nome' => 'Cliente Afinação', 'nif' => '5000000901'], $r->json('dados.movimentos.0.terceiro'));
        $this->assertArrayNotHasKey('terceiro_nome', $r->json('dados.movimentos.0'));

        $r = $this->getJson('/api/contabilidade/relatorios/razao?codigo_conta=611&data_inicio=2026-01-01&data_fim=2026-12-31', $this->s)->assertOk();
        $this->assertNull($r->json('dados.movimentos.0.terceiro'));

        // filtro por terceiro continua a funcionar com a junção
        $this->getJson("/api/contabilidade/relatorios/razao?codigo_conta=311&data_inicio=2026-01-01&data_fim=2026-12-31&terceiro_id={$this->terceiro}", $this->s)
            ->assertOk()->assertJsonCount(1, 'dados.movimentos')->assertJsonPath('dados.debito', '150.00');
    }
}
