/**
 * Folha de salários com UMA COLUNA POR RUBRICA (legado js/modules/rh/folha_salarios.js `tabelaDetalhada`): separação
 * Colaboradores / Avençados, totais e exportação CSV. Só apresentação: os valores vêm do servidor (fotografia do período
 * ou cálculo ao vivo) e nunca são recalculados aqui — os totais são somas exactas das linhas (`somar`, decimal).
 */
import { somar } from '@/utilitarios/decimal';
import { formatarNumero } from '@/utilitarios/formatacao';
import type { ResultadoSalarial } from '../api';
import { colunasRubricasFolha, somaRubrica, totalDescontos } from '../comum/simulacao';
import { ordenarRotulos, PRIORIDADE_REMUNERACOES, RUBRICA_ADIANTAMENTO, RUBRICA_FALTAS, RUBRICA_HORAS_EXTRA, rotuloRubrica } from './rotulosRubricas';

export type GrupoFolha = 'colaboradores' | 'avencados';

/** Separa a folha dos colaboradores (conta de outrem) da folha dos avençados (prestadores, IRT Grupo B). */
export function separarFolhas(resultados: ResultadoSalarial[], nome: (r: ResultadoSalarial) => string): Record<GrupoFolha, ResultadoSalarial[]> {
  const ordenados = [...resultados].sort((a, b) => nome(a).localeCompare(nome(b), 'pt', { numeric: true }));
  return { colaboradores: ordenados.filter((r) => !r.avencado), avencados: ordenados.filter((r) => r.avencado) };
}

/** Soma das rubricas com este rótulo (`rotuloRubrica`; vencimentos ou descontos), como `somaRubrica` do antigo. */
export function valorRubrica(r: ResultadoSalarial, rotulo: string, desconto: boolean): string {
  return somaRubrica(r, rotulo, desconto);
}

export function horasRubrica(r: ResultadoSalarial, rotulo: string): number {
  return (r.rubricas ?? []).filter((x) => x.horas && rotuloRubrica(x.nome) === rotulo).reduce((s, x) => s + Number(x.horas ?? 0), 0);
}

/**
 * Taxa efectiva arredondada à unidade (3 % / 8 % do INSS), lida dos próprios resultados (base INSS > 0); por omissão a
 * taxa legal. Só para o rótulo da coluna.
 */
export function taxaInss(resultados: ResultadoSalarial[], campo: 'inss_trabalhador' | 'inss_patronal', omissao: number): number {
  const r = resultados.find((x) => Number(x.base_inss) > 0 && Number(x[campo]) > 0);
  if (!r) return omissao;
  return Math.round((Number(r[campo]) / Number(r.base_inss)) * 100);
}

export interface ColunaFolha {
  chave: string;
  titulo: string;
  /** Classe de cor do cabeçalho do antigo (fd-base, fd-venc, fd-iliq, fd-desc, fd-liq, fd-pat). */
  classe: 'base' | 'venc' | 'iliq' | 'desc' | 'liq' | 'pat';
  valor: (r: ResultadoSalarial, i: number) => string | number;
  /** Coluna monetária (soma na linha TOTAIS). */
  moeda?: boolean;
  /** Colunas de texto: alinhamento (por omissão ao centro). */
  alinhamento?: 'left' | 'center';
  /** Rótulo da rubrica cujas horas se mostram por baixo do valor (ex.: «H. Extras»). */
  rubrica?: string;
  /** Começa escondida (disponível no menu «Colunas ▾»). */
  ocultaPorOmissao?: boolean;
}

/** Definição das colunas pela ordem do antigo: Nº, Colaborador, NIF, D.C., D.T., vencimentos…, Total Ilíquido, descontos…, INSS, IRT, Total Descontos, Líquido a Receber, INSS Patronal. */
export function colunasFolha(dados: ResultadoSalarial[], nome: (r: ResultadoSalarial) => string, todos: ResultadoSalarial[] = dados): ColunaFolha[] {
  const { vencimentos, descontos } = colunasRubricasFolha(dados);
  const avencados = dados.length > 0 && dados.every((r) => r.avencado);
  const inssT = taxaInss(todos, 'inss_trabalhador', 3);
  const inssP = taxaInss(todos, 'inss_patronal', 8);
  return [
    { chave: 'n', titulo: 'Nº', classe: 'base', valor: (_, i) => i + 1 },
    { chave: 'nome', titulo: avencados ? 'Prestador' : 'Colaborador', classe: 'base', valor: (r) => nome(r) },
    { chave: 'nif', titulo: 'NIF', classe: 'base', alinhamento: 'left', valor: (r) => r.nif ?? '' },
    { chave: 'dc', titulo: 'D.C.', classe: 'base', valor: (r) => formatarNumero(r.dias_contrato) },
    { chave: 'dt', titulo: 'D.T.', classe: 'base', valor: (r) => formatarNumero(r.dias_trabalhados) },
    ...vencimentos.map((n): ColunaFolha => ({ chave: `v:${n}`, titulo: n, classe: 'venc', moeda: true, rubrica: n, valor: (r) => valorRubrica(r, n, false) })),
    { chave: 'bruto', titulo: 'Total Ilíquido', classe: 'iliq', moeda: true, valor: (r) => r.bruto },
    ...descontos.map((n): ColunaFolha => ({ chave: `d:${n}`, titulo: n, classe: 'desc', moeda: true, rubrica: n, valor: (r) => valorRubrica(r, n, true) })),
    ...(avencados ? [] : [{ chave: 'inss', titulo: `INSS ${inssT}%`, classe: 'desc', moeda: true, valor: (r: ResultadoSalarial) => r.inss_trabalhador } as ColunaFolha]),
    { chave: 'irt', titulo: avencados ? 'IRT Grupo B 6,5%' : 'IRT', classe: 'desc', moeda: true, valor: (r) => r.irt },
    { chave: 'tdesc', titulo: 'Total Descontos', classe: 'desc', moeda: true, valor: (r) => totalDescontos(r) },
    { chave: 'liquido', titulo: 'Líquido a Receber', classe: 'liq', moeda: true, valor: (r) => r.liquido },
    ...(avencados ? [] : [{ chave: 'inss_pat', titulo: `INSS Patronal ${inssP}%`, classe: 'pat', moeda: true, valor: (r: ResultadoSalarial) => r.inss_patronal } as ColunaFolha]),
  ];
}

/** Rubricas com coluna fixa no Mapa de Remunerações (H. Extras, Faltas, Adiant.): não entram nas colunas dinâmicas. */
const FIXAS_REMUNERACOES = new Set([RUBRICA_HORAS_EXTRA, RUBRICA_FALTAS, RUBRICA_ADIANTAMENTO]);

/**
 * Colunas dinâmicas do Mapa de Remunerações (legado `loadReportTabs`: `infoCols`): todas as rubricas com valor (vencimentos
 * e descontos, pelo rótulo abreviado) menos «Adiant.», «H. Extras» e «Desc. Falta», pela `priority` do antigo e depois por
 * ordem alfabética. Um rótulo é coluna de desconto se só aparecer como desconto.
 */
export function colunasRubricasRemuneracoes(dados: ResultadoSalarial[]): { rotulo: string; desconto: boolean }[] {
  const venc = new Set<string>();
  const desc = new Set<string>();
  dados.forEach((r) => (r.rubricas ?? []).forEach((x) => {
    if (x.informativa || x.tipo === 'OUTROS' || !(Math.abs(Number(x.valor)) > 0.004)) return;
    const rotulo = rotuloRubrica(x.nome);
    if (!FIXAS_REMUNERACOES.has(rotulo)) (x.tipo === 'DESCONTO' ? desc : venc).add(rotulo);
  }));
  return ordenarRotulos([...venc, ...desc], PRIORIDADE_REMUNERACOES).map((rotulo) => ({ rotulo, desconto: !venc.has(rotulo) }));
}

/** Adiantamentos (legado `advances`): rubricas com «adiantamento» no nome. */
export function valorAdiantamentos(r: ResultadoSalarial): string {
  return somar((r.rubricas ?? []).filter((x) => rotuloRubrica(x.nome) === RUBRICA_ADIANTAMENTO).map((x) => x.valor));
}

/**
 * «Mapa Detalhado de Remunerações (Colunas)» (legado js/app_v2.js `loadReportTabs`, `view-relatorio`): Colaborador, NIF,
 * UN, CC, Nº INSS, Dias Cont., Dias Trab., rubricas…, Total Ilíquido, H. Extras, Faltas, INSS (3%), IRT, Adiant., Líquido.
 * As colunas que só o sistema novo tem (Nº, Total Descontos, INSS Patronal) ficam escondidas por omissão.
 */
export function colunasRemuneracoes(dados: ResultadoSalarial[], nome: (r: ResultadoSalarial) => string, todos: ResultadoSalarial[] = dados): ColunaFolha[] {
  const inssT = taxaInss(todos, 'inss_trabalhador', 3);
  const inssP = taxaInss(todos, 'inss_patronal', 8);
  return [
    { chave: 'n', titulo: 'Nº', classe: 'base', ocultaPorOmissao: true, valor: (_, i) => i + 1 },
    { chave: 'nome', titulo: 'Colaborador', classe: 'base', valor: (r) => nome(r) },
    { chave: 'nif', titulo: 'NIF', classe: 'base', alinhamento: 'left', valor: (r) => r.nif ?? '' },
    { chave: 'un', titulo: 'UN', classe: 'base', alinhamento: 'left', valor: (r) => r.unidade_negocio || '-' },
    { chave: 'cc', titulo: 'CC', classe: 'base', alinhamento: 'left', valor: (r) => r.centro_custo || '-' },
    { chave: 'ninss', titulo: 'Nº INSS', classe: 'base', alinhamento: 'left', valor: (r) => r.numero_inss || '-' },
    { chave: 'dc', titulo: 'Dias Cont.', classe: 'base', valor: (r) => formatarNumero(r.dias_contrato) },
    { chave: 'dt', titulo: 'Dias Trab.', classe: 'base', valor: (r) => formatarNumero(r.dias_trabalhados) },
    ...colunasRubricasRemuneracoes(dados).map(({ rotulo, desconto }): ColunaFolha => ({
      chave: `${desconto ? 'd' : 'v'}:${rotulo}`, titulo: rotulo, classe: desconto ? 'desc' : 'venc', moeda: true, rubrica: rotulo, valor: (r) => valorRubrica(r, rotulo, desconto),
    })),
    { chave: 'bruto', titulo: 'Total Ilíquido', classe: 'iliq', moeda: true, valor: (r) => r.bruto },
    { chave: 'hextras', titulo: 'H. Extras', classe: 'venc', moeda: true, rubrica: RUBRICA_HORAS_EXTRA, valor: (r) => valorRubrica(r, RUBRICA_HORAS_EXTRA, false) },
    { chave: 'faltas', titulo: 'Faltas', classe: 'desc', moeda: true, rubrica: RUBRICA_FALTAS, valor: (r) => valorRubrica(r, RUBRICA_FALTAS, true) },
    { chave: 'inss', titulo: `INSS (${inssT}%)`, classe: 'desc', moeda: true, valor: (r) => r.inss_trabalhador },
    { chave: 'irt', titulo: 'IRT', classe: 'desc', moeda: true, valor: (r) => r.irt },
    { chave: 'adiant', titulo: 'Adiant.', classe: 'desc', moeda: true, valor: (r) => valorAdiantamentos(r) },
    { chave: 'liquido', titulo: 'Líquido', classe: 'liq', moeda: true, valor: (r) => r.liquido },
    { chave: 'tdesc', titulo: 'Total Descontos', classe: 'desc', moeda: true, ocultaPorOmissao: true, valor: (r) => totalDescontos(r) },
    { chave: 'inss_pat', titulo: `INSS Patronal ${inssP}%`, classe: 'pat', moeda: true, ocultaPorOmissao: true, valor: (r) => r.inss_patronal },
  ];
}

/** Cores dos cabeçalhos do mapa do antigo (texto branco sobre fundo escuro). */
export const CORES_CABECALHO: Record<ColunaFolha['classe'], string> = {
  base: '#0f172a', venc: '#14532d', iliq: '#334155', desc: '#7f1d1d', liq: '#166534', pat: '#581c87',
};

/** Totais das colunas monetárias (soma decimal exacta). */
export function totaisFolha(dados: ResultadoSalarial[], colunas: ColunaFolha[]): Record<string, string> {
  return Object.fromEntries(colunas.filter((c) => c.moeda).map((c) => [c.chave, somar(dados.map((r, i) => String(c.valor(r, i))))]));
}

/** Célula CSV segura: aspas duplicadas, separador «;» e sem fórmulas (OWASP: CSV injection). */
function celulaCsv(v: string | number): string {
  let t = String(v ?? '');
  if (/^[=+\-@\t\r]/.test(t) && !/^-?\d/.test(t)) t = `'${t}`;
  return /[";\n\r]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t;
}

const numeroCsv = (v: string | number): string => {
  const n = Number(v);
  return Number.isFinite(n) ? n.toFixed(2).replace('.', ',') : '0,00';
};

/** CSV da folha (separador «;», vírgula decimal, linha TOTAIS) — «Exportar CSV» do antigo, com as colunas visíveis do mapa. */
export function csvFolha(dados: ResultadoSalarial[], colunas: ColunaFolha[]): string {
  const cab = colunas.map((c) => celulaCsv(c.titulo)).join(';');
  const linhas = dados.map((r, i) => colunas.map((c) => (c.moeda ? numeroCsv(c.valor(r, i)) : celulaCsv(c.valor(r, i)))).join(';'));
  const tot = totaisFolha(dados, colunas);
  const total = colunas.map((c, i) => (c.moeda ? numeroCsv(tot[c.chave]) : i === 0 ? `TOTAIS (${dados.length})` : '')).join(';');
  return [cab, ...linhas, ...(dados.length ? [total] : [])].join('\r\n') + '\r\n';
}

/** Descarrega o CSV com BOM UTF-8 (abre bem no Excel em pt-PT). */
export function descarregarCsv(conteudo: string, nomeFicheiro: string): void {
  const blob = new Blob(['﻿', conteudo], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = nomeFicheiro.replace(/[^\w.-]+/g, '_');
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
