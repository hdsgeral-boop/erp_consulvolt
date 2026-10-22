/**
 * Palavras-passe e nomes de utilizador (pedido de 2026-10-22). A regra de força é a mesma do servidor
 * (ServicoUtilizadores::problemaDeForca): pelo menos 8 caracteres, com maiúscula, minúscula e algarismo.
 */
export const MINIMO_PALAVRA_PASSE = 8;

/** Mensagem do problema, ou null se a palavra-passe é aceite. */
export function problemaPalavraPasse(p: string): string | null {
  if (p.length < MINIMO_PALAVRA_PASSE) return `Pelo menos ${MINIMO_PALAVRA_PASSE} caracteres.`;
  if (!/\p{Lu}/u.test(p) || !/\p{Ll}/u.test(p) || !/\d/.test(p)) return 'Fraca: use maiúsculas, minúsculas e algarismos.';
  return null;
}

const MAIUSCULAS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
const MINUSCULAS = 'abcdefghijkmnpqrstuvwxyz';
const ALGARISMOS = '23456789';
const SIMBOLOS = '#@$%&*!?';

function aleatorio(n: number): number {
  const a = new Uint32Array(1);
  crypto.getRandomValues(a);
  return a[0] % n;
}

/** Palavra-passe forte e legível (sem 0/O, 1/l/I), com pelo menos um carácter de cada grupo. */
export function gerarPalavraPasse(tamanho = 12): string {
  const grupos = [MAIUSCULAS, MINUSCULAS, ALGARISMOS, SIMBOLOS];
  const todos = grupos.join('');
  const chars = grupos.map((g) => g[aleatorio(g.length)]);
  while (chars.length < tamanho) chars.push(todos[aleatorio(todos.length)]);
  for (let i = chars.length - 1; i > 0; i--) {
    const j = aleatorio(i + 1);
    [chars[i], chars[j]] = [chars[j], chars[i]];
  }
  return chars.join('');
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
