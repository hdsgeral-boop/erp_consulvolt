/**
 * Conversão das demonstrações financeiras da API (Balanço, DR, Fluxos de caixa) em linhas planas para uma tabela única
 * (secção / linha / subtotal / total), com o valor do ano e o comparativo.
 */
import type { Balanco, DemonstracaoResultados, FluxoCaixa, LinhaTotal, Seccao } from '../api';

export type TipoLinha = 'seccao' | 'linha' | 'subtotal' | 'total';

/**
 * Realce do legado (js/ui_reports.js:1166-1199 e 1333-1356): `subseccao` (ACTIVO NÃO CORRENTE…), totais da DR a verde
 * (`proveitos`), a vermelho (`custos`) ou azul claro (`resultado`) e o resultado líquido final (`final`).
 */
export type EstiloLinha = 'subseccao' | 'proveitos' | 'custos' | 'resultado' | 'final';

export interface LinhaPlana {
  chave: string;
  tipo: TipoLinha;
  descricao: string;
  nota?: string | null;
  atual?: string;
  anterior?: string;
  /** Base da análise vertical (AV %) — valor do ano corrente do agregado a que a linha pertence. */
  base?: string;
  estilo?: EstiloLinha;
}

interface OpcoesSeccao {
  /** Título da secção como subsecção (fundo cinzento, letra menor). */
  subseccao?: boolean;
  /** Usa sempre `rotuloTotal` (o rótulo do legado) em vez da descrição do total vinda da API. */
  rotuloFixo?: boolean;
  /** Base AV % das linhas (por omissão, o total da secção). */
  baseLinhas?: string;
  /** Base AV % do total da secção. */
  baseTotal?: string;
  estiloTotal?: EstiloLinha;
  /** Sem análise vertical (Fluxo de caixa: o legado usava outra base, a soma dos fluxos positivos). */
  semAV?: boolean;
}

function seccao(chave: string, titulo: string, s: Seccao | undefined, rotuloTotal: string, o: OpcoesSeccao = {}): LinhaPlana[] {
  if (!s) return [];
  const baseLinhas = o.semAV ? undefined : (o.baseLinhas ?? s.total.atual);
  return [
    { chave: `${chave}-t`, tipo: 'seccao', descricao: titulo, ...(o.subseccao ? { estilo: 'subseccao' as const } : {}) },
    ...s.linhas.map((l, i) => ({
      chave: `${chave}-${i}`,
      tipo: (l.subtotal ? 'subtotal' : 'linha') as TipoLinha,
      descricao: l.descricao.trim(),
      nota: l.nota ?? l.codigo ?? null,
      atual: l.atual,
      anterior: l.anterior,
      base: baseLinhas,
    })),
    {
      chave: `${chave}-s`,
      tipo: 'subtotal',
      descricao: o.rotuloFixo ? rotuloTotal : (s.total.descricao ?? rotuloTotal),
      atual: s.total.atual,
      anterior: s.total.anterior,
      base: o.semAV ? undefined : (o.baseTotal ?? s.total.atual),
      ...(o.estiloTotal ? { estilo: o.estiloTotal } : {}),
    },
  ];
}

function total(chave: string, l: LinhaTotal | undefined, tipo: TipoLinha = 'total', extra: Partial<LinhaPlana> = {}): LinhaPlana[] {
  return l ? [{ chave, tipo, descricao: l.descricao, nota: l.nota ?? null, atual: l.atual, anterior: l.anterior, ...extra }] : [];
}

/**
 * Balanço com a estrutura e os rótulos do legado (js/ui_reports.js:1166-1199): ACTIVO › ACTIVO NÃO CORRENTE › notas ›
 * SUBTOTAL…; TOTAL DO ACTIVO; CAPITAL E PASSIVO › CAPITAL PRÓPRIO…; TOTAL DO CAPITAL E PASSIVO. AV % das notas sobre o
 * subtotal do grupo e dos subtotais/totais sobre o total do activo. «Total do passivo» é do sistema novo.
 */
export function linhasBalanco(b: Balanco): LinhaPlana[] {
  const s = b.seccoes;
  const t = (k: string) => ({ atual: b.totais.atual[k], anterior: b.totais.anterior[k] });
  const baseActivo = b.totais.atual.activo;
  const o = (rotulo: string): [string, OpcoesSeccao] => [rotulo, { subseccao: true, rotuloFixo: true, baseTotal: baseActivo }];
  return [
    { chave: 'activo', tipo: 'seccao', descricao: 'ACTIVO' },
    ...seccao('anc', 'ACTIVO NÃO CORRENTE', s.activo_nao_corrente, ...o('SUBTOTAL ACTIVO NÃO CORRENTE')),
    ...seccao('ac', 'ACTIVO CORRENTE', s.activo_corrente, ...o('SUBTOTAL ACTIVO CORRENTE')),
    { chave: 'total-activo', tipo: 'total', descricao: 'TOTAL DO ACTIVO', base: baseActivo, ...t('activo') },
    { chave: 'cpp', tipo: 'seccao', descricao: 'CAPITAL E PASSIVO' },
    ...seccao('cp', 'CAPITAL PRÓPRIO', s.capital_proprio, ...o('SUBTOTAL CAPITAL PRÓPRIO')),
    ...seccao('pnc', 'PASSIVO NÃO CORRENTE', s.passivo_nao_corrente, ...o('SUBTOTAL PASSIVO NÃO CORRENTE')),
    ...seccao('pc', 'PASSIVO CORRENTE', s.passivo_corrente, ...o('SUBTOTAL PASSIVO CORRENTE')),
    { chave: 'total-passivo', tipo: 'subtotal', descricao: 'Total do passivo', base: baseActivo, ...t('passivo') },
    { chave: 'total-cpp', tipo: 'total', descricao: 'TOTAL DO CAPITAL E PASSIVO', base: baseActivo, ...t('capital_proprio_passivo') },
  ];
}

/**
 * DR com a estrutura, os rótulos e os realces do legado (js/ui_reports.js:1333-1356). AV % sobre o total dos proveitos
 * operacionais (as notas de custos sobre o total dos custos).
 */
export function linhasDR(d: DemonstracaoResultados): LinhaPlana[] {
  const tProv = d.proveitos_operacionais?.total.atual;
  const r = { base: tProv, estilo: 'resultado' as const };
  return [
    ...seccao('po', 'PROVEITOS OPERACIONAIS', d.proveitos_operacionais, 'TOTAL PROVEITOS OPERACIONAIS', { rotuloFixo: true, estiloTotal: 'proveitos' }),
    ...seccao('co', 'CUSTOS OPERACIONAIS', d.custos_operacionais, 'TOTAL CUSTOS OPERACIONAIS', { rotuloFixo: true, baseTotal: tProv, estiloTotal: 'custos' }),
    ...total('ro', d.resultados_operacionais && { ...d.resultados_operacionais, descricao: 'RESULTADOS OPERACIONAIS' }, 'total', r),
    ...d.outros_resultados.map((l, i) => ({ chave: `or-${i}`, tipo: 'linha' as TipoLinha, descricao: l.descricao, nota: l.nota ?? null, atual: l.atual, anterior: l.anterior, base: tProv })),
    ...total('rai', d.resultados_antes_impostos && { ...d.resultados_antes_impostos, descricao: 'RESULTADOS ANTES DE IMPOSTOS' }, 'total', r),
    ...total('imp', d.imposto, 'linha', { base: tProv }),
    ...total('rac', d.resultado_actividades_correntes && { ...d.resultado_actividades_correntes, descricao: 'RESULTADO LÍQUIDO DAS ACTIVIDADES CORRENTES' }, 'subtotal', r),
    ...total('rex', d.resultados_extraordinarios, 'linha', { base: tProv }),
    ...total('rl', d.resultado_liquido && { ...d.resultado_liquido, descricao: 'RESULTADO LÍQUIDO DO EXERCÍCIO' }, 'total', { base: tProv, estilo: 'final' }),
  ];
}

/** Fluxos de caixa (legado js/ui_reports.js:1510-1560: títulos de secção cinzentos e totais a azul claro). */
export function linhasFluxo(f: FluxoCaixa): LinhaPlana[] {
  const s = f.seccoes;
  const o: OpcoesSeccao = { subseccao: true, estiloTotal: 'resultado', semAV: true };
  return [
    ...seccao('op', 'Fluxos de caixa das actividades operacionais', s.operacionais, 'Fluxo das actividades operacionais', o),
    ...seccao('inv', 'Fluxos de caixa das actividades de investimento', s.investimento, 'Fluxo das actividades de investimento', o),
    ...seccao('fin', 'Fluxos de caixa das actividades de financiamento', s.financiamento, 'Fluxo das actividades de financiamento', o),
    { chave: 'var', tipo: 'total', descricao: 'Variação de caixa e seus equivalentes', estilo: 'resultado', ...f.variacao_caixa },
    { chave: 'ini', tipo: 'linha', descricao: 'Caixa e equivalentes no início do período', ...f.caixa_inicial },
    { chave: 'fim', tipo: 'total', descricao: 'Caixa e equivalentes no fim do período', estilo: 'resultado', ...f.caixa_final },
  ];
}
