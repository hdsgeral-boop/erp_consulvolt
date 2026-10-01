import dayjs from 'dayjs';
import { deCentimos, paraCentimos, type Valor } from '@/modulos/contab/comum/decimal';
import type { GanttGlobal, Projecto, TarefaWbs, Wbs } from './tipos';

/** Regras de apresentação do módulo Projectos (o servidor valida sempre). */

type Pode = (...chaves: string[]) => boolean;

export const RUBRICAS: Record<string, string> = {
  MATERIAIS: 'Materiais',
  MAO_DE_OBRA: 'Mão de obra',
  SUBCONTRATOS: 'Subcontratos',
  EQUIPAMENTOS: 'Equipamentos',
  DIVERSOS: 'Diversos',
};

export function rotuloRubrica(r: string | null | undefined): string {
  if (!r) return '—';
  return RUBRICAS[r] ?? r.replace(/_/g, ' ').toLowerCase().replace(/^./, (c) => c.toUpperCase());
}

export const FECHADOS = ['ENCERRADO', 'CANCELADO'];

/** Acções sobre o projecto conforme o estado e as permissões (encerrar/cancelar exige proj_estado). */
export function accoesProjecto(p: Pick<Projecto, 'estado'> | null | undefined, pode: Pode) {
  const fechado = !!p && FECHADOS.includes(p.estado);
  return {
    editar: !!p && !fechado && pode('proj_gerir'),
    activar: !!p && p.estado === 'PREPARACAO' && pode('proj_gerir', 'proj_estado'),
    encerrar: !!p && !fechado && pode('proj_estado'),
    cancelar: !!p && !fechado && pode('proj_estado'),
    reabrir: fechado && pode('proj_estado'),
    gerir: !fechado && pode('proj_gerir'),
    execucao: !fechado && pode('proj_gerir', 'proj_execucao'),
    requisitar: !fechado && pode('proj_requisitar', 'proj_gerir'),
    revisao: !fechado && pode('proj_revisao'),
    eliminar: pode('proj_eliminar'),
  };
}

/** Lista plana da WBS (tarefas e subtarefas por ordem de árvore), com o marco de cada uma. */
export function achatarWbs(wbs: Wbs | undefined): (TarefaWbs & { marco: string | null })[] {
  const res: (TarefaWbs & { marco: string | null })[] = [];
  const visitar = (t: TarefaWbs, marco: string | null) => {
    res.push({ ...t, marco });
    t.subtarefas?.forEach((s) => visitar(s, marco));
  };
  wbs?.grupos.forEach((g) => g.tarefas.forEach((t) => visitar(t, g.marco?.nome ?? null)));
  return res;
}

/** Tarefa atrasada: não concluída e com data de fim anterior a hoje. */
export function tarefaAtrasada(t: { estado: string; data_fim: string | null }, hoje = dayjs()): boolean {
  return t.estado !== 'CONCLUIDA' && !!t.data_fim && dayjs(t.data_fim).isBefore(hoje, 'day');
}

// ───────────── Gantt ─────────────

export interface LinhaGantt {
  chave: string;
  nome: string;
  rotulo?: string;
  nivel: number;
  tipo: 'grupo' | 'projecto' | 'tarefa' | 'marco';
  inicio: string | null;
  fim: string | null;
  progresso?: number;
  estado?: string;
  id?: number;
}

export interface BarraGantt {
  /** Posição e largura em % da largura total do período. */
  esquerda: number;
  largura: number;
  /** Fim em falta: a barra vai até ao fim do período e mostra-se tracejada. */
  aberta: boolean;
}

export interface Escala {
  inicio: dayjs.Dayjs;
  fim: dayjs.Dayjs;
  dias: number;
}

/** Escala do Gantt: do menor início ao maior fim das linhas (com folga de 1 dia); por omissão o mês actual. */
export function escalaGantt(linhas: Pick<LinhaGantt, 'inicio' | 'fim'>[], inicio?: string | null, fim?: string | null): Escala {
  const datas = linhas.flatMap((l) => [l.inicio, l.fim]).filter((d): d is string => !!d).map((d) => dayjs(d));
  let i = inicio ? dayjs(inicio) : datas.length ? datas.reduce((a, b) => (b.isBefore(a) ? b : a)) : dayjs().startOf('month');
  let f = fim ? dayjs(fim) : datas.length ? datas.reduce((a, b) => (b.isAfter(a) ? b : a)) : dayjs().endOf('month');
  if (f.isBefore(i)) [i, f] = [f, i];
  i = i.startOf('day');
  f = f.startOf('day');
  return { inicio: i, fim: f, dias: Math.max(1, f.diff(i, 'day') + 1) };
}

/** Barra de uma linha na escala (null sem data de início). O fim é inclusivo (uma tarefa de 1 dia ocupa 1 dia). */
export function barraGantt(linha: Pick<LinhaGantt, 'inicio' | 'fim'>, escala: Escala): BarraGantt | null {
  if (!linha.inicio) return null;
  const i = dayjs(linha.inicio).startOf('day');
  const aberta = !linha.fim;
  const f = aberta ? escala.fim : dayjs(linha.fim).startOf('day');
  const ini = Math.max(0, i.diff(escala.inicio, 'day'));
  const fimDia = Math.min(escala.dias, f.diff(escala.inicio, 'day') + 1);
  if (fimDia <= 0 || ini >= escala.dias) return { esquerda: Math.min(100, (ini / escala.dias) * 100), largura: 0, aberta };
  const largura = Math.max(fimDia - ini, 1);
  return { esquerda: (ini / escala.dias) * 100, largura: (largura / escala.dias) * 100, aberta };
}

/** Marcas do cabeçalho: meses (ou semanas, em períodos curtos) com a posição em %. */
export function marcasGantt(escala: Escala): { rotulo: string; esquerda: number; largura: number }[] {
  const res: { rotulo: string; esquerda: number; largura: number }[] = [];
  const porSemana = escala.dias <= 62;
  let c = porSemana ? escala.inicio.startOf('week') : escala.inicio.startOf('month');
  while (c.isBefore(escala.fim) || c.isSame(escala.fim, 'day')) {
    const prox = porSemana ? c.add(1, 'week') : c.add(1, 'month');
    const a = Math.max(0, c.diff(escala.inicio, 'day'));
    const b = Math.min(escala.dias, prox.diff(escala.inicio, 'day'));
    if (b > a) res.push({ rotulo: porSemana ? c.format('DD/MM') : c.format('MMM YY'), esquerda: (a / escala.dias) * 100, largura: ((b - a) / escala.dias) * 100 });
    c = prox;
  }
  return res;
}

/** Posição de hoje em % (null se fora da escala). */
export function posicaoHoje(escala: Escala, hoje = dayjs()): number | null {
  const d = hoje.startOf('day').diff(escala.inicio, 'day');
  return d < 0 || d >= escala.dias ? null : ((d + 0.5) / escala.dias) * 100;
}

/** Linhas do Gantt global: segmento (INTERNO/EXTERNO) → projecto → tarefas. */
export function linhasGanttGlobal(g: GanttGlobal | undefined, comTarefas = true): LinhaGantt[] {
  const res: LinhaGantt[] = [];
  for (const s of g?.segmentos ?? []) {
    res.push({ chave: `s-${s.tipo}`, nome: s.tipo === 'INTERNO' ? 'Projectos internos' : s.tipo === 'EXTERNO' ? 'Projectos externos (obras)' : s.tipo, nivel: 0, tipo: 'grupo', inicio: null, fim: null });
    for (const p of s.projetos) {
      res.push({ chave: `p-${p.id}`, id: p.id, nome: p.nome, rotulo: p.codigo ?? undefined, nivel: 1, tipo: 'projecto', inicio: p.inicio, fim: p.fim });
      if (comTarefas) for (const t of p.tarefas) res.push({ chave: `t-${t.id}`, id: t.id, nome: t.nome, rotulo: t.codigo ?? undefined, nivel: 2, tipo: 'tarefa', inicio: t.inicio, fim: t.fim });
    }
  }
  return res;
}

/** Linhas do Gantt de um projecto a partir da WBS: marco → tarefas → subtarefas. */
export function linhasGanttWbs(wbs: Wbs | undefined): LinhaGantt[] {
  const res: LinhaGantt[] = [];
  const visitar = (t: TarefaWbs, nivel: number) => {
    res.push({ chave: `t-${t.id}`, id: t.id, nome: t.nome, rotulo: t.codigo ?? undefined, nivel, tipo: 'tarefa', inicio: t.data_inicio, fim: t.data_fim, progresso: t.execucao, estado: t.estado });
    t.subtarefas?.forEach((s) => visitar(s, nivel + 1));
  };
  for (const g of wbs?.grupos ?? []) {
    if (!g.marco && !g.tarefas.length) continue;
    res.push({ chave: `m-${g.marco?.id ?? 'sem'}`, nome: g.marco?.nome ?? 'Sem milestone', nivel: 0, tipo: 'marco', inicio: g.marco?.data ?? null, fim: g.marco?.data ?? null, progresso: g.execucao });
    g.tarefas.forEach((t) => visitar(t, 1));
  }
  return res;
}

// ───────────── Valores ─────────────

/** Consumo do orçamento em % (null sem orçamento). */
export function consumo(custo: Valor, orcamento: Valor): number | null {
  const o = paraCentimos(orcamento);
  return o > 0 ? Math.round((paraCentimos(custo) / o) * 1000) / 10 : null;
}

/** Totais do orçamento por rubrica (soma das linhas). */
export function totaisPorRubrica(linhas: { rubrica: string; montante: Valor }[]): Record<string, string> {
  const t: Record<string, number> = {};
  for (const l of linhas) t[l.rubrica] = (t[l.rubrica] ?? 0) + paraCentimos(l.montante);
  return Object.fromEntries(Object.entries(t).map(([k, v]) => [k, deCentimos(v)]));
}
