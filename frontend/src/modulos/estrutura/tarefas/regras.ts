import type {
  AnaliseEntregaveis, Atraso, Decimal, EstadoImpedimento, EstadoPeriodoEntregavel, EstadoValidacao, Execucao, MetricaEntregavel, Periodicidade, ResultadoSincronizacaoCRM,
} from './tipos';

/**
 * Regras puras dos entregáveis das unidades (legado js/modules/estrutura/estrutura_tarefas.js:55-142; especificação §3.1):
 * prazo «dia D do mês M do período», período servido, limite da entrega, rótulos de estado e agrupamentos para os ecrãs.
 * O servidor valida sempre; isto só serve para mostrar e explicar.
 */

export const PERIODICIDADES: Record<Periodicidade, string> = {
  PONTUAL: 'Pontual',
  DIARIA: 'Diária',
  SEMANAL: 'Semanal',
  MENSAL: 'Mensal',
  TRIMESTRAL: 'Trimestral',
  ANUAL: 'Anual',
};

/** Meses que cada período abrange (§3.1): DIARIA, SEMANAL e PONTUAL valem 1. */
export const MESES_PERIODO: Record<Periodicidade, number> = { PONTUAL: 1, DIARIA: 1, SEMANAL: 1, MENSAL: 1, TRIMESTRAL: 3, ANUAL: 12 };

export const METRICAS: Record<MetricaEntregavel, { nome: string; unidades: string[] }> = {
  QUANTIDADE: { nome: 'Quantidade', unidades: ['un', 'relatórios', 'viaturas', 'm²', 'm³', 'ton', 'km'] },
  HORAS: { nome: 'Horas', unidades: ['h'] },
  OBJECTIVO: { nome: 'Cumprimento de objectivo (%)', unidades: ['%'] },
  PONTOS: { nome: 'Pontos', unidades: ['pontos'] },
  TAREFAS: { nome: 'Tarefas concluídas', unidades: ['tarefas'] },
};

export const nomeMetrica = (m: string | null | undefined): string => METRICAS[(m ?? '') as MetricaEntregavel]?.nome ?? (m ?? '—');

export const VALIDACAO: Record<EstadoValidacao, { rotulo: string; cor: string; dica: string }> = {
  PENDENTE: { rotulo: 'por validar', cor: 'orange', dica: 'À espera do visto do responsável da unidade' },
  VALIDADA: { rotulo: 'validada', cor: 'green', dica: 'Validada pelo responsável' },
  DEVOLVIDA: { rotulo: 'devolvida', cor: 'volcano', dica: 'Devolvida a quem entregou, para corrigir e voltar a submeter' },
  RECUSADA: { rotulo: 'recusada', cor: 'red', dica: 'Recusada pelo responsável — não conta' },
};

export const IMPEDIMENTO: Record<EstadoImpedimento, { rotulo: string; cor: string }> = {
  ABERTO: { rotulo: 'por decidir', cor: 'orange' },
  ACEITE: { rotulo: 'aceite — período justificado', cor: 'green' },
  RECUSADO: { rotulo: 'recusado', cor: 'red' },
};

export const ESTADO_PERIODO: Record<EstadoPeriodoEntregavel, { rotulo: string; cor: string }> = {
  SEM_PRAZO: { rotulo: 'sem prazo', cor: 'default' },
  DENTRO_DO_PRAZO: { rotulo: 'dentro do prazo', cor: 'blue' },
  EM_ATRASO: { rotulo: 'em atraso', cor: 'red' },
  CUMPRIDO: { rotulo: 'cumprido', cor: 'green' },
  JUSTIFICADO: { rotulo: 'justificado', cor: 'gold' },
};

export const num = (v: Decimal | null | undefined): number => {
  const n = typeof v === 'number' ? v : parseFloat(String(v ?? '').replace(',', '.'));
  return Number.isFinite(n) ? n : 0;
};

/** Quantidades com até 4 casas, em português. */
export const fq = (v: Decimal | null | undefined): string => num(v).toLocaleString('pt-PT', { maximumFractionDigits: 4 });

/** Percentagem da meta (até 999 %), ou null sem meta. */
export function pctMeta(realizado: Decimal | null | undefined, meta: Decimal | null | undefined): number | null {
  const m = num(meta);
  if (m <= 0) return null;
  return Math.min(999, Math.round((num(realizado) / m) * 100));
}

/** Opções «de que mês» do prazo: 1..n do período, mais n+1 = o mês seguinte ao período. */
export function opcoesMes(periodicidade: Periodicidade): { value: number; label: string }[] {
  const n = MESES_PERIODO[periodicidade] ?? 1;
  const saida: { value: number; label: string }[] = [];
  for (let m = 1; m <= n; m += 1) saida.push({ value: m, label: n === 1 ? 'do próprio mês' : `${m}.º mês do período` });
  saida.push({ value: n + 1, label: n === 1 ? 'do mês seguinte' : 'do mês seguinte ao período' });
  return saida;
}

/** Texto do prazo («dia 15 do mês seguinte»), ou null sem prazo. */
export function prazoTexto(e: { prazo_dia: number | null; prazo_mes: number }, periodicidade: Periodicidade): string | null {
  if (!e.prazo_dia) return null;
  const n = MESES_PERIODO[periodicidade] ?? 1;
  const mes = e.prazo_mes || 1;
  const qual = mes > n ? (n === 1 ? 'do mês seguinte' : 'do mês seguinte ao período') : n === 1 ? 'do próprio mês' : `do ${mes}.º mês do período`;
  return `dia ${e.prazo_dia} ${qual}`;
}

/* ------------------------------------------------------------------ datas (AAAA-MM-DD, sem fusos) */

const p2 = (n: number) => String(n).padStart(2, '0');
const iso = (a: number, m: number, d: number) => `${a}-${p2(m)}-${p2(d)}`;
const partes = (data: string): [number, number, number] => {
  const [a, m, d] = String(data).slice(0, 10).split('-').map(Number);
  return [a, m, d];
};
export const ultimoDia = (a: number, m: number): number => new Date(Date.UTC(a, m, 0)).getUTCDate();

/** Soma meses a uma data; o dia é encurtado ao último dia do mês de chegada (31 → 30, 28…). */
export function somarMeses(data: string, meses: number): string {
  const [a, m, d] = partes(data);
  const total = a * 12 + (m - 1) + meses;
  const a2 = Math.floor(total / 12);
  const m2 = (total % 12) + 1;
  return iso(a2, m2, Math.min(d, ultimoDia(a2, m2)));
}

/** Início e fim do período civil (mês, trimestre ou ano) que contém a data. */
export function periodoCivil(data: string, periodicidade: Periodicidade): { de: string; ate: string } {
  const [a, m] = partes(data);
  const n = MESES_PERIODO[periodicidade] ?? 1;
  const inicio = n === 1 ? m : Math.floor((m - 1) / n) * n + 1;
  const fim = inicio + n - 1;
  return { de: iso(a, inicio, 1), ate: iso(a, fim, ultimoDia(a, fim)) };
}

/**
 * Período servido por uma entrega (§3.1): recua `prazo_mes − 1` meses e devolve o período civil que contém a data recuada.
 * Ex.: prazo «dia 15 do mês 2» (mês seguinte), entrega a 2026-02-10 serve Janeiro de 2026.
 */
export function periodoServido(e: { prazo_mes: number }, periodicidade: Periodicidade, dataEntrega: string): { de: string; ate: string } {
  const recuo = Math.max(0, (e.prazo_mes || 1) - 1);
  return periodoCivil(somarMeses(dataEntrega, -recuo), periodicidade);
}

/** Limite da entrega do período que começa em `periodoDe`: dia `prazo_dia` do mês `inicio + (prazo_mes − 1)`; dia 31 num mês curto → último dia. */
export function limiteDaEntrega(e: { prazo_dia: number | null; prazo_mes: number }, periodoDe: string): string | null {
  if (!e.prazo_dia) return null;
  const [a, m] = partes(somarMeses(periodoDe, Math.max(0, (e.prazo_mes || 1) - 1)));
  return iso(a, m, Math.min(e.prazo_dia, ultimoDia(a, m)));
}

export function foraDePrazo(e: { prazo_dia: number | null; prazo_mes: number }, periodicidade: Periodicidade, dataEntrega: string): boolean {
  const limite = limiteDaEntrega(e, periodoServido(e, periodicidade, dataEntrega).de);
  return !!limite && limite < dataEntrega.slice(0, 10);
}

/** Período servido corrente: o cujo limite já passou e é o mais recente (o que os atrasos avaliam), senão o actual. */
export function periodoCorrente(e: { prazo_dia: number | null; prazo_mes: number }, periodicidade: Periodicidade, hoje: string): { de: string; ate: string; limite: string | null } {
  const actual = periodoCivil(hoje, periodicidade);
  const n = MESES_PERIODO[periodicidade] ?? 1;
  let p = actual;
  for (let i = 0; i < 24; i += 1) {
    const limite = limiteDaEntrega(e, p.de);
    if (!limite || limite < hoje) return { ...p, limite };
    p = periodoCivil(somarMeses(p.de, -n), periodicidade);
  }
  return { ...actual, limite: limiteDaEntrega(e, actual.de) };
}

/* ------------------------------------------------------------------ unidades e URLs */

const SINONIMOS: Record<string, string> = {
  m2: 'm2', 'm²': 'm2', un: 'un', unidade: 'un', unidades: 'un', h: 'h', hora: 'h', horas: 'h', ton: 'ton', t: 'ton', tonelada: 'ton', toneladas: 'ton', '%': 'pct', pct: 'pct',
};

/** Chave de comparação de uma unidade de medida: sem acentos, minúsculas e com sinónimos (§3.1). */
export function chaveUnidade(u: string | null | undefined): string {
  const base = String(u ?? '').trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, '');
  const semSuper = base.replace('²', '2').replace('³', '3');
  return SINONIMOS[semSuper] ?? SINONIMOS[base] ?? semSuper;
}

/** Só http(s); acrescenta https:// quando falta o esquema; outros esquemas ou credenciais → null. */
export function normalizarUrl(url: string | null | undefined): string | null {
  const s = String(url ?? '').trim();
  if (!s) return null;
  const com = /^[a-z][a-z0-9+.-]*:/i.test(s) ? s : `https://${s}`;
  if (!/^https?:\/\//i.test(com)) return null;
  if (/^https?:\/\/[^/]*@/i.test(com)) return null;
  return com;
}

/* ------------------------------------------------------------------ entregas e agrupamentos */

export const contaComoEntregue = (x: Pick<Execucao, 'validacao'>): boolean => x.validacao === 'PENDENTE' || x.validacao === 'VALIDADA';

/** Botões de validação a mostrar para uma entrega (lista das entregas e aprovações do portal). */
export function accoesValidacao(x: Pick<Execucao, 'validacao' | 'pode_validar' | 'convertida' | 'periodo_produtividade_id'>): { validar: boolean; devolver: boolean; recusar: boolean; repor: boolean } {
  const convertida = !!x.convertida || x.periodo_produtividade_id != null;
  if (convertida || !x.pode_validar) return { validar: false, devolver: false, recusar: false, repor: false };
  return { validar: x.validacao !== 'VALIDADA', devolver: x.validacao !== 'DEVOLVIDA', recusar: x.validacao !== 'RECUSADA', repor: x.validacao !== 'PENDENTE' };
}

export function totalValidado(lista: Execucao[]): number {
  return lista.filter((x) => x.validacao === 'VALIDADA').reduce((s, x) => s + num(x.quantidade), 0);
}

/** Atrasos agrupados por unidade orgânica (faixa do painel e aprovações do portal). */
export function agruparAtrasosPorUnidade(atrasos: Atraso[]): { unidade: string; itens: Atraso[]; multa: number }[] {
  const grupos = new Map<string, Atraso[]>();
  atrasos.forEach((a) => {
    const k = a.unidade?.nome ?? '—';
    grupos.set(k, [...(grupos.get(k) ?? []), a]);
  });
  return [...grupos.entries()].sort((a, b) => a[0].localeCompare(b[0], 'pt')).map(([unidade, itens]) => ({ unidade, itens, multa: totalMultas(itens) }));
}

export const totalMultas = (atrasos: Pick<Atraso, 'multa_valor'>[]): number => atrasos.reduce((s, a) => s + num(a.multa_valor), 0);

/** Resumo da análise da ponte para a produtividade (válidas, excluídas por motivo, erros, total, quem passa a elegível). */
export function resumirAnalise(a: AnaliseEntregaveis | undefined): { validas: number; excluidas: number; erros: number; porMotivo: { codigo: string; motivo: string; n: number }[]; total: number; elegiveis: number } {
  if (!a) return { validas: 0, excluidas: 0, erros: 0, porMotivo: [], total: 0, elegiveis: 0 };
  const porMotivo = new Map<string, { codigo: string; motivo: string; n: number }>();
  (a.excluidas ?? []).forEach((x) => {
    const atual = porMotivo.get(x.codigo) ?? { codigo: x.codigo, motivo: x.motivo, n: 0 };
    atual.n += 1;
    porMotivo.set(x.codigo, atual);
  });
  return { validas: (a.validas ?? []).length, excluidas: (a.excluidas ?? []).length, erros: (a.erros ?? []).length, porMotivo: [...porMotivo.values()], total: num(a.total), elegiveis: (a.por_elegibilizar ?? []).length };
}

/** Contador do separador «Entregáveis» do portal = recusadas + devolvidas (portal_ui.js:160). */
export const contadorEntregaveis = (c: { recusadas: number; devolvidas: number } | undefined): number => (c ? num(c.recusadas) + num(c.devolvidas) : 0);

/** Mensagem da sincronização com o CRM (estrutura_tarefas_ui.js:757-765). */
export function textoSincronizacao(r: ResultadoSincronizacaoCRM): string {
  const partes: string[] = [];
  if (r.criadas) partes.push(`${r.criadas} nova(s)`);
  if (r.actualizadas) partes.push(`${r.actualizadas} data(s) corrigida(s)`);
  if (r.removidas) partes.push(`${r.removidas} retirada(s)`);
  return partes.length ? `Actualizado: ${partes.join(', ')}.` : 'Já estava a par do CRM — nada a mudar.';
}
