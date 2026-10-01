/** Tipos da API da lavandaria e alfaiataria (LavandariaController, ADR-049). */

export interface ClienteLav {
  id: number;
  nome: string;
  nif: string | null;
  telefone: string | null;
}

export interface IndicadoresOrdem {
  responsavel: string | null;
  atribuida_em: string | null;
  pronta_em: string | null;
  entregue_em: string | null;
  dias_aberta: number | null;
  atraso: number | null;
  no_prazo: boolean | null;
  situacao: string | null;
}

export interface OrdemResumo {
  id: number;
  numero_encomenda: string;
  codigo_terminal: string;
  terminal_pos_id: number;
  cliente_id: number;
  recebido_em: string;
  data_prometida: string | null;
  urgente: boolean;
  estado: string;
  nome_atribuido: string | null;
  modo_faturacao: 'RECEPCAO' | 'ENTREGA';
  cliente: ClienteLav | null;
  total: string;
  saldo: string;
  indicadores: IndicadoresOrdem;
  nao_levantada: boolean;
}

export interface ListaOrdens {
  contadores: Record<string, number>;
  ordens: OrdemResumo[];
  por_responsavel: unknown;
}

export interface ItemOrdem {
  linha_id: number;
  peca_id: number | null;
  nome_peca: string | null;
  codigo_peca?: string | null;
  produto_id: number;
  nome: string;
  codigo_servico?: string | null;
  grupo: string;
  unidade: string;
  quantidade: number | string;
  numero_pecas?: number | null;
  preco: number | string;
  valor: string;
  taxa_imposto?: number | string;
  estado: string;
  cor?: string | null;
  tecido?: string | null;
  estado_entrada?: string | null;
  notas_entrada?: string | null;
  etiquetas?: string[];
  requer_orcamento?: boolean;
  estado_orcamento?: string | null;
  valor_orcamento?: number | string | null;
  executado_por?: string | null;
  materiais?: { produto_id: number; quantidade: number | string; nome?: string | null }[];
}

export interface PedidoLav {
  id: number;
  numero_encomenda: string;
  terminal_pos_id: number;
  codigo_terminal: string;
  cliente_id: number;
  recebido_em: string;
  recebido_por: string | null;
  modo_faturacao: 'RECEPCAO' | 'ENTREGA';
  urgente: boolean;
  data_prometida: string | null;
  observacoes: string | null;
  recolha: { ativa?: boolean; morada?: string; data?: string; taxa?: number | null } | null;
  entrega: { ativa?: boolean; morada?: string; data?: string; taxa?: number | null } | null;
  extras: { id?: string; nome: string; valor: string | number }[] | null;
  historico_alteracoes: { em: string; por: string; texto: string }[] | null;
  itens: ItemOrdem[];
  estado: string;
  nome_atribuido: string | null;
  entregue_em: string | null;
  motivo_cancelamento: string | null;
}

export interface PagamentoLav {
  id: number;
  numero_recibo: string | null;
  data: string;
  montante: string;
  troco: string | null;
  natureza_registo: string;
  estado: string;
  pos_pagamentos: { nome?: string; tipo: string; valor: number | string; referencia?: string | null }[] | null;
  criado_por: string | null;
  venda_id: number | null;
}

export interface DetalheOrdem {
  pedido: PedidoLav;
  cliente: ClienteLav | null;
  totais: { servicos: string; extras: string; total: string; facturado: string; pago: string; saldo: string; por_facturar: string; saldo_facturado: string };
  indicadores: IndicadoresOrdem;
  faturas: { id: number; numero_documento: string; tipo_documento?: string; total_bruto: string; estado: string | null; data_emissao?: string }[];
  pagamentos: PagamentoLav[];
  reclamacoes: Reclamacao[];
  linhas_por_facturar: { ref: string; id: number | string; produto_id: number; descricao: string; quantidade: string; preco: string; total: string }[];
}

export interface Peca {
  id: number;
  codigo: string | null;
  nome: string;
  tecido: string | null;
  cor: string | null;
  unidade: 'PECA' | 'KG';
  ativo: boolean | null;
  preco: string;
  precos_servico: { produto_id: number; preco: number | string | null }[] | null;
}

export interface ServicoLav {
  id: number;
  codigo: string | null;
  nome: string;
  lavandaria_grupo: 'LAVANDARIA' | 'ALFAIATARIA';
  codigo_conta: string | null;
  taxa_imposto: string;
  lavandaria_dias_entrega: number | null;
  lavandaria_requer_orcamento: boolean | null;
  lavandaria_ativa: boolean | null;
}

export interface DefinicoesLav {
  taxa_armazenagem_ativa: boolean;
  dias_armazenagem_gratis: number;
  percentagem_armazenagem_dia: string;
  percentagem_adiantamento: string;
  percentagem_urgencia: string;
  fator_prazo_urgencia: number;
  valor_taxa_recolha: string;
  valor_taxa_entrega: string;
  faturar_no_adiantamento: boolean;
  conta_compensacao: string | null;
  conta_extras: string | null;
  taxa_extras: string;
  dias_reclamacao: number;
  estados_entrada: string[];
}

export interface Reclamacao {
  id: number;
  pedido_lavandaria_id?: number;
  numero_encomenda?: string | null;
  linha_pedido_id?: number | null;
  nome_item?: string | null;
  descricao_peca?: string | null;
  cliente_id?: number | null;
  documento_tesouraria_id?: number | null;
  pago_em?: string | null;
  descricao: string;
  valor_declarado: string | null;
  estado: string;
  referencia_comprovativo: string | null;
  valor_comprovativo: string | null;
  valor_compensacao?: string | null;
  nota_decisao: string | null;
  decidido_por: string | null;
  decidido_em?: string | null;
  criado_em?: string | null;
  criado_por?: string | null;
}

export interface SimulacaoEntrega {
  linhas: number[];
  taxa_armazenagem: { valor: string; dias?: number } | null;
  por_facturar: string;
  saldo_facturado: string;
  venda_directa_possivel: boolean;
  consumidor_final: boolean;
}

export interface RelatorioLav {
  periodo: { de: string; ate: string };
  resumo: Record<string, number | string>;
  documentos: { data: string; numero: string; tipo: string; ordem: string | null; cliente: string | null; terminal: string | null; base: string; iva: string; total: string; estado: string | null }[];
  por_meio: { meio: string; numero: number; total: string }[];
  por_servico: { servico: string; grupo: string; quantidade: string; total: string }[];
  por_peca: { peca: string; unidade: string; quantidade: string; etiquetas: number; valor: string }[];
  por_dia: { dia: string; recebidas: number; etiquetas: number; entregues: number; documentos: number; facturado: string; recebido: string }[];
  por_terminal: { terminal: string; ordens: number; documentos: number; facturado: string; recebido: string }[];
}

/** Preço da peça para o serviço: o da tabela peça × serviço, senão o preço base da peça. */
export function precoPecaServico(peca: Pick<Peca, 'preco' | 'precos_servico'> | undefined, produtoId: number | undefined): number {
  if (!peca) return 0;
  const especifico = (peca.precos_servico ?? []).find((p) => p.produto_id === produtoId && p.preco !== null && p.preco !== undefined);
  return Number(especifico?.preco ?? peca.preco ?? 0) || 0;
}
