/** Tipos e constantes da API de Vendas (backend: VendaResource, ServicoDocumentosVenda — ADR-029/043). */

export interface LinhaVenda {
  id: number;
  produto_id: number;
  descricao: string | null;
  quantidade: string;
  quantidade_faturada?: string | null;
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
  };
  linhas?: LinhaVenda[];
  [chave: string]: unknown;
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
