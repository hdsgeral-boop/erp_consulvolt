import { describe, expect, it } from 'vitest';
import { htmlTalaoVenda, PREFERENCIAS_PADRAO } from '../comum/impressao';
import type { VendaEmitida } from '../comum/tipos';
import { juntarVendas } from './taloes';

const linha = (id: number, descricao: string, total: string) => ({ id, produto_id: id, descricao, quantidade: '1', preco_unitario: total, taxa_imposto: '14', total });
// Check-out por quarto: Q1 22 572,00 (TPA 16 296,30 + numerário 6 275,70) e Q2 5 130,00 (TPA 3 703,70 + numerário 1 426,30, troco 298,00)
const v1 = {
  id: 1, numero_documento: 'FR H012026/1', data_emissao: '2026-03-11', total_liquido: '19800.00', total_imposto: '2772.00', total_bruto: '22572.00', desconto: '2508.00', pos_troco: '0.00',
  nome_tabela: 'Quarto 1', pos_operador: 'gestor',
  pos_pagamentos: [{ meio_id: 'pm_tpa', tipo: 'TPA', nome: 'Multicaixa', valor: '16296.30' }, { meio_id: 'pm_num', tipo: 'NUMERARIO', nome: 'Numerário', valor: '6275.70' }],
  itens_venda: [linha(1, 'Alojamento Quarto 1', '22800.00'), linha(2, 'Bebida', '2280.00')],
} as unknown as VendaEmitida;
const v2 = {
  id: 2, numero_documento: 'FR H012026/2', data_emissao: '2026-03-11', total_liquido: '4500.00', total_imposto: '630.00', total_bruto: '5130.00', desconto: '570.00', pos_troco: '298.00',
  nome_tabela: 'Quarto 2', pos_operador: 'gestor',
  pos_pagamentos: [{ meio_id: 'pm_tpa', tipo: 'TPA', nome: 'Multicaixa', valor: '3703.70' }, { meio_id: 'pm_num', tipo: 'NUMERARIO', nome: 'Numerário', valor: '1426.30' }],
  itens_venda: [linha(3, 'Alojamento Quarto 2', '5700.00')],
} as unknown as VendaEmitida;

describe('talão único do check-out (F-181)', () => {
  it('uma factura fica como está', () => {
    expect(juntarVendas([v1])).toBe(v1);
  });

  it('junta documentos, quartos, linhas, totais e pagamentos por meio', () => {
    const j = juntarVendas([v1, v2]);
    expect(j.numero_documento).toBe('FR H012026/1, FR H012026/2');
    expect(j.nome_tabela).toBe('Quarto 1, Quarto 2');
    expect([j.total_liquido, j.total_imposto, j.total_bruto, j.desconto, j.pos_troco]).toEqual(['24300.00', '3402.00', '27702.00', '3078.00', '298.00']);
    expect(j.itens_venda).toHaveLength(3);
    expect((j.pos_pagamentos ?? []).map((p) => [p.meio_id, p.valor])).toEqual([['pm_tpa', '20000.00'], ['pm_num', '7702.00']]);
  });

  it('o talão traz todos os números de documento, as linhas e o total geral', () => {
    const html = htmlTalaoVenda(juntarVendas([v1, v2]), { empresa: 'Hotel Demo', rotuloLocal: 'Quarto(s)' }, PREFERENCIAS_PADRAO);
    expect(html).toContain('FR H012026/1, FR H012026/2');
    expect(html).toContain('Quarto(s): Quarto 1, Quarto 2');
    expect(html).toContain('Alojamento Quarto 2');
    expect(html).toContain('Bebida');
  });
});
