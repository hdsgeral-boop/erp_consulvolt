<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Ficheiro de Inventário/Existências para a AGT (Circular 05/GACA/GJ/DSF/AGT/2026): stock a 31/12 do exercício, em CSV. */
final class LogisticaExportacaoInventarioTest extends TestCase
{
    private const NIF = '5417000098';

    private Empresa $empresa;

    private array $cabecalhos;

    private int $produtoId;

    private int $armazemId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => self::NIF, 'nome' => 'Empresa Inventário, Lda']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            PlanoConta::create(['codigo' => '2611', 'descricao' => 'Mercadorias', 'tipo' => 'M']);
            $this->produtoId = Produto::create(['codigo' => 'P1', 'nome' => 'Parafuso', 'preco_unitario' => 200, 'movimenta_stock' => true,
                'conta_inventario' => '2611', 'unidade_fe' => 'UN'])->id;
        });
        $this->cabecalhos = $this->sessao(['armazem_armazens_view', 'armazem_stock_view', 'armazem_config', 'armazem_ajuste']);
        $this->armazemId = $this->postJson('/api/logistica/armazens', ['nome' => 'Central'], $this->cabecalhos)->assertCreated()->json('dados.id');
        config(['erp.agt.software' => ['productId' => 'ERP Consulvolt', 'productVersion' => '2.0', 'softwareValidationNumber' => '123/AGT/2026', 'productCompanyTaxId' => '5000999999']]);
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function ajuste(string $sentido, string $quantidade, string $custo, string $data): void
    {
        $this->postJson('/api/logistica/ajustes', ['produto_id' => $this->produtoId, 'armazem_id' => $this->armazemId, 'sentido' => $sentido,
            'quantidade' => $quantidade, 'custo_unitario' => $custo, 'data' => $data, 'motivo' => 'Stock inicial'], $this->cabecalhos)->assertCreated();
    }

    #[Test]
    public function gera_o_csv_com_a_quantidade_e_o_valor_a_31_12_do_exercicio(): void
    {
        $this->ajuste('E', '10', '100', '2026-03-10');
        $this->ajuste('E', '10', '130', '2026-06-01');   // custo médio passa a 115
        $this->ajuste('S', '4', '0', '2026-09-01');       // sai ao custo médio corrente (115): fica 16 a 115
        $this->ajuste('E', '5', '200', '2027-01-15');     // fora do exercício de 2026: não deve entrar

        $this->getJson('/api/logistica/inventario-agt/validar?ano=2026', $this->cabecalhos)->assertOk()
            ->assertJsonPath('dados.produtos', 1)->assertJsonPath('dados.avisos', []);

        $r = $this->get('/api/logistica/inventario-agt?ano=2026', $this->cabecalhos)->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('filename="INVENTARIO_AO_'.self::NIF, $r->headers->get('Content-Disposition'));
        $this->assertSame('1', $r->headers->get('X-Inventario-Produtos'));

        $csv = str_replace("\xEF\xBB\xBF", '', $r->getContent());
        $linhas = array_map(fn ($l) => str_getcsv($l, ';'), array_filter(explode("\n", $csv)));
        $this->assertSame(['nif', 'periodo_fiscal', 'data_geracao', 'software_id', 'software_certificacao'], $linhas[0]);
        $this->assertSame([self::NIF, '2026'], [$linhas[1][0], $linhas[1][1]]);
        $this->assertSame(['codigo_produto', 'descricao', 'tipo_produto', 'quantidade', 'unidade_medida', 'valor', 'metodo_custeio'], $linhas[3]);
        $this->assertSame(['P1', 'Parafuso', 'MERCADORIA', '16', 'UN', '1840.00', 'CUSTO MEDIO PONDERADO'], $linhas[4]);
    }

    #[Test]
    public function sem_produtos_de_stock_gera_ficheiro_sem_operacoes_em_vez_de_recusar(): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Produto::query()->whereKey($this->produtoId)->update(['movimenta_stock' => false]));
        $r = $this->get('/api/logistica/inventario-agt?ano=2026', $this->cabecalhos)->assertOk();
        $this->assertStringContainsString('SEM OPERACOES', $r->getContent());
    }

    #[Test]
    public function exige_permissao(): void
    {
        $semPermissao = $this->sessao(['armazem_armazens_view']);
        $this->getJson('/api/logistica/inventario-agt?ano=2026', $semPermissao)->assertForbidden();
    }
}
