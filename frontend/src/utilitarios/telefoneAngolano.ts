/**
 * Telefone — ESPELHO de App\Support\Angola\TelefoneAngolano (servidor, a autoridade): angolano (9 dígitos começados por
 * 9 — móvel — ou 2 — fixo; «+244», «00244» ou «244» opcionais) ou internacional («+indicativo», 7 a 15 dígitos).
 */
export const MENSAGEM_TELEFONE =
  'Telefone inválido: use 9 dígitos começados por 9 (ex.: 923 000 111), com ou sem +244, ou um número internacional com «+» e o indicativo.';

export function normalizarTelefone(t: string | null | undefined): string {
  const v = (t ?? '').trim().replace(/[\s .\-()]+/gu, '');
  const ao = /^(?:\+|00)?244([29]\d{8})$/.exec(v);
  if (ao) return ao[1];
  if (v.startsWith('00') && /^00[1-9]\d{6,14}$/.test(v)) return `+${v.slice(2)}`;
  return v;
}

export function telefoneValido(t: string | null | undefined): boolean {
  const v = normalizarTelefone(t);
  return /^[29]\d{8}$/.test(v) || (/^\+[1-9]\d{6,14}$/.test(v) && !v.startsWith('+244'));
}
