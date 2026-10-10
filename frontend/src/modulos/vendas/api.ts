/** Tipos e constantes da API de Vendas (backend: VendaResource, ServicoDocumentosVenda — ADR-029/043). */

export interface LinhaVenda {
  id: number;
  produto_id: number;
  descricao: string | null;
  quantidade: string;
  quantidade_faturada?: string | null;
  quantidade_devolvida?: string | null;
  preco_unitario_moeda?: string | null;
  total_moeda?: string | null;
  preco_unitario: string;
  taxa_imposto: string;
  valor: string | null;
  total: string | null;
  observacoes?: string | null;
}

export interface DocumentoVenda {
  id: number;
  tipo_documento: string;
  numero_documento: string;
  data_emissao: string;
  cliente?: { id: number; nome: string; nif: string | null; endereco?: string | null } | null;
  cliente_id: number;
  total_liquido: string;
  total_imposto: string;
  total_bruto: string;
  valor_pago: string | null;
  valor_pendente: string | null;
  estado: string | null;
  contabilizado: boolean;
  numero_lan_contabilizacao: string | null;
  codigo_moeda: string;
  meio_pagamento: string | null;
  motivo_nota_credito: string | null;
  observacoes: string | null;
  data_vencimento: string | null;
  valido_ate: string | null;
  /** moeda estrangeira: câmbio e totais na moeda (VendaResource) */
  moeda?: { codigo: string; taxa_cambio: string | null; taxa_cambio_manual: boolean; total_liquido: string | null; total_imposto: string | null; total_bruto: string | null } | null;
  modo_pagamento?: 'PRONTO' | 'PRAZO' | 'MARCOS' | null;
  plano_pagamentos?: { percentagem: number | string; data?: string | null; descricao?: string | null }[] | null;
  desconto?: string | null;
  /** decisão 10: arredondamento AGT do POS, separado do desconto */
  arredondamento_agt?: string | null;
  faturacao_eletronica?: {
    serie: string | null;
    numero: number | null;
    estado: string | null;
    regime: boolean | null;
    erros: unknown[];
    avisos: unknown[];
    hash?: string | null;
    selado_em?: string | null;
    envio?: string | null;
    erros_agt?: unknown[];
    /** Última resposta da AGT (VendaResource, A-04). */
    envio_detalhe?: {
      request_id: string | null;
      enviado_em: string | null;
      validado_em: string | null;
      ultima_consulta: string | null;
      proxima_consulta: string | null;
      correccao: boolean;
      tentativas: number;
      historico: { em: string; accao: string; resultado: string }[];
    } | null;
  };
  linhas?: LinhaVenda[];
  /** paridade 4.2.4/4.8.2: códigos da UN e do CC e progresso de entrega/facturação (só na listagem) */
  unidade_negocio?: { id: number; codigo: string | null; nome: string | null } | null;
  centro_custo?: { id: number; codigo: string | null; descricao: string | null } | null;
  progresso?: { entrega: number; faturacao: number };
  /** paridade 4.2.12: contas dos meios de pagamento mostradas no documento e opção de ocultar o quadro na impressão */
  contas_pagamento?: ContaPagamentoDocumento[];
  ocultar_meios_pagamento?: boolean;
  [chave: string]: unknown;
}

/** Conta de um meio de pagamento gravada no documento (cópia dos dados da Tesouraria). */
export interface ContaPagamentoDocumento {
  meio_pagamento_id: number | null;
  nome: string | null;
  codigo_conta: string | null;
  iban: string | null;
  swift: string | null;
}

/** Meio de pagamento activo (GET /vendas/meios-pagamento). */
export interface MeioPagamentoVenda {
  id: number;
  nome: string | null;
  codigo_conta: string | null;
  iban: string | null;
  swift: string | null;
  predefinido: boolean;
  codigo_moeda: string | null;
}

/** Guia de saída do armazém por facturar (GET /vendas/guias-saida-pendentes). */
export interface GuiaSaidaPendente {
  id: number;
  numero_documento: string | null;
  data: string | null;
  estado: string;
  cliente: { id: number; nome: string | null } | null;
  armazem_id: number | null;
  projeto_id: number | null;
  linhas: { produto_id: number; codigo: string | null; nome: string | null; quantidade: string; preco_unitario: string | null; taxa_imposto: string | null }[];
}

export interface ProdutoCatalogo {
  id: number;
  codigo: string | null;
  nome: string;
  preco_unitario: string | null;
  taxa_imposto: string | null;
  movimenta_stock: boolean | null;
}

export interface Terceiro {
  id: number;
  nome: string;
  nif: string | null;
  codigo_conta: string | null;
  codigo_moeda?: string | null;
}

export const TIPOS_DOCUMENTO: Record<string, string> = {
  FT: 'Factura',
  FR: 'Factura-recibo',
  NC: 'Nota de crédito',
  OR: 'Orçamento',
  PF: 'Factura pró-forma',
  NE: 'Nota de encomenda',
  GR: 'Guia de remessa',
  GD: 'Guia de devolução',
};

/** Tipos que se emitem directamente (a GD só nasce de uma GR). */
export const TIPOS_EMITIVEIS = ['FT', 'FR', 'NC', 'OR', 'PF', 'NE', 'GR'] as const;

/** Conversões permitidas (ServicoDocumentosVenda::CONVERSOES). */
export const CONVERSOES: Record<string, string[]> = { OR: ['NE', 'FT'], PF: ['NE', 'FT'], NE: ['GR', 'FT'], GR: ['FT', 'GD'], FT: ['NC'], FR: ['NC'] };

export const FISCAIS = ['FT', 'FR', 'NC'];
export const CONTABILIZAVEIS = ['FT', 'FR', 'NC', 'GR', 'GD'];

export const CORES_ESTADO: Record<string, string> = { PAGO: 'green', PENDENTE: 'orange', PARCIAL: 'gold', CONCLUIDO: 'blue', ANULADO: 'red', VENCIDO: 'volcano' };

export const MEIOS_PAGAMENTO = [
  { value: 'NUMERARIO', label: 'Numerário' },
  { value: 'TPA', label: 'TPA (Multicaixa)' },
  { value: 'TRANSFERENCIA', label: 'Transferência' },
];
