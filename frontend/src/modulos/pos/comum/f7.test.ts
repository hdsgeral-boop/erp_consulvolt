import { beforeEach, describe, expect, it, vi } from 'vitest';

const obter = vi.fn();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obter(...a), enviar: vi.fn(), descarregarPost: vi.fn() }));

import { juntarVendas } from '../hotelaria/taloes';
import { obterTalaoFiscal, obterTalaoFiscalUnico } from './dados';
import { htmlTalaoVenda, PREFERENCIAS_PADRAO, type TalaoFiscal } from './impressao';
import type { VendaEmitida } from './tipos';

const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>';
const venda = (n: number, nome = 'FR T01/2026/' + n) =>
  ({
    id: n,
    numero_documento: nome,
    data_emissao: '2026-10-01',
    nome_tabela: `Q${n}`,
    total_liquido: '877.19',
    total_imposto: '122.81',
    total_bruto: '1000.00',
    desconto: '0.00',
    pos_troco: '0.00',
    pos_pagamentos: [{ tipo: 'NUMERARIO', nome: 'Numerário', valor: '1000.00' }],
    itens_venda: [{ id: n, produto_id: 1, descricao: `Quarto ${n}`, quantidade: '1', preco_unitario: '1000.00', taxa_imposto: '14', total: '1000.00' }],
  }) as unknown as VendaEmitida;
const fiscal = (n: number): TalaoFiscal => ({ fe_regime: true, serie: `FR T01202${n}`, qr_svg: SVG, cliente_nome: 'Hotel Cliente', cliente_nif: '5000000001', coordenadas_bancarias: [{ nome: 'BAI', iban: 'AO06.0040.0000.1234', swift: 'BAIPAOLU' }] });

beforeEach(() => {
  obter.mockReset();
});

describe('F7: QR da AGT e IBAN nos talões da hotelaria e da lavandaria', () => {
  it('check-out com talão único: um QR e uma série por documento, IBAN e «Processado por computador»', async () => {
    obter.mockImplementation(async (url: string) => fiscal(Number(url.split('/').pop())));
    const vendas = [venda(1), venda(2)];
    const unico = await obterTalaoFiscalUnico(vendas);
    expect(unico?.documentos).toHaveLength(2);
    const html = htmlTalaoVenda(juntarVendas(vendas), { empresa: 'Hotel', rotuloLocal: 'Quarto(s)' }, PREFERENCIAS_PADRAO, new Map(), unico);
    expect(html.match(/<img class="qr"/g)).toHaveLength(2);
    expect(html).toContain('FR T01/2026/1 · Facturação electrónica · Série FR T012021');
    expect(html).toContain('FR T01/2026/2 · Facturação electrónica · Série FR T012022');
    expect(html).toContain('AO06.0040.0000.1234');
    expect(html).toContain('SWIFT BAIPAOLU');
    expect(html).toContain('Processado por computador');
    expect(html).toContain('Quarto(s): Q1, Q2');
  });

  it('um documento só (um talão por factura ou talão único de uma factura) mantém o QR único de sempre', () => {
    const html = htmlTalaoVenda(venda(1), { empresa: 'Hotel' }, PREFERENCIAS_PADRAO, new Map(), fiscal(1));
    expect(html.match(/<img class="qr"/g)).toHaveLength(1);
    expect(html).toContain('Consulte este documento no portal da AGT');
    const comUm = htmlTalaoVenda(venda(1), { empresa: 'Hotel' }, PREFERENCIAS_PADRAO, new Map(), { ...fiscal(1), documentos: [{ numero_documento: 'FR T01/2026/1', serie: 'S', qr_svg: SVG }] });
    expect(comUm.match(/<img class="qr"/g)).toHaveLength(1);
  });

  it('sem os elementos fiscais o talão sai igual ao de antes (sem QR nem IBAN)', () => {
    const html = htmlTalaoVenda(venda(1), { empresa: 'Hotel' }, PREFERENCIAS_PADRAO);
    expect(html).not.toContain('<img class="qr"');
    expect(html).not.toContain('Coordenadas Bancárias');
    expect(html).toContain('Processado por computador');
  });

  it('se um dos documentos não tiver resposta, esse fica sem QR; se nenhum tiver, devolve null', async () => {
    obter.mockImplementation(async (url: string) => {
      if (!url.endsWith('/1')) throw new Error('rede');
      return fiscal(1);
    });
    const parcial = await obterTalaoFiscalUnico([venda(1), venda(2)]);
    expect(parcial?.documentos?.map((d) => !!d.qr_svg)).toEqual([true, false]);
    obter.mockImplementation(async () => {
      throw new Error('rede');
    });
    expect(await obterTalaoFiscalUnico([venda(1), venda(2)])).toBeNull();
    expect(await obterTalaoFiscal(1)).toBeNull();
  });
});
