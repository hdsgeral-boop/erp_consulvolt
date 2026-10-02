import { Fragment, isValidElement, type ReactNode } from 'react';

/**
 * Texto simples de um ReactNode (para imprimir células de tabelas a partir dos `render` das colunas).
 * Percorre elementos HTML e fragmentos; componentes (ex.: <EstadoTag/>, <Tag/>) são resolvidos por
 * `renderToStaticMarkup` (carregado só quando é preciso) e, se falharem (ex.: precisam de contexto), pelo texto
 * dos filhos/propriedades.
 */

type Renderizador = (no: ReactNode) => string;

function percorrer(no: ReactNode, componente: (el: ReactNode) => string): string {
  if (no === null || no === undefined || typeof no === 'boolean') return '';
  if (typeof no === 'string' || typeof no === 'number' || typeof no === 'bigint') return String(no);
  if (Array.isArray(no)) return no.map((n) => percorrer(n as ReactNode, componente)).join('');
  if (isValidElement(no)) {
    const props = (no.props ?? {}) as { children?: ReactNode };
    if (typeof no.type === 'string' || no.type === Fragment) {
      const t = percorrer(props.children, componente);
      return no.type === 'br' ? '\n' : t;
    }
    return componente(no);
  }
  if (typeof no === 'object' && Symbol.iterator in (no as object)) return Array.from(no as Iterable<ReactNode>).map((n) => percorrer(n, componente)).join('');
  return '';
}

function textoDosFilhos(no: ReactNode): string {
  return percorrer(no, (el) => {
    if (!isValidElement(el)) return '';
    const p = (el.props ?? {}) as Record<string, unknown>;
    const filhos = percorrer(p.children as ReactNode, (x) => textoDosFilhos(x));
    if (filhos) return filhos;
    for (const k of ['title', 'label', 'text', 'value', 'estado']) if (typeof p[k] === 'string' || typeof p[k] === 'number') return String(p[k]);
    return '';
  });
}

let renderizador: Renderizador | null = null;
let modelo: HTMLTemplateElement | null = null;

/** Carrega o `react-dom/server` (só na primeira impressão). */
export async function prepararTexto(): Promise<void> {
  if (renderizador) return;
  try {
    const servidor = await import('react-dom/server');
    renderizador = (no) => {
      // O React 18 avisa «useLayoutEffect does nothing on the server» por cada componente Ant Design: é esperado aqui.
      const erroOriginal = console.error;
      console.error = (...a: unknown[]) => {
        if (typeof a[0] === 'string' && a[0].includes('useLayoutEffect')) return;
        erroOriginal(...a);
      };
      let markup: string;
      try {
        markup = servidor.renderToStaticMarkup(no as Parameters<typeof servidor.renderToStaticMarkup>[0]);
      } finally {
        console.error = erroOriginal;
      }
      modelo ??= document.createElement('template');
      modelo.innerHTML = markup;
      return modelo.content.textContent ?? '';
    };
  } catch {
    renderizador = null;
  }
}

/** Texto de um nó React; usa o renderizador estático para componentes, se já carregado (`prepararTexto`). */
export function textoDeNo(no: ReactNode): string {
  const r = percorrer(no, (el) => {
    if (renderizador) {
      try {
        return renderizador(el);
      } catch {
        /* componente que precisa de contexto: usa o texto dos filhos */
      }
    }
    return textoDosFilhos(el);
  });
  return r.replace(/[ \t ]+/g, ' ').trim();
}
