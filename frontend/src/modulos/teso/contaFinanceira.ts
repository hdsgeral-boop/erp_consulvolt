/**
 * Conta financeira dos pagamentos e recebimentos — bancos ou cartão (Fase A, legado novo ui_tesouraria.js:653-678 e
 * :861-877, estendido pela conta-cartão de tesouraria). Desde 2026-10-07 os pagamentos e recebimentos só movimentam
 * contas bancárias (42 depósitos a prazo, 43 depósitos à ordem) ou uma conta-cartão (classe 3 marcada); o numerário
 * (45) é exclusivo da Folha de Caixa. O meio CAIXA só aparece ao editar um documento anterior que já estava numa
 * conta 45 — os históricos não se mexem. O meio CARTAO só aparece se a empresa tiver contas marcadas. Espelha
 * ServicoDocumentosTesouraria::exigirContaBancaria (o servidor valida sempre).
 */

import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';

export type MeioTesouraria = 'BANCO' | 'PRAZO' | 'CAIXA' | 'CARTAO';

/** GET /tesouraria/contas-cartao[/{codigo}] */
export interface ContaCartao {
  id: number;
  codigo: string;
  descricao: string | null;
  codigo_moeda: string;
  cartao_numero: string | null;
  cartao_banco: string | null;
}

/** Contas-cartão da empresa activa, por código — quem é cartão (para o meio, o distintivo do plano e os selectores). */
export function useContasCartaoMapa() {
  const q = useQuery({ queryKey: ['teso', 'contas-cartao'], queryFn: () => obter<ContaCartao[]>('/tesouraria/contas-cartao'), staleTime: 60_000 });
  return new Map((q.data ?? []).map((c) => [c.codigo, c]));
}

/** Meio → prefixo da conta financeira (TESO_MEIO_PREFIXO do legado novo); CARTAO não é um prefixo (ver `contaCombinaComMeio`). */
export const PREFIXO_MEIO: Record<'BANCO' | 'PRAZO' | 'CAIXA', string> = { BANCO: '43', PRAZO: '42', CAIXA: '45' };

export const MENSAGEM_SO_BANCOS = 'Os pagamentos e recebimentos só movimentam contas bancárias (42 depósitos a prazo, 43 depósitos à ordem) ou um cartão de tesouraria. O numerário (45) passou para a Folha de Caixa.';

export const AVISO_DOCUMENTO_ANTERIOR = 'O numerário passou para a Folha de Caixa. Este documento é anterior e mantém-se como está.';

/** Documento já gravado numa conta de caixa (45): só ele pode continuar em caixa. */
export function documentoAnteriorEmCaixa(contaOriginal: string | null | undefined): boolean {
  return String(contaOriginal ?? '').startsWith(PREFIXO_MEIO.CAIXA);
}

/** Meio de uma conta (ao abrir um documento): cartão (se marcada), 42 → prazo, 45 → caixa, o resto → depósitos à ordem. */
export function meioDaConta(conta: string | null | undefined, cartao = false): MeioTesouraria {
  if (cartao) return 'CARTAO';
  const c = String(conta ?? '');
  if (c.startsWith(PREFIXO_MEIO.PRAZO)) return 'PRAZO';
  if (c.startsWith(PREFIXO_MEIO.CAIXA)) return 'CAIXA';
  return 'BANCO';
}

/** Opções do campo «Meio»: depósitos à ordem e a prazo; caixa só para o documento anterior em 45; cartão só se a empresa tiver contas marcadas. */
export function opcoesMeio(contaOriginal: string | null | undefined, temContasCartao = false): { value: MeioTesouraria; label: string }[] {
  return [
    { value: 'BANCO', label: 'Depósitos à ordem (43)' },
    { value: 'PRAZO', label: 'Depósitos a prazo (42)' },
    ...(documentoAnteriorEmCaixa(contaOriginal) ? [{ value: 'CAIXA' as const, label: 'Caixa (45) — documento anterior' }] : []),
    ...(temContasCartao ? [{ value: 'CARTAO' as const, label: 'Cartão (classe 3)' }] : []),
  ];
}

/** A conta combina com o meio escolhido? CARTAO não é um prefixo — é a própria marcação da conta. */
export function contaCombinaComMeio(conta: string | null | undefined, meio: MeioTesouraria, cartao: boolean): boolean {
  if (meio === 'CARTAO') return cartao;
  return String(conta ?? '').startsWith(PREFIXO_MEIO[meio]);
}

/** A conta pode ser gravada? 42/43 ou cartão sempre; 45 só se o documento (editado, não copiado) já estava em 45. */
export function contaFinanceiraPermitida(conta: string | null | undefined, contaOriginal: string | null | undefined, cartao = false): boolean {
  if (cartao) return true;
  const c = String(conta ?? '');
  if (/^4[23]/.test(c)) return true;
  return c.startsWith(PREFIXO_MEIO.CAIXA) && documentoAnteriorEmCaixa(contaOriginal);
}

/** Conta a pré-preencher numa cópia: um documento novo não pode nascer em caixa (fica por escolher). */
export function contaParaCopia(conta: string | null | undefined, cartao = false): string | undefined {
  return conta && contaFinanceiraPermitida(conta, null, cartao) ? conta : undefined;
}
