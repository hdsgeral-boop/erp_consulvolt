/**
 * Tipos da API do POS (backend: POSController, PrestacaoContasController, LavandariaController, HotelariaController —
 * ADR-047 a 050). Os valores em Kz chegam como texto decimal com 2 casas; alguns JSON migrados do legado trazem números.
 */

export type TipoMeio = 'NUMERARIO' | 'TPA' | 'TRANSFERENCIA';
export type TipoTerminal = 'LOJA' | 'RESTAURANTE' | 'LAVANDARIA' | 'HOTELARIA';
export type ValorApi = string | number | null | undefined;

export interface MeioPagamento {
  id?: string;
  tipo: TipoMeio;
  nome: string;
  ativo: boolean;
  conta_transitoria: string | null;
  conta_liquidacao: string | null;
  codigo_tpa?: string | null;
  comissao_pct?: number | null;
  conta_comissao?: string | null;
  comissao_deduzida?: boolean | null;
  copiado_de?: string | null;
}

export interface SessaoResumo {
  id: number;
  terminal_pos_id: number;
  codigo_sessao: string;
  nome_operador: string | null;
  aberto_em: string;
}

export interface Terminal {
  id: number;
  codigo: string;
  nome: string;
  tipo: TipoTerminal;
  ativo: boolean;
  armazem_id: number | null;
  cliente_padrao_id: number | null;
  fundo_maneio_padrao: string | null;
  unidade_negocio_id: number | null;
  centro_custo_id: number | null;
  meios_pagamento: MeioPagamento[] | null;
  hotel_hora_entrada: string | null;
  hotel_hora_saida: string | null;
  hotel_tolerancia_atraso_min: number | null;
  hotel_bloco_horas: boolean | null;
  hotel_bloco_horas_de: string | null;
  hotel_bloco_horas_ate: string | null;
  sessao_aberta: SessaoResumo | null;
}

export interface DefinicoesPOS {
  conta_sobra: string | null;
  conta_quebra: string | null;
  conta_operador: string | null;
  tolerancia_desvio: string;
  codigo_diario: string;
}

export interface TotalMeio {
  meio_id: string | null;
  tipo: TipoMeio;
  nome: string;
  conta_transitoria?: string | null;
  codigo_tpa?: string | null;
  valor: ValorApi;
  quantidade: number;
}

export interface TransferenciaSessao {
  venda_id: number;
  numero_documento: string | null;
  valor: ValorApi;
  referencia: string | null;
  meio_id: string | null;
  terceiro_id?: number | null;
}

export interface FechoTPA {
  meio_id: string;
  nome: string;
  codigo_tpa: string | null;
  valor_sistema: ValorApi;
  operacoes_sistema: number;
  valor_talao: ValorApi;
  operacoes_talao: number;
  referencia_lote: string | null;
  diferenca: ValorApi;
}

export interface Deliberacao {
  decisao: string;
  automatica?: boolean;
  valor?: ValorApi;
  nota?: string | null;
  data?: string | null;
  por?: string | null;
  em?: string | null;
  conta?: string | null;
  numero_lan?: string | null;
}

export type EstadoContabilizacao = 'PENDENTE' | 'CONTABILIZADA' | 'SEM_MOVIMENTO' | string;
export type EstadoDesvio = 'NAO_APLICAVEL' | 'SEM_DESVIO' | 'PENDENTE' | 'DELIBERADO' | string;
export type EstadoLiquidacao = 'PENDENTE' | 'PARCIAL' | 'LIQUIDADA' | 'SEM_MOVIMENTO' | string;

export interface VendaSessao {
  id: number;
  numero_documento: string;
  data_emissao: string;
  cliente_id: number;
  total_bruto: string;
  estado: string | null;
  pos_pagamentos: { meio_id?: string; tipo: TipoMeio; nome?: string; valor: ValorApi; referencia?: string | null }[] | null;
  pos_troco: string | null;
  pos_operador: string | null;
  contabilizado: boolean;
}

export interface SessaoPOS {
  id: number;
  terminal_pos_id: number;
  codigo_terminal: string;
  nome_terminal: string;
  codigo_sessao: string;
  estado: 'ABERTA' | 'FECHADA';
  aberto_em: string;
  fundo_maneio_abertura: string;
  operador_id: number | null;
  nome_operador: string | null;
  fechado_em: string | null;
  fechado_por: string | null;
  numero_z: string | null;
  numero_vendas: number | null;
  total_vendas: string | null;
  totais_por_metodo: TotalMeio[] | null;
  transferencias: TransferenciaSessao[] | null;
  vendas_numerario: string | null;
  numerario_esperado: string | null;
  numerario_contado: string | null;
  contagens_numerario: Record<string, number> | null;
  desvio: string | null;
  fechos_tpa: FechoTPA[] | null;
  justificacao: string | null;
  estado_contabilizacao: EstadoContabilizacao;
  estado_liquidacao: EstadoLiquidacao;
  estado_desvio: EstadoDesvio;
  lans_contabilizacao: string[] | null;
  contabilizado_em: string | null;
  contabilizado_por: string | null;
  deliberacao: Deliberacao | null;
  descontabilizado_em?: string | null;
  vendas?: VendaSessao[];
}

export interface RelatorioX {
  sessao: Pick<SessaoPOS, 'id' | 'codigo_sessao' | 'codigo_terminal' | 'nome_terminal' | 'estado' | 'aberto_em' | 'fundo_maneio_abertura' | 'nome_operador'>;
  numero_vendas: number;
  total_vendas: string;
  totais_por_metodo: TotalMeio[];
  transferencias: TransferenciaSessao[];
  vendas_numerario: string;
  numerario_esperado: string;
  lavandaria: { numero_recibos: number; total_recibos: string; numero_faturas: number; total_faturas: string; movimento: boolean };
  emitido_em: string;
}

export interface ProdutoPOS {
  id: number;
  codigo: string | null;
  nome: string;
  preco_unitario: string | null;
  taxa_imposto: string | null;
  movimenta_stock: boolean | null;
  e_servico?: boolean | null;
  e_quarto?: boolean | null;
  categoria_produto_id?: number | null;
}

export interface LinhaVendaPOS {
  id: number;
  produto_id: number;
  descricao: string | null;
  quantidade: string;
  preco_unitario: string;
  taxa_imposto: string;
  total: string | null;
}

export interface VendaEmitida {
  id: number;
  numero_documento: string;
  data_emissao: string;
  total_liquido: string;
  total_imposto: string;
  total_bruto: string;
  desconto: string | null;
  pos_troco: string | null;
  /** Decisão 10: arredondamento AGT do POS, separado do desconto comercial. */
  arredondamento_agt?: string | null;
  /** M-15: mesa do restaurante (coluna do legado table_name). */
  nome_tabela?: string | null;
  pos_pagamentos: VendaSessao['pos_pagamentos'];
  pos_operador: string | null;
  codigo_terminal_pos?: string | null;
  itens_venda?: LinhaVendaPOS[];
  [chave: string]: unknown;
}

// ───────────── Prestação de contas ─────────────

export interface LiquidacaoResumo {
  id: number;
  alvo: 'FOLHA_CAIXA' | 'TESOURARIA';
  data: string;
  montante_bruto: string;
  comissao: string | null;
  montante_liquido: string;
  conta_destino: string | null;
  sessao_caixa_id: number | null;
  movimento_caixa_id: number | null;
  documento_tesouraria_id: number | null;
  documento_comissao_id: number | null;
  comissao_deduzida: boolean | null;
  criado_por: string | null;
  criado_em: string | null;
}

export interface ItemPrestacao {
  chave_item: string;
  natureza: TipoMeio;
  meio_id: string | null;
  nome: string | null;
  conta_transitoria: string | null;
  conta_liquidacao: string | null;
  valor: string;
  estado: 'PRESTADO' | 'BLOQUEADO' | 'POR_PRESTAR';
  bloqueio: { codigo: string; mensagem: string } | null;
  liquidacao: LiquidacaoResumo | null;
  // numerário
  numerario_sistema?: string;
  desvio_aplicado?: string | null;
  movimento?: 'REC' | 'PAG';
  // TPA
  codigo_tpa?: string | null;
  operacoes_sistema?: number | null;
  valor_talao?: string | null;
  operacoes_talao?: number | null;
  referencia_lote?: string | null;
  diferenca?: string | null;
  comissao_pct?: number;
  comissao_sugerida?: string;
  conta_comissao?: string | null;
  comissao_deduzida?: boolean;
  // transferência
  venda_id?: number;
  numero_documento?: string | null;
  referencia?: string | null;
}

export interface SessaoPrestacao
  extends Pick<
    SessaoPOS,
    | 'id'
    | 'codigo_sessao'
    | 'numero_z'
    | 'terminal_pos_id'
    | 'codigo_terminal'
    | 'nome_terminal'
    | 'operador_id'
    | 'nome_operador'
    | 'fechado_em'
    | 'estado_contabilizacao'
    | 'estado_desvio'
    | 'estado_liquidacao'
    | 'desvio'
    | 'fechos_tpa'
  > {
  itens: ItemPrestacao[];
  liquidacoes?: Liquidacao[];
}

export interface FolhaCaixa {
  id: number;
  codigo_conta: string;
  operador: string | null;
  data_abertura: string | null;
}

export interface Liquidacao {
  id: number;
  estado: 'REGISTADO' | 'ANULADO';
  sessao_pos_id: number;
  numero_z: string | null;
  chave_item: string;
  natureza_registo: TipoMeio;
  meio_pagamento_codigo: string | null;
  data: string;
  montante_bruto: string;
  comissao: string | null;
  montante_liquido: string;
  alvo: 'FOLHA_CAIXA' | 'TESOURARIA';
  conta_destino: string | null;
  conta_transitoria: string | null;
  sessao_caixa_id: number | null;
  movimento_caixa_id: number | null;
  documento_tesouraria_id: number | null;
  documento_comissao_id: number | null;
  numero_documento: string | null;
  referencia: string | null;
  criado_por: string | null;
  criado_em: string | null;
  cancelado_em: string | null;
  cancelado_por: string | null;
}

// ───────────── Relatórios ─────────────

export interface PainelRelatorios {
  kpis: {
    facturacao: string;
    documentos: number;
    ticket_medio: string;
    desvios: string;
    sessoes_abertas: number;
    desvios_por_deliberar: number;
    sessoes_por_integrar: number;
    sessoes_por_prestar: number;
    diferencas_tpa: number;
  };
  por_meio: { tipo: TipoMeio | null; nome: string; quantidade: number; valor: string }[];
  zs: (Pick<
    SessaoPOS,
    | 'id'
    | 'numero_z'
    | 'codigo_sessao'
    | 'terminal_pos_id'
    | 'codigo_terminal'
    | 'nome_terminal'
    | 'operador_id'
    | 'nome_operador'
    | 'aberto_em'
    | 'fechado_em'
    | 'numero_vendas'
    | 'total_vendas'
    | 'numerario_esperado'
    | 'numerario_contado'
    | 'desvio'
    | 'estado_desvio'
    | 'estado_contabilizacao'
    | 'estado_liquidacao'
  >)[];
  diferencas_tpa: { sessao_pos_id: number; numero_z: string; meio_id: string | null; nome: string | null; codigo_tpa: string | null; valor_sistema: ValorApi; valor_talao: ValorApi; diferenca: string; referencia_lote: string | null }[];
  por_produto: { produto_id: number; codigo: string | null; nome: string; quantidade: string; total_liquido: string; total_bruto: string }[];
  por_terminal: { terminal_pos_id: number; codigo_terminal: string; nome_terminal: string; documentos: number; total: string }[];
  por_operador: { operador: string | null; documentos: number; total: string }[];
}
