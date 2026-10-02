import { describe, expect, it } from 'vitest';
import { htmlDocumentoComercial } from '@/modulos/vendas/impressao/documentoComercial';
import { dadosGuiaSaida, pedidoStockPorArmazem } from './impressao';
import type { GuiaSaida, LinhaStock } from './tipos';

describe('impressão do armazém', () => {
  it('stock por armazém: grupos por armazém, subtotal do valor e total geral', () => {
    const l = (armazem: string, nome: string, valor: string): LinhaStock => ({ armazem_id: armazem === 'Central' ? 1 : 2, armazem, produto_id: 1, codigo: 'P1', nome, quantidade: '2', custo_medio: '5.00', valor, stock_minimo: null, ruptura: false });
    const html = pedidoStockPorArmazem([l('Loja', 'B', '30.00'), l('Central', 'A', '10.00')]).conteudo as string;
    expect(html.indexOf('Central')).toBeLessThan(html.indexOf('Loja'));
    expect(html).toContain('Subtotal Central');
    expect(html).toContain('Valor total do stock');
    expect(html).toContain('40,00');
  });

  it('guia de saída: destino, artigos, valor total e assinaturas', () => {
    const g = {
      id: 1, numero_documento: 'GS 2026/1', data: '2026-09-30', tipo: 'CONSUMO', tipo_original: null, terceiro_id: null, armazem_id: 1, area_rececao: 'Manutenção', estado: 'CONCLUIDO',
      venda_relacionada_id: null, contabilizado: false, observacoes: null, criado_por: 'operador', numero_lan_contabilizacao: null, anulado_em: null, motivo_anulacao: null,
      linhas: [{ id: 1, produto_id: 3, produto: { id: 3, codigo: 'P3', nome: 'Parafuso' }, quantidade: '4', valor_kz: '20.00', custo_unitario_kz: '5.00' }],
    } as GuiaSaida;
    const html = htmlDocumentoComercial(dadosGuiaSaida(g, 'Central'));
    expect(html).toContain('Manutenção');
    expect(html).toContain('P3 — Parafuso');
    expect(html).toContain('Valor total (custo)');
    expect(html).toContain('Recebeu');
  });
});
