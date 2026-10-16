import dayjs from 'dayjs';
import { deCentimos, paraCentimos, type Valor } from '@/utilitarios/decimal';
import type { GanttGlobal, MapaQuantidades, Projecto, TarefaWbs, Wbs } from './tipos';

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

/**
 * Ícones de acção da linha da Carteira (ui_projects.js:414-417): só ver detalhes (eye, abrir a ficha) e editar (edit), como
 * no antigo; o Gantt do projecto abre-se no separador Planeamento da ficha. Devolve os destinos relativos à Carteira.
 */
export function accoesLinhaCarteira(p: Pick<Projecto, 'id' | 'estado'>, pode: Pode): { icone: string; titulo: string; destino: string }[] {
  const a = accoesProjecto(p, pode);
  return [
    { icone: 'eye', titulo: 'Ver detalhes', destino: String(p.id) },
    ...(a.editar ? [{ icone: 'edit', titulo: 'Editar projecto', destino: `${p.id}?editar=1` }] : []),
  ];
}

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
  ordenarGruposWbs(wbs?.grupos).forEach((g) => g.tarefas.forEach((t) => visitar(t, g.marco?.nome ?? null)));
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
  /** Tem linhas por baixo (fase com tarefas ou tarefa com subtarefas): mostra o botão recolher/expandir. */
  filhos?: boolean;
  /** Fase com início: data alvo, desenhada como losango no fim da barra-resumo (ui_projects.js:1995-2033). */
  alvo?: string | null;
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

/**
 * Numeração WBS automática (1, 1.1, 1.1.2…) pela ordem da árvore: fases pela ordem dos grupos, tarefas e subtarefas
 * pela ordem dentro do pai. Não depende dos filtros (período, níveis, ramos fechados), para o número ficar estável.
 */
export function numeracaoWbs(wbs: Wbs | undefined): Map<string, string> {
  const res = new Map<string, string>();
  const visitar = (t: TarefaWbs, prefixo: string) => {
    res.set(`t-${t.id}`, prefixo);
    t.subtarefas?.forEach((s, i) => visitar(s, `${prefixo}.${i + 1}`));
  };
  let n = 0;
  for (const g of ordenarGruposWbs(wbs?.grupos)) {
    if (!g.marco && !g.tarefas.length) continue;
    n += 1;
    res.set(`m-${g.marco?.id ?? 'sem'}`, String(n));
    g.tarefas.forEach((t, i) => visitar(t, `${n}.${i + 1}`));
  }
  return res;
}

/**
 * Linhas do Gantt de um projecto a partir da WBS: marco → tarefas → subtarefas. Versão Actual: a fase vai da data de
 * início à data alvo (com losango na data alvo quando tem início); com `periodo`, só ficam as tarefas que tocam o
 * intervalo e os pais delas (ganttPeriodo); `fasesFechadas` recolhe as fases e as tarefas indicadas pela chave
 * (toggleGanttFase / toggleGanttWbs); `nivelMaximo` 0 = só fases, 1 = fases e tarefas. O rótulo é o código da tarefa
 * ou, sem código, a numeração WBS automática.
 */
export function linhasGanttWbs(wbs: Wbs | undefined, opcoes: { periodo?: { de?: string | null; ate?: string | null }; fasesFechadas?: Set<string>; nivelMaximo?: number } = {}): LinhaGantt[] {
  const res: LinhaGantt[] = [];
  const de = opcoes.periodo?.de ?? null;
  const ate = opcoes.periodo?.ate ?? null;
  const numeros = numeracaoWbs(wbs);
  const toca = (t: TarefaWbs) => (!de && !ate) || (!!t.data_inicio && (!ate || t.data_inicio <= ate) && (!de || (t.data_fim ?? t.data_inicio) >= de));
  const fica = (t: TarefaWbs): boolean => toca(t) || (t.subtarefas ?? []).some(fica);
  const visitar = (t: TarefaWbs, nivel: number) => {
    if (!fica(t) || (opcoes.nivelMaximo !== undefined && nivel > opcoes.nivelMaximo)) return;
    const chave = `t-${t.id}`;
    const sub = (t.subtarefas ?? []).filter(fica);
    const filhos = sub.length > 0 && (opcoes.nivelMaximo === undefined || nivel + 1 <= opcoes.nivelMaximo);
    res.push({ chave, id: t.id, nome: t.nome, rotulo: t.codigo || numeros.get(chave), nivel, tipo: 'tarefa', inicio: t.data_inicio, fim: t.data_fim, progresso: t.execucao, estado: t.estado, filhos });
    if (!opcoes.fasesFechadas?.has(chave)) sub.forEach((s) => visitar(s, nivel + 1));
  };
  for (const g of ordenarGruposWbs(wbs?.grupos)) {
    if (!g.marco && !g.tarefas.length) continue;
    if ((de || ate) && !g.tarefas.some(fica)) continue;
    const chave = `m-${g.marco?.id ?? 'sem'}`;
    const comInicio = !!g.marco?.data_inicio;
    const filhos = g.tarefas.some(fica) && opcoes.nivelMaximo !== 0;
    res.push({
      chave, nome: g.marco?.nome ?? 'Sem milestone', rotulo: numeros.get(chave), nivel: 0, tipo: 'marco', inicio: g.marco?.data_inicio ?? g.marco?.data ?? null,
      fim: g.marco?.data ?? g.marco?.data_inicio ?? null, progresso: g.execucao, filhos,
      alvo: comInicio && g.marco?.data && g.marco.data !== g.marco.data_inicio ? g.marco.data : null,
    });
    if (!opcoes.fasesFechadas?.has(chave)) g.tarefas.forEach((t) => visitar(t, 1));
  }
  return res;
}

/** Chaves das linhas que se podem recolher (para «Fechar árvore»); com `soTarefas`, só as tarefas (ganttArvore('tarefas')). */
export function chavesRecolhiveis(linhas: LinhaGantt[], soTarefas = false): Set<string> {
  return new Set(linhas.filter((l) => l.filhos && (!soTarefas || l.tipo === 'tarefa')).map((l) => l.chave));
}

/** Liga ou desliga uma chave num conjunto (imutável, para o estado do React). */
export function alternarChave(conjunto: Set<string>, chave: string): Set<string> {
  const n = new Set(conjunto);
  if (n.has(chave)) n.delete(chave);
  else n.add(chave);
  return n;
}

// ───────────── Ligação de tarefas no Gantt (arrastar de uma barra para outra) ─────────────

export type ResultadoLigacao =
  | { ok: true; predecessora: number; tarefa: number; substitui: boolean }
  | { ok: false; erro: string };

/**
 * Ligação criada ao arrastar da barra `origem` para a barra `destino` (projLigarGantt): a origem passa a predecessora
 * do destino. Só entre duas tarefas diferentes; cada tarefa tem uma única predecessora (a nova substitui a actual) e
 * recusa-se já aqui o ciclo evidente (a origem depende, directa ou indirectamente, do destino). O servidor volta a
 * validar tudo (ciclos, subtarefas, data mínima). Devolve null quando a largada não é sobre outra barra.
 */
export function ligacaoPorArrasto(origem: LinhaGantt | undefined, destino: LinhaGantt | undefined, ligacoes: { de: string; para: string }[]): ResultadoLigacao | null {
  if (!origem || !destino || origem.chave === destino.chave) return null;
  if (origem.tipo !== 'tarefa' || destino.tipo !== 'tarefa' || !origem.id || !destino.id) return { ok: false, erro: 'Só se ligam tarefas entre si (fases e projectos não têm precedências).' };
  const predDe = new Map(ligacoes.map((g) => [g.para, g.de]));
  if (predDe.get(destino.chave) === origem.chave) return { ok: false, erro: `«${destino.nome}» já depende de «${origem.nome}».` };
  const vistos = new Set<string>();
  for (let c: string | undefined = origem.chave; c && !vistos.has(c); c = predDe.get(c)) {
    vistos.add(c);
    if (c === destino.chave) return { ok: false, erro: `Ligação circular: «${origem.nome}» já depende de «${destino.nome}».` };
  }
  return { ok: true, predecessora: origem.id, tarefa: destino.id, substitui: predDe.has(destino.chave) };
}

/**
 * Datas novas para cumprir a data mínima devolvida pelo servidor (DEPENDENCIA_DATA): em FF move-se o fim, nos outros
 * tipos o início; a duração mantém-se.
 */
export function datasParaDataMinima(tipo: string | null | undefined, minima: string, inicio: string | null | undefined, fim: string | null | undefined): { data_inicio: string | null; data_fim: string | null } {
  const dur = inicio && fim ? dayjs(fim).diff(dayjs(inicio), 'day') : null;
  if (tipo === 'FF') return { data_fim: minima, data_inicio: dur !== null ? dayjs(minima).subtract(dur, 'day').format('YYYY-MM-DD') : inicio ?? null };
  return { data_inicio: minima, data_fim: dur !== null ? dayjs(minima).add(dur, 'day').format('YYYY-MM-DD') : fim ?? null };
}

// ───────────── Quantidades por período no Gantt ─────────────

export type EstadoPeriodo = 'cumprido' | 'aquem' | 'futuro' | 'sem_previsto';

export interface CelulaQuantidade {
  esquerda: number;
  largura: number;
  periodo: string;
  previsto: number;
  realizado: number;
  estado: EstadoPeriodo;
}

/**
 * Quantidades por período em cada linha do Gantt, a partir do mapa de quantidades (mesma escala e janela do Gantt).
 * Cor (ganttQuantidades, ui_projects.js:1806-1812): verde quando o registado no período chega ao previsto; âmbar quando
 * o período já começou e ficou aquém; cinzento nos períodos futuros. Uma linha com metas em várias unidades (fase
 * mista) fica com a primeira unidade, por ordem alfabética.
 */
export function quantidadesPorPeriodo(mapa: MapaQuantidades | undefined, escala: Escala, hoje = dayjs()): Map<string, { unidade: string; celulas: CelulaQuantidade[] }> {
  const res = new Map<string, { unidade: string; celulas: CelulaQuantidade[] }>();
  if (!mapa?.colunas.length) return res;
  const hojeIso = hoje.format('YYYY-MM-DD');
  const posicoes = mapa.colunas.map((c) => {
    const a = Math.max(0, dayjs(c.de).diff(escala.inicio, 'day'));
    const b = Math.min(escala.dias, dayjs(c.ate).diff(escala.inicio, 'day') + 1);
    return { esquerda: (a / escala.dias) * 100, largura: (Math.max(0, b - a) / escala.dias) * 100, de: c.de, periodo: c.titulo || c.rotulo };
  });
  for (const tab of mapa.quantidades) {
    for (const l of tab.linhas) {
      if (res.has(l.chave)) continue;
      const celulas: CelulaQuantidade[] = [];
      posicoes.forEach((p, i) => {
        const previsto = Number(l.previsto[i] ?? 0);
        const realizado = Number(l.realizado[i] ?? 0);
        if (p.largura <= 0 || (!previsto && !realizado)) return;
        const estado: EstadoPeriodo = p.de > hojeIso ? 'futuro' : !previsto ? 'sem_previsto' : realizado + 1e-9 >= previsto ? 'cumprido' : 'aquem';
        celulas.push({ esquerda: p.esquerda, largura: p.largura, periodo: p.periodo, previsto, realizado, estado });
      });
      res.set(l.chave, { unidade: tab.unidade, celulas });
    }
  }
  return res;
}

/** Escala das quantidades no Gantt: a escolhida ou, em automático, semanas até 62 dias e meses acima (como marcasGantt). */
export function escalaDasQuantidades(escolhida: string | undefined, escala: Escala): 'dia' | 'semana' | 'mes' | 'trimestre' | 'semestre' | 'ano' {
  return (escolhida as 'mes' | undefined) ?? (escala.dias <= 62 ? 'semana' : 'mes');
}

/** Ligações de precedência para o Gantt (o componente só desenha as que ligam duas linhas visíveis). */
export function ligacoesWbs(wbs: Wbs | undefined): { de: string; para: string; tipo: string; desf?: number }[] {
  const res: { de: string; para: string; tipo: string; desf?: number }[] = [];
  const visitar = (t: TarefaWbs) => {
    if (t.tarefa_predecessora_id) res.push({ de: `t-${t.tarefa_predecessora_id}`, para: `t-${t.id}`, tipo: t.tipo_dependencia || 'FS', desf: t.desfasamento_dias ?? 0 });
    t.subtarefas?.forEach(visitar);
  };
  wbs?.grupos.forEach((g) => g.tarefas.forEach(visitar));
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

/**
 * Ritmo do prazo (projectos_dashboard.js:164, 427): execução física ÷ tempo decorrido, em % com 1 casa; ≥ 100 % verde,
 * ≥ 90 % âmbar, abaixo vermelho. `desempenho` vem do servidor como fracção (1,3766 → «137,7 %»).
 */
export function ritmoPrazo(desempenho: number | null | undefined): { texto: string; cor: string } | null {
  if (desempenho === null || desempenho === undefined || !Number.isFinite(desempenho)) return null;
  const pct = desempenho * 100;
  return { texto: `${pct.toLocaleString('pt-PT', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`, cor: desempenho >= 1 ? '#16a34a' : desempenho >= 0.9 ? '#d97706' : '#dc2626' };
}

/** Percentagem com 1 casa à portuguesa (p1 do legado). */
export function pct1(v: number | null | undefined): string {
  return `${Number(v ?? 0).toLocaleString('pt-PT', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
}

/**
 * Ordem das fases na WBS e no Gantt, como no legado (renderProjectPlaneamento, ui_projects.js:753 e 2036-2046): as
 * fases vêm de `project_milestones.where('project_id')`, que o Dexie devolve pela chave primária — a ordem de criação
 * (id). Não é a data de início nem a data alvo. As tarefas sem fase ficam no fim.
 */
export function ordenarGruposWbs<G extends { marco: { id: number } | null }>(grupos: G[] | undefined): G[] {
  return [...(grupos ?? [])].sort((a, b) => (a.marco ? a.marco.id : Number.MAX_SAFE_INTEGER) - (b.marco ? b.marco.id : Number.MAX_SAFE_INTEGER));
}

/** Nº de tarefas de uma fase, com as subtarefas (coluna «Responsável» da linha da fase no Gantt: «N tarefa(s)»). */
export function contarTarefasFase(grupo: { tarefas: TarefaWbs[] }): number {
  const contar = (t: TarefaWbs): number => 1 + (t.subtarefas ?? []).reduce((s, x) => s + contar(x), 0);
  return grupo.tarefas.reduce((s, t) => s + contar(t), 0);
}
