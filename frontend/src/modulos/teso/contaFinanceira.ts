/**
 * Conta financeira dos pagamentos e recebimentos — só por bancos (Fase A, legado novo ui_tesouraria.js:653-678 e :861-877).
 * Desde 2026-10-07 os pagamentos e recebimentos só movimentam contas bancárias (42 depósitos a prazo, 43 depósitos à ordem);
 * o numerário (45) é exclusivo da Folha de Caixa. O meio CAIXA só aparece ao editar um documento anterior que já estava numa
 * conta 45 — os históricos não se mexem. Espelha ServicoDocumentosTesouraria::exigirContaBancaria (o servidor valida sempre).
 */

export type MeioTesouraria = 'BANCO' | 'PRAZO' | 'CAIXA';

/** Meio → prefixo da conta financeira (TESO_MEIO_PREFIXO do legado novo). */
export const PREFIXO_MEIO: Record<MeioTesouraria, string> = { BANCO: '43', PRAZO: '42', CAIXA: '45' };

export const MENSAGEM_SO_BANCOS = 'Os pagamentos e recebimentos só movimentam contas bancárias (42 depósitos a prazo, 43 depósitos à ordem). O numerário (45) passou para a Folha de Caixa.';

export const AVISO_DOCUMENTO_ANTERIOR = 'O numerário passou para a Folha de Caixa. Este documento é anterior e mantém-se como está.';

/** Documento já gravado numa conta de caixa (45): só ele pode continuar em caixa. */
export function documentoAnteriorEmCaixa(contaOriginal: string | null | undefined): boolean {
  return String(contaOriginal ?? '').startsWith(PREFIXO_MEIO.CAIXA);
}

/** Meio de uma conta (ao abrir um documento): 42 → prazo, 45 → caixa, o resto → depósitos à ordem. */
export function meioDaConta(conta: string | null | undefined): MeioTesouraria {
  const c = String(conta ?? '');
  if (c.startsWith(PREFIXO_MEIO.PRAZO)) return 'PRAZO';
  if (c.startsWith(PREFIXO_MEIO.CAIXA)) return 'CAIXA';
  return 'BANCO';
}

/** Opções do campo «Meio»: depósitos à ordem e a prazo; caixa só para o documento anterior em 45. */
export function opcoesMeio(contaOriginal: string | null | undefined): { value: MeioTesouraria; label: string }[] {
  return [
    { value: 'BANCO', label: 'Depósitos à ordem (43)' },
    { value: 'PRAZO', label: 'Depósitos a prazo (42)' },
    ...(documentoAnteriorEmCaixa(contaOriginal) ? [{ value: 'CAIXA' as const, label: 'Caixa (45) — documento anterior' }] : []),
  ];
}

/** A conta pode ser gravada? 42/43 sempre; 45 só se o documento (editado, não copiado) já estava em 45. */
export function contaFinanceiraPermitida(conta: string | null | undefined, contaOriginal: string | null | undefined): boolean {
  const c = String(conta ?? '');
  if (/^4[23]/.test(c)) return true;
  return c.startsWith(PREFIXO_MEIO.CAIXA) && documentoAnteriorEmCaixa(contaOriginal);
}

/** Conta a pré-preencher numa cópia: um documento novo não pode nascer em caixa (fica por escolher). */
export function contaParaCopia(conta: string | null | undefined): string | undefined {
  return conta && contaFinanceiraPermitida(conta, null) ? conta : undefined;
}
