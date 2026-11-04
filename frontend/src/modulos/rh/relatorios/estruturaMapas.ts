import type { ResultadoSalarial } from '../api';
import { paraCentimos, deCentimos } from '../comum/regras';

/**
 * Estrutura dos mapas de INSS e de IRT («Modelo Oficial — Angola») em Relatórios e Recibos. Tudo o que é valor vem do cálculo do
 * servidor (MotorSalarial → `ResultadoSalarial`): o INSS do trabalhador e da empresa são os dos recibos e da folha, e a base de
 * incidência (`base_inss`) é a que o servidor usou. As percentagens dos cabeçalhos vêm da configuração do INSS da empresa.
 */

export type ChaveSubsidioIrt = 'horas_extras' | 'subs_alimentacao' | 'subs_transporte' | 'subs_ferias' | 'sal_base' | 'subs_comunicacao';

const norm = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** Classifica a rubrica de vencimento pelo nome (as rubricas são da empresa; o servidor não tem um tipo para cada uma). */
export function classificarRubrica(nome: string): ChaveSubsidioIrt | null {
  const n = norm(nome);
  if (/hora.*extra|h\.? ?extra/.test(n)) return 'horas_extras';
  if (/aliment/.test(n)) return 'subs_alimentacao';
  if (/transport/.test(n)) return 'subs_transporte';
  if (/comunica/.test(n)) return 'subs_comunicacao';
  if (/ferias/.test(n)) return 'subs_ferias';
  if (/(salario|vencimento|sal\.?)\s*base|^salario$|^vencimento$/.test(n)) return 'sal_base';
  return null;
}

/** Valores das colunas por rubrica (em cêntimos somados) e o resto (o que não tem coluna própria) de um resultado. */
export function subsidiosDe(r: Pick<ResultadoSalarial, 'rubricas' | 'bruto'>): Record<ChaveSubsidioIrt | 'outros', string> {
  const acc: Record<string, number> = { horas_extras: 0, subs_alimentacao: 0, subs_transporte: 0, subs_ferias: 0, sal_base: 0, subs_comunicacao: 0 };
  for (const x of r.rubricas ?? []) {
    if (x.tipo !== 'VENCIMENTO' || x.informativa || x.falta) continue;
    const k = classificarRubrica(x.nome);
    if (k) acc[k] += paraCentimos(x.valor);
  }
  const listados = Object.values(acc).reduce((s, v) => s + v, 0);
  return { ...Object.fromEntries(Object.entries(acc).map(([k, v]) => [k, deCentimos(v)])), outros: deCentimos(paraCentimos(r.bruto) - listados) } as Record<ChaveSubsidioIrt | 'outros', string>;
}

export interface LinhaInss {
  id: number;
  colaborador: string;
  numero_inss: string;
  sal_base: string;
  subs_adic: string;
  total_base: string;
  trab: string;
  empresa: string;
  total: string;
}

/**
 * Linha do mapa de INSS: SAL. BASE (rubrica de salário base) + SUBS. ADIC. (o resto da base de incidência, que pode ser negativo
 * quando há descontos por faltas) = TOTAL BASE (a `base_inss` do servidor); TRAB., EMPRESA e TOTAL são os do cálculo do servidor.
 */
export function linhaInss(r: ResultadoSalarial, nome: string, numeroInss: string): LinhaInss {
  const sal = subsidiosDe(r).sal_base;
  const totalBase = r.base_inss;
  return {
    id: r.colaborador_id, colaborador: nome, numero_inss: numeroInss || '---', sal_base: sal, subs_adic: deCentimos(paraCentimos(totalBase) - paraCentimos(sal)), total_base: totalBase,
    trab: r.inss_trabalhador, empresa: r.inss_patronal, total: deCentimos(paraCentimos(r.inss_trabalhador) + paraCentimos(r.inss_patronal)),
  };
}

/** Rótulo de percentagem do cabeçalho: «3 %» → «(3%)» (vem da configuração, nunca fixo). */
export function rotuloPercentagem(p: number | undefined | null): string {
  return p === undefined || p === null ? '' : ` (${String(p).replace('.', ',')}%)`;
}

export interface LinhaIrtOficial {
  id: number;
  ord: number;
  nif: string;
  numero_ss: string;
  nome: string;
  provincia: string;
  municipio: string;
  periodo: string;
  horas_extras: string;
  subs_alimentacao: string;
  subs_transporte: string;
  subs_ferias: string;
  sal_base: string;
  subs_comunicacao: string;
  outros: string;
  total_bruto: string;
  inss_trabalhador: string;
  isencoes: string;
  materia_colectavel: string;
  parcela_fixa: string | null;
  taxa: number | null;
  excesso: string | null;
  imposto_devido: string | null;
  irt_retido: string;
}

/** Linha do Mapa Fiscal de IRT (Modelo Oficial — Angola): identificação, rubricas, bruto e o cálculo do IRT do servidor. */
export function linhaIrtOficial(r: ResultadoSalarial, ord: number, d: { nome: string; nif: string; inss: string; provincia: string; municipio: string }, periodo: string): LinhaIrtOficial {
  const s = subsidiosDe(r);
  const e = r.irt_escalao;
  return {
    id: r.colaborador_id, ord, nif: d.nif || '---', numero_ss: d.inss || '---', nome: d.nome, provincia: d.provincia || '---', municipio: d.municipio || '---', periodo,
    horas_extras: s.horas_extras, subs_alimentacao: s.subs_alimentacao, subs_transporte: s.subs_transporte, subs_ferias: s.subs_ferias, sal_base: s.sal_base,
    subs_comunicacao: s.subs_comunicacao, outros: s.outros, total_bruto: r.bruto, inss_trabalhador: r.inss_trabalhador, isencoes: r.isencoes, materia_colectavel: r.base_irt,
    parcela_fixa: e ? String(e.fixo) : null, taxa: e ? Number(e.taxa) : null, excesso: e ? String(e.excesso) : null, imposto_devido: e ? String(e.devido) : null, irt_retido: r.irt,
  };
}
