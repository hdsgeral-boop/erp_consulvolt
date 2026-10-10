/**
 * Regras de apresentação dos Relatórios de Gestão iguais às do sistema anterior (modules/gestao/relatorios_gestao.js):
 * ícones dos separadores, rótulo dos períodos «dd/mm/aaaa a dd/mm/aaaa (N dias)», texto da variação («+1 234,00 Kz
 * (+12,3 %)», «+2,5 p.p.») e seta ▲/▼. Os valores e a comparação vêm do servidor.
 */
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';

/** Ícone de cada separador (MODULOS[].icone e «★ Resumo executivo»). */
export const ICONES_MODULOS: Record<string, string> = {
  resumo: 'star',
  financas: 'balance-scale',
  vendas: 'file-invoice-dollar',
  compras: 'truck-loading',
  tesouraria: 'vault',
  stock: 'warehouse',
  rh: 'users-cog',
  activos: 'couch',
  projectos: 'briefcase',
  servicos: 'cash-register',
};

export function iconeModulo(id: string): string {
  return ICONES_MODULOS[id] ?? 'chart-line';
}

/** «2026-10-01» → «01/10/2026». */
export function dataPt(iso: string | null | undefined): string {
  if (!iso) return '—';
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
  return m ? `${m[3]}/${m[2]}/${m[1]}` : iso;
}

export interface PeriodoRotulo {
  inicio: string;
  fim: string;
  dias: number;
}

/** rotuloPeriodo do legado: «01/10/2026 a 31/10/2026». */
export function rotuloPeriodo(p: PeriodoRotulo | null | undefined): string {
  return p ? `${dataPt(p.inicio)} a ${dataPt(p.fim)}` : '—';
}

/** Linha por baixo dos filtros: «A 01/10/2026 a 31/10/2026 (31 dias) · B …». */
export function legendaPeriodos(a: PeriodoRotulo, b: PeriodoRotulo | null): { a: string; b: string | null } {
  return { a: `${rotuloPeriodo(a)} (${a.dias} dias)`, b: b ? `${rotuloPeriodo(b)} (${b.dias} dias)` : null };
}

function numero(v: unknown): number | null {
  if (v === null || v === undefined || v === '') return null;
  const n = typeof v === 'number' ? v : Number(v);
  return Number.isFinite(n) ? n : null;
}

const nf = (v: number, casas: number) => new Intl.NumberFormat('pt-PT', { minimumFractionDigits: casas, maximumFractionDigits: casas, useGrouping: 'always' as unknown as boolean }).format(v);

/** formatar do legado (relatorios_gestao.js:581-592). */
export function formatarRg(v: unknown, formato: string, casas?: number | null): string {
  const n = numero(v);
  if (n === null) return '—';
  switch (formato) {
    case 'kz':
      return `${formatarKz(n)} Kz`;
    case 'pct':
      return `${nf(n, casas ?? 1)} %`;
    case 'dias':
      return `${nf(n, 0)} dias`;
    case 'meses':
      return `${nf(n, casas ?? 1)} meses`;
    case 'horas':
      return `${nf(n, 1)} h`;
    case 'nota':
      return nf(n, casas ?? 2);
    default:
      return casas !== null && casas !== undefined ? nf(n, casas) : formatarNumero(n);
  }
}

/** formatarVar do legado: «+1 234,00 Kz (+12,3 %)»; nas percentagens «+2,5 p.p.». */
export function formatarVariacao(abs: unknown, pct: number | null, formato: string): string {
  const a = numero(abs);
  if (a === null) return '—';
  const sinal = a > 0 ? '+' : '';
  const base = formato === 'pct' ? `${sinal}${nf(a, 1)} p.p.` : sinal + formatarRg(a, formato === 'nota' ? 'nota' : formato);
  return base + (pct !== null && pct !== undefined && formato !== 'pct' ? ` (${pct > 0 ? '+' : ''}${nf(pct, 1)} %)` : '');
}

/** ▲ quando sobe, ▼ quando desce, • sem variação. */
export function setaVariacao(abs: unknown): string {
  const a = numero(abs);
  return a === null || a === 0 ? '•' : a > 0 ? '▲' : '▼';
}

export function dicaLeitura(leitura: string): string {
  return leitura === 'boa' ? 'Variação favorável' : leitura === 'ma' ? 'Variação desfavorável' : 'Sem leitura favorável/desfavorável';
}

export function dicaSentido(ajuda: string | undefined, sentido: string): string {
  return (ajuda ?? '') + (sentido === 'sobe' ? ' · Melhor quando sobe.' : sentido === 'desce' ? ' · Melhor quando desce.' : '');
}
