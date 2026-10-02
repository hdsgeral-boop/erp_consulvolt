import { describe, expect, it } from 'vitest';
import { htmlRelatorioSessao, htmlTalaoVenda, PREFERENCIAS_PADRAO, reimprimir } from './impressao';
import type { SessaoPOS, VendaEmitida } from './tipos';

const LOGO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
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
const cabecalho = { empresa: 'Demo E2E Comércio, Lda', nif: '5000000000', morada: 'Rua A', logotipo: LOGO, utilizador: 'Operador' };

describe('impressão POS', () => {
  it('talão térmico: largura do rolo (fora da regra A4/A3), logótipo e nome da empresa', () => {
    const html = htmlTalaoVenda(venda, cabecalho, { ...PREFERENCIAS_PADRAO, largura: 58 });
    expect(html).toContain('size: 58mm auto');
    expect(html).toContain(`<img class="logo" src="${LOGO}"`);
    expect(html).toContain('<h1>Demo E2E Comércio, Lda</h1>');
    expect(html).not.toContain('imp-cabecalho');
  });

  it('A4: documento do motor comum (cabeçalho com logótipo/nome, A4 retrato, «Página X de Y»)', () => {
    const html = htmlTalaoVenda(venda, cabecalho, { ...PREFERENCIAS_PADRAO, formato: 'A4' });
    expect(html).toContain('class="imp-cabecalho"');
    expect(html).toContain('<img class="imp-logotipo"');
    expect(html).toContain('Demo E2E Comércio, Lda');
    expect(html).toContain('size: A4 portrait');
    expect(html).toContain('counter(pages)');
    expect(html).toContain('<title>Factura-recibo FR T01 2026 9</title>');
    const z = htmlRelatorioSessao({ codigo_sessao: 'S1', numero_z: 'Z1', totais_por_metodo: [] } as unknown as SessaoPOS, cabecalho, { ...PREFERENCIAS_PADRAO, formato: 'A4' });
    expect(z).toContain('RELATÓRIO Z Z1');
    expect(z).toContain('imp-logotipo');
  });

  it('pré-visualização sem onclick inline (CSP script-src self): o botão imprime pelo handler', () => {
    const iframe = document.createElement('iframe');
    document.body.appendChild(iframe);
    const janela = iframe.contentWindow!;
    let impressoes = 0;
    janela.print = () => { impressoes += 1; };
    janela.focus = () => undefined;
    const original = window.open;
    window.open = (() => janela) as typeof window.open;
    try {
      reimprimir(htmlTalaoVenda(venda, cabecalho, PREFERENCIAS_PADRAO), PREFERENCIAS_PADRAO);
    } finally {
      window.open = original;
    }
    const botao = janela.document.querySelector<HTMLButtonElement>('[data-accao="imprimir"]')!;
    expect(janela.document.documentElement.outerHTML).not.toMatch(/onclick=/i);
    botao.click();
    expect(impressoes).toBe(1);
    iframe.remove();
  });
});
