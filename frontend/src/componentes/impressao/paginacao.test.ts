import { afterEach, describe, expect, it, vi } from 'vitest';
import { construirDocumento } from './documento';
import { alturaPaginaMm, cssPagina, FORMATO_PADRAO } from './formato';
import { estilosParaImpressao } from './motor';
import { paginarDocumento } from './paginacao';
import type { FormatoPagina } from './tipos';

/**
 * Paginação do motor com um layout simulado (o jsdom não calcula layout): cada folha tem 500 px de corpo; o
 * cabeçalho da empresa ocupa 60 px e o bloco do título 40 px (só na 1.ª folha); os nós de conteúdo têm a altura do
 * atributo data-h e os contentores a soma dos filhos. Assim testa-se o algoritmo (quebras, cabeçalhos repetidos,
 * totais no fim, «Página X de Y», títulos que não ficam sozinhos), independentemente do navegador.
 */

const ALTURA_CORPO = 500;
const PASSO_FOLHA = 1000;

function altura(el: Element): number {
  if (el.hasAttribute('data-h')) return Number(el.getAttribute('data-h'));
  if (el.classList.contains('imp-cabecalho')) return 60;
  if (el.classList.contains('imp-titulo-bloco')) return 40;
  const estilo = (el as HTMLElement).style?.getPropertyValue?.('zoom');
  const soma = Array.from(el.children).reduce((s, f) => s + altura(f), 0);
  return estilo ? soma * Number(estilo) : soma;
}

function rect(top: number, h: number): DOMRect {
  return { top, bottom: top + h, height: h, left: 0, right: 100, width: 100, x: 0, y: top, toJSON: () => ({}) } as DOMRect;
}

/** Documento numa iframe do jsdom (tem defaultView → getComputedStyle), com o layout simulado. */
function documento(conteudo: string, opcoes: { rodape?: string | null } = {}): Document {
  const iframe = document.createElement('iframe');
  document.body.appendChild(iframe);
  const doc = iframe.contentDocument!;
  doc.open();
  doc.write(construirDocumento({ titulo: 'Mapa de teste', identidade: { nome: 'Demo E2E Comércio, Lda', rodape: opcoes.rodape ?? 'Rodapé da empresa' }, conteudo }));
  doc.close();
  const janela = iframe.contentWindow as unknown as typeof globalThis;
  vi.spyOn(janela.Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
    const folha = this.closest('.imp-pagina');
    if (!folha) return rect(0, altura(this));
    const i = Array.from(doc.querySelectorAll('.imp-pagina')).indexOf(folha);
    const topoCorpo = i * PASSO_FOLHA;
    if (this.classList.contains('imp-pagina-corpo')) return rect(topoCorpo, ALTURA_CORPO);
    if (this.classList.contains('imp-conteudo')) {
      const antes = Array.from(this.parentElement!.children)
        .filter((e) => e !== this && !e.classList.contains('imp-conteudo'))
        .reduce((s, e) => s + altura(e), 0);
      return rect(topoCorpo + antes, altura(this));
    }
    return rect(topoCorpo, altura(this));
  });
  return doc;
}

const folhas = (doc: Document) => Array.from(doc.querySelectorAll<HTMLElement>('.imp-pagina'));
const linhasTabela = (n: number, h = 20) => Array.from({ length: n }, (_, i) => `<tr data-h="${h}"><td>L${i + 1}</td><td>${i + 1}</td></tr>`).join('');
// a folga de 1,5 mm (≈ 5,7 px) é descontada ao corpo: o limite útil fica em ~494 px
const FORMATO: FormatoPagina = FORMATO_PADRAO;

afterEach(() => {
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});

describe('paginação do motor (Chromium/Edge e Firefox)', () => {
  it('parte uma tabela longa por linhas, repete o cabeçalho em cada folha e deixa os totais só no fim', () => {
    const doc = documento(`<table class="imp-tabela" id="t1"><thead data-h="30"><tr><th>Nome</th><th>Valor</th></tr></thead><tbody>${linhasTabela(60)}</tbody><tfoot data-h="25"><tr><td>Total</td><td>1830</td></tr></tfoot></table>`);
    const { paginas } = paginarDocumento(doc, FORMATO, 'Rodapé da empresa');
    const fs = folhas(doc);
    expect(paginas).toBe(3);
    expect(fs).toHaveLength(3);
    // 1.ª folha: 500 − 100 (cabeçalho + título) − 30 (thead) − folga → 18 linhas; seguintes: 23 linhas
    expect(fs.map((f) => f.querySelectorAll('tbody tr').length)).toEqual([18, 23, 19]);
    fs.forEach((f) => expect(f.querySelectorAll('thead')).toHaveLength(1));
    expect(fs.map((f) => f.querySelectorAll('tfoot').length)).toEqual([0, 0, 1]);
    // ordem das linhas mantida e nenhuma perdida
    const textos = fs.flatMap((f) => Array.from(f.querySelectorAll('tbody tr td:first-child')).map((td) => td.textContent));
    expect(textos).toEqual(Array.from({ length: 60 }, (_, i) => `L${i + 1}`));
    // ids não se duplicam entre folhas
    expect(doc.querySelectorAll('#t1').length).toBeLessThanOrEqual(1);
  });

  it('cada folha leva o rodapé da empresa e «Página X de Y»; o cabeçalho da empresa só na primeira', () => {
    const doc = documento(`<table><thead data-h="30"><tr><th>A</th></tr></thead><tbody>${linhasTabela(60)}</tbody></table>`);
    paginarDocumento(doc, FORMATO, 'Demo E2E Comércio, Lda · NIF 5999000001');
    const fs = folhas(doc);
    fs.forEach((f, i) => {
      expect(f.querySelector('.imp-pagina-numero')?.textContent).toBe(`Página ${i + 1} de ${fs.length}`);
      expect(f.querySelector('.imp-pagina-rodape-empresa')?.textContent).toBe('Demo E2E Comércio, Lda · NIF 5999000001');
      expect(f.dataset.pagina).toBe(String(i + 1));
    });
    expect(fs.map((f) => !!f.querySelector('.imp-cabecalho'))).toEqual([true, false, false]);
    expect(fs.map((f) => !!f.querySelector('.imp-titulo-bloco'))).toEqual([true, false, false]);
    expect(doc.body.dataset.paginas).toBe(String(fs.length));
    expect(doc.body.classList.contains('imp-paginado')).toBe(true);
    // a fonte (conteúdo original) sai do documento
    expect(doc.querySelector('.imp-fonte')).toBeNull();
    expect(doc.querySelector('script')).toBeNull();
  });

  it('repete em cada folha os elementos marcados com .imp-repetir (linha de meses do Gantt)', () => {
    const linhas = Array.from({ length: 50 }, (_, i) => `<div class="erp-gantt-linha imp-sem-quebra" data-h="30"><div>Tarefa ${i + 1}</div><div>barra</div></div>`).join('');
    const doc = documento(`<div class="erp-gantt"><div style="min-width:1000px"><div class="erp-gantt-cabecalho imp-repetir" data-h="32"><div>Item</div><div>jan</div></div>${linhas}</div></div>`);
    const { paginas } = paginarDocumento(doc, { ...FORMATO, orientacao: 'paisagem' }, null);
    const fs = folhas(doc);
    expect(paginas).toBeGreaterThanOrEqual(3);
    fs.forEach((f) => {
      const cab = f.querySelectorAll('.erp-gantt-cabecalho');
      expect(cab).toHaveLength(1);
      // o cabeçalho vem antes da primeira linha da folha
      const primeiraLinha = f.querySelector('.erp-gantt-linha')!;
      expect(cab[0].compareDocumentPosition(primeiraLinha) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });
    expect(fs.reduce((s, f) => s + f.querySelectorAll('.erp-gantt-linha').length, 0)).toBe(50);
    // cada linha fica inteira (nome e barra juntos)
    fs.forEach((f) => f.querySelectorAll('.erp-gantt-linha').forEach((l) => expect(l.children).toHaveLength(2)));
  });

  it('um título no fundo da folha passa para a folha seguinte com o conteúdo que introduz', () => {
    const doc = documento(`<p data-h="380">Texto</p><h3 data-h="12">Secção 2</h3><table><thead data-h="30"><tr><th>A</th></tr></thead><tbody>${linhasTabela(5)}</tbody></table>`);
    paginarDocumento(doc, FORMATO, null);
    const fs = folhas(doc);
    expect(fs).toHaveLength(2);
    expect(fs[0].querySelector('h3')).toBeNull();
    expect(fs[1].querySelector('h3')?.textContent).toBe('Secção 2');
    expect(fs[1].querySelectorAll('tbody tr')).toHaveLength(5);
    // a casca vazia da tabela não fica na 1.ª folha
    expect(fs[0].querySelector('table')).toBeNull();
  });

  it('linhas de grupo (tr.imp-grupo) não ficam sozinhas no fim da folha', () => {
    const corpo = `${linhasTabela(17)}<tr class="imp-grupo" data-h="20"><td colspan="2">Departamento 2</td></tr>${linhasTabela(10)}`;
    const doc = documento(`<table><thead data-h="30"><tr><th>A</th><th>B</th></tr></thead><tbody>${corpo}</tbody></table>`);
    paginarDocumento(doc, FORMATO, null);
    const fs = folhas(doc);
    const ultima1 = Array.from(fs[0].querySelectorAll('tbody tr')).pop()!;
    expect(ultima1.classList.contains('imp-grupo')).toBe(false);
    expect(fs[1].querySelector('tbody tr')?.classList.contains('imp-grupo')).toBe(true);
  });

  it('respeita as quebras pedidas (.imp-quebra-pagina) e não deixa uma folha final vazia', () => {
    const doc = documento(`<div class="imp-quebra-pagina"><p data-h="50">Recibo 1</p></div><div class="imp-quebra-pagina"><p data-h="50">Recibo 2</p></div>`);
    const { paginas } = paginarDocumento(doc, FORMATO, null);
    expect(paginas).toBe(2);
    expect(folhas(doc).map((f) => f.querySelector('p')?.textContent)).toEqual(['Recibo 1', 'Recibo 2']);
  });

  it('um bloco indivisível mais alto do que uma folha é reduzido (zoom) para caber', () => {
    const doc = documento(`<img data-h="900" alt="organigrama" src="data:image/png;base64,AAAA">`);
    paginarDocumento(doc, FORMATO, null);
    const img = doc.querySelector('img') as HTMLImageElement;
    const zoom = Number(img.dataset.impZoom);
    expect(zoom).toBeGreaterThan(0.25);
    expect(900 * zoom).toBeLessThanOrEqual(ALTURA_CORPO);
    expect(folhas(doc)).toHaveLength(1);
  });

  it('documento curto: uma folha, com o rodapé e «Página 1 de 1»', () => {
    const doc = documento('<p data-h="40">Curto</p>', { rodape: null });
    const { paginas } = paginarDocumento(doc, FORMATO, null);
    expect(paginas).toBe(1);
    expect(doc.querySelector('.imp-pagina-numero')?.textContent).toBe('Página 1 de 1');
  });
});

describe('@page e estilos do documento paginado', () => {
  it('paginado: só tamanho e margens (sem caixas de margem, que o Firefox ignora)', () => {
    const css = cssPagina({ ...FORMATO_PADRAO, papel: 'A3', orientacao: 'paisagem' }, 'Rodapé', true);
    expect(css).toContain('size: A3 landscape');
    expect(css).toContain('margin: 10mm 10mm 8mm 10mm');
    expect(css).not.toContain('counter(page)');
    expect(css).not.toContain('@bottom');
  });

  it('altura útil de cada folha (papel − margens − desconto de segurança)', () => {
    expect(alturaPaginaMm({ papel: 'A4', orientacao: 'retrato' })).toBe(278.2);
    expect(alturaPaginaMm({ papel: 'A4', orientacao: 'paisagem' })).toBe(191.2);
    expect(alturaPaginaMm({ papel: 'A3', orientacao: 'paisagem' })).toBe(278.2);
    expect(alturaPaginaMm({ papel: 'A3', orientacao: 'retrato' })).toBe(401.2);
  });

  it('CSS das folhas: altura fixa, quebra depois de cada folha e rodapé no fundo', () => {
    const html = construirDocumento({ titulo: 'T', conteudo: '' });
    expect(html).toContain('.imp-pagina:not(:last-child) { break-after: page;');
    expect(html).toMatch(/\.imp-pagina \{ position: relative; width: var\(--imp-largura-pagina/);
    expect(html).toMatch(/\.imp-pagina-rodape \{ position: absolute;[^}]*bottom: 0/);
  });

  it('as regras @media print do ecrã passam a valer na medição e as @media screen deixam de valer', () => {
    const css = '<style>@media print { .a { display: none } } @media screen and (max-width: 600px) { .b { color: red } }</style><link rel="stylesheet" href="x.css" media="print">';
    const r = estilosParaImpressao(css);
    expect(r).toContain('@media all { .a');
    expect(r).toContain('@media not all and (max-width: 600px)');
    expect(r).toContain('media="all"');
  });
  it('vias de um recibo (.imp-uma-folha): nunca se partem, mesmo numa folha vazia — são reduzidas para caber', () => {
    const doc = documento('<div class="imp-vias imp-uma-folha"><section class="imp-via"><div data-h="380"></div></section><div class="imp-corte" data-h="20"></div><section class="imp-via"><div data-h="380"></div></section></div>');
    const { paginas } = paginarDocumento(doc, FORMATO, null);
    expect(paginas).toBe(1);
    const vias = doc.querySelector<HTMLElement>('.imp-pagina .imp-vias')!;
    expect(vias.querySelectorAll('.imp-via')).toHaveLength(2);
    expect(Number(vias.style.getPropertyValue('zoom'))).toBeLessThan(1);
  });

  it('vários recibos em vias: um por folha', () => {
    const via = '<section class="imp-via"><div data-h="150"></div></section>';
    const bloco = (q: boolean) => `<div class="imp-vias imp-uma-folha${q ? ' imp-quebra-pagina' : ''}">${via}<div class="imp-corte" data-h="10"></div>${via}</div>`;
    const doc = documento(bloco(true) + bloco(true) + bloco(false));
    const { paginas } = paginarDocumento(doc, FORMATO, null);
    expect(paginas).toBe(3);
    folhas(doc).forEach((f) => expect(f.querySelectorAll('.imp-via')).toHaveLength(2));
  });
});
