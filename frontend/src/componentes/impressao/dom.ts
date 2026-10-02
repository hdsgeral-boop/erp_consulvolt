import { esc } from './documento';

/**
 * Impressão de um nó DOM do ecrã (organigramas, gráficos, demonstrações, recibos): clona o elemento, retira o que
 * não se imprime e normaliza as tabelas Ant Design (cabeçalho fixo, colunas fixas, scroll). O original não é tocado.
 */

/** Elementos que nunca vão para o papel. */
export const SELECTOR_NAO_IMPRIMIR = [
  'script',
  'noscript',
  'template',
  'button',
  '.ant-btn',
  '.no-print',
  '.imp-nao-imprimir',
  '.imp-so-ecra',
  '.rh-nao-imprimir',
  '.sem-impressao',
  '.ant-pagination',
  '.ant-table-pagination',
  '.ant-table-sticky-scroll',
  '.ant-table-measure-row',
  '.ant-table-filter-trigger',
  '.ant-table-column-sorter',
  '.ant-tabs-nav',
  '.ant-slider',
  '.ant-tooltip',
  '.ant-popover',
].join(',');

export interface OpcoesClone {
  /** Ajustes ao clone antes de serializar (ex.: retirar um título que passa para o cabeçalho do documento). */
  aoClonar?: (clone: HTMLElement) => void;
}

/** Junta as tabelas Ant Design de cabeçalho fixo (duas tabelas: .ant-table-header + .ant-table-body) numa só. */
function juntarTabelasAnt(raiz: HTMLElement): void {
  raiz.querySelectorAll<HTMLElement>('.ant-table-container').forEach((cont) => {
    const cabecalho = cont.querySelector<HTMLElement>(':scope > .ant-table-header');
    const corpo = cont.querySelector<HTMLElement>(':scope > .ant-table-body');
    const tabelaCorpo = corpo?.querySelector('table');
    const thead = cabecalho?.querySelector('thead');
    if (cabecalho && tabelaCorpo && thead) {
      tabelaCorpo.querySelector('thead')?.remove();
      tabelaCorpo.insertBefore(thead, tabelaCorpo.querySelector('tbody'));
      cabecalho.remove();
    }
    const resumo = cont.querySelector<HTMLElement>(':scope > .ant-table-summary');
    const tfoot = resumo?.querySelector('tfoot');
    if (resumo && tabelaCorpo && tfoot) {
      tabelaCorpo.appendChild(tfoot);
      resumo.remove();
    }
  });
  // Larguras de colunas medidas no ecrã (e a coluna da barra de deslocação) não servem no papel.
  raiz.querySelectorAll('.ant-table colgroup').forEach((c) => c.remove());
  raiz.querySelectorAll<HTMLElement>('.ant-table table').forEach((t) => {
    t.style.removeProperty('table-layout');
    t.style.removeProperty('min-width');
  });
  raiz.querySelectorAll<HTMLElement>('.ant-table-cell-fix-left, .ant-table-cell-fix-right, .ant-table-cell-scrollbar').forEach((c) => {
    if (c.classList.contains('ant-table-cell-scrollbar')) c.remove();
    else {
      c.style.removeProperty('position');
      c.style.removeProperty('left');
      c.style.removeProperty('right');
    }
  });
}

/** Canvas não se clonam com o desenho: passam a imagem. */
function canvasParaImagem(original: Element, clone: HTMLElement): void {
  const origem = Array.from(original.querySelectorAll('canvas'));
  const destino = Array.from(clone.querySelectorAll('canvas'));
  destino.forEach((c, i) => {
    const o = origem[i];
    try {
      const img = clone.ownerDocument.createElement('img');
      img.src = o.toDataURL('image/png');
      img.style.width = o.style.width || `${o.width}px`;
      img.style.maxWidth = '100%';
      c.replaceWith(img);
    } catch {
      c.remove();
    }
  });
}

/** Campos de formulário → texto (o valor actual). */
function camposParaTexto(original: Element, clone: HTMLElement): void {
  const origem = Array.from(original.querySelectorAll<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>('input, textarea, select'));
  Array.from(clone.querySelectorAll('input, textarea, select')).forEach((c, i) => {
    const o = origem[i];
    if (!o || (o instanceof HTMLInputElement && ['hidden', 'checkbox', 'radio', 'file'].includes(o.type))) {
      if (o instanceof HTMLInputElement && (o.type === 'checkbox' || o.type === 'radio')) c.replaceWith(clone.ownerDocument.createTextNode(o.checked ? '☑' : '☐'));
      else c.remove();
      return;
    }
    const span = clone.ownerDocument.createElement('span');
    span.textContent = o instanceof HTMLSelectElement ? (o.selectedOptions[0]?.text ?? '') : o.value;
    c.replaceWith(span);
  });
}

/** Clona e limpa um elemento do ecrã, devolvendo o HTML a imprimir. */
export function clonarParaImpressao(elemento: Element, opcoes: OpcoesClone = {}): string {
  const clone = elemento.cloneNode(true) as HTMLElement;
  canvasParaImagem(elemento, clone);
  camposParaTexto(elemento, clone);
  clone.querySelectorAll(SELECTOR_NAO_IMPRIMIR).forEach((e) => e.remove());
  juntarTabelasAnt(clone);
  // Zoom de ecrã (ex.: organigrama) e alturas fixas com scroll não passam para o papel.
  [clone, ...Array.from(clone.querySelectorAll<HTMLElement>('[style]'))].forEach((e) => {
    if (!e.style) return;
    if (/scale\(/.test(e.style.transform)) e.style.removeProperty('transform');
    if (e.style.overflow || e.style.overflowX || e.style.overflowY) {
      e.style.removeProperty('overflow');
      e.style.removeProperty('overflow-x');
      e.style.removeProperty('overflow-y');
    }
    e.style.removeProperty('max-height');
  });
  clone.style.removeProperty('width');
  clone.style.removeProperty('max-width');
  opcoes.aoClonar?.(clone);
  return clone.outerHTML;
}

/**
 * Folhas de estilo do ecrã (Ant Design CSS-in-JS, CSS dos módulos — incluindo <style> no corpo, como o do organigrama)
 * como HTML para o <head> do documento impresso. Os <link> passam com URL absoluto (a iframe é about:blank).
 */
export function estilosDaPagina(doc: Document = document): string {
  const partes: string[] = [];
  doc.querySelectorAll('style, link[rel="stylesheet"]').forEach((n) => {
    if (n instanceof HTMLLinkElement) {
      if (n.href && !n.disabled) partes.push(`<link rel="stylesheet" href="${esc(n.href)}"${n.media ? ` media="${esc(n.media)}"` : ''}>`);
    } else if (n instanceof HTMLStyleElement) {
      const css = n.textContent ?? '';
      if (css.trim()) partes.push(`<style>${css.replace(/<\/style/gi, '<\\/style')}</style>`);
    }
  });
  return partes.join('\n');
}

/** Maior número de colunas numa linha de tabela (primeiras 30 linhas de cada tabela). */
export function contarColunas(raiz: ParentNode): number {
  let max = 0;
  raiz.querySelectorAll('table').forEach((t) => {
    Array.from(t.rows).slice(0, 30).forEach((tr) => {
      const n = Array.from(tr.cells).reduce((s, c) => s + (c.colSpan || 1), 0);
      if (n > max) max = n;
    });
  });
  return max;
}
