/**
 * Conversão das demonstrações financeiras da API (Balanço, DR, Fluxos de caixa) em linhas planas para uma tabela única
 * (secção / linha / subtotal / total), com o valor do ano e o comparativo.
 */
import type { Balanco, DemonstracaoResultados, FluxoCaixa, LinhaTotal, Seccao } from '../api';

export type TipoLinha = 'seccao' | 'linha' | 'subtotal' | 'total';

export interface LinhaPlana {
  chave: string;
  tipo: TipoLinha;
  descricao: string;
  nota?: string | null;
  atual?: string;
  anterior?: string;
}

function seccao(chave: string, titulo: string, s: Seccao | undefined, rotuloTotal: string): LinhaPlana[] {
  if (!s) return [];
  return [
    { chave: `${chave}-t`, tipo: 'seccao', descricao: titulo },
    ...s.linhas.map((l, i) => ({
      chave: `${chave}-${i}`,
      tipo: (l.subtotal ? 'subtotal' : 'linha') as TipoLinha,
      descricao: l.descricao.trim(),
      nota: l.nota ?? l.codigo ?? null,
      atual: l.atual,
      anterior: l.anterior,
    })),
    { chave: `${chave}-s`, tipo: 'subtotal', descricao: s.total.descricao ?? rotuloTotal, atual: s.total.atual, anterior: s.total.anterior },
  ];
}

function total(chave: string, l: LinhaTotal | undefined, tipo: TipoLinha = 'total'): LinhaPlana[] {
  return l ? [{ chave, tipo, descricao: l.descricao, nota: l.nota ?? null, atual: l.atual, anterior: l.anterior }] : [];
}

export function linhasBalanco(b: Balanco): LinhaPlana[] {
  const s = b.seccoes;
  const t = (k: string) => ({ atual: b.totais.atual[k], anterior: b.totais.anterior[k] });
  return [
    { chave: 'activo', tipo: 'seccao', descricao: 'ACTIVO' },
    ...seccao('anc', 'Activo não corrente', s.activo_nao_corrente, 'Total do activo não corrente'),
    ...seccao('ac', 'Activo corrente', s.activo_corrente, 'Total do activo corrente'),
    { chave: 'total-activo', tipo: 'total', descricao: 'Total do activo', ...t('activo') },
    { chave: 'cpp', tipo: 'seccao', descricao: 'CAPITAL PRÓPRIO E PASSIVO' },
    ...seccao('cp', 'Capital próprio', s.capital_proprio, 'Total do capital próprio'),
    ...seccao('pnc', 'Passivo não corrente', s.passivo_nao_corrente, 'Total do passivo não corrente'),
    ...seccao('pc', 'Passivo corrente', s.passivo_corrente, 'Total do passivo corrente'),
    { chave: 'total-passivo', tipo: 'subtotal', descricao: 'Total do passivo', ...t('passivo') },
    { chave: 'total-cpp', tipo: 'total', descricao: 'Total do capital próprio e passivo', ...t('capital_proprio_passivo') },
  ];
}

export function linhasDR(d: DemonstracaoResultados): LinhaPlana[] {
  return [
    ...seccao('po', 'Proveitos operacionais', d.proveitos_operacionais, 'Total proveitos operacionais'),
    ...seccao('co', 'Custos operacionais', d.custos_operacionais, 'Total custos operacionais'),
    ...total('ro', d.resultados_operacionais),
    ...d.outros_resultados.map((l, i) => ({ chave: `or-${i}`, tipo: 'linha' as TipoLinha, descricao: l.descricao, nota: l.nota ?? null, atual: l.atual, anterior: l.anterior })),
    ...total('rai', d.resultados_antes_impostos),
    ...total('imp', d.imposto, 'linha'),
    ...total('rac', d.resultado_actividades_correntes, 'subtotal'),
    ...total('rex', d.resultados_extraordinarios, 'linha'),
    ...total('rl', d.resultado_liquido),
  ];
}

export function linhasFluxo(f: FluxoCaixa): LinhaPlana[] {
  const s = f.seccoes;
  return [
    ...seccao('op', 'Fluxos de caixa das actividades operacionais', s.operacionais, 'Fluxo das actividades operacionais'),
    ...seccao('inv', 'Fluxos de caixa das actividades de investimento', s.investimento, 'Fluxo das actividades de investimento'),
    ...seccao('fin', 'Fluxos de caixa das actividades de financiamento', s.financiamento, 'Fluxo das actividades de financiamento'),
    { chave: 'var', tipo: 'total', descricao: 'Variação de caixa e seus equivalentes', ...f.variacao_caixa },
    { chave: 'ini', tipo: 'linha', descricao: 'Caixa e equivalentes no início do período', ...f.caixa_inicial },
    { chave: 'fim', tipo: 'total', descricao: 'Caixa e equivalentes no fim do período', ...f.caixa_final },
  ];
}
