/** Tipos das respostas de /api/logistica e /api/pos/armazem (StockController, POSArmazemController). */
import type { RefProduto, RefTerceiro } from '@/modulos/compras/comum/referencias';

export interface LinhaStock {
  armazem_id: number;
  armazem: string;
  produto_id: number;
  codigo: string | null;
  nome: string;
  quantidade: string;
  custo_medio: string | null;
  valor: string | null;
  stock_minimo: string | null;
  ruptura: boolean;
}

export interface RespostaStock {
  linhas: LinhaStock[];
  valorizacao: { total: string; por_armazem: { armazem: string; valor: string; produtos: number }[]; rupturas: number };
}

export interface Movimento {
  id: number;
  produto_id: number;
  armazem_id: number;
  tipo: string;
  quantidade: string;
  data: string;
  referencia: string | null;
  preco_unitario: string | null;
  sentido: 'E' | 'S';
  valor: string | null;
  custo_medio_apos: string | null;
  documento_tipo: string | null;
  documento_id: number | null;
  armazem_contraparte_id: number | null;
  criado_por: string | null;
}

export interface Extracto {
  produto: { id: number; codigo: string | null; nome: string; custo_medio: string | null; quantidade_stock: string | null };
  armazem_id: number | null;
  de: string;
  ate: string;
  saldo_inicial: { quantidade: string; valor: string };
  movimentos: {
    id: number;
    data: string;
    tipo: string;
    sentido: 'E' | 'S';
    armazem_id: number;
    referencia: string | null;
    entrada: string | null;
    saida: string | null;
    custo_unitario: string | null;
    valor: string | null;
    saldo_quantidade: string;
    saldo_valor: string;
  }[];
  saldo_final: { quantidade: string; valor: string };
}

export interface GuiaSaida {
  id: number;
  numero_documento: string;
  data: string;
  tipo: string;
  tipo_original: string | null;
  terceiro_id: number | null;
  /** {id,nome,nif} — na lista e no detalhe de /logistica/guias-saida (ADR-064). */
  terceiro?: RefTerceiro | null;
  armazem_id: number | null;
  area_rececao: string | null;
  estado: string | null;
  venda_relacionada_id: number | null;
  contabilizado: boolean | null;
  observacoes: string | null;
  criado_por: string | null;
  numero_lan_contabilizacao: string | null;
  anulado_em: string | null;
  motivo_anulacao: string | null;
  linhas?: { id: number; produto_id: number; produto?: RefProduto | null; quantidade: string; valor_kz: string | null; custo_unitario_kz: string | null }[];
  aviso_contabilizacao?: string | null;
}

export interface SessaoInventario {
  id: number;
  armazem_id: number;
  data: string;
  descricao: string | null;
  estado: string;
  tipo: string | null;
  aprovado_por: string | null;
  aprovado_em: string | null;
  numero_lan_contabilizacao: string | null;
  motivo_anulacao: string | null;
  iniciado_por: string | null;
  linhas?: LinhaInventario[];
}

export interface LinhaInventario {
  id: number;
  produto_id: number;
  codigo: string | null;
  nome: string;
  custo_medio: string | null;
  /** Ocultos durante a contagem (contagem cega). */
  quantidade_sistema?: string | null;
  diferenca?: string | null;
  quantidade_contada: string | null;
  observacoes: string | null;
  justificacao: string | null;
  custo_personalizado: string | null;
  custo_unitario: string | null;
  valor_diferenca: string | null;
}

export interface EncomendaPicking {
  id: number;
  numero_documento: string;
  data_emissao: string;
  cliente_id: number;
  cliente: string | null;
  total_bruto: string;
  estado: string | null;
  estado_picking: string;
  linhas_por_expedir: number;
}

export interface ListaRecolha {
  encomenda: { id: number; numero_documento: string; cliente_id: number; estado: string | null; total_bruto: string };
  armazem_id: number;
  linhas: {
    item_id: number;
    produto_id: number;
    codigo: string | null;
    nome: string;
    quantidade: string;
    quantidade_entregue: string;
    por_expedir: string;
    movimenta_stock: boolean;
    stock_armazem: string | null;
    disponivel: boolean;
  }[];
  pode_expedir: boolean;
}

export const TIPOS_MOVIMENTO: Record<string, string> = { ENTRADA: 'Entrada', SAIDA: 'Saída', TRANSFERENCIA: 'Transferência', AJUSTE: 'Ajuste' };
export const TIPOS_GUIA: Record<string, string> = { CONSUMO: 'Consumo interno', VENDA: 'Venda', BACK_TO_BACK: 'Back-to-back', VENDA_BALCAO: 'Venda ao balcão' };

/** POST /logistica/stock/recalcular-valorizacoes (tarefa armazem_recalcular, ADR-064). */
export interface MovimentoRecalculado {
  movimento_id: number;
  data: string | null;
  tipo: string;
  sentido: 'E' | 'S';
  armazem_id: number;
  documento_tipo: string | null;
  documento_id: number | null;
  quantidade: string;
  valor_atual: string;
  valor_recalculado: string;
  preco_recalculado: string;
  custo_medio_apos: string;
  /** Documento de origem contabilizado: a diferença é só informativa (não é aplicada). */
  contabilizado: boolean;
}

export interface ProdutoRecalculado {
  produto_id: number;
  codigo: string | null;
  nome: string;
  custo_medio_atual: string;
  custo_medio_recalculado: string;
  custo_medio_alterado: boolean;
  movimentos_recalculados: number;
  movimentos_alterados: number;
  divergencias_contabilizadas: number;
  diferenca_valor: string;
  movimentos: MovimentoRecalculado[];
}

export interface ResultadoRecalculo {
  aplicado: boolean;
  resumo: { produtos_analisados: number; produtos_com_alteracoes: number; movimentos_alterados: number; divergencias_contabilizadas: number; diferenca_valor: string };
  produtos: ProdutoRecalculado[];
  avisos: string[];
}
