/**
 * Validação de força de palavras-passe e nomes de utilizador sugeridos. A regra de força é a do servidor
 * (ServicoUtilizadores::problemaDeForca, código PALAVRA_PASSE_FRACA): pelo menos 8 caracteres, com maiúscula,
 * minúscula e algarismo. Neste sistema a palavra-passe provisória é sempre gerada pelo servidor; só a escolhida pelo
 * próprio utilizador (troca obrigatória, Minha conta) passa por esta validação.
 */
export const MINIMO_PALAVRA_PASSE = 8;

/** Mensagem do problema, ou null se a palavra-passe é aceite. */
export function problemaPalavraPasse(p: string): string | null {
  if (p.length < MINIMO_PALAVRA_PASSE) return `Pelo menos ${MINIMO_PALAVRA_PASSE} caracteres.`;
  if (!/\p{Lu}/u.test(p) || !/\p{Ll}/u.test(p) || !/\d/.test(p)) return 'Fraca: use maiúsculas, minúsculas e algarismos.';
  return null;
}

/** Nome de utilizador sugerido a partir do nome completo: «Ana Maria Silva» → «ana.silva» (sem acentos). */
export function sugerirNomeUtilizador(nomeCompleto: string): string {
  const partes = nomeCompleto
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9\s]/g, ' ')
    .split(/\s+/)
    .filter(Boolean);
  if (partes.length === 0) return '';
  return (partes.length === 1 ? partes[0] : `${partes[0]}.${partes[partes.length - 1]}`).slice(0, 100);
}
