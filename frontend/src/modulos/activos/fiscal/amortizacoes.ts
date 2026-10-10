/**
 * Regras de apresentação do ecrã «Amortizações» como no legado (renderAmortizationView, js/ui_assets.js:1750-1925) e das
 * ferramentas em massa (Reabrir em Massa, Cálculo em Massa, Integração em Massa, ui_assets.js:2085-2380).
 */
import { deCentimos, paraCentimos } from '@/utilitarios/decimal';
import type { LinhaPeriodo, MesPendente, PeriodoAmortizacoes } from '../comum/tipos';
import { ordemPeriodo } from '../comum/regras';

export type EstadoLegado = { chave: 'INTEGRADO' | 'PENDENTE' | 'ABERTO'; rotulo: string; icone: string; cor: string };

/**
 * Estado do período no topo do ecrã: «INTEGRADO» quando há quotas integradas (o legado: some(is_posted)), «Cálculos
 * PENDENTES» com rascunhos, «ABERTO» sem nada calculado.
 */
export function estadoLegado(p: Pick<PeriodoAmortizacoes, 'estado' | 'contagens'> | undefined): EstadoLegado {
  if (p && (p.estado === 'INTEGRADO' || p.estado === 'PARCIAL' || (p.contagens.INTEGRADO ?? 0) > 0)) {
    return { chave: 'INTEGRADO', rotulo: 'INTEGRADO', icone: 'check-circle', cor: 'success' };
  }
  if (p && (p.contagens.RASCUNHO ?? 0) > 0) return { chave: 'PENDENTE', rotulo: 'Cálculos PENDENTES', icone: 'edit', cor: 'warning' };
  return { chave: 'ABERTO', rotulo: 'ABERTO', icone: 'clock', cor: 'default' };
}

/** Texto do botão de cálculo: «Recalcular Período» quando já há rascunhos. */
export function textoCalcular(p: Pick<PeriodoAmortizacoes, 'contagens'> | undefined): string {
  return (p?.contagens.RASCUNHO ?? 0) > 0 ? 'Recalcular Período' : 'Calcular Período';
}

/** Amortização acumulada mostrada na linha (ficha: inicial + integradas; sem o dado, o acumulado antes do período). */
export function acumuladaLinha(l: Pick<LinhaPeriodo, 'amortizacao_acumulada' | 'acumulado_anterior'>): string {
  return l.amortizacao_acumulada ?? l.acumulado_anterior;
}

/** «Vlr Líquido» do legado: aquisição − amortização acumulada. */
export function liquidoLinha(l: Pick<LinhaPeriodo, 'valor_aquisicao' | 'amortizacao_acumulada' | 'acumulado_anterior'>): string {
  return deCentimos(paraCentimos(l.valor_aquisicao) - paraCentimos(acumuladaLinha(l)));
}

/** Quota em rascunho diferente da calculada (definida à mão) — no legado a laranja com a mão («Valor Manual»). */
export function quotaManual(l: Pick<LinhaPeriodo, 'estado' | 'quota' | 'quota_calculada'>): boolean {
  return l.estado === 'RASCUNHO' && paraCentimos(l.quota) !== paraCentimos(l.quota_calculada);
}

/** A linha tem acções (cota manual / integrar individualmente)? Só as quotas ainda não integradas (legado: !isAssetPosted). */
export function linhaComAccoes(l: Pick<LinhaPeriodo, 'estado'>): boolean {
  return l.estado !== 'INTEGRADO';
}

/** Ordena períodos «MM-AAAA» do mais recente para o mais antigo. */
export function maisRecentesPrimeiro<T extends { periodo: string }>(lista: T[]): T[] {
  return [...lista].sort((a, b) => ordemPeriodo(b.periodo) - ordemPeriodo(a.periodo));
}

/** Total (Kz) de uma lista de meses pendentes. */
export function totalMeses(lista: Pick<MesPendente, 'valor'>[]): string {
  return deCentimos(lista.reduce((t, m) => t + paraCentimos(m.valor), 0));
}

/** Selecção inicial de um painel em massa: todos marcados (como o legado, `checked`). */
export function seleccaoInicial(lista: { periodo: string }[]): string[] {
  return lista.map((m) => m.periodo);
}
