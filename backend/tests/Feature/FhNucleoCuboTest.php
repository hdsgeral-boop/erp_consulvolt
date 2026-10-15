<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Services\Gestao\Paineis\Cubo\CatalogoCubo;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase H (Versão Actual): dimensões novas da Análise Dinâmica (NIF visível a quem vê o cubo — ADR-071 —, conta do papel,
 * liquidação, artigo, taxa, UN/CC da linha, meio de pagamento, colaborador…), medidas de contagem e o cubo novo
 * «Recursos Humanos — Rubricas (infotipos)». Cenário fictício de Outubro de 2026.
 */
final class FhNucleoCuboTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nome' => 'Cubo Fictício, Lda', 'nif' => '5000888111']);
        $this->cenario($this->empresa->id);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'all' => true])->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->s = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function cenario(int $e): void
    {
        $id = fn (string $t, array $d) => DB::table($t)->insertGetId($d + ['empresa_id' => $e]);
        $cliente = $id('terceiros', ['nome' => 'Cliente Fictício', 'nif' => '5401112223', 'tipo' => 'CLIENTE', 'conta_cliente' => '31121']);
        $id('enderecos_terceiros', ['nif' => '5401112223', 'provincia' => 'Benguela']);
        $forn = $id('terceiros', ['nome' => 'Fornecedor Fictício', 'nif' => '5409998887', 'tipo' => 'FORNECEDOR', 'conta_fornecedor' => '32111']);
        $cat = $id('categorias_produtos', ['nome' => 'Bebidas']);
        $prod = $id('produtos', ['nome' => 'Sumo', 'codigo' => 'SUM01', 'categoria_produto_id' => $cat, 'movimenta_stock' => true, 'unidade_fe' => 'UN']);
        $serv = $id('produtos', ['nome' => 'Montagem', 'codigo' => 'SRV01', 'movimenta_stock' => false]);
        $unCab = $id('unidades_negocio', ['codigo' => 'UN-SEDE', 'nome' => 'Sede']);
        $unLinha = $id('unidades_negocio', ['codigo' => 'UN-LOJA', 'nome' => 'Loja']);

        // FT com 2 linhas (uma com UN própria), parcialmente paga
        $v = $id('vendas', ['tipo_documento' => 'FT', 'numero_documento' => 'FT A2026/1', 'data_emissao' => '2026-10-05 10:00:00', 'cliente_id' => $cliente,
            'total_liquido' => 300, 'total_imposto' => 42, 'total_bruto' => 342, 'valor_pago' => 100, 'estado' => 'PARCIAL', 'contabilizado' => true, 'unidade_negocio_id' => $unCab]);
        $id('itens_venda', ['venda_id' => $v, 'produto_id' => $prod, 'quantidade' => 2, 'preco_unitario' => 100, 'taxa_imposto' => 14, 'total_linha' => 200, 'total' => 228]);
        $id('itens_venda', ['venda_id' => $v, 'produto_id' => $serv, 'quantidade' => 1, 'preco_unitario' => 100, 'taxa_imposto' => 14, 'total_linha' => 100, 'total' => 114, 'unidade_negocio_id' => $unLinha]);

        $fc = $id('faturas_compra', ['numero_fatura' => 'FF 9', 'data' => '2026-10-06', 'fornecedor_id' => $forn, 'montante_total' => 228, 'total_imposto' => 28, 'contabilizado' => false]);
        $id('itens_compra', ['fatura_compra_id' => $fc, 'tipo_documento_origem' => 'FATURA', 'produto_id' => $prod, 'quantidade' => 4, 'preco_unitario' => 50, 'taxa_imposto' => 14, 'total_kz' => 200, 'imposto_kz' => 28]);

        // RH: período validado com rubricas gravadas no resultado
        $fn = $id('cargos_funcoes', ['nome' => 'Técnico']);
        $org = $id('tipos_organizacao_rh', ['nome' => 'Direcção']);
        $col = $id('colaboradores', ['nome_completo' => 'Colaborador Fictício', 'nif' => '000111222LA033', 'cargo_funcao_id' => $fn, 'tipo_organizacao_id' => $org, 'estado' => 'ACTIVO', 'sexo' => 'F']);
        $inf = $id('infotipos_salariais', ['nome' => 'Vencimento base', 'tipo' => 'VENCIMENTO', 'sujeito_inss' => true, 'irt' => true]);
        $pp = $id('periodos_processamento_salarial', ['mes_ano' => '10/2026', 'estado' => 'VALIDADO']);
        $id('resultados_folha_salarial', ['periodo_processamento_salarial_id' => $pp, 'colaborador_id' => $col, 'bruto' => 110000, 'liquido' => 95000, 'base_inss' => 100000,
            'dias_contrato' => 22, 'dias_trabalhados' => 21, 'rubricas' => json_encode([
                ['nome' => 'Vencimento base', 'infotipo_id' => $inf, 'tipo' => 'VENCIMENTO', 'valor' => 100000],
                ['nome' => 'H. Extras', 'tipo' => 'VENCIMENTO', 'valor' => 12000, 'horas' => 6, 'automatica' => true],
                ['nome' => 'Subsídio de alimentação', 'tipo' => 'VENCIMENTO', 'valor' => 3000],
                ['nome' => 'Desc. Falta', 'tipo' => 'DESCONTO', 'valor' => 5000, 'falta' => true, 'horas' => 8, 'origem' => 'PRO_RATA'],
                ['nome' => 'Mal gravada', 'tipo' => 'DESCONTO', 'valor' => 'abc'],
            ])]);
        // período aberto: não entra
        $pa = $id('periodos_processamento_salarial', ['mes_ano' => '09/2026', 'estado' => 'ABERTO']);
        $id('resultados_folha_salarial', ['periodo_processamento_salarial_id' => $pa, 'colaborador_id' => $col, 'bruto' => 1, 'rubricas' => json_encode([['nome' => 'X', 'tipo' => 'VENCIMENTO', 'valor' => 1]])]);

        // Activos: 2 quotas do mesmo bem (o valor de aquisição conta uma vez)
        $at = $id('ativos_imobilizados', ['codigo' => 'AT1', 'descricao' => 'Viatura', 'tipo_ativo' => 'BEM', 'valor_aquisicao' => 1200, 'data_aquisicao' => '2025-03-01', 'fornecedor_id' => $forn]);
        foreach (['2026-10-31', '2026-09-30'] as $d) {
            $id('amortizacoes_ativos', ['ativo_imobilizado_id' => $at, 'periodo_codigo' => substr($d, 0, 7), 'contabilizado' => false, 'valor' => 100, 'data' => $d]);
        }

        // Tesouraria e armazém
        $id('meios_pagamento', ['nome' => 'Transferência BFA', 'codigo_conta' => '43.1']);
        $dt = $id('documentos_tesouraria', ['tipo' => 'RECEBIMENTO', 'data_documento' => '2026-10-07', 'conta_financeira' => '43.1', 'estado' => 'PENDENTE']);
        $id('itens_documento_tesouraria', ['documento_tesouraria_id' => $dt, 'codigo_conta' => '311', 'tipo_dc' => 'C', 'valor' => 100, 'terceiro_id' => $cliente]);
        $id('movimentos_inventario', ['produto_id' => $prod, 'data' => '2026-10-06 09:00:00', 'tipo' => 'ENTRADA', 'sentido' => 'E', 'quantidade' => 4, 'preco_unitario' => 50]);
        $id('movimentos_inventario', ['produto_id' => $prod, 'data' => '2026-10-07 09:00:00', 'tipo' => 'SAIDA', 'sentido' => 'S', 'quantidade' => 2, 'preco_unitario' => 50]);
    }

    private function consultar(string $conjunto, array $linhas, array $medidas, array $extra = []): array
    {
        return $this->postJson('/api/gestao/cubo/consultar', array_merge(['conjunto' => $conjunto, 'data_inicio' => '2026-10-01', 'data_fim' => '2026-10-31', 'linhas' => $linhas,
            'medidas' => array_map(fn ($m) => ['medida' => $m, 'agregacao' => 'soma'], $medidas)], $extra), $this->s)->assertOk()->json('dados');
    }

    private static function porChave(array $r): array
    {
        return collect($r['resultado'])->mapWithKeys(fn ($l) => [implode(' | ', $l['chave']) => $l['total']])->all();
    }

    #[Test]
    public function vendas_com_nif_provincia_conta_liquidacao_artigo_taxa_e_un_da_linha(): void
    {
        $this->assertSame(['Sim' => ['342.00']], self::porChave($this->consultar('vendas', ['contabilizado'], ['total'])));
        $r = $this->consultar('vendas', ['nif_cliente', 'provincia_cliente', 'conta_cliente', 'liquidacao'], ['total', 'documentos']);
        $this->assertSame(['5401112223 | Benguela | 31121 | Parcial' => ['342.00', '1.000']], self::porChave($r));

        $art = self::porChave($this->consultar('vendas', ['codigo_artigo', 'categoria_artigo', 'natureza_artigo', 'taxa_iva'], ['valor_sem_iva', 'documentos']));
        $this->assertSame(['200.00', '1.000'], $art['SUM01 | Bebidas | Produto | 14%']);
        $this->assertSame(['100.00', '0.000'], $art['SRV01 | (sem categoria) | Serviço | 14%']);   // o documento conta uma vez

        $un = self::porChave($this->consultar('vendas', ['unidade_negocio'], ['valor_sem_iva']));
        $this->assertSame(['UN-LOJA' => ['100.00'], 'UN-SEDE' => ['200.00']], $un);   // a UN da linha manda sobre a do cabeçalho

        // com filtro por artigo, o documento continua a contar uma vez
        $f = $this->consultar('vendas', [], ['documentos'], ['filtros' => ['codigo_artigo' => ['SRV01']]]);
        $this->assertSame(['1.000'], $f['total_geral']);
    }

    #[Test]
    public function compras_tesouraria_armazem_contabilidade_e_activos_com_as_dimensoes_novas(): void
    {
        $c = self::porChave($this->consultar('compras', ['nif_fornecedor', 'conta_fornecedor', 'natureza_artigo', 'taxa_iva'], ['total', 'faturas', 'preco_unitario']));
        $this->assertSame(['5409998887 | 32111 | Existências | 14%' => ['228.00', '1.000', '50.00']], $c);

        $t = self::porChave($this->consultar('tesouraria', ['meio_pagamento', 'classe_contrapartida', 'contabilizado', 'nif_terceiro'], ['entradas', 'saidas', 'movimentos']));
        $this->assertSame(['Transferência BFA | 3 | Não | 5401112223' => ['100.00', '0.00', '1.000']], $t);

        $a = self::porChave($this->consultar('armazem', ['codigo_artigo', 'categoria_artigo', 'unidade_medida'], ['entradas', 'saidas', 'quantidade', 'movimentos']));
        $this->assertSame(['SUM01 | Bebidas | UN' => ['4.000', '2.000', '2.000', '2.000']], $a);

        $at = $this->consultar('ativos', ['tipo_ativo', 'fornecedor', 'ano_aquisicao'], ['amortizacao', 'valor_aquisicao', 'amortizacoes'], ['data_inicio' => '2026-09-01']);
        $this->assertSame(['BEM | Fornecedor Fictício | 2025' => ['200.00', '1200.00', '2.000']], self::porChave($at));

        DB::table('lancamentos_contabeis')->insert([
            ['empresa_id' => $this->empresa->id, 'data_documento' => '2026-10-08', 'codigo_conta' => '62211', 'tipo_dc' => 'D', 'valor' => 50, 'numero_lan' => 'OD2026000001', 'descricao' => 'Água'],
            ['empresa_id' => $this->empresa->id, 'data_documento' => '2026-10-08', 'codigo_conta' => '43.1', 'tipo_dc' => 'C', 'valor' => 50, 'numero_lan' => 'OD2026000001', 'descricao' => 'Água'],
        ]);
        $l = self::porChave($this->consultar('contabilidade', ['conta3', 'numero_lan', 'origem'], ['debito', 'linhas']));
        $this->assertSame(['43. | OD2026000001 | Manual' => ['0.00', '1.000'], '622 | OD2026000001 | Manual' => ['50.00', '1.000']], $l);
    }

    #[Test]
    public function rh_e_cubo_de_rubricas_com_o_colaborador_e_o_nif(): void
    {
        $rh = self::porChave($this->consultar('rh', ['nif_colaborador', 'funcao', 'tipo_orgao', 'genero'],
            ['horas_extra', 'horas_falta', 'valor_horas_extra', 'valor_faltas', 'subsidio_alimentacao', 'colaboradores']));
        $this->assertSame(['000111222LA033 | Técnico | Direcção | Feminino' => ['6.000', '8.000', '12000.00', '5000.00', '3000.00', '1.000']], $rh);

        $v = self::porChave($this->consultar('rh', ['vinculo', 'aposentado', 'estado_periodo', 'estado_colaborador'], ['dias_trabalhados', 'base_inss']));
        $this->assertSame(['Efectivo | Não | VALIDADO | ACTIVO' => ['21.000', '100000.00']], $v);

        $conj = collect($this->getJson('/api/gestao/cubo/conjuntos', $this->s)->assertOk()->json('dados'))->keyBy('id');
        $this->assertTrue($conj->has('rh_rubricas'));
        $this->assertSame('Recursos Humanos — Rubricas (infotipos)', $conj['rh_rubricas']['nome']);

        $ru = $this->consultar('rh_rubricas', ['infotipo', 'natureza_rubrica', 'entra_inss', 'falta'], ['valor', 'valor_sinal', 'horas']);
        $p = self::porChave($ru);
        $this->assertSame(['100000.00', '100000.00', '0.000'], $p['Vencimento base | Vencimento | Sim | Não']);
        $this->assertSame(['5000.00', '-5000.00', '8.000'], $p['Desc. Falta | Desconto | Não | Sim']);
        $this->assertSame(['0.00', '0.00', '0.000'], $p['Mal gravada | Desconto | Não | Não']);   // valor mal gravado não parte a consulta
        $this->assertSame(['120000.00', '110000.00', '14.000'], $ru['total_geral']);           // o período ABERTO não entra

        $tot = $this->consultar('rh_rubricas', ['nif_colaborador'], ['vencimentos', 'descontos']);
        $this->assertSame(['000111222LA033' => ['115000.00', '5000.00']], self::porChave($tot));
    }

    #[Test]
    public function todas_as_dimensoes_e_medidas_de_todos_os_conjuntos_respondem(): void
    {
        foreach (CatalogoCubo::conjuntos() as $id => $def) {
            foreach (array_keys(array_filter($def['dimensoes'], fn ($x) => empty($x['holding']))) as $d) {
                $this->postJson('/api/gestao/cubo/consultar', ['conjunto' => $id, 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'linhas' => [$d],
                    'medidas' => [['agregacao' => 'contagem']]], $this->s)->assertOk();
            }
            foreach (array_chunk(array_keys($def['medidas']), 6) as $grupo) {
                $this->postJson('/api/gestao/cubo/consultar', ['conjunto' => $id, 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31',
                    'medidas' => array_map(fn ($m) => ['medida' => $m, 'agregacao' => 'soma'], $grupo)], $this->s)->assertOk();
            }
            $this->getJson("/api/gestao/cubo/valores?conjunto={$id}&dimensao=ano&data_inicio=2026-01-01&data_fim=2026-12-31", $this->s)->assertOk();
        }
    }

    #[Test]
    public function rubricas_exigem_acesso_ao_rh(): void
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'dashboard_view' => true, 'vendas_faturacao_view' => true])->id]);
        $u->empresas()->attach($this->empresa->id);
        $s = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
        $ids = array_column($this->getJson('/api/gestao/cubo/conjuntos', $s)->assertOk()->json('dados'), 'id');
        $this->assertSame(['vendas'], $ids);
        $this->postJson('/api/gestao/cubo/consultar', ['conjunto' => 'rh_rubricas', 'data_inicio' => '2026-10-01', 'data_fim' => '2026-10-31'], $s)->assertForbidden();
        // o NIF do cliente é visível a quem vê o cubo de vendas (decisão do ADR-071)
        $r = $this->postJson('/api/gestao/cubo/consultar', ['conjunto' => 'vendas', 'data_inicio' => '2026-10-01', 'data_fim' => '2026-10-31', 'linhas' => ['nif_cliente']], $s)->assertOk();
        $this->assertSame([['5401112223']], array_column($r->json('dados.resultado'), 'chave'));
    }
}
