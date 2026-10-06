<?php

namespace Tests\Feature;

use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Projeto;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pré-visualização do lançamento antes de contabilizar (legado showPostingPreview / «Simulação contabilística»):
 * as linhas mostradas são EXACTAMENTE as que a contabilização grava, e a pré-visualização não grava nada.
 * Dados fictícios.
 */
final class SimulacoesPreVisualizacaoTest extends TestCase
{
    private int $empresa;

    private Terceiro $cliente;

    private Terceiro $fornecedor;

    private Produto $produto;

    private Produto $servico;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000900'])->id;
        $this->emEmpresa(function () {
            foreach (['311' => 'Clientes', '3211' => 'Fornecedores', '3452' => 'IVA liquidado', '3451' => 'IVA dedutível', '611' => 'Vendas', '752' => 'Serviços'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->cliente = Terceiro::create(['nome' => 'Cliente Simulação', 'nif' => '5000000901', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311']);
            $this->fornecedor = Terceiro::create(['nome' => 'Fornecedor Simulação', 'nif' => '5000000902', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '3211']);
            $this->produto = Produto::create(['codigo' => 'P1', 'nome' => 'Produto 1', 'preco_unitario' => 1000, 'taxa_imposto' => 14,
                'codigo_conta' => '611', 'conta_iva_liquidado' => '3452', 'movimenta_stock' => false]);
            $this->servico = Produto::create(['codigo' => 'S1', 'nome' => 'Transporte', 'taxa_imposto' => 14, 'movimenta_stock' => false,
                'conta_custo' => '752', 'conta_iva_dedutivel' => '3451']);
        });
    }

    private function emEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa, $f);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa];
    }

    private function linhasGravadas(?string $numeroLan): array
    {
        return $this->emEmpresa(fn () => LancamentoContabil::query()->where('numero_lan', $numeroLan)->orderBy('id')->get()
            ->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->all());
    }

    private static function linhasPrevistas(array $dados): array
    {
        return array_map(fn ($l) => "{$l['tipo_dc']} {$l['codigo_conta']} {$l['valor']}", $dados['linhas']);
    }

    #[Test]
    public function venda_mostra_as_linhas_que_a_contabilizacao_grava_sem_gravar_nada(): void
    {
        $s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fat_contabilizar']);
        $ft = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $this->cliente->id, 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 1]]], $s)->assertCreated()->json('dados.id');
        $antes = $this->emEmpresa(fn () => LancamentoContabil::query()->count());

        $p = $this->getJson("/api/vendas/documentos/{$ft}/contabilizacao/pre-visualizacao", $s)->assertOk()
            ->assertJsonPath('dados.diario', 'FC')->assertJsonPath('dados.equilibrado', true)
            ->assertJsonPath('dados.total_debito', '1140.00')->assertJsonPath('dados.total_credito', '1140.00')
            ->assertJsonPath('dados.linhas.0.nome_conta', 'Clientes')->assertJsonPath('dados.linhas.0.terceiro', 'Cliente Simulação')
            ->json('dados');
        $this->assertSame(['D 311 1140.00', 'C 611 1000.00', 'C 3452 140.00'], self::linhasPrevistas($p));
        // nada gravado: nem lançamento, nem estado do documento
        $this->assertSame($antes, $this->emEmpresa(fn () => LancamentoContabil::query()->count()));
        $this->getJson("/api/vendas/documentos/{$ft}", $s)->assertJsonPath('dados.contabilizado', false);

        $lan = $this->postJson("/api/vendas/documentos/{$ft}/contabilizar", [], $s)->assertOk()->json('dados.numero_lan_contabilizacao');
        $this->assertSame(self::linhasPrevistas($p), $this->linhasGravadas($lan));
        // já contabilizado: a pré-visualização recusa como a contabilização
        $this->getJson("/api/vendas/documentos/{$ft}/contabilizacao/pre-visualizacao", $s)->assertStatus(422)->assertJsonPath('codigo', 'JA_CONTABILIZADO');
    }

    #[Test]
    public function venda_nao_contabilizavel_e_sem_permissao_sao_recusadas(): void
    {
        $s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fat_contabilizar']);
        $or = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'OR', 'cliente_id' => $this->cliente->id, 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 1]]], $s)->assertCreated()->json('dados.id');
        $this->getJson("/api/vendas/documentos/{$or}/contabilizacao/pre-visualizacao", $s)->assertStatus(422)->assertJsonPath('codigo', 'NAO_CONTABILIZAVEL');
        $this->getJson("/api/vendas/documentos/{$or}/contabilizacao/pre-visualizacao", $this->sessao(['vendas_faturacao_view']))->assertForbidden();
    }

    #[Test]
    public function factura_de_fornecedor_mostra_as_linhas_que_a_contabilizacao_grava(): void
    {
        $s = $this->sessao(['compras_faturacao_view', 'compras_fact_registar', 'compras_fact_contabilizar']);
        $p = $this->emEmpresa(fn () => Projeto::create(['codigo' => 'PRJ-SIM', 'nome' => 'Obra fictícia'])->id);
        $f = $this->postJson('/api/compras/faturas', ['fornecedor_id' => $this->fornecedor->id, 'numero_fatura' => 'F-SIM-1', 'data' => $this->hoje, 'projeto_id' => $p,
            'linhas' => [['produto_id' => $this->servico->id, 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_imposto' => 14]]], $s)->assertCreated()->json('dados.id');

        $prev = $this->getJson("/api/compras/faturas/{$f}/contabilizacao/pre-visualizacao", $s)->assertOk()
            ->assertJsonPath('dados.diario', 'FF')->assertJsonPath('dados.equilibrado', true)->assertJsonPath('dados.linhas.0.projeto', 'PRJ-SIM')
            ->json('dados');
        $this->assertSame(['D 752 1000.00', 'D 3451 140.00', 'C 3211 1140.00'], self::linhasPrevistas($prev));
        $this->assertSame(0, $this->emEmpresa(fn () => LancamentoContabil::query()->count()));

        $lan = $this->postJson("/api/compras/faturas/{$f}/contabilizar", [], $s)->assertOk()->json('dados.numero_lan_contabilizacao');
        $this->assertSame(self::linhasPrevistas($prev), $this->linhasGravadas($lan));
        $this->getJson("/api/compras/faturas/{$f}/contabilizacao/pre-visualizacao", $this->sessao(['compras_faturacao_view']))->assertForbidden();
    }
}
