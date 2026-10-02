import { describe, expect, it } from 'vitest';
import { construirDocumento, logotipoSeguro, nomeFicheiroPadrao } from './documento';
import { clonarParaImpressao, contarColunas } from './dom';
import { candidatos, cssPagina, decidirFormato, ESCALA_MINIMA, FORMATO_PADRAO, mmParaPx } from './formato';
import { tabelaHtml } from './tabela';

const A4R = mmParaPx(190);
const A4P = mmParaPx(277);
const A3P = mmParaPx(400);
const LOGO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/** Conteúdo com largura mínima fixa: ocupa a coluna se couber, senão transborda. */
const fixo = (natural: number) => (largura: number) => Math.max(largura, natural);

describe('decisão do papel/orientação', () => {
  it('mapa estreito fica em A4 retrato, sem escala', () => {
    expect(decidirFormato({ medir: fixo(600) })).toMatchObject({ papel: 'A4', orientacao: 'retrato', escala: 1, quebrarTexto: false });
  });

  it('transborda em retrato → A4 paisagem', () => {
    expect(decidirFormato({ medir: fixo(900) })).toMatchObject({ papel: 'A4', orientacao: 'paisagem', escala: 1 });
  });

  it('9 ou mais colunas → paisagem, mesmo que caiba em retrato', () => {
    expect(decidirFormato({ medir: fixo(500), colunas: 12 })).toMatchObject({ papel: 'A4', orientacao: 'paisagem' });
    expect(decidirFormato({ medir: fixo(500), colunas: 8 })).toMatchObject({ orientacao: 'retrato' });
  });

  it('um pouco mais largo que A4 paisagem → A4 paisagem reduzido (≥ 85 %), sem exigir impressora A3', () => {
    const f = decidirFormato({ medir: fixo(1150) });
    expect(f).toMatchObject({ papel: 'A4', orientacao: 'paisagem', quebrarTexto: false });
    expect(f.escala).toBeGreaterThanOrEqual(0.85);
    expect(f.escala).toBeLessThan(1);
    expect(1150 * f.escala).toBeLessThanOrEqual(mmParaPx(277));
  });

  it('não cabe em A4 paisagem → A3 paisagem antes de reduzir a escala', () => {
    expect(decidirFormato({ medir: fixo(1300) })).toMatchObject({ papel: 'A3', orientacao: 'paisagem', escala: 1, larguraUtilMm: 400 });
  });

  it('não cabe em A3 → reduz a escala no A3 paisagem e cabe em largura', () => {
    const f = decidirFormato({ medir: fixo(2500) });
    expect(f).toMatchObject({ papel: 'A3', orientacao: 'paisagem', quebrarTexto: false });
    expect(f.escala).toBeLessThan(1);
    expect(f.escala).toBeGreaterThanOrEqual(ESCALA_MINIMA);
    expect(2500 * f.escala).toBeLessThanOrEqual(A3P);
  });

  it('abaixo da escala mínima: deixa o texto quebrar e fica em 55 %', () => {
    // sem quebra precisa de 5000 px; com quebra adapta-se à coluna
    const medir = (largura: number, quebrar: boolean) => (quebrar ? largura : Math.max(largura, 5000));
    expect(decidirFormato({ medir })).toMatchObject({ papel: 'A3', orientacao: 'paisagem', escala: ESCALA_MINIMA, quebrarTexto: true });
  });

  it('nem com quebra cabe a 55 %: a escala desce o necessário (nada é cortado)', () => {
    const medir = (largura: number) => Math.max(largura, 6000);
    const f = decidirFormato({ medir });
    expect(6000 * f.escala).toBeLessThanOrEqual(A3P);
  });

  it('respeita orientação e papel impostos', () => {
    expect(decidirFormato({ medir: fixo(900), orientacao: 'retrato' })).toMatchObject({ papel: 'A3', orientacao: 'retrato' });
    expect(decidirFormato({ medir: fixo(1300), papel: 'A4' })).toMatchObject({ papel: 'A4', orientacao: 'paisagem' });
    expect(decidirFormato({ medir: fixo(300), orientacao: 'paisagem' })).toMatchObject({ papel: 'A4', orientacao: 'paisagem' });
    expect(candidatos('auto', 'auto').map((c) => `${c.papel}-${c.orientacao}`)).toEqual(['A4-retrato', 'A4-paisagem', 'A3-paisagem']);
  });

  it('larguras úteis: A4 190/277 mm e A3 400 mm', () => {
    expect([A4R, A4P, A3P]).toEqual([718, 1047, 1512]);
  });

  it('@page sem paginação do motor (recurso): tamanho, orientação e «Página X de Y» nas caixas de margem', () => {
    const css = cssPagina({ ...FORMATO_PADRAO, papel: 'A3', orientacao: 'paisagem' }, 'Rodapé "da" empresa');
    expect(css).toContain('margin: 10mm 10mm 14mm 10mm');
    expect(css).toContain('size: A3 landscape');
    expect(css).toContain('counter(page)');
    expect(css).toContain('counter(pages)');
    expect(css).toContain('Rodapé \\"da\\" empresa');
  });

  it('@page com paginação do motor (normal): sem caixas de margem — a numeração está em cada folha', () => {
    const css = cssPagina({ ...FORMATO_PADRAO, papel: 'A4', orientacao: 'paisagem' }, 'Rodapé', true);
    expect(css).toContain('size: A4 landscape');
    expect(css).not.toContain('counter(pages)');
  });
});

describe('documento', () => {
  const identidade = { nome: 'Demo E2E Comércio, Lda', nif: '5000000000', morada: 'Rua A, Luanda', logotipo: LOGO, rodape: 'Rodapé' };

  it('cabeçalho com logótipo, nome da empresa, NIF, título, período e emissão', () => {
    const html = construirDocumento({ titulo: 'Mapa de teste', periodo: 'Janeiro de 2026', identidade, utilizador: 'Operador', conteudo: '<p>x</p>', emitidoEm: new Date(2026, 0, 31, 10, 5) });
    expect(html).toContain('<img class="imp-logotipo" src="data:image/png;base64,');
    expect(html).toContain('Demo E2E Comércio, Lda');
    expect(html).toContain('NIF 5000000000');
    expect(html).toContain('Rua A, Luanda');
    expect(html).toContain('Mapa de teste');
    expect(html).toContain('Período: Janeiro de 2026');
    expect(html).toContain('Emitido em 31/01/2026 10:05');
    expect(html).toContain('por Operador');
    expect(html).toContain('<title>Mapa de teste - Demo E2E Comércio, Lda - 2026-01-31</title>');
    expect(html).not.toContain('<script');
  });

  it('CSS sem barras de deslocação e com cabeçalhos repetidos', () => {
    const html = construirDocumento({ titulo: 'T', conteudo: '' });
    expect(html).toMatch(/overflow: visible !important/);
    expect(html).toMatch(/max-height: none !important/);
    expect(html).toMatch(/::-webkit-scrollbar \{ display: none/);
    expect(html).toContain('thead { display: table-header-group; }');
    expect(html).toMatch(/tr, img, svg[^{]*\{ break-inside: avoid/);
    expect(html).toContain('size: A4 portrait');
  });

  it('escala aplicada com zoom e quebra de texto', () => {
    const html = construirDocumento({ titulo: 'T', conteudo: '' }, { ...FORMATO_PADRAO, papel: 'A3', orientacao: 'paisagem', escala: 0.7, quebrarTexto: true });
    expect(html).toContain('style="zoom: 0.7;"');
    expect(html).toContain('imp-quebrar');
    expect(html).toContain('size: A3 landscape');
  });

  it('escapa o texto e recusa logótipos que não sejam data URI de imagem', () => {
    const html = construirDocumento({ titulo: '<b>x</b>', identidade: { nome: 'A & B', logotipo: 'https://exemplo/logo.png' }, conteudo: '' });
    expect(html).toContain('&lt;b&gt;x&lt;/b&gt;');
    expect(html).toContain('A &amp; B');
    expect(html).not.toContain('imp-logotipo"');
    expect(logotipoSeguro('data:text/html;base64,AAAA')).toBeNull();
    expect(nomeFicheiroPadrao('Mapa: IRT/2026', 'Empresa', new Date(2026, 1, 3))).toBe('Mapa IRT 2026 - Empresa - 2026-02-03');
  });
});

describe('tabelaHtml', () => {
  const linhas = [
    { nome: 'Ana', dep: 'A', valor: '1000.50' },
    { nome: 'Rui <x>', dep: 'A', valor: '200.25' },
    { nome: 'Eva', dep: 'B', valor: '10' },
  ];
  const colunas = [
    { titulo: 'Nome', valor: (l: (typeof linhas)[number]) => l.nome },
    { titulo: 'Valor', valor: (l: (typeof linhas)[number]) => l.valor, formato: 'moeda' as const, somar: true },
  ];

  it('números à direita, total geral e texto escapado', () => {
    const html = tabelaHtml({ colunas, linhas, totais: true });
    expect(html).toContain('<thead><tr><th>Nome</th><th class="imp-num">Valor</th></tr></thead>');
    expect(html).toContain('<td class="imp-num">1000,50</td>');
    expect(html).toContain('Rui &lt;x&gt;');
    expect(html).toMatch(/<tfoot><tr class="imp-total"><td>Total<\/td><td class="imp-num">1210,75<\/td><\/tr><\/tfoot>/);
  });

  it('agrupamentos com subtotais', () => {
    const html = tabelaHtml({ colunas, linhas, agrupar: { chave: (l) => l.dep, titulo: (k) => `Departamento ${k}`, subtotais: true } });
    expect(html).toContain('<tr class="imp-grupo"><td colspan="2">Departamento A</td></tr>');
    expect(html).toContain('<td>Subtotal A</td><td class="imp-num">1200,75</td>');
    expect(html).toContain('<td>Subtotal B</td><td class="imp-num">10,00</td>');
  });

  it('sem linhas mostra a mensagem', () => {
    expect(tabelaHtml({ colunas, linhas: [] })).toContain('Sem registos.');
  });
});

describe('clonar o DOM do ecrã', () => {
  it('retira botões e zonas «no-print», junta as tabelas de cabeçalho fixo do Ant Design e tira o zoom', () => {
    const raiz = document.createElement('div');
    raiz.innerHTML = `
      <div class="organigrama" style="transform: scale(0.6); max-height: 300px; overflow: auto">
        <button>Apagar</button><span class="no-print">x</span><span class="rh-nao-imprimir">y</span>
        <div class="ant-table"><div class="ant-table-container">
          <div class="ant-table-header"><table><colgroup><col style="width:80px"></colgroup><thead><tr><th>A</th><th>B</th></tr></thead></table></div>
          <div class="ant-table-body" style="max-height: 200px; overflow-y: scroll"><table style="table-layout: fixed"><tbody>
            <tr class="ant-table-measure-row"><td></td><td></td></tr><tr><td>1</td><td>2</td></tr></tbody></table></div>
        </div></div>
      </div>`;
    document.body.appendChild(raiz);
    const html = clonarParaImpressao(raiz.firstElementChild!);
    const d = document.createElement('div');
    d.innerHTML = html;
    expect(d.querySelector('button, .no-print, .rh-nao-imprimir, .ant-table-header, .ant-table-measure-row, colgroup')).toBeNull();
    const tabela = d.querySelector('.ant-table-body table')!;
    expect(tabela.querySelector('thead th')?.textContent).toBe('A');
    expect(tabela.querySelectorAll('tbody tr')).toHaveLength(1);
    const org = d.firstElementChild as HTMLElement;
    expect(org.style.transform).toBe('');
    expect(org.style.maxHeight).toBe('');
    expect(org.style.overflow).toBe('');
    expect((d.querySelector('.ant-table-body') as HTMLElement).style.maxHeight).toBe('');
    expect(contarColunas(d)).toBe(2);
    // o original fica intacto
    expect(raiz.querySelector('button')).not.toBeNull();
    raiz.remove();
  });
});
