import { describe, expect, it } from 'vitest';
import { corpoPedidoReposicao, sugestoes, type LinhaNivel } from './niveis';

const linha = (produto_id: number, estado: LinhaNivel['estado'], quantidade_sugerida: string): LinhaNivel => ({
  produto_id, codigo: `P${produto_id}`, nome: `Produto ${produto_id}`, categoria: null, unidade: 'UN', stock: '1.000', em_encomenda: '0.000', posicao: '1.000',
  stock_minimo: '2.000', stock_seguranca: null, ponto_encomenda: '5.000', stock_maximo: '10.000', estado, motivo: null, quantidade_sugerida, custo_medio: '10.00', valor_sugerido: '0.00',
});

describe('sugestões de reposição', () => {
  it('só críticos e reposições com quantidade a encomendar', () => {
    const l = [linha(1, 'CRITICO', '9.000'), linha(2, 'REPOSICAO', '0.000'), linha(3, 'EXCESSO', '0.000'), linha(4, 'REPOSICAO', '4.000')];
    expect(sugestoes(l).map((x) => x.produto_id)).toEqual([1, 4]);
  });
  it('o pedido usa a quantidade corrigida ou a sugerida e ignora zeros', () => {
    const l = [linha(1, 'CRITICO', '9.000'), linha(4, 'REPOSICAO', '4.000'), linha(5, 'REPOSICAO', '3.000')];
    expect(corpoPedidoReposicao(l, { 4: 6, 5: 0 })).toEqual({ linhas: [{ produto_id: 1, quantidade: 9 }, { produto_id: 4, quantidade: 6 }] });
  });
});
