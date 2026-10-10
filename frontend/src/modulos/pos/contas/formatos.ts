import { formatarKz } from '@/utilitarios/formatacao';
import type { Deliberacao, Liquidacao, TotalMeio } from '../comum/tipos';

/**
 * Textos das secções de integração, desvios e prestação de contas como no sistema anterior (js/pos_prestacao.js).
 * Só formatação: as regras estão no servidor.
 */

/** Rótulos das decisões no histórico «Deliberações» (rotDecisao, pos_prestacao.js:372). */
export const ROTULOS_DECISAO: Record<string, string> = {
  SOBRA_PROVEITO: 'Sobra levada a proveito',
  FALTA_CUSTO: 'Falta assumida como custo',
  FALTA_OPERADOR: 'Falta imputada ao operador',
  SEM_EFEITO: 'Sem efeito (erro de contagem)',
};

export function rotuloDecisao(d: Pick<Deliberacao, 'decisao' | 'automatica'> | null | undefined): string {
  if (!d) return '—';
  const r = ROTULOS_DECISAO[d.decisao] ?? d.decisao;
  return d.automatica ? `${r} (automática, dentro da tolerância)` : r;
}

/** «Meios» das sessões: «Numerário: 1 140,00» por linha (pos_prestacao.js:237). */
export function linhasMeios(meios: TotalMeio[] | null | undefined): string[] {
  return (meios ?? []).map((m) => `${m.nome}: ${formatarKz(m.valor)}`);
}

/** Destino de um movimento de prestação de contas (pos_prestacao.js:606). */
export function destinoLiquidacao(l: Pick<Liquidacao, 'alvo' | 'conta_destino' | 'documento_tesouraria_id'>): string {
  if (l.alvo === 'FOLHA_CAIXA') return `Folha de Caixa ${l.conta_destino ?? ''}`.trim();
  return `Tesouraria${l.documento_tesouraria_id ? ` #${l.documento_tesouraria_id}` : ''} · ${l.conta_destino ?? '—'}`;
}

/** Sinal do desvio como no antigo: «+10,00» nas sobras. */
export function textoDesvio(valor: string | number | null | undefined): string {
  const n = Number(valor ?? 0);
  return `${n > 0 ? '+' : ''}${formatarKz(n)}`;
}

/** Cor do desvio (#b91c1c falta, #059669 sobra). */
export function corDesvio(valor: string | number | null | undefined): string {
  return Number(valor ?? 0) < 0 ? '#b91c1c' : '#059669';
}
