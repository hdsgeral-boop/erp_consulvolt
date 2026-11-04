/** Retenção na fonte no recibo de cliente: cálculos puros (em cêntimos, sem erros de vírgula flutuante) e tipos. */

export interface TipoRetencao {
  id: number;
  codigo: string;
  descricao: string;
  percentagem: string;
  conta_imposto: string | null;
  ativo: boolean;
}

/** Retenção guardada no recibo (ReciboVendaResource.retencao). */
export interface RetencaoRecibo {
  tipo_retencao_fonte_id: number | null;
  codigo_tipo: string;
  descricao_tipo: string;
  percentagem: string;
  base_incidencia: string;
  valor_retido: string;
  valor_calculado: string;
  referencia_comprovativo: string | null;
  data_comprovativo: string | null;
  conta_imposto: string | null;
}

/** Estado do bloco «Retenção na fonte» do formulário. `base`/`valor` nulos = por omissão (total / calculado). */
export interface EstadoRetencao {
  aplicar: boolean;
  tipoId?: number;
  percentagem: number | null;
  base: number | null;
  valor: number | null;
  referencia?: string;
  data?: string | null;
}

export const RETENCAO_VAZIA: EstadoRetencao = { aplicar: false, percentagem: null, base: null, valor: null };

const centimos = (v: number) => Math.round((v + Number.EPSILON) * 100);

/** Base efectiva: a indicada, limitada ao total do recibo (nunca acima) e nunca negativa; sem base indicada, o total. */
export function baseEfectiva(total: number, base: number | null): number {
  const t = Math.max(0, centimos(total));
  if (base === null || base === undefined) return t / 100;
  return Math.min(t, Math.max(0, centimos(base))) / 100;
}

/** base × percentagem ÷ 100 arredondado ao cêntimo (meio para cima), como o servidor. */
export function calcularRetencao(base: number, percentagem: number | null): number {
  if (percentagem === null || percentagem === undefined || !(percentagem >= 0)) return 0;
  return Math.floor((centimos(base) * Math.min(100, percentagem)) / 100 + 0.5) / 100;
}

export interface ResumoRetencao {
  total: number;
  base: number;
  calculado: number;
  retido: number;
  liquido: number;
  /** o valor foi editado à mão e difere do calculado: mostrar o aviso (o comprovativo do cliente manda) */
  editado: boolean;
  /** erro de validação (mesmos limites do servidor), ou null */
  erro: string | null;
}

/** Resumo do recibo: total bruto, retenção e valor líquido a entrar em caixa/banco. Sem retenção, líquido = total. */
export function resumirRecibo(total: number, r: EstadoRetencao): ResumoRetencao {
  const t = centimos(Math.max(0, total)) / 100;
  if (!r.aplicar) return { total: t, base: t, calculado: 0, retido: 0, liquido: t, editado: false, erro: null };
  const base = baseEfectiva(t, r.base);
  const calculado = calcularRetencao(base, r.percentagem);
  const retido = r.valor !== null && r.valor !== undefined ? centimos(r.valor) / 100 : calculado;
  let erro: string | null = null;
  if (r.percentagem === null || r.percentagem < 0 || r.percentagem > 100) erro = 'A percentagem tem de estar entre 0 e 100.';
  else if (r.base !== null && centimos(r.base) > centimos(t)) erro = 'A base de incidência não pode exceder o total a liquidar.';
  else if (centimos(retido) > centimos(base)) erro = 'O valor retido não pode exceder a base de incidência.';
  else if (t > 0 && centimos(retido) >= centimos(t)) erro = 'O valor retido não pode igualar o total: não entraria dinheiro em caixa/banco.';
  return { total: t, base, calculado, retido, liquido: (centimos(t) - centimos(retido)) / 100, editado: retido !== calculado, erro };
}

/** Bloco `retencao` do pedido de emissão (ou undefined sem retenção/valor nulo). */
export function retencaoParaPedido(r: EstadoRetencao, total: number): Record<string, unknown> | undefined {
  if (!r.aplicar) return undefined;
  const s = resumirRecibo(total, r);
  if (s.retido <= 0) return undefined;
  return {
    tipo_retencao_fonte_id: r.tipoId,
    percentagem: r.percentagem,
    base_incidencia: s.base,
    valor_retido: s.retido,
    referencia_comprovativo: r.referencia?.trim() || undefined,
    data_comprovativo: r.data || undefined,
  };
}
