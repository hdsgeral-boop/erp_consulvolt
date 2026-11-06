/**
 * Nome de utilizador de contas NOVAS — ESPELHO de App\Rules\NomeUtilizador (servidor, a autoridade): 3 a 50 caracteres,
 * letras a-z sem acento, algarismos e os sinais . _ - (nunca no início, no fim nem repetidos), sem «@», fora da lista de
 * reservados. Maiúsculas são aceites e gravadas em minúsculas.
 */
export const NOME_UTILIZADOR_MINIMO = 3;
export const NOME_UTILIZADOR_MAXIMO = 50;

export const NOMES_RESERVADOS = [
  'admin', 'administrador', 'administrator', 'root', 'suporte', 'support', 'sistema', 'system', 'superadmin', 'superadministrador',
  'plataforma', 'consulvolt', 'api', 'www', 'null', 'undefined', 'anonymous', 'anonimo', 'nobody', 'guest', 'convidado', 'test', 'teste',
];

export function normalizarNomeUtilizador(v: string | null | undefined): string {
  return (v ?? '').trim().toLowerCase();
}

/** Mensagem do problema, ou null se o nome é aceite. */
export function problemaNomeUtilizador(valor: string | null | undefined): string | null {
  const v = normalizarNomeUtilizador(valor);
  if (v.length < NOME_UTILIZADOR_MINIMO || v.length > NOME_UTILIZADOR_MAXIMO) {
    return `O nome de utilizador deve ter entre ${NOME_UTILIZADOR_MINIMO} e ${NOME_UTILIZADOR_MAXIMO} caracteres.`;
  }
  if (!/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/.test(v)) {
    return 'Use só letras sem acentos, algarismos e os sinais . _ - (sem espaços nem @, e sem sinais no início, no fim ou repetidos).';
  }
  if (NOMES_RESERVADOS.includes(v)) return 'Este nome de utilizador está reservado. Escolha outro.';
  return null;
}
