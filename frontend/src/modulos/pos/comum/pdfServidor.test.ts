import { beforeEach, describe, expect, it, vi } from 'vitest';

const descarregarPost = vi.fn();
vi.mock('@/api/cliente', () => ({ descarregarPost: (...a: unknown[]) => descarregarPost(...a), obter: vi.fn(), enviar: vi.fn() }));

import { htmlTalaoVenda, PREFERENCIAS_PADRAO } from './impressao';
import { descarregarPdfServidor, montarPedidoPdf, nomePdf, pedidoPdfDeDocumentoHtml } from './pdfServidor';
import type { VendaEmitida } from './tipos';

const venda = {
  id: 1,
  numero_documento: 'FR T01/2026/9',
  data_emissao: '2026-10-01',
  total_liquido: '877.19',
  total_imposto: '122.81',
  total_bruto: '1000.00',
  desconto: '0.00',
  pos_troco: '0.00',
  pos_pagamentos: [{ tipo: 'NUMERARIO', nome: 'Numerário', valor: '1000.00' }],
  itens_venda: [{ id: 1, produto_id: 1, descricao: 'Artigo', quantidade: '1', preco_unitario: '1000.00', taxa_imposto: '14', total: '1000.00' }],
} as unknown as VendaEmitida;

beforeEach(() => {
  descarregarPost.mockReset();
});

describe('PDF A4 no servidor (F7)', () => {
  it('monta o pedido com o corpo, o CSS próprio e a orientação (nunca a identidade)', () => {
    const p = montarPedidoPdf('sessao', { titulo: 'Relatório Z', subtitulo: 'T01', periodo: '01/10/2026', filtros: ['Operador: ana', null], conteudo: '<p>x</p>', cssExtra: '.a{color:red}', orientacao: 'paisagem' });
    expect(p).toMatchObject({ tipo: 'sessao', titulo: 'Relatório Z', subtitulo: 'T01 · Período: 01/10/2026 · Operador: ana', html: '<p>x</p>', css: '.a{color:red}', orientacao: 'paisagem' });
    expect(Object.keys(p)).not.toContain('identidade');
    expect(montarPedidoPdf('talao', { titulo: 'T', conteudo: '<p/>' })).toMatchObject({ orientacao: 'retrato', css: null, subtitulo: null });
  });

  it('um elemento do ecrã segue como o seu HTML', () => {
    const el = document.createElement('div');
    el.innerHTML = '<b>ola</b>';
    expect(montarPedidoPdf('relatorio', { titulo: 'T', conteudo: el }).html).toBe('<div><b>ola</b></div>');
  });

  it('o nome do ficheiro não leva caracteres perigosos', () => {
    expect(nomePdf({ titulo: 'Guia: GE POS 2026/0001' })).toBe('Guia GE POS 2026 0001');
    expect(nomePdf({ titulo: '', nomeFicheiro: '' })).toBe('Documento');
  });

  it('extrai o corpo do talão A4 (com QR) do documento do motor; o térmico não tem PDF no servidor', () => {
    const a4 = htmlTalaoVenda(venda, { empresa: 'Demo' }, { ...PREFERENCIAS_PADRAO, formato: 'A4' }, new Map(), { qr_svg: '<svg xmlns="http://www.w3.org/2000/svg"/>', serie: 'FR T012026' });
    const pedido = pedidoPdfDeDocumentoHtml(a4);
    expect(pedido).not.toBeNull();
    expect(String(pedido?.conteudo)).toContain('FR T01/2026/9');
    expect(String(pedido?.conteudo)).toContain('data:image/svg+xml');
    expect(pedido?.cssExtra).toContain('.talao');
    expect(pedido?.cssExtra).not.toContain('.imp-cabecalho');
    expect(pedidoPdfDeDocumentoHtml(htmlTalaoVenda(venda, { empresa: 'Demo' }, PREFERENCIAS_PADRAO))).toBeNull();
  });

  it('descarrega por POST para o endpoint do servidor', async () => {
    descarregarPost.mockResolvedValue({ nome: 'Relatório.pdf', cabecalhos: {} });
    const nome = await descarregarPdfServidor('guia_saida', { titulo: 'Guia', conteudo: '<p>g</p>' });
    expect(nome).toBe('Relatório.pdf');
    expect(descarregarPost).toHaveBeenCalledWith('/pos/impressao/pdf', expect.objectContaining({ tipo: 'guia_saida', html: '<p>g</p>' }), 'Guia.pdf');
  });
});
