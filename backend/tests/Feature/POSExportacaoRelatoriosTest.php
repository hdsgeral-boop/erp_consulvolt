<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Services\Logistica\ServicoStock;
use App\Services\Vendas\ServicoConfigVendas;
use App\Support\Tenancy\ContextoEmpresa;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F7 do POS: Excel dos relatórios (GET /api/pos/relatorios/excel): 7 folhas com os valores do painel (números, não texto),
 * filtros iguais aos de GET /api/pos/relatorios, permissões, isolamento por empresa e texto nunca gravado como fórmula.
 * Produto de 1 140 Kz com IVA 14 % (base 1 000).
 */
final class POSExportacaoRelatoriosTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000123']);
        $ids = app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['311' => 'Clientes', '3452' => 'IVA liquidado', '611' => 'Vendas', '2611' => 'Mercadorias', '7111' => 'CMV', '489' => 'Transitória numerário', '4511' => 'Caixa'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            app(ServicoConfigVendas::class)->definir(['clientes_default' => '311']);
            $p = Produto::create(['codigo' => 'P1', 'nome' => '=HYPERLINK("http://x","clique")', 'preco_unitario' => 1140, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => true, 'conta_inventario' => '2611', 'conta_custo' => '7111', 'e_servico' => false])->id;
            $a = Armazem::create(['nome' => 'Loja', 'predefinido' => true])->id;
            app(ServicoStock::class)->entrada($p, $a, '10', '600', now()->toDateString(), 'Stock inicial');

            return [$p, $a];
        });
        $this->s = $this->sessao(['pos_view', 'pos_venda', 'pos_terminais_gerir', 'pos_relatorios_view']);
        $terminal = $this->postJson('/api/pos/terminais', ['codigo' => 'T01', 'nome' => 'Loja 1', 'armazem_id' => $ids[1], 'fundo_maneio_padrao' => 0,
            'meios_pagamento' => [['id' => 'pm_num', 'tipo' => 'NUMERARIO', 'nome' => 'Numerário', 'conta_transitoria' => '489', 'conta_liquidacao' => '4511']]], $this->s)->assertCreated()->json('dados.id');
        $sessao = $this->postJson("/api/pos/terminais/{$terminal}/sessoes", [], $this->s)->assertCreated()->json('dados.id');
        $this->postJson("/api/pos/sessoes/{$sessao}/vendas", ['linhas' => [['produto_id' => $ids[0], 'quantidade' => 1]], 'pagamentos' => [['meio_id' => 'pm_num', 'valor' => 1140]]], $this->s)->assertCreated();
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function livro(string $url, ?array $s = null): Spreadsheet
    {
        $r = $this->get($url, ($s ?? $this->s) + ['Accept' => 'application/json'])->assertOk();

        return IOFactory::load($r->baseResponse->getFile()->getPathname());
    }

    #[Test]
    public function exporta_as_sete_folhas_com_numeros(): void
    {
        $r = $this->get('/api/pos/relatorios/excel', $this->s)->assertOk();
        $this->assertStringContainsString('.xlsx', $r->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $livro = IOFactory::load($r->baseResponse->getFile()->getPathname());
        $this->assertSame(['Resumo', 'Por meio', 'Fechos Z', 'Por produto', 'Por terminal', 'Por operador', 'Diferenças TPA'], $livro->getSheetNames());
        $resumo = $livro->getSheetByName('Resumo');
        $this->assertSame('Todos', $resumo->getCell('B2')->getValue());
        $this->assertSame('Facturação POS', $resumo->getCell('A4')->getValue());
        $this->assertEquals(1140.0, $resumo->getCell('B4')->getValue());
        $this->assertIsNumeric($resumo->getCell('B4')->getValue());
        $meio = $livro->getSheetByName('Por meio');
        $this->assertSame('Numerário', $meio->getCell('A2')->getValue());
        $this->assertEquals(1140.0, $meio->getCell('D2')->getValue());
        $terminal = $livro->getSheetByName('Por terminal');
        $this->assertSame('T01', $terminal->getCell('A2')->getValue());
        $this->assertEquals(1, $terminal->getCell('C2')->getValue());
    }

    #[Test]
    public function o_texto_que_parece_formula_fica_como_texto(): void
    {
        $livro = $this->livro('/api/pos/relatorios/excel');
        $celula = $livro->getSheetByName('Por produto')->getCell('B2');
        $this->assertSame(DataType::TYPE_STRING, $celula->getDataType());
        $this->assertSame('=HYPERLINK("http://x","clique")', $celula->getValue());
    }

    #[Test]
    public function os_filtros_valem_e_a_permissao_e_a_do_relatorio(): void
    {
        $futuro = now()->addYear()->format('Y-m-d');
        $r = $this->livro("/api/pos/relatorios/excel?data_inicio={$futuro}&data_fim={$futuro}");
        $this->assertEquals(0, $r->getSheetByName('Resumo')->getCell('B4')->getValue());
        $this->assertNull($r->getSheetByName('Por meio')->getCell('A2')->getValue());
        $this->get('/api/pos/relatorios/excel?data_inicio=ontem', $this->s + ['Accept' => 'application/json'])->assertStatus(422);
        $this->get('/api/pos/relatorios/excel', $this->sessao(['pos_venda']) + ['Accept' => 'application/json'])->assertStatus(403);
    }

    #[Test]
    public function nao_mistura_empresas(): void
    {
        $outra = $this->criarEmpresa(['nif' => '5417000131']);
        $livro = $this->livro('/api/pos/relatorios/excel', $this->sessao(['pos_relatorios_view'], $outra));
        $this->assertEquals(0, $livro->getSheetByName('Resumo')->getCell('B4')->getValue());
        $this->assertNull($livro->getSheetByName('Por produto')->getCell('A2')->getValue());
    }
}
