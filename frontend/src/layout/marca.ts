/** Palavras ignoradas nas iniciais (formas societárias e preposições). */
const IGNORADAS = new Set(['lda', 'ltd', 'limitada', 'sa', 's.a', 'sarl', 'su', 'unipessoal', 'e', 'de', 'da', 'do', 'das', 'dos', '&']);

/**
 * Iniciais (até 2 letras, maiúsculas) para o avatar de uma empresa sem logótipo.
 * «Demo E2E Comércio, Lda» → «DE»; «Consulvolt» → «CO»; «» → «?».
 */
export function iniciais(nome: string | null | undefined): string {
  const palavras = (nome ?? '')
    .split(/[\s,.;:/\\\-–—()]+/)
    .map((p) => p.trim())
    .filter((p) => p && !IGNORADAS.has(p.toLowerCase()));
  if (!palavras.length) return '?';
  const letra = (p: string) => (p.match(/[\p{L}\p{N}]/u)?.[0] ?? '').toUpperCase();
  if (palavras.length === 1) return palavras[0].replace(/[^\p{L}\p{N}]/gu, '').slice(0, 2).toUpperCase() || '?';
  return (letra(palavras[0]) + letra(palavras[1])) || '?';
}
