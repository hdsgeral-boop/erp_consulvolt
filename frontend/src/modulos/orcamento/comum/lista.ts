/**
 * Textos da lista de orçamentos como no sistema anterior (orcamento_ui.js:311-318): coluna «Preparação» (base zero,
 * top-down/bottom-up ou meta/contributo, responsável e prazo) e «Última acção» (aprovado / submetido / criado, por quem e quando).
 */

import { formatarData, formatarDataHora } from '@/utilitarios/formatacao';
import type { OrcamentoLista } from './tipos';

/** Cor do estado do legado (COR_ESTADO, texto branco). */
export const COR_ESTADO: Record<string, string> = { RASCUNHO: '#64748b', SUBMETIDO: '#b45309', APROVADO: '#15803d', SUBSTITUIDO: '#94a3b8' };

export interface ChipPreparacao {
  texto: string;
  fundo: string;
  cor: string;
}

/** Chips da coluna «Preparação». */
export function chipsPreparacao(o: Pick<OrcamentoLista, 'metodo' | 'abordagem' | 'nivel'>): ChipPreparacao[] {
  const chips: ChipPreparacao[] = [];
  if (o.metodo === 'BASE_ZERO') chips.push({ texto: 'Base zero', fundo: '#fef3c7', cor: '#92400e' });
  if (o.abordagem === 'TOP_DOWN') chips.push({ texto: o.nivel ? 'Meta top-down' : 'Top-down', fundo: '#e0f2fe', cor: '#075985' });
  else if (o.abordagem === 'BOTTOM_UP') chips.push({ texto: o.nivel ? 'Contributo' : 'Bottom-up', fundo: '#ede9fe', cor: '#5b21b6' });
  return chips;
}

/** «responsável · até dd/mm/aaaa» (ou vazio). */
export function textoResponsavel(o: Pick<OrcamentoLista, 'responsavel' | 'prazo_contributo'>): string {
  if (!o.responsavel) return '';
  return `${o.responsavel}${o.prazo_contributo ? ` · até ${formatarData(o.prazo_contributo)}` : ''}`;
}

export function textoPreparacao(o: OrcamentoLista): string {
  return [...chipsPreparacao(o).map((c) => c.texto), textoResponsavel(o)].filter(Boolean).join(' · ');
}

/** «Aprovado por X dd/mm/aaaa hh:mm», «Submetido por …» ou «Criado por …». */
export function textoUltimaAccao(o: Pick<OrcamentoLista, 'ultima_accao'>): string {
  const u = o.ultima_accao;
  if (!u) return '';
  const accao = u.accao === 'APROVADO' ? 'Aprovado' : u.accao === 'SUBMETIDO' ? 'Submetido' : 'Criado';
  return `${accao} por ${u.por ?? '—'}${u.em ? ` ${formatarDataHora(u.em)}` : ''}`;
}
