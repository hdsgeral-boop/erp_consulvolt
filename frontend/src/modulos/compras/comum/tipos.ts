import type { RefProduto, RefTerceiro } from './referencias';

/**
 * Tipos das respostas de /api/compras (ComprasController / ContratosComprasController). Estas rotas devolvem
 * os modelos Eloquent (toArray): datas em ISO 8601, decimais em texto, os ids das entidades relacionadas e, desde a
 * afinação (ADR-064), `fornecedor` {id,nome,nif} nos documentos e `produto` {id,codigo,nome} nas linhas.
 */

export interface ItemCompra {
  id: number;
  produto_id: number;
  produto?: RefProduto | null;
  descricao: string | null;
  quantidade: string;
  preco_unitario: string | null;
  taxa_imposto: string | null;
  total: string | null;
  total_kz: string | null;
  total_moeda: string | null;
  preco_unitario_moeda: string | null;
  quantidade_recebida: string | null;
  quantidade_faturada: string | null;
  [chave: string]: unknown;
}

export interface EtapaDeliberacao {
  nome: string;
  tipo?: string;
  ordem: number;
  estado: string;
  tarefa?: string | null;
  nome_aprovador?: string | null;
  nota_aprovador?: string | null;
  /** Quem decidiu e quando (gravado na decisão). */
  por?: string | null;
  em?: string | null;
  nota?: string | null;
  [chave: string]: unknown;
}

export interface PedidoCompra {
  id: number;
  numero_pedido: string | null;
  nome_requerente: string;
  data: string | null;
  data_entrega: string | null;
  estado: string;
  descricao: string | null;
  observacoes: string | null;
  projeto_id: number | null;
  unidade_negocio_id: number | null;
  centro_custo_id: number | null;
  venda_origem_id: number | null;
  deliberacao: { valor?: number; etapas?: EtapaDeliberacao[]; sem_preco?: number; valor_aprovado?: number; [k: string]: unknown } | null;
  criado_por: string | null;
  anulado_em: string | null;
  motivo_anulacao: string | null;
  linhas?: ItemCompra[];
  valor_estimado?: { valor: string; sem_preco: number };
}

export interface PropostaCompra {
  id: number;
  numero_proposta: string | null;
  pedido_compra_id: number;
  fornecedor_id: number;
  fornecedor?: RefTerceiro | null;
  referencia: string;
  data: string | null;
  data_entrega: string | null;
  estado: string;
  codigo_moeda: string | null;
  taxa_cambio: string | null;
  montante_total: string | null;
  montante_total_moeda: string | null;
  total_imposto: string | null;
  total_com_imposto: string | null;
  linhas?: ItemCompra[];
}

export interface EncomendaCompra {
  id: number;
  numero_encomenda: string | null;
  pedido_compra_id: number | null;
  cotacao_compra_id: number | null;
  fornecedor_id: number;
  fornecedor?: RefTerceiro | null;
  data: string | null;
  estado: string;
  contabilizado: boolean | null;
  contrato_fornecedor_id: number | null;
  codigo_moeda: string | null;
  taxa_cambio: string | null;
  montante_total: string | null;
  montante_total_moeda: string | null;
  total_imposto: string | null;
  total_com_imposto: string | null;
  data_entrega_prevista: string | null;
  anulado_em: string | null;
  motivo_anulacao: string | null;
  linhas?: ItemCompra[];
}

export interface LinhaRececao {
  id: number;
  produto_id: number;
  produto?: RefProduto | null;
  quantidade: string;
  valor_kz: string | null;
  custo_unitario_kz: string | null;
  item_compra_id: number | null;
}

export interface RececaoCompra {
  id: number;
  numero_rececao: string | null;
  encomenda_compra_id: number;
  numero_entrega: string | null;
  data: string | null;
  estado: string;
  validado: boolean | null;
  contabilizado: boolean | null;
  armazem_id: number | null;
  valor_total_kz: string | null;
  numero_lan_contabilizacao: string | null;
  validado_em: string | null;
  validado_por: string | null;
  anulado_em: string | null;
  motivo_anulacao: string | null;
  linhas?: LinhaRececao[];
}

export interface FaturaCompra {
  id: number;
  numero_fatura: string;
  encomenda_compra_id: number | null;
  /** Número da encomenda de origem (só no detalhe). */
  numero_encomenda?: string | null;
  fornecedor_id: number;
  fornecedor?: RefTerceiro | null;
  data: string | null;
  data_vencimento: string | null;
  estado: string | null;
  contabilizado: boolean | null;
  numero_lan_contabilizacao: string | null;
  codigo_moeda: string | null;
  taxa_cambio: string | null;
  montante_total: string | null;
  total_imposto: string | null;
  montante_total_moeda: string | null;
  total_imposto_moeda: string | null;
  anulado_em: string | null;
  motivo_anulacao: string | null;
  linhas?: ItemCompra[];
}

export interface MarcoContrato {
  id: number;
  titulo: string;
  data_prevista: string | null;
  montante: string;
  fatura_compra_id: number | null;
  [chave: string]: unknown;
}

export interface ContratoCompra {
  id: number;
  fornecedor_id: number;
  fornecedor?: RefTerceiro | null;
  referencia: string;
  descricao: string | null;
  data_inicio: string | null;
  data_fim: string | null;
  valor_total: string;
  estado: string;
  cancelado_em: string | null;
  motivo_cancelamento: string | null;
  encomendas?: { id: number; numero_encomenda: string | null; data: string | null; estado: string; montante_total: string | null; total_com_imposto: string | null }[];
  marcos?: MarcoContrato[];
  consumo?: { encomendado: string; faturado: string; pago: string; percentagem: number; excedido: boolean };
}

export interface LinhaEncomendaCliente {
  id: number;
  produto_id: number;
  produto: string | null;
  descricao: string | null;
  quantidade: string;
  pendente: string;
  stock_disponivel: string | null;
  pedido_compra: { id: number; numero_pedido: string | null; estado: string } | null;
  por_comprar: boolean;
}

export interface EncomendaCliente {
  id: number;
  numero_documento: string;
  data_emissao: string;
  cliente_id: number | null;
  cliente: { id: number; nome: string } | null;
  estado: string | null;
  linhas: LinhaEncomendaCliente[];
}

export interface Escalao {
  nome: string;
  limite: number | null;
}

/** Identificação de um documento sem número (registos migrados do legado). */
export function numeroOuId(numero: string | null | undefined, id: number): string {
  return numero?.trim() ? numero : `#${id}`;
}
