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
  /** AV % sobre o valor absoluto (Fluxo de caixa: pagamentos negativos mostram a mesma percentagem — js/ui_reports.js:1451-1455). */
  avAbsoluto?: boolean;
  estilo?: EstiloLinha;
  /**
   * Rubrica para o «cálculo» da linha (GET /contabilidade/relatorios/composicao/{tipo}): o código da nota, `res_liq`,
   * a chave da secção (ex.: `activo_corrente`, `total_custos`, `operacionais`) ou do total. Sem rubrica, a linha não abre.
   */
  rubrica?: string;
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
  /** Sem análise vertical na secção (a base é posta depois, como no Fluxo de caixa). */
  semAV?: boolean;
  /** Rubrica do total da secção (cálculo detalhado). */
  rubricaTotal?: string;
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
      rubrica: l.codigo ?? l.nota ?? undefined,
    })),
    {
      chave: `${chave}-s`,
      tipo: 'subtotal',
      descricao: o.rotuloFixo ? rotuloTotal : (s.total.descricao ?? rotuloTotal),
      atual: s.total.atual,
      anterior: s.total.anterior,
      base: o.semAV ? undefined : (o.baseTotal ?? s.total.atual),
      ...(o.estiloTotal ? { estilo: o.estiloTotal } : {}),
      ...(o.rubricaTotal ? { rubrica: o.rubricaTotal } : {}),
    },
  ];
}

function total(chave: string, l: LinhaTotal | undefined, tipo: TipoLinha = 'total', extra: Partial<LinhaPlana> = {}): LinhaPlana[] {
  return l ? [{ chave, tipo, descricao: l.descricao, nota: l.nota ?? null, atual: l.atual, anterior: l.anterior, ...(l.nota ? { rubrica: l.nota } : {}), ...extra }] : [];
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
  const o = (rotulo: string, rubricaTotal: string): [string, OpcoesSeccao] => [rotulo, { subseccao: true, rotuloFixo: true, baseTotal: baseActivo, rubricaTotal }];
  return [
    { chave: 'activo', tipo: 'seccao', descricao: 'ACTIVO' },
    ...seccao('anc', 'ACTIVO NÃO CORRENTE', s.activo_nao_corrente, ...o('SUBTOTAL ACTIVO NÃO CORRENTE', 'activo_nao_corrente')),
    ...seccao('ac', 'ACTIVO CORRENTE', s.activo_corrente, ...o('SUBTOTAL ACTIVO CORRENTE', 'activo_corrente')),
    { chave: 'total-activo', tipo: 'total', descricao: 'TOTAL DO ACTIVO', base: baseActivo, rubrica: 'activo', ...t('activo') },
    { chave: 'cpp', tipo: 'seccao', descricao: 'CAPITAL E PASSIVO' },
    ...seccao('cp', 'CAPITAL PRÓPRIO', s.capital_proprio, ...o('SUBTOTAL CAPITAL PRÓPRIO', 'capital_proprio')),
    ...seccao('pnc', 'PASSIVO NÃO CORRENTE', s.passivo_nao_corrente, ...o('SUBTOTAL PASSIVO NÃO CORRENTE', 'passivo_nao_corrente')),
    ...seccao('pc', 'PASSIVO CORRENTE', s.passivo_corrente, ...o('SUBTOTAL PASSIVO CORRENTE', 'passivo_corrente')),
    { chave: 'total-passivo', tipo: 'subtotal', descricao: 'Total do passivo', base: baseActivo, rubrica: 'passivo', ...t('passivo') },
    { chave: 'total-cpp', tipo: 'total', descricao: 'TOTAL DO CAPITAL E PASSIVO', base: baseActivo, rubrica: 'capital_proprio_passivo', ...t('capital_proprio_passivo') },
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
    ...seccao('po', 'PROVEITOS OPERACIONAIS', d.proveitos_operacionais, 'TOTAL PROVEITOS OPERACIONAIS', { rotuloFixo: true, estiloTotal: 'proveitos', rubricaTotal: 'total_proveitos' }),
    ...seccao('co', 'CUSTOS OPERACIONAIS', d.custos_operacionais, 'TOTAL CUSTOS OPERACIONAIS', { rotuloFixo: true, baseTotal: tProv, estiloTotal: 'custos', rubricaTotal: 'total_custos' }),
    ...total('ro', d.resultados_operacionais && { ...d.resultados_operacionais, descricao: 'RESULTADOS OPERACIONAIS' }, 'total', { ...r, rubrica: 'resultados_operacionais' }),
    ...d.outros_resultados.map((l, i) => ({ chave: `or-${i}`, tipo: 'linha' as TipoLinha, descricao: l.descricao, nota: l.nota ?? null, atual: l.atual, anterior: l.anterior, base: tProv, rubrica: l.nota ?? undefined })),
    ...total('rai', d.resultados_antes_impostos && { ...d.resultados_antes_impostos, descricao: 'RESULTADOS ANTES DE IMPOSTOS' }, 'total', { ...r, rubrica: 'resultados_antes_impostos' }),
    ...total('imp', d.imposto, 'linha', { base: tProv }),
    ...total('rac', d.resultado_actividades_correntes && { ...d.resultado_actividades_correntes, descricao: 'RESULTADO LÍQUIDO DAS ACTIVIDADES CORRENTES' }, 'subtotal', { ...r, rubrica: 'resultado_actividades_correntes' }),
    ...total('rex', d.resultados_extraordinarios, 'linha', { base: tProv }),
    ...total('rl', d.resultado_liquido && { ...d.resultado_liquido, descricao: 'RESULTADO LÍQUIDO DO EXERCÍCIO' }, 'total', { base: tProv, estilo: 'final', rubrica: 'resultado_liquido' }),
  ];
}

/** Fluxos de caixa (legado js/ui_reports.js:1510-1560: títulos de secção cinzentos e totais a azul claro). */
/**
 * Base da AV % do Fluxo de caixa, como o legado (js/ui_reports.js:1469): soma dos fluxos positivos do ano.
 * O legado somava todos os códigos de `vals`, incluindo os subtotais (11, 14, 21…), o que contava os recebimentos
 * duas vezes; aqui somam-se só as linhas de detalhe (sem subtotais), que é o critério pretendido («fluxos positivos»).
 */
export function baseAVFluxo(f: FluxoCaixa): string {
  const soma = Object.values(f.seccoes)
    .flatMap((s) => s?.linhas ?? [])
    .filter((l) => !l.subtotal)
    .map((l) => Number(l.atual ?? 0))
    .filter((v) => v > 0)
    .reduce((a, b) => a + b, 0);
  // legado: `|| 1` — sem fluxos positivos a base é 1 (as percentagens ficam em valores absolutos ×100)
  return (Math.round(soma * 100) / 100 || 1).toFixed(2);
}

export function linhasFluxo(f: FluxoCaixa): LinhaPlana[] {
  const s = f.seccoes;
  const o = (rubricaTotal: string): OpcoesSeccao => ({ subseccao: true, estiloTotal: 'resultado', semAV: true, rubricaTotal });
  const base = baseAVFluxo(f);
  // AV % em todas as linhas com valor (detalhe, subtotais e totais), sobre o valor absoluto (renderAV do legado)
  const comAV = (l: LinhaPlana): LinhaPlana => (l.tipo === 'seccao' ? l : { ...l, base, avAbsoluto: true });
  return [
    ...seccao('op', 'Fluxos de caixa das actividades operacionais', s.operacionais, 'Fluxo das actividades operacionais', o('operacionais')),
    ...seccao('inv', 'Fluxos de caixa das actividades de investimento', s.investimento, 'Fluxo das actividades de investimento', o('investimento')),
    ...seccao('fin', 'Fluxos de caixa das actividades de financiamento', s.financiamento, 'Fluxo das actividades de financiamento', o('financiamento')),
    { chave: 'var', tipo: 'total', descricao: 'Variação de caixa e seus equivalentes', estilo: 'resultado', rubrica: 'variacao', ...f.variacao_caixa } as LinhaPlana,
    { chave: 'ini', tipo: 'linha', descricao: 'Caixa e equivalentes no início do período', rubrica: 'caixa_inicial', ...f.caixa_inicial } as LinhaPlana,
    { chave: 'fim', tipo: 'total', descricao: 'Caixa e equivalentes no fim do período', estilo: 'resultado', rubrica: 'caixa_final', ...f.caixa_final } as LinhaPlana,
  ].map(comAV);
}
