/**
 * Regras de apresentação do Orçamento. Os valores mensais vêm como números: as somas fazem-se em cêntimos
 * inteiros para não acumular erros de vírgula flutuante. O servidor recalcula e valida sempre.
 */

import type { NaturezaRubrica, Orcamento } from './tipos';

type Pode = (...chaves: string[]) => boolean;

export const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

const c = (v: number | string | null | undefined) => {
  const n = typeof v === 'number' ? v : Number(v ?? 0);
  return Number.isFinite(n) ? Math.round(n * 100) : 0;
};
const deC = (x: number) => x / 100;

/** 12 valores mensais normalizados (faltas = 0). */
export function doze(valores: (number | string | null | undefined)[] | null | undefined): number[] {
  return Array.from({ length: 12 }, (_, i) => deC(c(valores?.[i])));
}

/** Total anual de uma linha (soma dos 12 meses ao cêntimo). */
export function totalAnual(valores: (number | string | null | undefined)[] | null | undefined): number {
  return deC(doze(valores).reduce((t, v) => t + c(v), 0));
}

/** Totais por mês de várias linhas (+ total anual na posição 12). Com `sinal`, entradas somam e saídas subtraem. */
export function totaisMensais(linhas: { valores: number[]; natureza?: NaturezaRubrica }[], comSinal = false): number[] {
  const t = Array.from({ length: 13 }, () => 0);
  for (const l of linhas) {
    const s = comSinal ? sinal(l.natureza) : 1;
    doze(l.valores).forEach((v, i) => {
      t[i] += s * c(v);
      t[12] += s * c(v);
    });
  }
  return t.map(deC);
}

/** +1 para proveitos e recebimentos, −1 para custos e pagamentos. */
export function sinal(natureza: NaturezaRubrica | undefined): 1 | -1 {
  return natureza === 'PROVEITO' || natureza === 'RECEBIMENTO' ? 1 : -1;
}

/** Reparte um total anual por 12 meses iguais ao cêntimo; o último mês absorve o resto. */
export function repartirAnual(total: number): number[] {
  const t = c(total);
  const base = Math.trunc(t / 12);
  return Array.from({ length: 12 }, (_, i) => deC(i === 11 ? t - base * 11 : base));
}

/** Reparte um total pelos pesos indicados (ex.: sazonalidade ou percentagens), ao cêntimo; o último absorve o resto. */
export function repartirPorPesos(total: number, pesos: number[]): number[] {
  const soma = pesos.reduce((a, b) => a + Math.max(0, b), 0);
  if (!soma) return repartirAnual(total);
  const t = c(total);
  let acum = 0;
  return pesos.map((p, i) => {
    if (i === pesos.length - 1) return deC(t - acum);
    const v = Math.round((t * Math.max(0, p)) / soma);
    acum += v;
    return deC(v);
  });
}

/** As percentagens da repartição top-down manual têm de somar 100 (tolerância de 0,01). */
export function percentagensValidas(pcts: number[]): boolean {
  return pcts.length > 0 && pcts.every((p) => p >= 0) && Math.abs(pcts.reduce((a, b) => a + b, 0) - 100) < 0.01;
}

/** Acções sobre um orçamento conforme o estado, as permissões e se o utilizador é o responsável do contributo. */
export function accoesOrcamento(o: Pick<Orcamento, 'estado' | 'responsavel' | 'submetido_por' | 'orcamento_pai_id'> | null | undefined, pode: Pode, utilizador?: string | null) {
  const responsavel = !!o?.responsavel && o.responsavel === utilizador;
  const rascunho = o?.estado === 'RASCUNHO';
  return {
    editarValores: rascunho && (pode('orc_editar') || (responsavel && pode('orc_contributo'))),
    submeter: rascunho && (pode('orc_submeter') || (responsavel && pode('orc_contributo'))),
    aprovar: o?.estado === 'SUBMETIDO' && pode('orc_aprovar') && o.submetido_por !== utilizador,
    devolver: o?.estado === 'SUBMETIDO' && pode('orc_aprovar'),
    novaVersao: o?.estado === 'APROVADO' && pode('orc_editar'),
    eliminar: rascunho && pode('orc_editar'),
    hierarquia: rascunho && pode('orc_hierarquia') && !o?.orcamento_pai_id,
  };
}

/** Desvio favorável: acima do orçado nas entradas, abaixo nas saídas. */
export function desvioFavoravel(natureza: NaturezaRubrica, orcado: number, realizado: number): boolean {
  return sinal(natureza) * (realizado - orcado) >= 0;
}

/** Cor do estado do monitor de consumo. */
export function corMonitor(estado: string): string {
  return estado === 'EXCEDIDO' ? 'red' : estado === 'AVISO' ? 'orange' : estado === 'SEM_DOTACAO' ? 'default' : 'green';
}
