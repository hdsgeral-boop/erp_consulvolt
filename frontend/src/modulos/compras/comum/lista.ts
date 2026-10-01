import { obter } from '@/api/cliente';
import type { Pagina } from '@/api/tipos';

/**
 * Formato das listagens de /api/compras (ComprasController::listar): o envelope traz
 * `dados = { itens, total, pagina, por_pagina }` em vez de `metadados.paginacao`.
 */
export interface ListaCompras<T> {
  itens: T[];
  total: number;
  pagina: number;
  por_pagina: number;
}

/** Converte a lista de Compras para o formato `Pagina<T>` usado pelas tabelas. */
export function paraPagina<T>(r: ListaCompras<T> | null | undefined): Pagina<T> {
  const itens = r?.itens ?? [];
  const porPagina = r?.por_pagina || itens.length || 1;
  const total = r?.total ?? itens.length;
  return {
    itens,
    paginacao: { pagina_atual: r?.pagina ?? 1, por_pagina: porPagina, total, ultima_pagina: Math.max(1, Math.ceil(total / porPagina)) },
  };
}

export async function obterLista<T>(url: string, params?: Record<string, unknown>): Promise<Pagina<T>> {
  return paraPagina(await obter<ListaCompras<T>>(url, params));
}

/** Filtro local por texto (sem acentos, sem maiúsculas) sobre vários campos. */
export function contemTexto(pesquisa: string, ...campos: (string | number | null | undefined)[]): boolean {
  const p = normalizar(pesquisa.trim());
  if (!p) return true;
  return campos.some((c) => c !== null && c !== undefined && normalizar(String(c)).includes(p));
}

export function normalizar(texto: string): string {
  return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}
