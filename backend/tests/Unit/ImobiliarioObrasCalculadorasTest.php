<?php

namespace Tests\Unit;

use App\Services\Imobiliario\CalculadoraObras;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Gestão Imobiliária, Onda 4 (F5): fórmulas puras das obras, do apetrechamento e dos planos, com números calculados à mão (as fixtures do
 * legado onde o legado está certo; as correcções assinaladas): comparação ficha × inventário, repartição armazém/compras, quantidade
 * recebida, datas propostas por periodicidade a partir do dia original, calendário e horizonte de geração.
 */
final class ImobiliarioObrasCalculadorasTest extends TestCase
{
    #[Test]
    public function a_chave_compara_descricoes_sem_acentos_nem_maiusculas(): void
    {
        $this->assertSame('cadeira de escritorio', CalculadoraObras::chave('  Cadeira de Escritório '));
        $this->assertSame('', CalculadoraObras::chave(null));
        $this->assertSame('3.500', CalculadoraObras::q('3.5'));
        $this->assertSame('0.000', CalculadoraObras::q('abc'));
    }

    #[Test]
    public function comparar_a_ficha_com_o_inventario_conta_so_o_que_esta_em_condicoes(): void
    {
        $itens = [
            ['produto_id' => 1, 'descricao' => null, 'quantidade' => 2, 'obrigatorio' => true, 'nome' => 'Cama'],
            ['produto_id' => 2, 'descricao' => null, 'quantidade' => 1, 'obrigatorio' => true, 'nome' => 'Colchão'],
            ['produto_id' => null, 'descricao' => 'Mesa', 'quantidade' => 1, 'obrigatorio' => false, 'nome' => 'Mesa'],
            ['produto_id' => null, 'descricao' => 'Sofá', 'quantidade' => 1, 'obrigatorio' => true, 'nome' => 'Sofá'],
        ];
        $bens = [
            ['produto_id' => 1, 'descricao' => null, 'quantidade' => 1, 'estado' => 'BOM'],
            ['produto_id' => 1, 'descricao' => null, 'quantidade' => 1, 'estado' => 'DANIFICADO'],
            ['produto_id' => 2, 'descricao' => null, 'quantidade' => 1, 'estado' => 'EM_FALTA'],
            ['produto_id' => null, 'descricao' => ' MESA ', 'quantidade' => 1, 'estado' => 'USADO'],
            ['produto_id' => 9, 'descricao' => null, 'quantidade' => 1, 'estado' => 'NOVO'],   // um bem com artigo e sem descrição não casa com linha só de descrição (legado)
        ];
        $l = CalculadoraObras::comparar($itens, $bens);
        $this->assertSame(['2.000', '1.000', '1.000', 1], [$l[0]['precisa'], $l[0]['tem'], $l[0]['falta'], $l[0]['danificados']]);   // 2 − 1 bom; o danificado não conta
        $this->assertSame(['1.000', '0.000', '1.000', 1], [$l[1]['precisa'], $l[1]['tem'], $l[1]['falta'], $l[1]['danificados']]);
        $this->assertSame(['0.000', 0], [$l[2]['falta'], $l[2]['danificados']]);   // «MESA» = «Mesa»
        $this->assertSame('1.000', $l[3]['falta']);
        $this->assertFalse($l[2]['obrigatorio']);
        // correcção: a quantidade é decimal (o legado arredondava 0,5 a 1 e a falta desaparecia)
        $m = CalculadoraObras::comparar([['produto_id' => 5, 'descricao' => null, 'quantidade' => 1]], [['produto_id' => 5, 'descricao' => null, 'quantidade' => 0.5, 'estado' => 'BOM']]);
        $this->assertSame(['0.500', '0.500'], [$m[0]['tem'], $m[0]['falta']]);
    }

    #[Test]
    public function repartir_manda_ao_armazem_o_que_ha_e_a_compras_o_que_falta(): void
    {
        $a = CalculadoraObras::repartir('5.000', true, true, '3.000');
        $this->assertSame(['3.000', '2.000', 'O stock só dá para 3 — a falta vai a compras', false], [$a['para_armazem'], $a['para_compra'], $a['porque'], $a['bloqueada']]);
        $b = CalculadoraObras::repartir('5.000', true, true, '10.000');
        $this->assertSame(['5.000', '0.000', 'Há stock que chega'], [$b['para_armazem'], $b['para_compra'], $b['porque']]);
        $c = CalculadoraObras::repartir('5.000', true, true, '0.000');
        $this->assertSame(['0.000', '5.000', 'Material sem stock'], [$c['para_armazem'], $c['para_compra'], $c['porque']]);
        $d = CalculadoraObras::repartir('2.500', true, true, '1.250');
        $this->assertSame(['1.250', '1.250'], [$d['para_armazem'], $d['para_compra']]);
        $e = CalculadoraObras::repartir('4.000', true, false, null);   // serviço ou artigo que não é de armazém
        $this->assertSame(['0.000', '4.000', 'Serviço ou artigo que não é de armazém'], [$e['para_armazem'], $e['para_compra'], $e['porque']]);
        $f = CalculadoraObras::repartir('4.000', false, false, null);   // sem artigo: o pedido de compra recusa-o
        $this->assertTrue($f['bloqueada']);
        $this->assertSame('0.000', $f['para_compra']);
        $g = CalculadoraObras::repartir('4.000', true, true, '-2.000');   // stock negativo não conta
        $this->assertSame(['0.000', '4.000'], [$g['para_armazem'], $g['para_compra']]);
    }

    #[Test]
    public function a_quantidade_recebida_soma_as_duas_origens_sem_passar_do_pedido(): void
    {
        $this->assertSame('4.000', CalculadoraObras::recebida('5.000', '3.000', '3.000', '2.000', '1.000'));   // armazém 3 + compras 1
        $this->assertSame('5.000', CalculadoraObras::recebida('5.000', '3.000', '3.000', '2.000', '7.000'));   // compras recebeu a mais: conta só o que pediu (2)
        $this->assertSame('3.000', CalculadoraObras::recebida('5.000', '3.000', '4.000', '0.000', '0.000'));   // entrega a mais do armazém: só conta 3
        $this->assertSame('0.000', CalculadoraObras::recebida('5.000', '0.000', '0.000', '5.000', '0.000'));
        $this->assertSame('2.500', CalculadoraObras::recebida('2.500', '0.000', '0.000', '2.500', '2.500'));
    }

    #[Test]
    public function as_datas_propostas_contam_se_sempre_a_partir_do_dia_original(): void
    {
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], CalculadoraObras::proporDatas('2026-01-31', 'MENSAL', 4));   // não encadeado: Março volta ao 31
        $this->assertSame(['2028-01-31', '2028-02-29'], CalculadoraObras::proporDatas('2028-01-31', 'MENSAL', 2));   // bissexto
        $this->assertSame(['2026-11-30', '2027-02-28', '2027-05-30'], CalculadoraObras::proporDatas('2026-11-30', 'TRIMESTRAL', 3));
        $this->assertSame(['2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29'], CalculadoraObras::proporDatas('2028-02-29', 'ANUAL', 5));
        $this->assertSame(['2026-05-15', '2028-05-15'], CalculadoraObras::proporDatas('2026-05-15', 'BIANUAL', 2));
        $this->assertSame(['2026-01-15', '2026-03-15', '2026-05-15'], CalculadoraObras::proporDatas('2026-01-15', 'BIMESTRAL', 3));
        $this->assertSame(['2026-01-15', '2026-07-15'], CalculadoraObras::proporDatas('2026-01-15', 'SEMESTRAL', 2));
        $this->assertSame(['2026-04-10'], CalculadoraObras::proporDatas('2026-04-10', 'MANUAL', 5));
        $this->assertSame(['2026-04-10'], CalculadoraObras::proporDatas('2026-04-10', 'QUINZENAL', 5));   // desconhecida: só a primeira
        $this->assertSame(['2026-04-10'], CalculadoraObras::proporDatas('2026-04-10', 'ANUAL', 0));   // pelo menos uma
        $this->assertCount(60, CalculadoraObras::proporDatas('2026-01-01', 'MENSAL', 99));   // no máximo 60
        $this->assertSame([], CalculadoraObras::proporDatas('2026-02-30', 'MENSAL', 3));   // data que não existe
        $this->assertSame([], CalculadoraObras::proporDatas('31/01/2026', 'MENSAL', 3));
        $this->assertSame([], CalculadoraObras::proporDatas(null, 'MENSAL', 3));
    }

    #[Test]
    public function as_datas_limpam_se_ordenam_e_sem_repeticoes(): void
    {
        $this->assertSame(['2026-01-10', '2026-03-01'], CalculadoraObras::limparDatas(['2026-03-01', '2026-02-30', '2026-01-10', '2026-03-01', 'x', '']));
        $this->assertSame([], CalculadoraObras::limparDatas([]));
    }

    #[Test]
    public function o_calendario_marca_cada_data_com_o_estado_da_ordem_ou_em_falta_ou_por_gerar(): void
    {
        $datas = ['2026-01-10', '2026-03-20', '2026-06-01', '2026-09-01'];
        $ordens = ['2026-01-10' => ['id' => 7, 'numero' => 'OB 2026/0007', 'estado' => 'CONCLUIDA'], '2026-06-01' => ['id' => 9, 'numero' => 'OB 2026/0009', 'estado' => 'ABERTA']];
        $e = CalculadoraObras::estadoDasDatas($datas, $ordens, '2026-03-20');
        $this->assertSame(['CONCLUIDA', 'POR_GERAR', 'ABERTA', 'POR_GERAR'], array_column($e['linhas'], 'estado'));   // hoje ainda não é «passada»
        $this->assertSame([2, 2, 0, 1, '2026-03-20'], [$e['geradas'], $e['por_gerar'], $e['em_falta'], $e['concluidas'], $e['proxima']]);
        $this->assertSame([7, null, 9, null], array_column($e['linhas'], 'ordem_id'));
        $v = CalculadoraObras::estadoDasDatas($datas, [], '2026-03-20');
        $this->assertSame(['EM_FALTA', 'POR_GERAR', 'POR_GERAR', 'POR_GERAR'], array_column($v['linhas'], 'estado'));
        $this->assertSame([0, 3, 1], [$v['geradas'], $v['por_gerar'], $v['em_falta']]);
        $this->assertSame('2026-03-20', $v['proxima']);
        $this->assertNull(CalculadoraObras::estadoDasDatas(['2026-01-10'], [], '2026-03-20')['proxima']);
    }

    #[Test]
    public function o_horizonte_da_geracao_e_hoje_mais_a_antecedencia_e_zero_e_valido(): void
    {
        $this->assertSame('2026-04-19', CalculadoraObras::horizonte('2026-03-20', 30));
        $this->assertSame('2026-03-20', CalculadoraObras::horizonte('2026-03-20', 0));   // o legado tratava 0 como 30
        $this->assertSame('2026-03-20', CalculadoraObras::horizonte('2026-03-20', -5));
        $this->assertSame('2027-03-20', CalculadoraObras::horizonte('2026-03-20', 365));
    }
}
