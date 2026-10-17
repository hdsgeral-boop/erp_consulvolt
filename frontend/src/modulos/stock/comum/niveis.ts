/**
 * Níveis de stock (ronda 3): tipos e regras de apresentação dos alertas calculados no servidor
 * (GET /logistica/niveis-stock — ServicoNiveisStock): CRITICO, REPOSICAO e EXCESSO.
 */

export type EstadoNivel = 'CRITICO' | 'REPOSICAO' | 'EXCESSO';

export interface LinhaNivel {
  produto_id: number;
  codigo: string | null;
  nome: string;
  categoria: string | null;
  unidade: string;
  stock: string;
  em_encomenda: string;
  posicao: string;
  stock_minimo: string | null;
  stock_seguranca: string | null;
  ponto_encomenda: string | null;
  stock_maximo: string | null;
  estado: EstadoNivel;
  motivo: string | null;
  quantidade_sugerida: string;
  custo_medio: string;
  valor_sugerido: string;
}

export interface RespostaNiveis {
  resumo: { criticos: number; reposicao: number; excesso: number; valor_sugerido: string };
  calculado_em: string | null;
  linhas: LinhaNivel[];
}

export const ESTADOS_NIVEL: Record<EstadoNivel, { rotulo: string; cor: 'error' | 'warning' | 'processing'; kpi: 'vermelho' | 'laranja' | 'roxo' }> = {
  CRITICO: { rotulo: 'Crítico', cor: 'error', kpi: 'vermelho' },
  REPOSICAO: { rotulo: 'Repor', cor: 'warning', kpi: 'laranja' },
  EXCESSO: { rotulo: 'Excesso', cor: 'processing', kpi: 'roxo' },
};

/** Linhas que entram nas «Sugestões de reposição» (crítico ou reposição, com quantidade a encomendar). */
export function sugestoes(linhas: LinhaNivel[]): LinhaNivel[] {
  return linhas.filter((l) => l.estado !== 'EXCESSO' && Number(l.quantidade_sugerida) > 0);
}

/**
 * Corpo de POST /logistica/niveis-stock/pedido-compra: as linhas escolhidas, com a quantidade corrigida pelo utilizador
 * (ou a sugerida). Quantidades vazias ou ≤ 0 ficam de fora.
 */
export function corpoPedidoReposicao(escolhidas: LinhaNivel[], quantidades: Record<number, number | null | undefined>): { linhas: { produto_id: number; quantidade: number }[] } {
  return {
    linhas: escolhidas
      .map((l) => ({ produto_id: l.produto_id, quantidade: quantidades[l.produto_id] ?? Number(l.quantidade_sugerida) }))
      .filter((l) => Number.isFinite(l.quantidade) && l.quantidade > 0),
  };
}
