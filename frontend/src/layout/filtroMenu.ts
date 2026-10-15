import type { MenuProps } from 'antd';

/**
 * Busca de operações na árvore do menu (Versão Actual, js/busca_menu.js): filtro em linha à medida que se escreve.
 *
 * - sem acentos e sem maiúsculas/minúsculas («reconciliacao» encontra «Reconciliação»);
 * - procura no rótulo do ecrã E no caminho dos grupos acima («tesouraria» traz tudo o que está dentro de Tesouraria);
 *   com várias palavras, todas têm de aparecer (no rótulo ou no caminho) — inclui a pesquisa por texto contíguo do legado;
 * - só filtra os itens que o menu já tem: os ecrãs escondidos por permissões nunca chegam aqui, por isso nunca são
 *   revelados nem contados;
 * - devolve os grupos a abrir (os que contêm resultados) e a lista ordenada dos resultados (setas e Enter).
 */
export type ItemMenu = NonNullable<MenuProps['items']>[number];

export interface ResultadoFiltroMenu {
  itens: ItemMenu[];
  /** chaves dos grupos que contêm resultados (abertos durante a busca) */
  abertos: string[];
  /** resultados (ecrãs), pela ordem do menu */
  folhas: { key: string; rotulo: string }[];
}

/** Texto sem acentos, em minúsculas e com os espaços normalizados. */
export function normalizarTexto(t: string): string {
  return t
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/\s+/g, ' ')
    .trim();
}

/** Palavras da busca (normalizadas). */
export const palavrasBusca = (termo: string) => normalizarTexto(termo).split(' ').filter(Boolean);

interface No {
  key?: string | number | bigint;
  label?: unknown;
  title?: unknown;
  children?: ItemMenu[];
  type?: string;
  className?: string;
}

export const rotuloItem = (x: No): string => (typeof x.label === 'string' ? x.label : typeof x.title === 'string' ? x.title : '');

/**
 * Filtra a árvore do menu. `focado` (chave de um resultado) recebe a classe `erp-menu-focado` (navegação por setas).
 * `rotular` permite trocar o rótulo dos resultados (realce do texto encontrado).
 */
export function filtrarMenu(itens: ItemMenu[], termo: string, focado?: string | null, rotular?: (rotulo: string) => unknown): ResultadoFiltroMenu {
  const palavras = palavrasBusca(termo);
  if (!palavras.length) return { itens, abertos: [], folhas: [] };
  const abertos: string[] = [];
  const folhas: { key: string; rotulo: string }[] = [];
  const visitar = (lista: ItemMenu[], caminho: string[]): ItemMenu[] =>
    lista.flatMap((it) => {
      if (!it || typeof it !== 'object') return [];
      const x = it as No;
      if (x.type === 'divider' || x.type === 'group') return [];
      const rotulo = rotuloItem(x);
      if (x.children) {
        const filhos = visitar(x.children, [...caminho, rotulo]);
        if (!filhos.length) return [];
        abertos.push(String(x.key));
        return [{ ...x, children: filhos } as ItemMenu];
      }
      const texto = normalizarTexto([...caminho, rotulo].join(' '));
      if (!palavras.every((p) => texto.includes(p))) return [];
      const key = String(x.key);
      folhas.push({ key, rotulo });
      const classe = [x.className, key === focado ? 'erp-menu-focado' : null].filter(Boolean).join(' ') || undefined;
      return [{ ...x, className: classe, ...(rotular && typeof x.label === 'string' ? { label: rotular(x.label) } : {}) } as ItemMenu];
    });
  return { itens: visitar(itens, []), abertos, folhas };
}

/**
 * Posição do primeiro pedaço do rótulo que corresponde a uma das palavras (sem acentos), para o realce.
 * Cada carácter do rótulo é normalizado à parte, para os índices baterem com o texto original.
 */
export function posicaoRealce(rotulo: string, termo: string): { inicio: number; fim: number } | null {
  const palavras = palavrasBusca(termo);
  if (!palavras.length) return null;
  let normal = '';
  const origem: number[] = [];
  for (let i = 0; i < rotulo.length; i++) {
    const n = rotulo[i].normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    for (const c of n) {
      normal += c;
      origem.push(i);
    }
  }
  for (const p of palavras) {
    const k = normal.indexOf(p);
    if (k >= 0) return { inicio: origem[k], fim: origem[k + p.length - 1] + 1 };
  }
  return null;
}

/** Índice seguinte nas setas (circular; -1 = nenhum seleccionado). */
export function moverFoco(actual: number, passo: 1 | -1, total: number): number {
  if (total <= 0) return -1;
  if (actual < 0) return passo > 0 ? 0 : total - 1;
  return (actual + passo + total) % total;
}

/** Texto do contador (aria-live). */
export function textoContador(n: number): string {
  if (n === 0) return 'Nada encontrado. Experimente outra palavra.';
  return `${n} ${n === 1 ? 'operação encontrada' : 'operações encontradas'} · Enter abre ${n === 1 ? 'a operação' : 'a primeira'}`;
}

/** Retira os ícones de todos os itens (aspecto «só nomes»). */
export function semIcones(itens: ItemMenu[]): ItemMenu[] {
  return itens.map((it) => {
    if (!it || typeof it !== 'object') return it;
    const x = it as No & { icon?: unknown };
    const { icon: _icon, ...resto } = x;
    void _icon;
    return (x.children ? { ...resto, children: semIcones(x.children) } : resto) as ItemMenu;
  });
}
