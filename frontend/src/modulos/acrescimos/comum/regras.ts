import dayjs from 'dayjs';
import { deCentimos, paraCentimos, type Valor } from '@/utilitarios/decimal';
import type { Definicoes, ItemAD, LinhaProposta, NaturezaAD, TipoAD } from './tipos';

/** Regras de apresentação dos Acréscimos e Diferimentos (o servidor valida e calcula sempre). */

type Pode = (...chaves: string[]) => boolean;

export const FECHADOS = ['REGULARIZADO', 'CONCLUIDO', 'ANULADO'];

/** Acções possíveis num registo, conforme o tipo, o estado e as permissões. */
export function accoesItem(it: Pick<ItemAD, 'tipo' | 'estado' | 'tem_lancamentos'> | null | undefined, pode: Pode, temLancamentos = it?.tem_lancamentos ?? false) {
  const editar = pode('ad_editar');
  const aberto = !!it && !FECHADOS.includes(it.estado);
  return {
    editar: editar && aberto,
    /** Com lançamentos só se alteram as notas e a data limite. */
    edicaoParcial: editar && aberto && temLancamentos,
    eliminar: editar && !!it && !temLancamentos,
    regularizar: editar && !!it && it.tipo === 'ACRESCIMO' && (it.estado === 'ACTIVO' || it.estado === 'A_REGULARIZAR'),
    terminar: editar && !!it && it.estado === 'ACTIVO',
    desfazer: editar && !!it && (it.estado === 'A_REGULARIZAR' || it.estado === 'A_TERMINAR'),
  };
}

/** Conta 37 por omissão para o tipo e a natureza (definições do módulo). */
export function contaBalancoPadrao(def: Pick<Definicoes, 'contas'> | undefined, tipo: TipoAD | undefined, natureza: NaturezaAD | undefined): string | undefined {
  if (!def || !tipo || !natureza) return undefined;
  return def.contas[`${tipo}_${natureza}` as keyof Definicoes['contas']] ?? undefined;
}

/** Soma das quotas do plano (deve igualar o valor do registo: a última absorve o arredondamento). */
export function somaQuotas(quotas: { valor: Valor }[]): string {
  return deCentimos(quotas.reduce((t, q) => t + paraCentimos(q.valor), 0));
}

/** Diferença entre o valor do registo e a soma das quotas (0 = plano equilibrado). */
export function diferencaPlano(valor: Valor, quotas: { valor: Valor }[]): string {
  return deCentimos(paraCentimos(valor) - paraCentimos(somaQuotas(quotas)));
}

/** Totais das linhas seleccionadas da proposta (número e valor). */
export function totaisSeleccao(linhas: LinhaProposta[], chaves: string[]): { n: number; total: string } {
  const s = new Set(chaves);
  const escolhidas = linhas.filter((l) => s.has(l.chave));
  return { n: escolhidas.length, total: deCentimos(escolhidas.reduce((t, l) => t + paraCentimos(l.valor), 0)) };
}

/** Mês da proposta (AAAA-MM) e rótulo legível. */
export function mesApi(d: dayjs.Dayjs): string {
  return d.format('YYYY-MM');
}

export function rotuloMes(mes: string): string {
  const d = dayjs(`${mes}-01`);
  return d.isValid() ? d.format('MMMM [de] YYYY') : mes;
}

/** Data de fim sugerida por um modelo (n meses a partir do início, até à véspera). */
export function fimPorMeses(inicio: dayjs.Dayjs, meses: number): dayjs.Dayjs {
  return inicio.add(Math.max(1, meses), 'month').subtract(1, 'day');
}
