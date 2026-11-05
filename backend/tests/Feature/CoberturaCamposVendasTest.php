<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Services\Vendas\ServicoConfigVendas;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Auditoria de cobertura de campos (docs/paridade/COBERTURA_CAMPOS_2026-11.md), lote Vendas:
 * vendas.data_entrega e vendas.local_entrega entram na emissão, na resposta e herdam-se na conversão. Dados fictícios.
 */
final class CoberturaCamposVendasTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000088']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['311' => 'Clientes', '3112' => 'Clientes nacionais', '3452' => 'IVA liquidado', '611' => 'Vendas'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            app(ServicoConfigVendas::class)->definir(['clientes_default' => '311']);
            $this->ids['produto'] = Produto::create(['codigo' => 'P1', 'nome' => 'Serviço', 'preco_unitario' => 100, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => false, 'e_servico' => true])->id;
            Armazem::create(['nome' => 'Loja', 'predefinido' => true]);
            $this->ids['cliente'] = Terceiro::create(['nome' => 'Cliente Alfa', 'nif' => '5000000011', 'tipo' => Terceiro::CLIENTE, 'conta_cliente' => '311'])->id;
        });
    }

    private function sessao(): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'vendas_faturacao_view' => true, 'vendas_fat_emitir' => true])->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function documento(string $tipo, array $extra = []): array
    {
        return ['tipo_documento' => $tipo, 'cliente_id' => $this->ids['cliente'], 'data_emissao' => now()->toDateString(),
            'linhas' => [['produto_id' => $this->ids['produto'], 'quantidade' => 1]]] + $extra;
    }

    #[Test]
    public function entrega_prevista_grava_devolve_e_acompanha_a_conversao(): void
    {
        $s = $this->sessao();
        $amanha = now()->addDay()->toDateString();
        $or = $this->postJson('/api/vendas/documentos', $this->documento('OR', ['data_entrega' => $amanha, 'local_entrega' => 'Armazém do cliente, Viana']), $s)
            ->assertCreated()->assertJsonPath('dados.data_entrega', $amanha)->assertJsonPath('dados.local_entrega', 'Armazém do cliente, Viana');
        $id = $or->json('dados.id');
        $this->getJson("/api/vendas/documentos/{$id}", $s)->assertOk()->assertJsonPath('dados.data_entrega', $amanha)->assertJsonPath('dados.local_entrega', 'Armazém do cliente, Viana');

        $ft = $this->postJson("/api/vendas/documentos/{$id}/converter", ['tipo_destino' => 'FT'], $s)->assertCreated()->json('dados.id');
        $this->getJson("/api/vendas/documentos/{$ft}", $s)->assertJsonPath('dados.data_entrega', $amanha)->assertJsonPath('dados.local_entrega', 'Armazém do cliente, Viana');
    }

    #[Test]
    public function entrega_e_validada_e_a_nota_de_credito_nao_a_guarda(): void
    {
        $s = $this->sessao();
        $this->postJson('/api/vendas/documentos', $this->documento('FT', ['data_entrega' => '31-12-2026']), $s)->assertUnprocessable();
        $this->postJson('/api/vendas/documentos', $this->documento('FT', ['local_entrega' => str_repeat('x', 501)]), $s)->assertUnprocessable();

        $ft = $this->postJson('/api/vendas/documentos', $this->documento('FT'), $s)->assertCreated()->assertJsonPath('dados.data_entrega', null)->json('dados.id');
        $this->postJson('/api/vendas/documentos', $this->documento('NC', ['venda_origem_id' => $ft, 'motivo_nota_credito' => 'Devolução', 'local_entrega' => 'Rua X']), $s)
            ->assertCreated()->assertJsonPath('dados.local_entrega', null);
    }
}
