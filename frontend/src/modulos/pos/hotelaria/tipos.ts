/** Tipos da API da hotelaria (HotelariaController, ADR-050). */

export interface Quarto {
  produto_id: number;
  codigo: string | null;
  nome: string;
  preco_por_dia: string;
  preco_por_hora: string;
  horas_minimas: string;
  taxa_imposto: string;
  estado: 'LIVRE' | 'OCUPADO';
  estadia: { id: number; nome_hospede: string | null; entrada_em: string; saida_prevista_em: string; atrasado: boolean; total_em_aberto: string } | null;
}

export interface ConsumoEstadia {
  produto_id: number;
  descricao?: string | null;
  quantidade: number | string;
  preco_unitario: number | string;
  taxa_imposto?: number | string;
}

export interface RegrasHotel {
  entrada: string;
  saida: string;
  tolerancia: number;
  bloqueio: boolean;
  de: string;
  ate: string;
}

export interface Estadia {
  id: number;
  terminal_pos_id: number;
  codigo_terminal: string | null;
  sessao_pos_id: number | null;
  produto_quarto_id: number;
  nome_quarto: string;
  estado: 'ABERTA' | 'FECHADA' | 'ANULADA';
  cliente_hospede_id: number;
  nome_hospede: string | null;
  numero_hospedes: number | null;
  modo: 'DIA' | 'HORA';
  entrada_em: string;
  saida_prevista_em: string;
  quantidade: string;
  preco_unitario: string;
  taxa_imposto: string | null;
  observacoes: string | null;
  itens: ConsumoEstadia[] | null;
  historico_alteracoes: { em: string; por: string; texto: string }[] | null;
  saida_em: string | null;
  quantidade_final: string | null;
  opcao_atraso: string | null;
  percentagem_desconto: string | null;
  venda_id: number | null;
  numero_venda: string | null;
  motivo_cancelamento: string | null;
  // detalhe
  total_alojamento?: string;
  total_consumos?: string;
  total_em_aberto?: string;
  proposta_atraso?: { quantidade: string; extra: string } | null;
  regras?: RegrasHotel;
}

export interface FacturaSimulada {
  cliente_id: number;
  estadias: { id: number; nome_quarto: string; cliente_hospede_id: number; quantidade_final: string; opcao_atraso: string | null; proposta: { quantidade: string; extra: string } | null; total_alojamento: string; total_consumos: string }[];
  linhas: { produto_id: number; quantidade: string; preco_unitario: string; descricao: string | null }[];
  bruto: string;
  desconto: string;
  /** Decisão 10: arredondamento AGT (base e IVA por excesso ao cêntimo), separado do desconto comercial. */
  arredondamento_agt?: string;
  total_liquido: string;
  total_imposto: string;
  total: string;
}

export interface SimulacaoCheckout {
  facturas: FacturaSimulada[];
  total: string;
  percentagem_desconto: string;
  saidas_por_decidir: string[];
  regras: RegrasHotel;
}

/** Quantidade mínima do check-in: diárias inteiras ≥ 1; à hora, o mínimo de horas do quarto. */
export function quantidadeMinima(modo: 'DIA' | 'HORA', quarto: Pick<Quarto, 'horas_minimas'>): number {
  return modo === 'HORA' ? Math.max(1, Number(quarto.horas_minimas) || 1) : 1;
}

/** Preço do quarto pelo modo (o preço diferente exige pos_desconto). */
export function precoQuarto(modo: 'DIA' | 'HORA', quarto: Pick<Quarto, 'preco_por_dia' | 'preco_por_hora'>): number {
  return Number(modo === 'HORA' ? quarto.preco_por_hora : quarto.preco_por_dia) || 0;
}
