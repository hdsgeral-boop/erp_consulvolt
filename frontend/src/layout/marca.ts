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

const PAPEIS: Readonly<Record<string, string>> = {
  SUPER_ADMINISTRADOR: 'Super Admin',
  ADMINISTRADOR: 'Administrador',
  UTILIZADOR: 'Utilizador',
};

/** Papel do utilizador para a barra superior (como no sistema anterior: «Super Admin» por baixo do nome). */
export function rotuloPapel(papel: string | null | undefined): string | null {
  if (!papel) return null;
  return PAPEIS[papel.toUpperCase()] ?? papel.charAt(0).toUpperCase() + papel.slice(1).toLowerCase().replace(/_/g, ' ');
}
