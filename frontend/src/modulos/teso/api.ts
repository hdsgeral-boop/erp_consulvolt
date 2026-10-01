/** Tipos da API de Tesouraria (TesourariaController, OperacoesTesourariaController, MapasTesourariaController). */
import type { RefTerceiro } from '@/modulos/compras/comum/referencias';

export type TipoDocumento = 'PAGAMENTO' | 'RECEBIMENTO';
export type EstadoDocumento = 'PENDENTE' | 'INTEGRADO' | 'ANULADO';

export interface LinhaDocumentoTesouraria {
  id: number;
  codigo_conta: string;
  terceiro_id: number | null;
  terceiro?: RefTerceiro | null;
  numero_documento: string | null;
  descricao: string | null;
  valor: string;
  tipo_dc: 'D' | 'C';
  unidade_negocio_id: number | null;
  centro_custo_id: number | null;
  projeto_id: number | null;
  venda_id: number | null;
  fatura_compra_id: number | null;
  codigo_moeda: string | null;
  valor_moeda: string | null;
  valor_introduzido: string | null;
  nota_demonstracao_id: number | null;
  nota_fluxo_caixa_id: number | null;
}

export interface DocumentoTesouraria {
  id: number;
  tipo: TipoDocumento;
  data_documento: string;
  conta_financeira: string;
  descricao: string | null;
  valor_total: string;
  estado: EstadoDocumento | null;
  referencia: string | null;
  projeto_id: number | null;
  codigo_moeda: string | null;
  taxa_cambio: string | null;
  valor_total_moeda: string | null;
  periodo_processamento_salarial_id: number | null;
  reconciliacao_codigo: string | null;
  numero_documento: string | null;
  numero_lan_contabilizacao: string | null;
  integrado_em: string | null;
  integrado_por: string | null;
  anulado_em: string | null;
  motivo_anulacao: string | null;
  importado: boolean | null;
  linhas?: LinhaDocumentoTesouraria[];
}

/** POST /tesouraria/documentos/integrar — integração em lote (cada documento na sua transacção). */
export interface ResultadoIntegracaoLote {
  integrados: { id: number; numero_documento: string | null; numero_lan_contabilizacao: string | null }[];
  erros: { id: number; numero_documento: string | null; codigo: string; mensagem: string }[];
}

/** GET /tesouraria/disponibilidades — saldos das contas 43/45 à data (saldo final do balancete). */
export interface Disponibilidades {
  data: string;
  contas: { codigo_conta: string; descricao: string | null; grupo: string; tipo: 'BANCO' | 'CAIXA'; meio_pagamento: string | null; codigo_moeda: string | null; saldo: string }[];
  totais: { bancos: string; caixa: string; total: string };
}

/** GET /tesouraria/extrato-conta — razão de uma conta 43/45 com saldo corrido. */
export interface ExtratoConta {
  codigo_conta: string;
  descricao: string | null;
  data_inicio: string;
  data_fim: string;
  saldo_inicial: string;
  debito: string;
  credito: string;
  saldo_final: string;
  movimentos: {
    id: number;
    data_documento: string;
    numero_lan: string | null;
    numero_documento: string | null;
    descricao: string | null;
    tipo_dc: 'D' | 'C';
    valor: string;
    saldo: string;
    diario: string | null;
    terceiro_id: number | null;
    terceiro: RefTerceiro | null;
    estorno_de_id: number | null;
    estornado_por_id: number | null;
  }[];
}

export interface Pendente {
  codigo_moeda: string | null;
  saldo_moeda: string | null;
  total_moeda: string | null;
  terceiro_id: number;
  terceiro: string | null;
  codigo_conta: string;
  numero_documento: string;
  data_documento: string;
  natureza: 'A_RECEBER' | 'A_PAGAR';
  total: string;
  liquidado: string;
  em_liquidacao: string;
  saldo: string;
  liquidar_a: 'D' | 'C';
  venda_id: number | null;
  fatura_compra_id: number | null;
}

export interface MeioPagamento {
  id: number;
  nome: string;
  codigo_conta: string;
  iban: string | null;
  swift: string | null;
  ativo: boolean;
  predefinido: boolean;
  codigo_moeda: string | null;
}

export type EstadoSessao = 'ABERTA' | 'FECHADA' | 'CONTABILIZADA';

export interface MovimentoCaixa {
  id: number;
  tipo: 'REC' | 'PAG';
  data_documento: string;
  numero_documento: string | null;
  referencia: string | null;
  terceiro_id: number | null;
  terceiro?: RefTerceiro | null;
  conta_debito: string;
  conta_credito: string;
  descricao: string | null;
  valor: string;
  tipo_origem: string | null;
  contabilizado: boolean | null;
}

export interface SessaoCaixa {
  id: number;
  codigo_conta: string;
  operador: string | null;
  data_abertura: string;
  data_fecho: string | null;
  saldo_abertura: string | null;
  saldo_fecho: string | null;
  saldo_fisico: string | null;
  estado: EstadoSessao;
  codigo_moeda: string | null;
  numeros_lan_contabilizacao: string | null;
  fechado_por: string | null;
  contabilizado_em: string | null;
  saldo_sistema?: string;
  diferenca?: string | null;
  movimentos?: MovimentoCaixa[];
  aviso?: string | null;
}

export type EstadoConferencia = 'RASCUNHO' | 'FINALIZADO';

export interface ConferenciaCaixa {
  id: number;
  codigo_conta: string;
  nome_conta: string | null;
  data_conferencia: string;
  nome_operador: string | null;
  denominacoes: string | Record<string, number> | null;
  total_fisico: string;
  total_sistema: string;
  saldo_externo: string | null;
  diferenca: string;
  justificacao: string | null;
  estado: EstadoConferencia;
  referencia_lancamento: string | null;
  conta_regularizacao: string | null;
  nome_gerente: string | null;
  assinado_gerente_em: string | null;
}

/** Notas e moedas aceites na conferência (ServicoConferenciaCaixa::DENOMINACOES). */
export const DENOMINACOES: { chave: string; valor: number; rotulo: string }[] = [
  { chave: 'N5000', valor: 5000, rotulo: 'Nota 5 000' },
  { chave: 'N2000', valor: 2000, rotulo: 'Nota 2 000' },
  { chave: 'N1000', valor: 1000, rotulo: 'Nota 1 000' },
  { chave: 'N500', valor: 500, rotulo: 'Nota 500' },
  { chave: 'N200', valor: 200, rotulo: 'Nota 200' },
  { chave: 'M200', valor: 200, rotulo: 'Moeda 200' },
  { chave: 'M100', valor: 100, rotulo: 'Moeda 100' },
  { chave: 'M50', valor: 50, rotulo: 'Moeda 50' },
  { chave: 'M20', valor: 20, rotulo: 'Moeda 20' },
  { chave: 'M10', valor: 10, rotulo: 'Moeda 10' },
  { chave: 'M5', valor: 5, rotulo: 'Moeda 5' },
  { chave: 'M1', valor: 1, rotulo: 'Moeda 1' },
];

export interface LinhaExtratoBancario {
  id: number;
  codigo_conta: string;
  data: string;
  referencia: string | null;
  descricao: string | null;
  tipo_dc: 'D' | 'C';
  valor: string;
  estado: 'PENDENTE' | 'CONCILIADO' | 'ANULADO';
  reconciliacao_codigo: string | null;
  lote_codigo?: string | null;
}

export interface SugestaoReconciliacao {
  linha_extrato_id: number;
  lancamento_id: number;
  valor: string;
  criterio: 'MESMA_DATA' | 'TOLERANCIA_DATA' | 'VALOR_UNICO';
}

export interface MapaReconciliacao {
  conta: string;
  data: string;
  saldo_diario: string;
  saldo_banco_esperado: string;
  por_reconciliar_diario: { total: string; linhas: { id: number; data_documento: string; numero_lan: string; numero_documento: string | null; descricao: string | null; tipo_dc: 'D' | 'C'; valor: string }[] };
  por_reconciliar_extrato: { total: string; linhas: { id: number; data: string; referencia: string | null; descricao: string | null; tipo_dc: 'D' | 'C'; valor: string }[] };
}

export interface ReconciliacaoBancaria {
  id: number;
  reconciliacao_codigo: string;
  data: string;
  valor_total: string | null;
  estado: string;
  tipo?: string | null;
  codigo_conta?: string | null;
}

export const ROTULO_TIPO: Record<TipoDocumento, string> = { PAGAMENTO: 'Pagamento', RECEBIMENTO: 'Recebimento' };
