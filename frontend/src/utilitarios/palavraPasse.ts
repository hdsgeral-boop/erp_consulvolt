/**
 * Política das palavras-passe escolhidas por pessoas — ESPELHO de App\Support\Seguranca\PoliticaPalavraPasse (servidor,
 * que é a autoridade): mínimo de 8 caracteres (10 no registo de uma empresa), máximo de 128, maiúscula + minúscula +
 * algarismo, nunca uma palavra trivial (password, qwerty, 12345678…), sequências ou um só carácter repetido, e sem
 * conter o nome de utilizador nem a parte local do e-mail da própria conta. As palavras-passe provisórias são sempre
 * geradas pelo servidor; só a escolhida pelo utilizador (registo, troca obrigatória, «Gerir dados pessoais») passa por aqui.
 */
export const MINIMO_PALAVRA_PASSE = 8;
export const MINIMO_PALAVRA_PASSE_REGISTO = 10;
export const MAXIMO_PALAVRA_PASSE = 128;

const TRIVIAIS = [
  'password', 'passw0rd', 'palavrapasse', 'palavrapass', 'senha', 'senha123', 'qwerty', 'qwertyuiop', 'azerty', 'abc123',
  'admin', 'admin123', 'administrador', 'administrator', 'root', 'letmein', 'welcome', 'bemvindo', 'iloveyou', 'teste', 'test',
  'consulvolt', 'consulvoltrp', 'erp', 'erpconsulvolt', 'mudar123', 'mudaragora', 'trocar', 'login',
];

/** Só letras a-z e algarismos, sem acentos nem maiúsculas (a mesma «chave» do servidor). */
function chave(v: string): string {
  return v
    .toLowerCase()
    .normalize('NFD')
    .replace(/\p{Mn}+/gu, '')
    .replace(/[^a-z0-9]/g, '');
}

export function eTrivial(p: string): boolean {
  const c = chave(p);
  const sem = c.replace(/\d+$/, '');
  if (TRIVIAIS.includes(c) || TRIVIAIS.includes(sem)) return true;
  if (c !== '' && /^(.)\1+$/u.test(c)) return true;
  return (
    c.length >= 6 &&
    ['abcdefghijklmnopqrstuvwxyz', 'zyxwvutsrqponmlkjihgfedcba', '01234567890', '09876543210', 'qwertyuiopasdfghjklzxcvbnm'].some((s) => s.includes(c))
  );
}

/**
 * Mensagem do problema, ou null se a palavra-passe é aceite.
 * @param evitar textos da própria conta que não podem estar na palavra-passe (utilizador, e-mail)
 */
export function problemaPalavraPasse(p: string, evitar: Array<string | null | undefined> = [], minimo: number = MINIMO_PALAVRA_PASSE): string | null {
  if (p.length < minimo) return `Pelo menos ${minimo} caracteres.`;
  if (p.length > MAXIMO_PALAVRA_PASSE) return `No máximo ${MAXIMO_PALAVRA_PASSE} caracteres.`;
  if (!/\p{Lu}/u.test(p) || !/\p{Ll}/u.test(p) || !/\d/.test(p)) return 'Fraca: use maiúsculas, minúsculas e algarismos.';
  if (eTrivial(p)) return 'Demasiado comum ou previsível: escolha outra.';
  const c = chave(p);
  for (const t of evitar) {
    const k = chave(t ?? '');
    if (k.length >= 4 && c.includes(k)) return 'Não pode conter o seu nome de utilizador nem o seu e-mail.';
  }
  return null;
}

export interface ForcaPalavraPasse {
  /** 0 (vazia) a 4 (muito forte) */
  nivel: 0 | 1 | 2 | 3 | 4;
  rotulo: string;
  /** percentagem para a barra de progresso */
  percentagem: number;
  estado: 'exception' | 'normal' | 'success';
}

/** Indicador de força (só orientação visual; quem decide é `problemaPalavraPasse` e o servidor). */
export function forcaPalavraPasse(p: string, evitar: Array<string | null | undefined> = [], minimo: number = MINIMO_PALAVRA_PASSE): ForcaPalavraPasse {
  if (!p) return { nivel: 0, rotulo: '', percentagem: 0, estado: 'normal' };
  if (problemaPalavraPasse(p, evitar, minimo) !== null) {
    const curta = p.length < minimo;
    return { nivel: 1, rotulo: curta ? 'Curta' : 'Fraca', percentagem: 25, estado: 'exception' };
  }
  let pontos = 0;
  if (p.length >= minimo + 4) pontos++;
  if (p.length >= 16) pontos++;
  if (/[^\p{L}\d]/u.test(p)) pontos++;
  if (new Set(p).size >= Math.min(10, p.length * 0.7)) pontos++;
  if (pontos >= 3) return { nivel: 4, rotulo: 'Muito forte', percentagem: 100, estado: 'success' };
  if (pontos === 2) return { nivel: 3, rotulo: 'Forte', percentagem: 75, estado: 'success' };
  return { nivel: 2, rotulo: 'Razoável', percentagem: 50, estado: 'normal' };
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
