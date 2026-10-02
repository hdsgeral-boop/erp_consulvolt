/**
 * Pagar e receber facturas a partir da folha de caixa (lacuna A-11; legado «Pagar Faturas Fornecedor» /
 * «Receber Faturas Cliente», js/ui_folha_caixa.js:841-1040). Cada documento escolhido dá um movimento da sessão
 * (POST /tesouraria/caixa/sessoes/{id}/movimentos) ligado à factura (venda_id / fatura_compra_id), que o servidor
 * valida contra o saldo em aberto e o sentido, e que só liquida a factura quando a sessão é contabilizada.
 */
import { deCentimos, paraCentimos } from '@/utilitarios/decimal';
import type { Pendente } from './api';

export type ModoLiquidacao = 'PAGAR' | 'RECEBER';

export const CONFIG_LIQUIDACAO: Record<ModoLiquidacao, { tipo: 'PAG' | 'REC'; natureza: Pendente['natureza']; liquidarA: 'D' | 'C'; titulo: string; prefixo: string; botao: string }> = {
  PAGAR: { tipo: 'PAG', natureza: 'A_PAGAR', liquidarA: 'D', titulo: 'Pagar facturas em aberto', prefixo: 'Pagamento', botao: 'Pagar facturas' },
  RECEBER: { tipo: 'REC', natureza: 'A_RECEBER', liquidarA: 'C', titulo: 'Receber facturas em aberto', prefixo: 'Recebimento', botao: 'Receber facturas' },
};

export const chavePendente = (p: Pick<Pendente, 'terceiro_id' | 'codigo_conta' | 'numero_documento'>) => `${p.terceiro_id}|${p.codigo_conta}|${p.numero_documento}`;

/** Motivo por que um documento não se liquida na caixa (null = pode). A folha de caixa é só em Kz (ADR-034). */
export function motivoNaoLiquidavel(p: Pendente, modo: ModoLiquidacao): string | null {
  if (p.codigo_moeda && p.codigo_moeda !== 'AOA') return `Documento em ${p.codigo_moeda}: liquide-o num pagamento/recebimento de tesouraria.`;
  if (paraCentimos(p.saldo) <= 0) return 'Sem saldo em aberto.';
  if (p.liquidar_a !== CONFIG_LIQUIDACAO[modo].liquidarA) return modo === 'PAGAR' ? 'É um documento a receber.' : 'É um documento a pagar.';
  return null;
}

/** Valor a liquidar: positivo e não superior ao saldo em aberto (a mesma tolerância de 1 cêntimo do servidor). */
export function validarValor(valor: number | null | undefined, saldo: string): string | null {
  if (valor === null || valor === undefined || !Number.isFinite(valor) || paraCentimos(valor) <= 0) return 'Indique um valor positivo.';
  if (paraCentimos(valor) > paraCentimos(saldo) + 1) return `Excede o saldo em aberto (${saldo.replace('.', ',')}).`;
  return null;
}

export interface OpcoesLiquidacao {
  data: string;
  nota_demonstracao_id?: number | null;
  nota_fluxo_caixa_id?: number | null;
  unidade_negocio_id?: number | null;
  centro_custo_id?: number | null;
}

/** Corpo do movimento de caixa que liquida um documento (descrição e referência como no legado). */
export function movimentoDeLiquidacao(p: Pendente, valor: number, modo: ModoLiquidacao, o: OpcoesLiquidacao): Record<string, unknown> {
  const c = CONFIG_LIQUIDACAO[modo];
  const nome = p.terceiro?.trim();
  const corpo: Record<string, unknown> = {
    tipo: c.tipo,
    data_documento: o.data,
    conta_contrapartida: p.codigo_conta,
    valor: Number(deCentimos(paraCentimos(valor))),
    descricao: `${c.prefixo} ${p.numero_documento}${nome ? ` - ${nome}` : ''}`.slice(0, 1000),
    terceiro_id: p.terceiro_id,
    numero_documento: p.numero_documento,
    referencia: `${modo === 'PAGAR' ? 'PG' : 'RC'}-DOC ${p.numero_documento}`.slice(0, 100),
  };
  if (p.venda_id) corpo.venda_id = p.venda_id;
  if (p.fatura_compra_id) corpo.fatura_compra_id = p.fatura_compra_id;
  for (const k of ['nota_demonstracao_id', 'nota_fluxo_caixa_id', 'unidade_negocio_id', 'centro_custo_id'] as const) {
    if (o[k]) corpo[k] = o[k];
  }
  return corpo;
}

/** Total dos valores escolhidos (para o resumo do diálogo). */
export function totalLiquidacao(valores: (number | null | undefined)[]): string {
  return deCentimos(valores.reduce<number>((s, v) => s + (v ? paraCentimos(v) : 0), 0));
}

/** Campos de classificação a enviar: só os escolhidos (os vazios mantêm o que está), ou limpos se pedido. */
export function camposClassificacao(v: { nota_demonstracao_id?: number | null; nota_fluxo_caixa_id?: number | null; unidade_negocio_id?: number | null; centro_custo_id?: number | null }, limpar: string[] = []) {
  const saida: Record<string, number | null> = {};
  for (const k of ['nota_demonstracao_id', 'nota_fluxo_caixa_id', 'unidade_negocio_id', 'centro_custo_id'] as const) {
    if (limpar.includes(k)) saida[k] = null;
    else if (v[k]) saida[k] = v[k] as number;
  }
  return saida;
}
