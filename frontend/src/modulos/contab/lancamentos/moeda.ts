/** A-07 — lançamento manual em moeda estrangeira (legado: js/moedas_lancamentos.js). Regras puras, testáveis. */
import { deCentimos, equilibrio, paraCentimos, type LinhaDC } from '@/utilitarios/decimal';

export const MOEDA_BASE = 'AOA';

export function emMoeda(moeda?: string | null): boolean {
  return !!moeda && moeda.toUpperCase() !== MOEDA_BASE;
}

/** Contravalor estimado em Kz (o servidor recalcula: arred(valor × câmbio) por linha, com acerto ≤ 1 Kz). */
export function contravalorKz(valor: string, cambio?: number | null): string | null {
  if (!cambio || cambio <= 0) return null;
  return deCentimos(Math.round(paraCentimos(valor) * cambio));
}

/**
 * «Equilibrar» (legado: balanceEntry): preenche a primeira linha sem valor (ou a última) com a diferença, do lado
 * que está em falta. Devolve as linhas alteradas ou null se já estiver equilibrado.
 */
export function equilibrarLinhas<T extends LinhaDC & { valor?: number | string | null }>(linhas: T[]): T[] | null {
  const e = equilibrio(linhas);
  const dif = paraCentimos(e.diferenca);
  if (dif === 0 || !linhas.length) return null;
  const lado: 'D' | 'C' = dif > 0 ? 'C' : 'D';
  let i = linhas.findIndex((l) => paraCentimos(l.valor) <= 0);
  if (i < 0) {
    // sem linha vazia: acerta a última linha do lado em falta (soma a diferença)
    i = linhas.map((l) => l.tipo_dc).lastIndexOf(lado);
    if (i < 0) return null;
    return linhas.map((l, k) => (k === i ? { ...l, valor: Number(deCentimos(paraCentimos(l.valor) + Math.abs(dif))) } : l));
  }
  return linhas.map((l, k) => (k === i ? { ...l, tipo_dc: lado, valor: Number(deCentimos(Math.abs(dif))) } : l));
}

/** Linhas do formulário → corpo do pedido: em moeda estrangeira o valor vai em `valor_moeda` (o Kz calcula-se no servidor). */
export function linhasComMoeda(linhas: Record<string, unknown>[], moeda?: string | null): Record<string, unknown>[] {
  if (!emMoeda(moeda)) return linhas;
  return linhas.map(({ valor, ...l }) => ({ ...l, valor_moeda: valor }));
}
