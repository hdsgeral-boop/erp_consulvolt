<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Utilizador;
use App\Services\Integracoes\Operacoes\ServicoOperacoes;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/** Preferências do utilizador no servidor (M-19) e operações em segundo plano (M-05). */
final class PreferenciasOperacoesTest extends TestCase
{
    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
    }

    private function sessao(): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'lancamentos_view' => true])->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id, '_id' => $u->id];
    }

    #[Test]
    public function cada_utilizador_le_e_escreve_so_as_suas_preferencias(): void
    {
        $a = $this->sessao();
        $b = $this->sessao();
        $favoritos = [['modulo' => 'contab', 'ecra' => 'lancamentos'], ['modulo' => 'vendas', 'ecra' => 'vendas_faturacao']];
        $this->putJson('/api/sistema/preferencias/favoritos/lista', ['valor' => $favoritos], $a)->assertOk();
        $this->putJson('/api/sistema/preferencias/visoes_cubo/vendas.por-cliente', ['valor' => ['conjunto' => 'vendas', 'linhas' => ['cliente']]], $a)->assertOk();
        $this->putJson('/api/sistema/preferencias/favoritos/lista', ['valor' => [['modulo' => 'rh', 'ecra' => 'colaboradores']]], $a)->assertOk();   // actualiza

        $this->getJson('/api/sistema/preferencias/favoritos', $a)->assertOk()->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.valor.0.ecra', 'colaboradores');
        $this->getJson('/api/sistema/preferencias/favoritos', $b)->assertOk()->assertJsonCount(0, 'dados');
        $this->deleteJson('/api/sistema/preferencias/visoes_cubo/vendas.por-cliente', [], $b)->assertOk();
        $this->assertSame(1, DB::table('preferencias_utilizador')->where('tipo', 'visoes_cubo')->count(), 'B não apaga as de A');
        $this->deleteJson('/api/sistema/preferencias/visoes_cubo/vendas.por-cliente', [], $a)->assertOk();
        $this->assertSame(0, DB::table('preferencias_utilizador')->where('tipo', 'visoes_cubo')->count());

        $this->getJson('/api/sistema/preferencias/segredos', $a)->assertStatus(404)->assertJsonPath('codigo', 'PREFERENCIA_TIPO_INVALIDO');
        $this->putJson('/api/sistema/preferencias/interface/grande', ['valor' => str_repeat('x', 70000)], $a)->assertStatus(422)->assertJsonPath('codigo', 'PREFERENCIA_INVALIDA');
        $this->putJson('/api/sistema/preferencias/interface/x', [], $a)->assertStatus(422);
    }

    #[Test]
    public function operacao_em_segundo_plano_com_progresso_so_para_o_dono(): void
    {
        $a = $this->sessao();
        $b = $this->sessao();
        $this->actingAs(Utilizador::query()->findOrFail($a['_id']));
        $op = app(ServicoOperacoes::class)->despachar('Teste de lote', [new TrabalhoTesteOperacao(false), new TrabalhoTesteOperacao(false), new TrabalhoTesteOperacao(false)], null, $this->empresa->id);
        $this->assertSame('Teste de lote', $op['titulo']);

        $this->getJson("/api/sistema/operacoes/{$op['id']}", $a)->assertOk()->assertJsonPath('dados.total', 3)->assertJsonPath('dados.falhados', 0)->assertJsonPath('dados.progresso', 100)
            ->assertJsonPath('dados.concluida', true)->assertJsonPath('dados.estado', 'CONCLUIDA')->assertJsonPath('dados.titulo', 'Teste de lote');
        $this->getJson("/api/sistema/operacoes/{$op['id']}", $b)->assertNotFound();
        $this->getJson('/api/sistema/operacoes/inexistente', $a)->assertNotFound();
        $this->postJson("/api/sistema/operacoes/{$op['id']}/cancelar", [], $a)->assertOk()->assertJsonPath('dados.cancelada', true)->assertJsonPath('dados.estado', 'CANCELADA');
    }
}

/** Trabalho de teste para os lotes (falha quando pedido). */
final class TrabalhoTesteOperacao implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue;

    public function __construct(private readonly bool $falhar) {}

    public function handle(): void
    {
        if ($this->falhar) {
            throw new RuntimeException('falha de teste');
        }
    }
}
