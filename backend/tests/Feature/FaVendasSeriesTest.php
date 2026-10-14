<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\SerieFaturacaoEletronica;
use App\Models\Terceiro;
use App\Models\TerminalPOS;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase A — Vendas › Séries de numeração (js/vendas_series.js e js/facturacao_agt.js:125-186 do legado novo):
 * número inicial, «usada» = tem documentos emitidos, uma activa por tipo+ano+origem, fecha-se em vez de apagar.
 * Dados fictícios.
 */
final class FaVendasSeriesTest extends TestCase
{
    private Empresa $empresa;

    private Terceiro $cliente;

    private Produto $produto;

    private array $s;

    private string $hoje;

    private int $ano;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->ano = (int) now()->year;
        $this->empresa = $this->criarEmpresa(['nif' => '5417000099']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach ([['311', 'Clientes'], ['3452', 'IVA liquidado'], ['611', 'Vendas']] as [$c, $d]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->cliente = Terceiro::create(['nome' => 'Cliente Fictício', 'nif' => '5000000011', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311']);
            $this->produto = Produto::create(['codigo' => 'PX', 'nome' => 'Produto X', 'preco_unitario' => 100, 'taxa_imposto' => 14,
                'codigo_conta' => '611', 'conta_iva_liquidado' => '3452', 'movimenta_stock' => false]);
            TerminalPOS::create(['codigo' => 'CX1', 'nome' => 'Caixa da loja']);
        });
        $this->s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fe_config']);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function serie(array $dados = [], ?array $s = null): TestResponse
    {
        return $this->postJson('/api/vendas/series', $dados + ['tipo' => 'FT', 'ano' => $this->ano, 'codigo' => 'LIV'.$this->ano, 'proximo_numero' => 501], $s ?? $this->s);
    }

    private function emitir(string $data): TestResponse
    {
        return $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $this->cliente->id, 'data_emissao' => $data,
            'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 1]]], $this->s);
    }

    #[Test]
    public function serie_com_numero_inicial_continua_a_numeracao_e_fica_presa_depois_do_primeiro_documento(): void
    {
        $id = $this->serie()->assertCreated()->assertJsonPath('dados.proximo_numero', 501)->json('dados.id');
        $this->emitir($this->hoje)->assertCreated()->assertJsonPath('dados.numero_documento', "FT LIV{$this->ano}/501");

        $lista = $this->getJson('/api/vendas/series', $this->s)->assertOk()
            ->assertJsonPath('dados.resumo.0.tipo', 'FT')->assertJsonPath('dados.resumo.0.proximo_documento', "FT LIV{$this->ano}/502")
            ->assertJsonPath('dados.resumo.1.serie_id', null);
        $linha = collect($lista->json('dados.series'))->firstWhere('id', $id);
        $this->assertSame(1, $linha['emitidos']);
        $this->assertTrue($linha['usada']);
        $this->assertSame("FT LIV{$this->ano}/501", $linha['ultimo_documento']);
        $this->assertSame("FT LIV{$this->ano}/502", $linha['proximo_documento']);
        $this->assertSame('Facturação (geral)', $linha['origem_rotulo']);

        $base = ['tipo' => 'FT', 'ano' => $this->ano, 'codigo' => 'LIV'.$this->ano, 'origem' => 'GERAL'];
        // usada: o número e o código já não mudam; o estado sim
        $this->putJson("/api/vendas/series/{$id}", $base + ['proximo_numero' => 900], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'SERIE_USADA');
        $this->putJson("/api/vendas/series/{$id}", ['codigo' => 'OUTRA'] + $base, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'SERIE_USADA');
        // não se apaga, fecha-se
        $this->deleteJson("/api/vendas/series/{$id}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'SERIE_USADA');
        $this->putJson("/api/vendas/series/{$id}", $base + ['estado' => 'FECHADA', 'proximo_numero' => 502], $this->s)->assertOk()->assertJsonPath('dados.estado', 'FECHADA');
    }

    #[Test]
    public function serie_virgem_que_arranca_acima_de_1_continua_editavel_e_eliminavel(): void
    {
        $id = $this->serie()->assertCreated()->json('dados.id');
        $base = ['tipo' => 'FT', 'ano' => $this->ano, 'codigo' => 'LIV'.$this->ano];
        // a regra antiga (proximo_numero > 1 = usada) bloquearia estas duas operações
        $this->putJson("/api/vendas/series/{$id}", array_merge($base, ['proximo_numero' => 700, 'codigo' => 'NOVA'.$this->ano]), $this->s)->assertOk()
            ->assertJsonPath('dados.proximo_numero', 700)->assertJsonPath('dados.codigo', 'NOVA'.$this->ano);
        $this->deleteJson("/api/vendas/series/{$id}", [], $this->s)->assertOk();
    }

    #[Test]
    public function uma_so_serie_activa_por_tipo_ano_e_origem(): void
    {
        $this->serie()->assertCreated();
        $this->serie(['codigo' => 'B'.$this->ano])->assertStatus(422)->assertJsonPath('codigo', 'SERIE_ATIVA_DUPLICADA');
        // outra origem (terminal POS da empresa) pode ter a sua
        $this->serie(['codigo' => 'CX1'.$this->ano, 'origem' => 'CX1'])->assertCreated()->assertJsonPath('dados.origem', 'CX1')
            ->assertJsonPath('dados.origem_nome', 'POS CX1 — Caixa da loja');
        // mesmo código no mesmo tipo e ano, ainda que fechada
        $this->serie(['codigo' => 'LIV'.$this->ano, 'estado' => 'FECHADA', 'origem' => 'CX1'])->assertStatus(422)->assertJsonPath('codigo', 'SERIE_DUPLICADA');
    }

    #[Test]
    public function valida_numero_inicial_codigo_e_origem(): void
    {
        $this->serie(['proximo_numero' => 0])->assertStatus(422);
        $this->serie(['proximo_numero' => 100_000_000])->assertStatus(422);
        $this->serie(['codigo' => 'A/2026'])->assertStatus(422);
        $this->serie(['codigo' => str_repeat('A', 31)])->assertStatus(422);
        $this->serie(['origem' => 'TERMINAL-INEXISTENTE'])->assertStatus(422);
        $this->serie(['tipo' => 'OR'])->assertStatus(422);
        $this->serie(['proximo_numero' => 99_999_999, 'codigo' => 'MAX'])->assertCreated();
    }

    #[Test]
    public function a_data_do_documento_nao_pode_ser_anterior_a_ultima_da_serie(): void
    {
        if (now()->dayOfYear === 1) {
            $this->markTestSkipped('No dia 1 de Janeiro não há data anterior no mesmo ano.');
        }
        $this->serie()->assertCreated();
        $this->emitir($this->hoje)->assertCreated();
        $this->emitir(now()->subDay()->toDateString())->assertStatus(422)->assertJsonPath('codigo', 'DATA_ANTERIOR_SERIE');
    }

    #[Test]
    public function autorizacao_no_servidor_e_isolamento_por_empresa(): void
    {
        // só consulta: vê, não grava (exigir antes de validar: 403 mesmo com dados inválidos)
        $leitor = $this->sessao(['vendas_series_view']);
        $this->getJson('/api/vendas/series', $leitor)->assertOk();
        $this->postJson('/api/vendas/series', [], $leitor)->assertForbidden();
        $this->getJson('/api/vendas/series', $this->sessao(['vendas_clientes_view']))->assertForbidden();

        $id = $this->serie()->assertCreated()->json('dados.id');
        $outra = $this->criarEmpresa(['nif' => '5417000098']);
        $s2 = $this->sessao(['vendas_faturacao_view', 'vendas_fe_config'], $outra);
        $this->putJson("/api/vendas/series/{$id}", ['tipo' => 'FT', 'ano' => $this->ano, 'codigo' => 'X1'], $s2)->assertNotFound();
        $this->deleteJson("/api/vendas/series/{$id}", [], $s2)->assertNotFound();
        $this->assertSame([], $this->getJson('/api/vendas/series', $s2)->json('dados.series'));
        $this->assertTrue(app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => SerieFaturacaoEletronica::query()->whereKey($id)->exists()));
    }
}
