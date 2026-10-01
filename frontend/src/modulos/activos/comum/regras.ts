import { deCentimos, paraCentimos, type Valor } from '@/utilitarios/decimal';
import type { Activo, EstadoPeriodo, LinhaImportacao, LinhaMapa, LinhaPeriodo } from './tipos';

/** Regras de apresentação do módulo Activos (o servidor valida sempre; aqui decide-se só o que se mostra). */

type Pode = (...chaves: string[]) => boolean;

export const MESES_CURTOS = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

/** Código de período da API: «MM-AAAA». */
export function codigoPeriodo(ano: number, mes: number): string {
  return `${String(mes).padStart(2, '0')}-${ano}`;
}

/** «MM-AAAA» → número ordenável (ano × 12 + mês − 1); inválido = NaN. */
export function ordemPeriodo(periodo: string): number {
  const m = /^(\d{2})-(\d{4})$/.exec(periodo);
  if (!m) return Number.NaN;
  return Number(m[2]) * 12 + Number(m[1]) - 1;
}

export function ordenarPeriodos(periodos: string[]): string[] {
  return [...new Set(periodos)].sort((a, b) => ordemPeriodo(a) - ordemPeriodo(b));
}

/** Rótulo legível: «09-2026» → «Set 2026». */
export function rotuloPeriodo(periodo: string): string {
  const m = /^(\d{2})-(\d{4})$/.exec(periodo);
  return m ? `${MESES_CURTOS[Number(m[1]) - 1]} ${m[2]}` : periodo;
}

/** Acções possíveis sobre um período de amortizações, conforme o estado e as permissões. */
export function accoesPeriodo(estado: EstadoPeriodo | string, pode: Pode) {
  return {
    calcular: pode('activos_amort_calcular') && estado !== 'INTEGRADO',
    quotaManual: pode('activos_amort_calcular', 'activos_mapa_editar') && estado !== 'INTEGRADO',
    integrar: pode('activos_amort_integrar') && (estado === 'CALCULADO' || estado === 'PARCIAL'),
    reabrir: pode('activos_amort_anular') && (estado === 'INTEGRADO' || estado === 'PARCIAL'),
  };
}

/** A quota de uma linha só se edita em rascunho ou por calcular (uma quota integrada exige reabrir o período). */
export function quotaEditavel(linha: Pick<LinhaPeriodo, 'estado'>): boolean {
  return linha.estado !== 'INTEGRADO';
}

/** Acções da ficha de um activo. */
export function accoesActivo(a: Pick<Activo, 'estado'> | null | undefined, pode: Pode) {
  const activo = a?.estado === 'ACTIVO';
  return {
    editar: !!a && pode('activos_gerir') && a.estado !== 'ABATIDO',
    eliminar: !!a && pode('activos_eliminar'),
    transferir: activo && pode('activos_gerir'),
    afectar: activo && pode('activos_gerir'),
    abater: activo && pode('activos_abater'),
    integrar: activo && pode('activos_amort_integrar'),
  };
}

/** Totais de uma lista de linhas do mapa (para quando se filtra no cliente). */
export function totaisMapa(linhas: LinhaMapa[]) {
  const meses: Record<string, string> = {};
  for (let m = 1; m <= 12; m++) meses[String(m)] = deCentimos(linhas.reduce((t, l) => t + paraCentimos(l.meses[String(m)]?.valor), 0));
  const soma = (k: keyof LinhaMapa) => deCentimos(linhas.reduce((t, l) => t + paraCentimos(l[k] as Valor), 0));
  return {
    aquisicao_anos_anteriores: soma('aquisicao_anos_anteriores'),
    aquisicao_ano: soma('aquisicao_ano'),
    acumulado_anterior: soma('acumulado_anterior'),
    ano: soma('ano'),
    acumulado: soma('acumulado'),
    liquido: soma('liquido'),
    meses,
  };
}

/** Soma das 12 quotas mensais de uma linha (deve igualar o total anual devolvido pelo servidor). */
export function somaMesesLinha(linha: Pick<LinhaMapa, 'meses'>): string {
  return deCentimos(Object.values(linha.meses).reduce((t, m) => t + paraCentimos(m?.valor), 0));
}

/** Estimativa do resultado de um abate/venda: valor de venda − valor líquido (positivo = mais-valia). */
export function resultadoAbate(valorVenda: Valor, valorAquisicao: Valor, acumulada: Valor): { liquido: string; resultado: string; tipo: 'MAIS_VALIA' | 'MENOS_VALIA' | 'NULO' } {
  const liquido = paraCentimos(valorAquisicao) - paraCentimos(acumulada);
  const resultado = paraCentimos(valorVenda) - liquido;
  return { liquido: deCentimos(liquido), resultado: deCentimos(resultado), tipo: resultado > 0 ? 'MAIS_VALIA' : resultado < 0 ? 'MENOS_VALIA' : 'NULO' };
}

// ───────────── Importação (CSV no cliente → JSON para a API) ─────────────

const CABECALHOS: Record<string, keyof LinhaImportacao> = {
  codigo: 'codigo', 'código': 'codigo', descricao: 'descricao', 'descrição': 'descricao', designacao: 'descricao', 'designação': 'descricao',
  valor: 'valor_aquisicao', valor_aquisicao: 'valor_aquisicao', 'valor de aquisição': 'valor_aquisicao', 'valor aquisicao': 'valor_aquisicao',
  categoria: 'categoria', vida_util: 'vida_util', 'vida útil': 'vida_util', 'vida util': 'vida_util', 'vida útil (meses)': 'vida_util',
  anos_amortizados: 'anos_amortizados', 'anos amortizados': 'anos_amortizados',
  amortizacao_acumulada: 'amortizacao_acumulada', 'amortização acumulada': 'amortizacao_acumulada', 'amortizacao acumulada': 'amortizacao_acumulada',
  ano_amortizacao_acumulada: 'ano_amortizacao_acumulada', 'ano da amortização acumulada': 'ano_amortizacao_acumulada', 'ano acumulada': 'ano_amortizacao_acumulada',
  data_aquisicao: 'data_aquisicao', 'data de aquisição': 'data_aquisicao', 'data aquisicao': 'data_aquisicao', data: 'data_aquisicao',
};

const NUMERICOS: (keyof LinhaImportacao)[] = ['valor_aquisicao', 'vida_util', 'anos_amortizados', 'amortizacao_acumulada', 'ano_amortizacao_acumulada'];

/** Número escrito em pt («1 234,56» ou «1.234,56») ou em formato técnico («1234.56»). */
export function lerNumero(texto: string): number | undefined {
  let t = texto.trim().replace(/\s/g, '');
  if (!t) return undefined;
  if (t.includes(',') && t.includes('.')) t = t.lastIndexOf(',') > t.lastIndexOf('.') ? t.replace(/\./g, '').replace(',', '.') : t.replace(/,/g, '');
  else if (t.includes(',')) t = t.replace(',', '.');
  const n = Number(t);
  return Number.isFinite(n) ? n : undefined;
}

function dividir(linha: string, sep: string): string[] {
  const res: string[] = [];
  let actual = '';
  let aspas = false;
  for (let i = 0; i < linha.length; i++) {
    const c = linha[i];
    if (c === '"') {
      if (aspas && linha[i + 1] === '"') {
        actual += '"';
        i++;
      } else aspas = !aspas;
    } else if (c === sep && !aspas) {
      res.push(actual);
      actual = '';
    } else actual += c;
  }
  res.push(actual);
  return res.map((s) => s.trim());
}

/**
 * Lê um CSV (separador «;», «,» ou tabulação; 1.ª linha = cabeçalhos) e devolve as linhas da importação.
 * Cabeçalhos desconhecidos são ignorados; devolve também a lista dos reconhecidos para mostrar ao utilizador.
 */
export function lerCsvImportacao(texto: string): { linhas: LinhaImportacao[]; colunas: (keyof LinhaImportacao)[]; ignoradas: string[] } {
  const linhasTexto = texto.replace(/^﻿/, '').split(/\r?\n/).filter((l) => l.trim() !== '');
  if (linhasTexto.length < 2) return { linhas: [], colunas: [], ignoradas: [] };
  const primeira = linhasTexto[0];
  const sep = primeira.includes(';') ? ';' : primeira.includes('\t') ? '\t' : ',';
  const cab = dividir(primeira, sep).map((c) => c.toLowerCase().replace(/\s+/g, ' ').trim());
  const mapa = cab.map((c) => CABECALHOS[c] ?? CABECALHOS[c.replace(/ /g, '_')]);
  const linhas: LinhaImportacao[] = [];
  for (const l of linhasTexto.slice(1)) {
    const cel = dividir(l, sep);
    const r: LinhaImportacao = {};
    mapa.forEach((campo, i) => {
      if (!campo) return;
      const v = cel[i] ?? '';
      if (v === '') return;
      if (NUMERICOS.includes(campo)) {
        const n = lerNumero(v);
        if (n !== undefined) (r as Record<string, unknown>)[campo] = n;
      } else (r as Record<string, unknown>)[campo] = v;
    });
    if (Object.keys(r).length) linhas.push(r);
  }
  return { linhas, colunas: mapa.filter((m): m is keyof LinhaImportacao => !!m), ignoradas: cab.filter((_, i) => !mapa[i]) };
}

/** Reparte um valor em n partes iguais ao cêntimo; a última absorve o resto (a soma bate sempre com o total). */
export function repartirValor(total: Valor, n: number): string[] {
  const partes = Math.max(1, Math.floor(n));
  const c = paraCentimos(total);
  const base = Math.trunc(c / partes);
  return Array.from({ length: partes }, (_, i) => deCentimos(i === partes - 1 ? c - base * (partes - 1) : base));
}

/** Valida a inventariação de uma linha 11/12: a soma dos itens não pode exceder o valor por inventariar. */
export function validarInventariacao(itens: { valor_aquisicao?: Valor }[], porInventariar: Valor): { total: string; restante: string; excede: boolean } {
  const total = itens.reduce((t, i) => t + paraCentimos(i.valor_aquisicao), 0);
  const resto = paraCentimos(porInventariar) - total;
  return { total: deCentimos(total), restante: deCentimos(resto), excede: resto < 0 };
}
