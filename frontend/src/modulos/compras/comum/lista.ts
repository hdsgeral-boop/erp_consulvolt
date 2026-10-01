/**
 * Utilitários das listas de Compras. As listagens de /api/compras usam desde a afinação (ADR-064) o formato comum
 * `RespostaApi::paginado` (dados = lista, metadados.paginacao): lêem-se com `obterPagina` e mostram-se com `TabelaApi`.
 */

/** Filtro local por texto (sem acentos, sem maiúsculas) sobre vários campos. */
export function contemTexto(pesquisa: string, ...campos: (string | number | null | undefined)[]): boolean {
  const p = normalizar(pesquisa.trim());
  if (!p) return true;
  return campos.some((c) => c !== null && c !== undefined && normalizar(String(c)).includes(p));
}

export function normalizar(texto: string): string {
  return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}
