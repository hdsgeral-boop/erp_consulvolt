/**
 * Regras de apresentação do Programa de Férias (legado js/modules/rh/ferias.js): cores dos estados, sobreposições,
 * filtro por estado, totais dos cartões e posição das barras no «Mapa anual». Os dias, o direito e o saldo vêm do
 * servidor; aqui só se agrupa e desenha.
 */
import type { PeriodoFerias, ResumoFerias } from '../api';

export const ESTADOS_FERIAS = [
  { k: 'PEDIDO', r: 'Pedido (a aprovar)', cor: '#f59e0b' },
  { k: 'PLANEADO', r: 'Planeado', cor: '#64748b' },
  { k: 'APROVADO', r: 'Aprovado', cor: '#2563eb' },
  { k: 'GOZADO', r: 'Gozado', cor: '#16a34a' },
  { k: 'CANCELADO', r: 'Cancelado', cor: '#dc2626' },
] as const;

export const corEstadoFerias = (estado: string): string => ESTADOS_FERIAS.find((e) => e.k === estado)?.cor ?? '#64748b';
export const rotuloEstadoFerias = (estado: string): string => ESTADOS_FERIAS.find((e) => e.k === estado)?.r ?? estado;

/** Os cancelados não contam para o saldo nem para sobreposições (como no antigo). */
export const contaSaldo = (p: PeriodoFerias): boolean => p.estado !== 'CANCELADO';

export const sobrepoe = (a: Pick<PeriodoFerias, 'data_inicio' | 'data_fim'>, b: Pick<PeriodoFerias, 'data_inicio' | 'data_fim'>): boolean =>
  a.data_inicio.slice(0, 10) <= b.data_fim.slice(0, 10) && b.data_inicio.slice(0, 10) <= a.data_fim.slice(0, 10);

/** Ids dos períodos (válidos) que se sobrepõem a outro período do mesmo colaborador. */
export function conflitos(periodos: PeriodoFerias[]): Set<number> {
  const validos = periodos.filter(contaSaldo);
  const r = new Set<number>();
  validos.forEach((p, i) => validos.forEach((q, j) => {
    if (i < j && p.colaborador_id === q.colaborador_id && sobrepoe(p, q)) {
      r.add(p.id);
      r.add(q.id);
    }
  }));
  return r;
}

/** Períodos de cada colaborador, por data de início. */
export function periodosPorColaborador(periodos: PeriodoFerias[]): Map<number, PeriodoFerias[]> {
  const m = new Map<number, PeriodoFerias[]>();
  [...periodos].sort((a, b) => a.data_inicio.localeCompare(b.data_inicio)).forEach((p) => {
    const l = m.get(p.colaborador_id) ?? [];
    l.push(p);
    m.set(p.colaborador_id, l);
  });
  return m;
}

/** Filtro «Estado» do antigo: '' = todos; 'SEM' = sem férias planeadas; senão «com períodos <estado>». */
export function filtrarPorEstado(resumo: ResumoFerias[], porColab: Map<number, PeriodoFerias[]>, estado: string): ResumoFerias[] {
  if (!estado) return resumo;
  return resumo.filter((r) => {
    const ps = porColab.get(r.colaborador_id) ?? [];
    return estado === 'SEM' ? !ps.some(contaSaldo) : ps.some((p) => p.estado === estado);
  });
}

/** Cartões do topo (sobre todos os colaboradores do ano, como no antigo). */
export function totaisFerias(resumo: ResumoFerias[], periodos: PeriodoFerias[]) {
  const c = conflitos(periodos);
  const comConflito = new Set(periodos.filter((p) => c.has(p.id)).map((p) => p.colaborador_id));
  return {
    colaboradores: resumo.length,
    direito: resumo.reduce((t, r) => t + r.direito, 0),
    planeados: resumo.reduce((t, r) => t + r.marcados, 0),
    gozados: resumo.reduce((t, r) => t + r.gozados, 0),
    sem: resumo.filter((r) => r.marcados === 0).length,
    sobreposicoes: comConflito.size,
  };
}

/** «17/09–30/09 · 10 d» */
export function rotuloChip(p: Pick<PeriodoFerias, 'data_inicio' | 'data_fim' | 'dias'>): string {
  const dm = (iso: string) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}`;
  return `${dm(p.data_inicio)}–${dm(p.data_fim)} · ${Number(p.dias) || 0} d`;
}

const diaDoAno = (iso: string, ano: number): number => {
  const [a, m, d] = iso.slice(0, 10).split('-').map(Number);
  return Math.round((Date.UTC(a, m - 1, d) - Date.UTC(ano, 0, 1)) / 86_400_000);
};

/** Posição da barra no mapa anual (percentagens); null se o período não toca o ano. */
export function barraNoAno(p: Pick<PeriodoFerias, 'data_inicio' | 'data_fim'>, ano: number): { esquerda: number; largura: number } | null {
  const total = diaDoAno(`${ano}-12-31`, ano) + 1;
  const a = Math.max(0, diaDoAno(p.data_inicio, ano));
  const z = Math.min(total - 1, diaDoAno(p.data_fim, ano));
  if (z < 0 || a > total - 1 || z < a) return null;
  return { esquerda: (a / total) * 100, largura: ((z - a + 1) / total) * 100 };
}
