import { describe, expect, it } from 'vitest';
import { htmlEtiquetas, htmlTalaoGenerico, PREFERENCIAS_PADRAO } from '../comum/impressao';
import type { ProdutoPOS } from '../comum/tipos';
import { carrinhoDaConta, estadoMesa, linhasDaConta, type MesaPOS } from './mesas';

const produtos = [
  { id: 1, codigo: 'P1', nome: 'Prato do dia', preco_unitario: '1140.00', taxa_imposto: '14' },
  { id: 2, codigo: 'B1', nome: 'Sumo', preco_unitario: '500.00', taxa_imposto: '14' },
] as unknown as ProdutoPOS[];

describe('mesas do POS restaurante (M-15)', () => {
  it('conta do servidor → carrinho, com preço alterado e produtos em falta', () => {
    const r = carrinhoDaConta([{ produto_id: 1, quantidade: '2.000' }, { produto_id: 2, quantidade: 1, preco_unitario: '450.00' }, { produto_id: 9, quantidade: 1 }], produtos);
    expect(r.emFalta).toBe(1);
    expect(r.carrinho.map((i) => [i.produto_id, i.quantidade, i.preco, i.preco_catalogo])).toEqual([
      [1, 2, 114000, 114000],
      [2, 1, 45000, 50000],
    ]);
    expect(linhasDaConta(r.carrinho)).toEqual([{ produto_id: 1, quantidade: 2 }, { produto_id: 2, quantidade: 1, preco_unitario: '450.00' }]);
  });

  it('estado visual da mesa', () => {
    const m = (id: number, itens: number | null): MesaPOS => ({ id, terminal_pos_id: 1, nome: `Mesa ${id}`, ordem: id, ativo: true, conta: itens === null ? null : { id: 1, versao: 1, itens, total: '0', operador: null, aberta_em: null, atualizado_em: null } });
    expect(estadoMesa(m(1, 2), 1)).toBe('ACTIVA');
    expect(estadoMesa(m(2, 2), 1)).toBe('OCUPADA');
    expect(estadoMesa(m(3, null), 1)).toBe('LIVRE');
  });

  it('talão de consulta e etiquetas escapam os textos', () => {
    const c = { empresa: 'Empresa <Fictícia>' };
    const html = htmlTalaoGenerico('Consulta de mesa', { dados: ['Mesa: <b>5</b>'], aviso: 'Não serve de factura', linhas: [{ descricao: 'Prato', quantidade: 2, preco: '1140.00', total: '2280.00' }], totais: [['TOTAL', '2280.00', true]] }, c, PREFERENCIAS_PADRAO);
    expect(html).toContain('Mesa: &lt;b&gt;5&lt;/b&gt;');
    expect(html).toContain('Não serve de factura');
    expect(html).not.toContain('<b>5</b>');
    const et = htmlEtiquetas('Etiquetas', [{ titulo: 'OS-1/3', linhas: ['Camisa', null] }], c, PREFERENCIAS_PADRAO);
    expect(et).toContain('OS-1/3');
    expect(et).toContain('Empresa &lt;Fictícia&gt;');
  });
});
