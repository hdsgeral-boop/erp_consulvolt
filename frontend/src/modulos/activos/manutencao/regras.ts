import { paraCentimos, deCentimos } from '@/utilitarios/decimal';
import type { EstadoItem, EstadoOrdem, LinhaPlanoRequisicao, Periodicidade, SituacaoPlano, TipoManutencao } from './tipos';

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
