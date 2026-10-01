import {
  adicionarProduto,
  alterarPreco,
  alterarQuantidade,
  avaliarFecho,
  calcularComIva,
  contagensParaApi,
  exigeDesconto,
  liquidarTroco,
  linhasParaApi,
  pagamentosParaApi,
  ratearPagamentos,
  removerLinha,
  resumirPagamentos,
  sugestoesNumerario,
  totaisCarrinho,
  totalContagem,
  totalUnidades,
  type ItemCarrinho,
  type Pagamento,
} from './calculos';
import { filtrarProdutos, lerCodigo, produtosVendaveis } from './produtos';
import type { ProdutoPOS } from './tipos';

const produto = (id: number, preco: string, extra: Partial<ProdutoPOS> = {}): ProdutoPOS => ({
  id,
  codigo: `P${id}`,
  nome: `Produto ${id}`,
  preco_unitario: preco,
  taxa_imposto: '14.0000',
  movimenta_stock: true,
  ...extra,
});

const pag = (tipo: Pagamento['tipo'], valor: number, referencia?: string): Pagamento => ({
  chave: `${tipo}${valor}`,
  meio_id: `pm_${tipo.toLowerCase()}`,
  tipo,
  nome: tipo,
  valor,
  referencia,
});

describe('cálculo com IVA (CalculadoraDocumento::calcularComIva)', () => {
  it('preço com IVA sem desconto: total = valor cobrado e base + IVA batem ao cêntimo', () => {
    const t = calcularComIva([{ quantidade: 1, preco: 4050000, taxa: 14 }]);
    expect(t.total).toBe(4050000);
    expect(t.liquido + t.imposto).toBe(t.total);
    expect(t.liquido).toBe(3552631);
    expect(t.imposto).toBe(497369);
  });

  it('desconto global em % sobre o total com IVA', () => {
    const t = calcularComIva([{ quantidade: 2, preco: 100000, taxa: 14 }], 10);
    expect(t.bruto).toBe(200000);
    expect(t.total).toBe(180000);
    expect(t.desconto).toBe(20000);
  });

  it('isento (taxa 0) e quantidades decimais', () => {
    const t = calcularComIva([{ quantidade: 1.5, preco: 333, taxa: 0 }]);
    expect(t.total).toBe(500);
    expect(t.imposto).toBe(0);
  });
});

describe('carrinho', () => {
  it('acrescenta, soma o mesmo produto, altera e retira quantidades', () => {
    let c: ItemCarrinho[] = [];
    c = adicionarProduto(c, produto(1, '1000.00'));
    c = adicionarProduto(c, produto(1, '1000.00'), 2);
    c = adicionarProduto(c, produto(2, '250.50'));
    expect(c).toHaveLength(2);
    expect(c[0].quantidade).toBe(3);
    expect(totalUnidades(c)).toBe(4);
    c = alterarQuantidade(c, 1, 0.5);
    expect(c[0].quantidade).toBe(0.5);
    c = alterarQuantidade(c, 2, 0);
    expect(c).toHaveLength(1);
    c = removerLinha(c, 1);
    expect(c).toHaveLength(0);
  });

  it('totais do carrinho com desconto', () => {
    const c = adicionarProduto([], produto(1, '1140.00'), 2);
    const t = totaisCarrinho(c, 50);
    expect(t.total).toBe(114000);
    expect(t.bruto).toBe(228000);
  });

  it('preço alterado ou desconto exigem pos_desconto e só o preço alterado segue para a API', () => {
    let c = adicionarProduto([], produto(1, '1000.00'));
    c = adicionarProduto(c, produto(2, '500.00'));
    expect(exigeDesconto(c, 0)).toBe(false);
    expect(exigeDesconto(c, 5)).toBe(true);
    c = alterarPreco(c, 2, 45000);
    expect(exigeDesconto(c, 0)).toBe(true);
    expect(linhasParaApi(c)).toEqual([
      { produto_id: 1, quantidade: 1 },
      { produto_id: 2, quantidade: 1, preco_unitario: '450.00' },
    ]);
  });
});

describe('pesquisa e leitura de códigos', () => {
  const lista = [produto(1, '10', { codigo: 'AGUA', nome: 'Água 0,5 L' }), produto(2, '20', { codigo: 'AGUA15', nome: 'Água 1,5 L' }), produto(3, '30', { codigo: 'Q101', nome: 'Suíte', e_quarto: true })];

  it('exclui os quartos da frente de caixa', () => {
    expect(produtosVendaveis(lista).map((p) => p.id)).toEqual([1, 2]);
  });

  it('pesquisa sem acentos, com o código exacto primeiro', () => {
    expect(filtrarProdutos(lista, 'agua15').map((p) => p.id)).toEqual([2]);
    expect(filtrarProdutos(lista, 'agua')[0].id).toBe(1);
  });

  it('lê «3*COD» e «COD*3»; só aceita resultado único ou código exacto', () => {
    expect(lerCodigo(lista, '3*AGUA15')).toEqual({ produto: lista[1], quantidade: 3 });
    expect(lerCodigo(lista, 'agua*2')).toEqual({ produto: lista[0], quantidade: 2 });
    expect(lerCodigo(lista, 'Água')).toEqual({ produto: lista[0], quantidade: 1 });
    expect(lerCodigo(lista, 'L')).toBeNull();
  });
});

describe('pagamentos mistos e troco', () => {
  it('troco só em numerário', () => {
    const r = resumirPagamentos([pag('TPA', 30000), pag('NUMERARIO', 100000)], 120000);
    expect(r.valido).toBe(true);
    expect(r.troco).toBe(10000);
    expect(r.falta).toBe(0);
  });

  it('TPA e transferências não podem exceder o total', () => {
    const r = resumirPagamentos([pag('TPA', 130000)], 120000);
    expect(r.valido).toBe(false);
    expect(r.troco).toBe(0);
    expect(r.erros.join(' ')).toMatch(/troco só se dá em numerário/);
  });

  it('transferência exige comprovativo', () => {
    expect(resumirPagamentos([pag('TRANSFERENCIA', 120000)], 120000).valido).toBe(false);
    expect(resumirPagamentos([pag('TRANSFERENCIA', 120000, 'TRF-123')], 120000).valido).toBe(true);
  });

  it('pagamento insuficiente indica o que falta', () => {
    const r = resumirPagamentos([pag('NUMERARIO', 50000)], 120000);
    expect(r.falta).toBe(70000);
    expect(r.valido).toBe(false);
  });

  it('o troco abate-se ao último numerário (valor líquido gravado)', () => {
    const { linhas, troco } = liquidarTroco([pag('NUMERARIO', 5000), pag('TPA', 10000), pag('NUMERARIO', 10000)], 20000);
    expect(troco).toBe(5000);
    expect(linhas.map((l) => [l.tipo, l.valor])).toEqual([
      ['NUMERARIO', 5000],
      ['TPA', 10000],
      ['NUMERARIO', 5000],
    ]);
  });

  it('formato da API: valor decimal e referência só quando indicada', () => {
    expect(pagamentosParaApi([pag('NUMERARIO', 123456), pag('TRANSFERENCIA', 100, ' X1 ')])).toEqual([
      { meio_id: 'pm_numerario', valor: '1234.56' },
      { meio_id: 'pm_transferencia', valor: '1.00', referencia: 'X1' },
    ]);
  });

  it('sugere o total e notas arredondadas acima', () => {
    expect(sugestoesNumerario(1234500)).toEqual([1234500, 1300000, 1400000, 1500000]);
    expect(sugestoesNumerario(0)).toEqual([]);
  });
});

describe('fecho Z', () => {
  it('soma a contagem por notas e moedas e ignora quantidades inválidas', () => {
    expect(totalContagem({ '5000': 80, '1000': 30, '2': 1 })).toBe(43000200);
    expect(totalContagem({ '5000': -1, '1000': 1.7 })).toBe(100000);
    expect(contagensParaApi({ '5000': 2, '1000': 0, '500': null })).toEqual({ '5000': 2 });
  });

  it('classifica o desvio e exige justificação acima da tolerância', () => {
    const dentro = avaliarFecho(43900000, '440000.00', '1000.00');
    expect(dentro.desvio).toBe(-100000);
    expect(dentro.estadoDesvio).toBe('DELIBERADO');
    expect(dentro.exigeJustificacao).toBe(false);

    const acima = avaliarFecho(43000000, '440000.00', '1000.00');
    expect(acima.estadoDesvio).toBe('PENDENTE');
    expect(acima.exigeJustificacao).toBe(true);

    expect(avaliarFecho(44000000, '440000.00', '0').estadoDesvio).toBe('SEM_DESVIO');
  });

  it('talão TPA diferente do sistema exige justificação', () => {
    const r = avaliarFecho(1000, '10.00', '0', [{ sistema: '300000.00', talao: 299000 }]);
    expect(r.tpaDifere).toBe(true);
    expect(r.exigeJustificacao).toBe(true);
    expect(avaliarFecho(1000, '10.00', '0', [{ sistema: '300000.00', talao: 300000 }]).exigeJustificacao).toBe(false);
  });
});

describe('rateio do check-out (ServicoCheckoutHotel::ratear)', () => {
  it('reparte na proporção do total, cêntimo no maior pagamento e troco na última factura', () => {
    const liquidos = [
      { meio_id: 'num', tipo: 'NUMERARIO' as const, valor: 2000000 },
      { meio_id: 'tpa', tipo: 'TPA' as const, valor: 1000001 },
    ];
    const r = ratearPagamentos(liquidos, 50000, [1000000, 2000001]);
    const soma = (x: { valor: number }[]) => x.reduce((t, p) => t + p.valor, 0);
    expect(soma(r[0])).toBe(1000000);
    expect(soma(r[1])).toBe(2000001 + 50000);
    // numerário recebe o troco na última factura
    expect(r[1].find((p) => p.meio_id === 'num')!.valor).toBe(2000000 - r[0].find((p) => p.meio_id === 'num')!.valor + 50000);
  });

  it('factura única recebe tudo', () => {
    const r = ratearPagamentos([{ meio_id: 'trf', tipo: 'TRANSFERENCIA', valor: 500000, referencia: 'C1' }], 0, [500000]);
    expect(r).toEqual([[{ meio_id: 'trf', tipo: 'TRANSFERENCIA', valor: 500000, referencia: 'C1' }]]);
  });
});
