<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\LogAuditoria;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Fase A — anulação dos documentos de venda com motivo, autor e data (anulacao_documentos.js:anularVenda). Dados fictícios. */
final class FaVendasAnulacaoTest extends TestCase
{
    private Empresa $empresa;

    private Terceiro $cliente;

    private Produto $produto;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000095']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            PlanoConta::create(['codigo' => '311', 'descricao' => 'Clientes', 'tipo' => 'M']);
            $this->cliente = Terceiro::create(['nome' => 'Cliente Fictício', 'nif' => '5000000031', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311']);
            $this->produto = Produto::create(['codigo' => 'PZ', 'nome' => 'Produto Z', 'preco_unitario' => 50, 'taxa_imposto' => 14, 'movimenta_stock' => false]);
        });
        $this->s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fat_del'], 'anulador.teste');
    }

    private function sessao(array $permissoes, string $nome): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id, 'nome_utilizador' => $nome]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function proforma(): int
    {
        return $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'PF', 'cliente_id' => $this->cliente->id, 'data_emissao' => now()->toDateString(),
            'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 1]]], $this->s)->assertCreated()->json('dados.id');
    }

    #[Test]
    public function anular_exige_motivo_e_grava_autor_data_e_motivo(): void
    {
        $pf = $this->proforma();
        $this->postJson("/api/vendas/documentos/{$pf}/anular", [], $this->s)->assertStatus(422);
        $this->postJson("/api/vendas/documentos/{$pf}/anular", ['motivo' => 'abcd'], $this->s)->assertStatus(422);
        $this->getJson("/api/vendas/documentos/{$pf}", $this->s)->assertJsonPath('dados.estado', 'PENDENTE')->assertJsonPath('dados.anulacao', null);

        $r = $this->postJson("/api/vendas/documentos/{$pf}/anular", ['motivo' => '  Proposta recusada pelo cliente  '], $this->s)->assertOk()
            ->assertJsonPath('dados.estado', 'ANULADO')->assertJsonPath('dados.anulacao.motivo', 'Proposta recusada pelo cliente')
            ->assertJsonPath('dados.anulacao.por', 'anulador.teste');
        $this->assertNotNull($r->json('dados.anulacao.em'));
        $this->getJson("/api/vendas/documentos/{$pf}", $this->s)->assertJsonPath('dados.anulacao.motivo', 'Proposta recusada pelo cliente');
        $this->assertTrue(app(ContextoEmpresa::class)->executarComo($this->empresa->id,
            fn () => LogAuditoria::query()->where('acao', 'Anular documento')->where('detalhes', 'like', '%Proposta recusada%')->exists()));
    }

    #[Test]
    public function sem_permissao_recusa_antes_de_validar(): void
    {
        $pf = $this->proforma();
        $this->postJson("/api/vendas/documentos/{$pf}/anular", [], $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir'], 'sem.anular'))->assertForbidden();
    }
}
