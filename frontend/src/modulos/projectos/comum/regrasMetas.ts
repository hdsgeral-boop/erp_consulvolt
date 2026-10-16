import dayjs from 'dayjs';
import type { Calendario } from './tipos';

/**
 * Regras de apresentação das metas por quantidade, do calendário e das dependências (Versão Actual, 2026-10-11:
 * js/projectos_metas.js e js/projectos_hierarquia.js). O servidor valida e calcula sempre; aqui fica só o que o ecrã
 * precisa para mostrar, pré-visualizar e preencher formulários.
 */

export const UNIDADES = ['un', 'm', 'm²', 'm³', 'kg', 'ton', 'km', 'L', 'h', 'dia', 'visita', 'ensaio', 'lote', 'peça', '%'];

export const NOMES_DIA = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

export const MODELOS_CAL: Record<string, { nome: string; dias: number[] }> = {
  SEG_SEX: { nome: 'Segunda a sexta', dias: [0, 1, 1, 1, 1, 1, 0] },
  SEG_SEX_SAB12: { nome: 'Segunda a sexta + meio sábado', dias: [0, 1, 1, 1, 1, 1, 0.5] },
  SEG_SAB: { nome: 'Segunda a sábado', dias: [0, 1, 1, 1, 1, 1, 1] },
  TODOS: { nome: 'Todos os dias', dias: [1, 1, 1, 1, 1, 1, 1] },
};

export const TIPOS_DEP: Record<string, { nome: string; ajuda: string }> = {
  FS: { nome: 'Fim → Início', ajuda: 'só pode começar depois de a outra terminar (o mais comum)' },
  SS: { nome: 'Início → Início', ajuda: 'começa quando a outra começa' },
  FF: { nome: 'Fim → Fim', ajuda: 'só pode terminar depois de a outra terminar' },
};

export const ESCALAS: { value: 'dia' | 'semana' | 'mes' | 'trimestre' | 'semestre' | 'ano'; label: string }[] = [
  { value: 'dia', label: 'Dia' },
  { value: 'semana', label: 'Semana' },
  { value: 'mes', label: 'Mês' },
  { value: 'trimestre', label: 'Trimestre' },
  { value: 'semestre', label: 'Semestre' },
  { value: 'ano', label: 'Ano' },
];
export type Escala = (typeof ESCALAS)[number]['value'];

/** Dias 0..6 do calendário (com os valores por omissão: segunda a sexta). */
export function diasDoCalendario(c: Calendario | null | undefined): number[] {
  return [0, 1, 2, 3, 4, 5, 6].map((d) => {
    const v = Number(c?.dias?.[String(d)]);
    return Number.isFinite(v) && c?.dias && String(d) in c.dias ? v : MODELOS_CAL.SEG_SEX.dias[d];
  });
}

/** Código do modelo a que os dias correspondem, ou PERSONALIZADO (modeloDe, projectos_metas.js:155-158). */
export function modeloDe(dias: number[]): string {
  return Object.keys(MODELOS_CAL).find((k) => MODELOS_CAL[k].dias.every((v, i) => v === dias[i])) ?? 'PERSONALIZADO';
}

/** «Segunda a sexta · 8 h/dia» ou a lista dos dias (descricaoCal, :159-164). */
export function descricaoCalendario(c: Calendario | null | undefined): string {
  const dias = diasDoCalendario(c);
  const m = modeloDe(dias);
  const base = m !== 'PERSONALIZADO' ? MODELOS_CAL[m].nome
    : [1, 2, 3, 4, 5, 6, 0].filter((d) => dias[d] > 0).map((d) => NOMES_DIA[d] + (dias[d] < 1 ? ' (meio dia)' : '')).join(', ');
  return `${base} · ${formatarQtd(c?.horas_dia && c.horas_dia > 0 ? c.horas_dia : 8)} h/dia`;
}

export function formatarQtd(v: number | string | null | undefined, casas = 2): string {
  const n = Number(v ?? 0);
  return (Number.isFinite(n) ? n : 0).toLocaleString('pt-PT', { maximumFractionDigits: casas });
}

/** «1 234,56», «1.234,56», «1,234.56» ou «12,5» → número; null se não for número (colarMedicoes, :633-639). */
export function numeroTexto(s: string | null | undefined): number | null {
  let t = String(s ?? '').trim().replace(/[\s ]/g, '');
  if (!t) return null;
  if (/^-?\d{1,3}(\.\d{3})+(,\d+)?$/.test(t) || /^-?\d+,\d+$/.test(t)) t = t.replace(/\./g, '').replace(',', '.');
  else if (/^-?\d{1,3}(,\d{3})+(\.\d+)?$/.test(t)) t = t.replace(/,/g, '');
  return /^-?\d+(\.\d+)?$/.test(t) ? parseFloat(t) : null;
}

const normalizar = (s: string | null | undefined) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').trim().toLowerCase();

/**
 * Colar duas colunas de uma folha de cálculo: código (ou nome) da tarefa e quantidade, separadas por tabulação, «|» ou
 * «;» (colarMedicoes, projectos_metas.js:631-675). Devolve as quantidades por tarefa e o que não foi reconhecido.
 */
export function interpretarColagem(texto: string, tarefas: { id: number; codigo: string | null; nome: string }[]): { quantidades: Map<number, number>; naoReconhecidas: string[] } {
  const linhas = texto.replace(/\r/g, '').split('\n').filter((l) => l.trim());
  const sep = linhas.some((l) => l.includes('\t')) ? '\t' : linhas.length && linhas.every((l) => l.includes('|')) ? '|' : linhas.length && linhas.every((l) => l.includes(';')) ? ';' : '\t';
  const quantidades = new Map<number, number>();
  const naoReconhecidas: string[] = [];
  for (const l of linhas) {
    const p = l.split(sep).map((x) => x.trim());
    if (p.length < 2) { naoReconhecidas.push(l.trim()); continue; }
    const chave = normalizar(p[0]);
    const q = numeroTexto(p[p.length - 1]);
    if (q === null) { naoReconhecidas.push(l.trim()); continue; }
    const t = tarefas.find((x) => normalizar(x.codigo) === chave || normalizar(x.nome) === chave || normalizar(`${x.codigo ?? ''} ${x.nome}`) === chave);
    if (!t) { naoReconhecidas.push(p[0]); continue; }
    quantidades.set(t.id, q);
  }
  return { quantidades, naoReconhecidas };
}

/** Curva acumulada: s_i = Σ_{k ≤ i} v_k (mapaAcumulado). */
export function acumular(valores: number[]): number[] {
  let s = 0;
  return valores.map((v) => (s = Math.round((s + Number(v || 0)) * 100) / 100));
}

/** «Fim → Início +2d» (simbolos, projectos_hierarquia.js:222-233). */
export function rotuloDependencia(tipo: string | null | undefined, desf: number | null | undefined): string {
  const t = tipo || 'FS';
  const d = Number(desf || 0);
  return `${TIPOS_DEP[t]?.nome ?? t}${d ? ` ${d > 0 ? '+' : ''}${d}d` : ''}`;
}

/** Partes iguais da meta total, com o resto no fim (projMetasEmMassa, :749-760). */
export function partesIguais(total: number, n: number): number[] {
  const partes = Math.max(1, Math.min(60, Math.floor(n) || 1));
  const cada = Math.round((total / partes) * 100) / 100;
  return Array.from({ length: partes }, (_, i) => (i === partes - 1 ? Math.round((total - cada * (partes - 1)) * 100) / 100 : cada));
}

/** Prazo repartido em sequência: (fim − início + 1) dias em partes (projMetasEmMassa, :797-812). */
export function prazoRepartido(inicio: string, fim: string, n: number): { inicio: string; fim: string }[] {
  const i = dayjs(inicio);
  const dias = Math.max(1, dayjs(fim).diff(i, 'day') + 1);
  return Array.from({ length: n }, (_, k) => {
    const de = Math.floor((dias * k) / n);
    const ate = Math.floor((dias * (k + 1)) / n) - 1;
    return { inicio: i.add(de, 'day').format('YYYY-MM-DD'), fim: i.add(Math.max(de, ate), 'day').format('YYYY-MM-DD') };
  });
}

/** % da meta: clamp(round(realizado ÷ meta × 100), 0, 100) (pctDaMeta, :46). */
export function pctDaMeta(realizado: number | string | null | undefined, meta: number | string | null | undefined): number | null {
  const m = Number(meta ?? 0);
  if (!(m > 0)) return null;
  return Math.max(0, Math.min(100, Math.round((Number(realizado ?? 0) / m) * 100)));
}

/** Marcas do cabeçalho do Gantt pela escala escolhida, alinhadas à unidade (periodosDaEscala, :200-242). */
export function marcasDaEscala(inicio: dayjs.Dayjs, fim: dayjs.Dayjs, escala: Escala): { de: dayjs.Dayjs; ate: dayjs.Dayjs; rotulo: string }[] {
  const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
  let c = inicio.startOf('day');
  if (escala === 'semana') c = c.subtract((c.day() + 6) % 7, 'day');
  else if (escala === 'mes') c = c.startOf('month');
  else if (escala === 'trimestre') c = c.month(Math.floor(c.month() / 3) * 3).startOf('month');
  else if (escala === 'semestre') c = c.month(c.month() < 6 ? 0 : 6).startOf('month');
  else if (escala === 'ano') c = c.startOf('year');
  const avancar = (d: dayjs.Dayjs) => (escala === 'dia' ? d.add(1, 'day') : escala === 'semana' ? d.add(7, 'day') : escala === 'mes' ? d.add(1, 'month')
    : escala === 'trimestre' ? d.add(3, 'month') : escala === 'semestre' ? d.add(6, 'month') : d.add(1, 'year'));
  const saida: { de: dayjs.Dayjs; ate: dayjs.Dayjs; rotulo: string }[] = [];
  let guarda = 0;
  while (!c.isAfter(fim, 'day') && guarda++ < 4000) {
    const prox = avancar(c);
    const m = c.month();
    saida.push({
      de: c, ate: prox.subtract(1, 'day'),
      rotulo: escala === 'dia' ? (c.date() === 1 ? MESES[m] : String(c.date())) : escala === 'semana' ? `${c.date()}/${m + 1}`
        : escala === 'mes' ? `${MESES[m]}${m === 0 ? ` ${c.year()}` : ''}` : escala === 'trimestre' ? `T${Math.floor(m / 3) + 1} ${c.year()}`
          : escala === 'semestre' ? `S${m < 6 ? 1 : 2} ${c.year()}` : String(c.year()),
    });
    c = prox;
  }
  return saida;
}

/** Peso de trabalho do intervalo: Σ factor de cada dia (diasUteis, RegrasMetasProjetos::diasUteis). */
export function diasUteis(de: string | null | undefined, ate: string | null | undefined, cal: Calendario | null | undefined): number {
  if (!de || !ate) return 0;
  const a = dayjs(de.slice(0, 10));
  const b = dayjs(ate.slice(0, 10));
  if (!a.isValid() || !b.isValid() || b.isBefore(a, 'day')) return 0;
  const dias = diasDoCalendario(cal);
  const total = b.diff(a, 'day') + 1;
  let soma = Math.floor(total / 7) * dias.reduce((s, v) => s + v, 0);
  for (let i = 0; i < total % 7; i++) soma += dias[(a.day() + i) % 7];
  return Math.round(soma * 100) / 100;
}

export interface RitmoExigido { dias: number; uteis: number; corridos: number; porCalendario: boolean; dia: number; semana: number; mes: number; hora: number; unidade: string }

/**
 * #2: ritmo exigido pela meta (RegrasMetasProjetos::ritmo, projectos_metas.js:335-348): por dia = meta ÷ dias úteis
 * (dias corridos se o calendário não tiver nenhum no prazo); semana = dia × Σ factores; mês = semana × 4,33; hora =
 * meta ÷ (dias × horas/dia). null sem meta ou sem início.
 */
export function ritmoExigido(meta: number | null | undefined, unidade: string | null | undefined, inicio: string | null | undefined, fim: string | null | undefined, cal: Calendario | null | undefined): RitmoExigido | null {
  const m = Number(meta ?? 0);
  if (!(m > 0) || !inicio) return null;
  const f = fim || inicio;
  const uteis = diasUteis(inicio, f, cal);
  const corridos = Math.max(0, dayjs(f.slice(0, 10)).diff(dayjs(inicio.slice(0, 10)), 'day') + 1);
  const dias = uteis > 0 ? uteis : corridos;
  if (!dias) return null;
  const r2 = (v: number) => Math.round(v * 100) / 100;
  const semana = diasDoCalendario(cal).reduce((s, v) => s + v, 0) || 7;
  const horasDia = cal?.horas_dia && cal.horas_dia > 0 ? cal.horas_dia : 8;
  return { dias, uteis, corridos, porCalendario: !(uteis > 0), dia: r2(m / dias), semana: r2((m / dias) * semana), mes: r2((m / dias) * semana * 4.33), hora: r2(m / (dias * horasDia)), unidade: String(unidade ?? '').trim() || 'un' };
}

/** «12,5 m²/dia · 62,5 m²/semana · 270,63 m²/mês · 1,56 m²/h (8 dias úteis)». */
export function textoRitmo(r: RitmoExigido): string {
  const u = r.unidade;
  return `${formatarQtd(r.dia)} ${u}/dia · ${formatarQtd(r.semana)} ${u}/semana · ${formatarQtd(r.mes)} ${u}/mês · ${formatarQtd(r.hora)} ${u}/h (${formatarQtd(r.dias)} ${r.porCalendario ? 'dias corridos — o calendário não tem dias de trabalho no prazo' : 'dias úteis'})`;
}

/** Linhas do mapa (por ordem de árvore, com o nível) que têm filhos — as que mostram a seta abrir/fechar (mapaArvore). */
export function chavesComFilhosMapa(linhas: { chave: string; nivel: number }[]): Set<string> {
  const res = new Set<string>();
  linhas.forEach((l, i) => { if (i + 1 < linhas.length && linhas[i + 1].nivel > l.nivel) res.add(l.chave); });
  return res;
}

/** Linhas visíveis do mapa: esconde os descendentes dos nós fechados (toggleMapa, ui_projects.js:5277-5300). */
export function linhasVisiveisMapa<T extends { chave: string; nivel: number }>(linhas: T[], fechados: Set<string>): T[] {
  const res: T[] = [];
  let corte: number | null = null;
  for (const l of linhas) {
    if (corte !== null && l.nivel > corte) continue;
    corte = fechados.has(l.chave) ? l.nivel : null;
    res.push(l);
  }
  return res;
}

/**
 * Nós a fechar em «Fechar tudo» (só ficam as fases) e em «Só tarefas» (fases e tarefas de topo, sem subtarefas:
 * fecham-se só os nós de nível ≥ 1 — mapaSoTarefas, ui_projects.js:5304-5312).
 */
export function fechadosMapa(linhas: { chave: string; nivel: number }[], modo: 'tudo' | 'tarefas'): Set<string> {
  const comFilhos = chavesComFilhosMapa(linhas);
  return new Set(linhas.filter((l) => comFilhos.has(l.chave) && (modo === 'tudo' || l.nivel >= 1)).map((l) => l.chave));
}
