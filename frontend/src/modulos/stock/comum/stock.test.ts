import { accoesGuia, accoesInventario, guiaGerida, previsualizarRegularizacao } from './regras';
import { adicionarAoCarrinho, alterarQuantidade, totalUnidades } from './carrinho';
import { linhasAlteradas } from './Inventario';
import type { GuiaSaida, SessaoInventario } from './tipos';

const tudo = () => true;

describe('guias de saída', () => {
  it('reconhece as guias geridas (consumo e venda ao balcão)', () => {
    expect(guiaGerida({ tipo: 'CONSUMO', tipo_original: null })).toBe(true);
    expect(guiaGerida({ tipo: 'VENDA', tipo_original: 'VENDA_BALCAO' })).toBe(true);
    expect(guiaGerida({ tipo: 'VENDA', tipo_original: 'VENDA' })).toBe(false);
  });

  it('mostra as acções conforme o estado e a contabilização', () => {
    const g = (o: Partial<GuiaSaida>) => ({ tipo: 'CONSUMO', tipo_original: null, estado: 'CONCLUIDO', contabilizado: false, ...o }) as GuiaSaida;
    expect(accoesGuia(g({}), tudo)).toEqual({ contabilizar: true, descontabilizar: false, anular: true });
    expect(accoesGuia(g({ contabilizado: true }), tudo)).toEqual({ contabilizar: false, descontabilizar: true, anular: false });
    expect(accoesGuia(g({ tipo: 'VENDA' }), tudo)).toEqual({ contabilizar: false, descontabilizar: false, anular: false });
    expect(accoesGuia(g({ estado: 'ANULADA' }), tudo).anular).toBe(false);
  });
});

describe('inventário', () => {
  const s = (estado: string) => ({ estado }) as SessaoInventario;

  it('acções por estado', () => {
    expect(accoesInventario(s('EM_CONTAGEM'), tudo)).toMatchObject({ contar: true, rever: false, aprovar: false, anular: true, reabrir: false });
    expect(accoesInventario(s('REVISAO'), tudo)).toMatchObject({ contar: false, rever: true, voltarContagem: true, aprovar: true, anular: true });
    expect(accoesInventario(s('CONCLUIDA'), tudo)).toMatchObject({ reabrir: true, anular: false, aprovar: false });
    expect(Object.values(accoesInventario(s('ANULADA'), tudo)).some(Boolean)).toBe(false);
  });

  it('pré-visualiza a regularização com o custo personalizado ou o custo médio', () => {
    const p = previsualizarRegularizacao([
      { quantidade_sistema: '10', quantidade_contada: '12', diferenca: '2.000', custo_personalizado: null, custo_medio: '100.5', justificacao: null },
      { quantidade_sistema: '5', quantidade_contada: '4', diferenca: '-1.000', custo_personalizado: '80', custo_medio: '100', justificacao: 'Partido' },
      { quantidade_sistema: '3', quantidade_contada: '3', diferenca: '0', custo_personalizado: null, custo_medio: '1', justificacao: null },
    ]);
    expect(p.sobras).toEqual({ linhas: 1, quantidade: 2, valor: 201 });
    expect(p.quebras).toEqual({ linhas: 1, quantidade: 1, valor: 80 });
    expect(p.semDiferenca).toBe(1);
    expect(p.porJustificar).toBe(1);
  });

  it('envia na contagem só as linhas alteradas e os produtos acrescentados', () => {
    const originais = [
      { produto_id: 1, quantidade_contada: null, observacoes: null },
      { produto_id: 2, quantidade_contada: '5.000', observacoes: null },
    ];
    expect(linhasAlteradas(originais, { 1: { quantidade_contada: 3 }, 2: { quantidade_contada: 5 }, 9: { quantidade_contada: 1 } })).toEqual([
      { produto_id: 1, quantidade_contada: 3, observacoes: null },
      { produto_id: 9, quantidade_contada: 1, observacoes: null },
    ]);
    expect(linhasAlteradas(originais, { 2: { observacoes: 'caixa aberta' } })).toEqual([{ produto_id: 2, quantidade_contada: 5, observacoes: 'caixa aberta' }]);
  });
});

describe('carrinho do POS de armazém', () => {
  const p = { produto_id: 1, codigo: 'A', nome: 'Artigo', disponivel: 2 };
  it('soma e limita ao stock disponível', () => {
    let c = adicionarAoCarrinho([], p);
    c = adicionarAoCarrinho(c, p);
    c = adicionarAoCarrinho(c, p);
    expect(c).toEqual([{ ...p, quantidade: 2 }]);
    expect(totalUnidades(c)).toBe(2);
  });
  it('retira a linha com quantidade zero e ignora produtos sem stock', () => {
    expect(alterarQuantidade([{ ...p, quantidade: 1 }], 1, 0)).toEqual([]);
    expect(adicionarAoCarrinho([], { ...p, disponivel: 0 })).toEqual([]);
  });
});
