import { paraCentimos, deCentimos } from '@/utilitarios/decimal';
import type { AccaoHistorico, EstadoItem, EstadoOrdem, LinhaPlanoRequisicao, Periodicidade, ProgressoTarefas, SituacaoPlano, SituacaoTarefas, Tarefa, TipoManutencao } from './tipos';

/** Catálogos e regras de apresentação da manutenção (js/activos_manutencao.js:53-81). O servidor valida e decide. */

export const TIPOS: Record<TipoManutencao, string> = {
  PREVENTIVA: 'Preventiva',
  CORRECTIVA: 'Correctiva',
  LEGAL: 'Inspecção legal',
  CALIBRACAO: 'Calibração / aferição',
  MELHORIA: 'Melhoria',
};

export const UNIDADES: Record<Periodicidade['unidade'], string> = {
  DIAS: 'dias', SEMANAS: 'semanas', MESES: 'meses', ANOS: 'anos', HORAS: 'horas de trabalho', KM: 'quilómetros',
};
export const UNIDADES_TEMPO: Periodicidade['unidade'][] = ['DIAS', 'SEMANAS', 'MESES', 'ANOS'];
export const UNIDADES_CONTADOR: Periodicidade['unidade'][] = ['HORAS', 'KM'];

export const PRIORIDADES = { BAIXA: 'Baixa', NORMAL: 'Normal', ALTA: 'Alta', URGENTE: 'Urgente' } as const;

export const ESTADOS_ORDEM: Record<EstadoOrdem, { rotulo: string; cor: string }> = {
  PLANEADA: { rotulo: 'Planeada', cor: 'default' },
  AGUARDA_MATERIAL: { rotulo: 'Aguarda material', cor: 'gold' },
  EM_CURSO: { rotulo: 'Em curso', cor: 'blue' },
  CONCLUIDA: { rotulo: 'Concluída', cor: 'green' },
  CANCELADA: { rotulo: 'Cancelada', cor: 'red' },
};

export const ESTADOS_ITEM: Record<EstadoItem, string> = {
  POR_PEDIR: 'Por pedir',
  REQUISITADO: 'Requisitado ao armazém',
  EM_COMPRA: 'Em pedido de compra',
  RECEBIDO: 'Recebido',
  CANCELADO: 'Cancelado',
};

export const ABERTAS: EstadoOrdem[] = ['PLANEADA', 'AGUARDA_MATERIAL', 'EM_CURSO'];

export const COR_SITUACAO: Record<SituacaoPlano['chave'], string> = {
  VENCIDO: 'red', A_VENCER: 'gold', EM_DIA: 'green', SEM_DATA: 'default', INACTIVO: 'default',
};

export const ordemAberta = (estado: EstadoOrdem | string | null | undefined) => ABERTAS.includes(estado as EstadoOrdem);

/** «Cada 3 meses», «Cada 250 horas de trabalho». */
export function textoPeriodicidade(p: Periodicidade | null | undefined): string {
  if (!p) return '—';
  return `Cada ${p.intervalo} ${UNIDADES[p.unidade] ?? ''}`.trim();
}

/** Unidades válidas para o modo escolhido (o servidor recusa a unidade de outro modo). */
export function unidadesDoModo(modo: Periodicidade['modo']): Periodicidade['unidade'][] {
  return modo === 'CONTADOR' ? UNIDADES_CONTADOR : UNIDADES_TEMPO;
}

/** Custo total = materiais + serviços + mão-de-obra (custoTotal :316), em cêntimos. */
export function custoTotal(o: { custo_materiais?: string | null; custo_servicos?: string | null; custo_mao_obra?: string | null }): string {
  return deCentimos(paraCentimos(o.custo_materiais) + paraCentimos(o.custo_servicos) + paraCentimos(o.custo_mao_obra));
}

export function destinoTexto(d: 'ARMAZEM' | 'COMPRA' | 'AMBOS' | null | undefined): string {
  return d === 'AMBOS' ? 'Armazém + compras' : d === 'ARMAZEM' ? 'Armazém' : d === 'COMPRA' ? 'Compras' : '—';
}

/** Resumo da pré-visualização: quantas linhas vão à requisição e quantas ao pedido de compra (assistenteRequisicao, UI :614-618). */
export function resumoRequisicao(linhas: LinhaPlanoRequisicao[]): { armazem: number; compra: number; bloqueadas: number } {
  return {
    armazem: linhas.filter((l) => Number(l.para_armazem) > 0).length,
    compra: linhas.filter((l) => Number(l.para_compra) > 0).length,
    bloqueadas: linhas.filter((l) => l.bloqueada).length,
  };
}

/** Quantidade sem zeros à direita («6.000» → «6», «1.500» → «1,5»). */
export function quantidade(v: string | number | null | undefined): string {
  if (v === null || v === undefined || v === '') return '—';
  const n = Number(v);
  return Number.isFinite(n) ? n.toLocaleString('pt-PT', { maximumFractionDigits: 3 }) : String(v);
}

// ───────────── Tarefas da ordem (r3_activos) ─────────────

export const SITUACOES_TAREFAS: Record<SituacaoTarefas, { rotulo: string; cor: string }> = {
  SEM_TAREFAS: { rotulo: 'Sem tarefas', cor: 'default' },
  POR_INICIAR: { rotulo: 'Pendente', cor: 'default' },
  EM_CURSO: { rotulo: 'Em curso', cor: 'blue' },
  PRONTA: { rotulo: 'Pronta a concluir', cor: 'green' },
};

export const ACCOES_HISTORICO: Record<AccaoHistorico, string> = {
  ORDEM_INICIADA: 'Ordem iniciada',
  ORDEM_CONCLUIDA: 'Ordem concluída',
  ORDEM_REABERTA: 'Ordem reaberta',
  ORDEM_CANCELADA: 'Ordem cancelada',
  TAREFA_CONCLUIDA: 'Tarefa concluída',
  TAREFA_REABERTA: 'Tarefa reaberta',
  TAREFA_ACRESCENTADA: 'Tarefa acrescentada',
  TAREFA_RETIRADA: 'Tarefa retirada',
};

/** Progresso calculado no cliente (igual ao do servidor) — para listas antigas sem «progresso». */
export function progressoTarefas(tarefas: Tarefa[] | null | undefined): ProgressoTarefas {
  const t = tarefas ?? [];
  const total = t.length;
  const feitas = t.filter((x) => x.feito).length;
  const obrigatorias = t.filter((x) => x.obrigatoria !== false).length;
  const porFazer = t.filter((x) => x.obrigatoria !== false && !x.feito).length;
  return {
    total, feitas, obrigatorias, obrigatorias_por_fazer: porFazer, opcionais_por_fazer: total - feitas - porFazer,
    percentagem: total ? Math.floor((feitas * 100) / total) : 0,
    situacao: total === 0 ? 'SEM_TAREFAS' : porFazer === 0 ? 'PRONTA' : feitas === 0 ? 'POR_INICIAR' : 'EM_CURSO',
  };
}

/** «2 de 3 feitas». */
export const textoProgresso = (p: Pick<ProgressoTarefas, 'feitas' | 'total'>) => (p.total ? `${p.feitas} de ${p.total} feita${p.total === 1 ? '' : 's'}` : 'Sem tarefas');

/** A ordem só se conclui com todas as tarefas obrigatórias feitas (o servidor confirma: TAREFAS_PENDENTES). */
export const podeConcluirTarefas = (p: Pick<ProgressoTarefas, 'obrigatorias_por_fazer'>) => p.obrigatorias_por_fazer === 0;

/** Tarefas da ordem avulsa: texto (obrigatória por omissão) ou {texto, obrigatoria}; vazias fora. */
export function tarefasParaEnviar(lista: unknown): { texto: string; obrigatoria: boolean }[] {
  if (!Array.isArray(lista)) return [];
  return lista
    .map((t) => (typeof t === 'string' ? { texto: t, obrigatoria: true }
      : t && typeof t === 'object' ? { texto: String((t as { texto?: unknown }).texto ?? ''), obrigatoria: (t as { obrigatoria?: unknown }).obrigatoria !== false } : null))
    .filter((t): t is { texto: string; obrigatoria: boolean } => !!t && t.texto.trim() !== '')
    .map((t) => ({ ...t, texto: t.texto.trim() }));
}
