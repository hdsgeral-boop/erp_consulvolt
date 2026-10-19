<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\EncomendaCompra;
use App\Models\ItemCompra;
use App\Models\PedidoCompra;
use App\Models\Produto;
use App\Services\Logistica\ServicoNiveisStock;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ronda 4 — a avaliação dos níveis de stock em cache (ServicoNiveisStock::chaveCache) conta os pedidos e encomendas de
 * compra em curso como «em encomenda»: anular/rejeitar/mudar o estado ou as quantidades desses documentos invalida-a
 * (agora e depois do commit), pelos hooks dos modelos de Compras.
 */
final class R4NiveisStockComprasTest extends TestCase
{
    private Empresa $empresa;

    private array $h;

    private int $produto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->produto = $this->como(fn () => Produto::create(['codigo' => 'B1', 'nome' => 'Bomba', 'movimenta_stock' => true, 'stock_minimo' => 5, 'stock_maximo' => 20])->id);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'armazem_stock_view' => true, 'compras_pedidos_view' => true,
            'compras_ped_criar' => true, 'compras_ped_eliminar' => true])->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->h = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function como(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function emEncomenda(): string
    {
        return (string) $this->getJson('/api/logistica/niveis-stock', $this->h)->assertOk()->json('dados.linhas.0.em_encomenda');
    }

    private function emCache(): bool
    {
        return Cache::has(ServicoNiveisStock::chaveCache($this->empresa->id));
    }

    #[Test]
    public function anular_o_pedido_de_compra_invalida_a_cache_dos_niveis(): void
    {
        $pc = $this->postJson('/api/logistica/niveis-stock/pedido-compra', ['linhas' => [['produto_id' => $this->produto, 'quantidade' => 20]]], $this->h)
            ->assertCreated()->json('dados.id');
        $this->assertSame('20.000', $this->emEncomenda());
        $this->assertTrue($this->emCache());

        $this->postJson("/api/compras/pedidos/{$pc}/anular", ['motivo' => 'Já não é preciso'], $this->h)->assertOk();
        $this->assertFalse($this->emCache());
        $this->assertSame('0.000', $this->emEncomenda());
    }

    #[Test]
    public function estado_e_quantidades_dos_pedidos_e_encomendas_invalidam_depois_do_commit(): void
    {
        [$pedido, $linha] = $this->como(function () {
            $p = PedidoCompra::create(['estado' => 'PENDENTE', 'data' => now()->toDateString(), 'descricao' => 'Teste']);
            $l = ItemCompra::create(['tipo_documento_origem' => 'PEDIDO', 'pedido_compra_id' => $p->id, 'produto_id' => $this->produto, 'quantidade' => 8]);

            return [$p->id, $l->id];
        });
        $this->assertSame('8.000', $this->emEncomenda());

        // quantidade da linha alterada dentro de uma transacção: invalidada também depois do commit (um pedido concorrente
        // que voltasse a pôr os dados antigos em cache durante a transacção não ficaria até ao TTL)
        $this->como(fn () => DB::transaction(function () use ($linha) {
            ItemCompra::query()->findOrFail($linha)->update(['quantidade' => 12]);
            Cache::put(ServicoNiveisStock::chaveCache($this->empresa->id), 'antigo', 600);
        }));
        $this->assertFalse($this->emCache());
        $this->assertSame('12.000', $this->emEncomenda());

        // rejeitado: deixa de contar
        $this->como(fn () => PedidoCompra::query()->findOrFail($pedido)->update(['estado' => 'REJEITADO']));
        $this->assertFalse($this->emCache());
        $this->assertSame('0.000', $this->emEncomenda());

        // encomenda em curso: conta o que falta receber; anulada deixa de contar
        $encomenda = $this->como(function () {
            $e = EncomendaCompra::create(['estado' => 'EM_PROCESSAMENTO', 'data' => now()->toDateString()]);
            ItemCompra::create(['tipo_documento_origem' => 'ENCOMENDA', 'encomenda_compra_id' => $e->id, 'produto_id' => $this->produto, 'quantidade' => 10, 'quantidade_recebida' => 3]);

            return $e->id;
        });
        $this->assertSame('7.000', $this->emEncomenda());
        $this->como(fn () => EncomendaCompra::query()->findOrFail($encomenda)->update(['estado' => 'ANULADA', 'anulado_em' => now()]));
        $this->assertFalse($this->emCache());
        $this->assertSame('0.000', $this->emEncomenda());

        // alterações que não mexem na posição de stock não invalidam
        $this->emEncomenda();
        $this->como(fn () => PedidoCompra::query()->findOrFail($pedido)->update(['observacoes' => 'nota']));
        $this->assertTrue($this->emCache());
    }
}
