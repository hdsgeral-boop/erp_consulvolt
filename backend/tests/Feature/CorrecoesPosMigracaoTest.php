<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Produto;
use App\Services\Logistica\ServicoMigracaoStock;
use App\Services\Migracao\ServicoCorrecoesPosMigracao;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Correcções de dados herdados do legado (ADR-070): armazém de outra empresa e NC que abate a factura. */
final class CorrecoesPosMigracaoTest extends TestCase
{
    private Empresa $dona;

    private Empresa $outra;

    private int $armazemOutra;

    private int $armazemDona;

    private int $produto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dona = $this->criarEmpresa();
        $this->outra = $this->criarEmpresa();
        $this->armazemOutra = DB::table('armazens')->insertGetId(['empresa_id' => $this->outra->id, 'nome' => 'Armazém 1 da outra']);
        $this->armazemDona = DB::table('armazens')->insertGetId(['empresa_id' => $this->dona->id, 'nome' => 'Principal']);
        $this->produto = app(ContextoEmpresa::class)->executarComo($this->dona->id,
            fn () => Produto::create(['codigo' => 'P1', 'nome' => 'Artigo', 'preco_unitario' => 100, 'movimenta_stock' => true])->id);
        // saldo do legado no armazém da dona (10) já acertado com o histórico sem as saídas do POS
        DB::table('stock_armazem')->insert(['empresa_id' => $this->dona->id, 'armazem_id' => $this->armazemDona, 'produto_id' => $this->produto, 'quantidade_stock' => 10]);
        $this->movimento($this->armazemDona, 'E', 10, ServicoMigracaoStock::REFERENCIA);
    }

    private function movimento(int $armazem, string $sentido, int $q, string $ref, ?int $empresa = null, ?int $produto = null): int
    {
        return DB::table('movimentos_inventario')->insertGetId(['empresa_id' => $empresa ?? $this->dona->id, 'produto_id' => $produto ?? $this->produto,
            'armazem_id' => $armazem, 'tipo' => $sentido === 'S' ? 'SAIDA' : 'ENTRADA', 'sentido' => $sentido, 'quantidade' => $q, 'valor' => 0,
            'data' => '2026-08-21', 'referencia' => $ref]);
    }

    private function saldoMovimentos(int $armazem): string
    {
        return (string) DB::table('movimentos_inventario')->where('armazem_id', $armazem)->where('produto_id', $this->produto)
            ->selectRaw("COALESCE(SUM(CASE WHEN sentido = 'S' THEN -quantidade ELSE quantidade END), 0) AS q")->value('q');
    }

    #[Test]
    public function movimento_em_armazem_de_outra_empresa_passa_para_a_dona_e_volta_a_bater_com_o_saldo(): void
    {
        $id = $this->movimento($this->armazemOutra, 'S', 3, 'Venda POS: FR-POS/2026/0001');

        $this->artisan('erp:migracao:corrigir-dados', ['--simular' => true])->assertSuccessful();
        $this->assertSame($this->armazemOutra, (int) DB::table('movimentos_inventario')->where('id', $id)->value('armazem_id'), 'a simulação não grava');

        $this->artisan('erp:migracao:corrigir-dados')->assertSuccessful();
        $m = DB::table('movimentos_inventario')->where('id', $id)->first();
        $this->assertSame([$this->dona->id, $this->armazemDona], [(int) $m->empresa_id, (int) $m->armazem_id]);
        // o histórico volta a bater com o saldo por armazém do legado (acerto de +3)
        $this->assertSame('10.000', $this->saldoMovimentos($this->armazemDona));
        $this->assertSame('10.000', (string) DB::table('stock_armazem')->where('armazem_id', $this->armazemDona)->value('quantidade_stock'));
        // notificação gravada na empresa dona e visível nas validações
        $log = DB::table('logs_auditoria')->where('acao', ServicoCorrecoesPosMigracao::ACCAO)->where('tabela', 'movimentos_inventario')->where('registo_id', (string) $id)->first();
        $this->assertSame($this->dona->id, (int) $log->empresa_id);
        $this->assertStringContainsString('Armazém 1 da outra', $log->detalhes);

        // idempotente
        $r = app(ServicoCorrecoesPosMigracao::class)->movimentosEmArmazemDeOutraEmpresa();
        $this->assertSame(['reatribuidos' => 0, 'eliminados' => 0, 'acertos' => 0], $r);
    }

    #[Test]
    public function movimento_de_empresa_que_ja_nao_existe_e_eliminado_com_registo(): void
    {
        $extinta = $this->criarEmpresa();
        $p = app(ContextoEmpresa::class)->executarComo($extinta->id, fn () => Produto::create(['codigo' => 'X', 'nome' => 'X', 'preco_unitario' => 1, 'movimenta_stock' => true])->id);
        $id = $this->movimento($this->armazemOutra, 'S', 1, 'Venda POS: X', $extinta->id, $p);
        DB::table('empresas')->where('id', $extinta->id)->update(['eliminado_em' => now()]);

        $r = app(ServicoCorrecoesPosMigracao::class)->movimentosEmArmazemDeOutraEmpresa();

        $this->assertSame(1, $r['eliminados']);
        $this->assertFalse(DB::table('movimentos_inventario')->where('id', $id)->exists());
        $this->assertSame($this->outra->id, (int) DB::table('logs_auditoria')->where('acao', ServicoCorrecoesPosMigracao::ACCAO)->where('registo_id', (string) $id)->value('empresa_id'));
    }

    #[Test]
    public function nota_de_credito_sobre_a_factura_abate_a_factura(): void
    {
        $doc = fn (string $tipo, string $num, ?string $estado) => DB::table('vendas')->insertGetId(['empresa_id' => $this->dona->id, 'tipo_documento' => $tipo,
            'numero_documento' => $num, 'data_emissao' => '2026-08-21 10:00:00', 'total_liquido' => 1000, 'total_imposto' => 0, 'total_bruto' => 1000,
            'valor_pago' => 0, 'valor_pendente' => 1000, 'estado' => $estado]);
        $fa = $doc('FT', 'FA 2026/1', 'PENDENTE');
        $nc = $doc('NC', 'NO 2026/1', null);
        DB::table('vendas_documentos_relacionados')->insert(['empresa_id' => $this->dona->id, 'venda_id' => $nc, 'venda_relacionada_id' => $fa]);

        $r = app(ServicoCorrecoesPosMigracao::class)->abaterNotasCredito();

        $this->assertSame(['facturas' => 1, 'notas' => 1], $r);
        $f = DB::table('vendas')->where('id', $fa)->first();
        $this->assertSame(['PAGO', '0.00'], [$f->estado, (string) $f->valor_pendente]);
        $this->assertSame('0.00', (string) DB::table('vendas')->where('id', $nc)->value('valor_pendente'));
        $this->assertSame(2, DB::table('logs_auditoria')->where('acao', ServicoCorrecoesPosMigracao::ACCAO)->where('empresa_id', $this->dona->id)->count());
        $this->assertSame(['facturas' => 0, 'notas' => 0], app(ServicoCorrecoesPosMigracao::class)->abaterNotasCredito(), 'idempotente');
    }
}
