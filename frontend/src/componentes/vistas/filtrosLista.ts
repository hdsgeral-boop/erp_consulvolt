import { lerNumeroPt } from '../impressao/excel';
import { chaveColuna, eColunaAccoes, folhas, textoColuna, tituloTexto, type ColunaLegado, type FolhaLegado } from './colunasTabela';

/**
 * Filtros das listas, como no sistema anterior (js/shared/tabelas_padrao.js e o preInit das DataTables):
 * - caixa por coluna («contém» ao escrever, sem acentos nem maiúsculas);
 * - funil da coluna / «Filtro personalizado»: Contém, Não contém, Começa com, Termina com, Igual a, Diferente de,
 *   Maior que (>), Menor que (<).
 * Tudo no cliente e sempre como TEXTO (o valor escrito nunca é interpretado como código nem como expressão regular).
 */
export type OperadorFiltro = 'contains' | 'not_contains' | 'starts_with' | 'ends_with' | 'equals' | 'not_equals' | 'gt' | 'lt';

export const OPERADORES_FILTRO: Readonly<Record<OperadorFiltro, string>> = {
  contains: 'Contém',
  not_contains: 'Não contém',
  starts_with: 'Começa com',
  ends_with: 'Termina com',
  equals: 'Igual a',
  not_equals: 'Diferente de',
  gt: 'Maior que (>)',
  lt: 'Menor que (<)',
};

/** Rótulo curto (mostrado na caixa da coluna quando o filtro vem do funil, como no antigo: «Igual a: X»). */
export const OPERADORES_CURTOS: Readonly<Record<OperadorFiltro, string>> = {
  contains: 'Contém',
  not_contains: 'Não contém',
  starts_with: 'Começa com',
  ends_with: 'Termina com',
  equals: 'Igual a',
  not_equals: 'Diferente de',
  gt: '>',
  lt: '<',
};

export interface CondicaoFiltro {
  operador: OperadorFiltro;
  valor: string;
}

/** Condição do «Filtro personalizado» (construtor de condições). */
export interface CondicaoPersonalizada extends CondicaoFiltro {
  /** Chave da coluna (`chaveColuna`). */
  coluna: string;
}

export interface FiltroPersonalizado {
  /** `todas` = E (todas as condições); `qualquer` = OU. */
  juncao: 'todas' | 'qualquer';
  condicoes: CondicaoPersonalizada[];
}

export const FILTRO_PERSONALIZADO_VAZIO: FiltroPersonalizado = { juncao: 'todas', condicoes: [] };

/** Máximo de caracteres de um valor de filtro (evita textos enormes colados por engano). */
export const MAX_VALOR_FILTRO = 200;

export const normalizarTexto = (s: unknown): string =>
  String(s ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/\s+/g, ' ')
    .trim();

/** Número em texto pt-PT («1 234,50 Kz», «-12,5», «1234.5»). */
export function numeroTexto(s: string): number {
  const lido = lerNumeroPt(s);
  if (lido) return lido.valor;
  const t = String(s).replace(/[^\d,.-]/g, '');
  if (!t) return NaN;
  return parseFloat(t.includes(',') ? t.replace(/\./g, '').replace(',', '.') : t);
}

/** A célula (texto) satisfaz a condição? Valor vazio = sem filtro. */
export function passaCondicao(texto: string, c: CondicaoFiltro): boolean {
  const q = normalizarTexto(String(c.valor).slice(0, MAX_VALOR_FILTRO));
  if (!q) return true;
  const v = normalizarTexto(texto);
  switch (c.operador) {
    case 'not_contains':
      return !v.includes(q);
    case 'starts_with':
      return v.startsWith(q);
    case 'ends_with':
      return v.endsWith(q);
    case 'equals':
      return v === q;
    case 'not_equals':
      return v !== q;
    case 'gt':
    case 'lt': {
      const a = numeroTexto(texto);
      const b = numeroTexto(c.valor);
      if (Number.isNaN(a) || Number.isNaN(b)) return false;
      return c.operador === 'gt' ? a > b : a < b;
    }
    default:
      return v.includes(q);
  }
}

/** Colunas que levam caixa de filtro (com título, não de acções, sem `filtravel: false`). */
export function colunaFiltravel<T>(c: FolhaLegado<T>): boolean {
  if (c.filtravel === false || c.hidden) return false;
  if (eColunaAccoes(c)) return false;
  return !!tituloTexto(c) || c.filtravel === true;
}

/** Texto de uma célula para filtrar. */
export function textoFiltro<T>(c: FolhaLegado<T>, linha: T, i: number): string {
  if (c.valorFiltro) return c.valorFiltro(linha, i);
  const t = textoColuna(c, linha, i);
  return t === null || t === undefined ? '' : String(t);
}

export interface EstadoFiltros {
  /** Caixas por coluna (chave da coluna → condição). */
  colunas: Record<string, CondicaoFiltro>;
  personalizado: FiltroPersonalizado;
}

/** Há algum filtro activo (caixas ou personalizado)? `excluir` ignora as colunas filtradas no servidor. */
export function filtrosActivos(estado: EstadoFiltros, excluir: ReadonlySet<string> = new Set()): boolean {
  return (
    Object.entries(estado.colunas).some(([k, c]) => !excluir.has(k) && normalizarTexto(c.valor) !== '') ||
    estado.personalizado.condicoes.some((c) => normalizarTexto(c.valor) !== '')
  );
}

/**
 * Aplica os filtros às linhas (no cliente). `excluir` = colunas cujo filtro vai para o servidor (TabelaApi).
 * Devolve as linhas que passam, pela ordem original (sem filtros activos, a própria lista recebida).
 */
export function aplicarFiltros<T>(linhas: readonly T[], colunas: readonly ColunaLegado<T>[], estado: EstadoFiltros, excluir: ReadonlySet<string> = new Set()): readonly T[] {
  // sem filtros devolve a MESMA lista (identidade estável: a tabela não recalcula nada)
  if (!filtrosActivos(estado, excluir)) return linhas;
  const fs = folhas(colunas);
  const porChave = new Map(fs.map((c, i) => [chaveColuna(c, i), c] as const));
  const caixas = Object.entries(estado.colunas)
    .filter(([k, c]) => !excluir.has(k) && normalizarTexto(c.valor) !== '' && porChave.has(k))
    .map(([k, c]) => ({ col: porChave.get(k)!, c }));
  const pers = estado.personalizado.condicoes.filter((c) => normalizarTexto(c.valor) !== '' && porChave.has(c.coluna)).map((c) => ({ col: porChave.get(c.coluna)!, c }));
  return linhas.filter((linha, i) => {
    if (!caixas.every(({ col, c }) => passaCondicao(textoFiltro(col, linha, i), c))) return false;
    if (!pers.length) return true;
    const r = pers.map(({ col, c }) => passaCondicao(textoFiltro(col, linha, i), c));
    return estado.personalizado.juncao === 'qualquer' ? r.some(Boolean) : r.every(Boolean);
  });
}

/** Valida o filtro personalizado (guardado/recebido): só operadores conhecidos e textos limitados. */
export function normalizarFiltroPersonalizado(v: unknown): FiltroPersonalizado {
  if (!v || typeof v !== 'object') return FILTRO_PERSONALIZADO_VAZIO;
  const o = v as { juncao?: unknown; condicoes?: unknown };
  const condicoes = Array.isArray(o.condicoes)
    ? o.condicoes
        .filter((c): c is Record<string, unknown> => !!c && typeof c === 'object')
        .filter((c) => typeof c.coluna === 'string' && typeof c.operador === 'string' && c.operador in OPERADORES_FILTRO)
        .slice(0, 20)
        .map((c) => ({ coluna: String(c.coluna).slice(0, 120), operador: c.operador as OperadorFiltro, valor: String(c.valor ?? '').slice(0, MAX_VALOR_FILTRO) }))
    : [];
  return { juncao: o.juncao === 'qualquer' ? 'qualquer' : 'todas', condicoes };
}
