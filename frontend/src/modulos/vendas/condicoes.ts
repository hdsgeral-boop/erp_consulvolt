/**
 * Condições de pagamento e moeda da emissão de vendas (A-03; js/condicoes_pagamento_vendas.js e js/moedas_vendas.js do
 * legado). Só preparação do pedido e estimativas no ecrã: o servidor valida o plano (soma 100 %, datas no PRAZO) e calcula
 * os valores oficiais em Kz com as regras AGT (ServicoDocumentosVenda).
 */
import dayjs from 'dayjs';

export type ModoPagamento = 'PRONTO' | 'PRAZO' | 'MARCOS';

export interface Prestacao {
  descricao: string;
  percentagem: number;
  dias: number | null;
  data: string | null;
}

export const DIAS_VALIDADE = [15, 30, 60, 90, 120];
export const DIAS_PRAZO = [30, 60, 90, 120];

/** Modelos de marcos do legado (descrição, %, dias após a data do documento). */
export const MODELOS_MARCOS: { rotulo: string; linhas: [string, number, number][] }[] = [
  { rotulo: '30% / 70%', linhas: [['Adjudicação', 30, 0], ['Entrega / conclusão', 70, 30]] },
  { rotulo: '50% / 50%', linhas: [['Adjudicação', 50, 0], ['Conclusão', 50, 30]] },
  { rotulo: '30% / 40% / 30%', linhas: [['Adjudicação', 30, 0], ['Execução intermédia', 40, 30], ['Conclusão', 30, 60]] },
];

export const somarDias = (dataIso: string, dias: number | null): string | null => (dias == null ? null : dayjs(dataIso).add(dias, 'day').format('YYYY-MM-DD'));

/** Reparte 100 % em n prestações iguais (a última absorve o resto), como o legado. */
export function repartir(n: number): number[] {
  if (n <= 0) return [];
  const base = Math.floor((100 / n) * 100) / 100;
  return Array.from({ length: n }, (_, i) => (i === n - 1 ? Math.round((100 - base * (n - 1)) * 100) / 100 : base));
}

/** Plano «a prazo» a partir dos prazos escolhidos (30/60/90/120 dias…). */
export function planoPrazo(dataIso: string, dias: number[]): Prestacao[] {
  const ordenados = [...new Set(dias)].filter((d) => Number.isFinite(d) && d > 0).sort((a, b) => a - b);
  const pct = repartir(ordenados.length);
  return ordenados.map((d, i) => ({ descricao: `${i + 1}ª prestação (${d} dias)`, percentagem: pct[i], dias: d, data: somarDias(dataIso, d) }));
}

export function planoMarcos(dataIso: string, modelo: number): Prestacao[] {
  return (MODELOS_MARCOS[modelo]?.linhas ?? []).map(([descricao, percentagem, dias]) => ({ descricao, percentagem, dias, data: somarDias(dataIso, dias) }));
}

export const somaPercentagens = (plano: Prestacao[]): number => Math.round(plano.reduce((s, p) => s + (Number(p.percentagem) || 0), 0) * 100) / 100;

/** Erro do plano a mostrar antes de enviar (o servidor repete a validação). */
export function erroPlano(modo: ModoPagamento, plano: Prestacao[]): string | null {
  if (modo === 'PRONTO') return null;
  if (!plano.length) return 'Defina pelo menos uma prestação.';
  if (plano.some((p) => !(Number(p.percentagem) > 0))) return 'Todas as prestações têm de ter percentagem positiva.';
  if (modo === 'PRAZO' && plano.some((p) => !p.data)) return 'No pagamento a prazo todas as prestações têm de ter data.';
  const soma = somaPercentagens(plano);
  return Math.abs(soma - 100) > 0.005 ? `As percentagens somam ${soma} % (têm de somar 100 %).` : null;
}

/** Valor de cada prestação sobre o total do documento (a última fica com o resto, para somar o total ao cêntimo). */
export function valoresPrestacoes(plano: Prestacao[], total: number): number[] {
  let acumulado = 0;
  return plano.map((p, i) => {
    if (i === plano.length - 1) return Math.round((total - acumulado) * 100) / 100;
    const v = Math.round(total * (Number(p.percentagem) || 0)) / 100;
    acumulado += v;
    return v;
  });
}

const arred = (v: number) => Math.round((v + Number.EPSILON) * 100) / 100;
/** IVA por excesso ao cêntimo (regra AGT, CalculadoraDocumento::excessoCentimo). */
const excesso = (v: number) => Math.ceil(Math.round(v * 1e6) / 1e4) / 100;

/**
 * Estimativa dos totais na moeda do documento e do contravalor em Kz (o servidor converte o preço a 6 casas e aplica as
 * regras AGT em Kz). Linhas: quantidade × preço na moeda, IVA % do produto.
 */
export function estimarTotais(linhas: { quantidade?: number; preco?: number; taxa?: number }[], cambio = 1) {
  let liquido = 0;
  let imposto = 0;
  let liquidoKz = 0;
  let impostoKz = 0;
  for (const l of linhas) {
    const q = l.quantidade ?? 0;
    const p = l.preco ?? 0;
    const t = l.taxa ?? 0;
    const v = arred(q * p);
    liquido += v;
    imposto += excesso((v * t) / 100);
    const vKz = arred(q * Math.round(p * cambio * 1e6) / 1e6);
    liquidoKz += vKz;
    impostoKz += excesso((vKz * t) / 100);
  }
  return {
    liquido: arred(liquido), imposto: arred(imposto), total: arred(liquido + imposto),
    liquidoKz: arred(liquidoKz), impostoKz: arred(impostoKz), totalKz: arred(liquidoKz + impostoKz),
  };
}

/** Desvio (%) de um câmbio manual face ao câmbio do dia (o servidor decide com a tolerância configurada). */
export const desvioCambio = (manual: number, referencia: number): number => (referencia > 0 ? Math.round(((manual - referencia) / referencia) * 10000) / 100 : 0);
