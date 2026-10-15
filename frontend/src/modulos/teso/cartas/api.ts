/** Tipos da API das cartas de pagamento (CartasPagamentoController, /api/tesouraria/cartas — Fase D, ADR-071). */

export type TipoCarta = 'TERCEIRO' | 'SALARIOS';
export type EstadoCarta = 'EMITIDA' | 'ANULADA';

export const ROTULO_TIPO_CARTA: Record<TipoCarta, string> = { TERCEIRO: 'Pagamento a terceiros', SALARIOS: 'Pagamento de salários' };

export interface ContaBancariaBeneficiario {
  id: number;
  banco: string | null;
  iban: string | null;
  numero_conta: string | null;
  titular: string | null;
  codigo_moeda: string | null;
  predefinida: boolean;
}

/** Beneficiário elegível (calculado no servidor a partir do pagamento). */
export interface BeneficiarioCarta {
  tipo: TipoCarta;
  terceiro_id: number | null;
  colaborador_id: number | null;
  nome: string;
  nif: string | null;
  valor: string;
  referencia: string | null;
  conta_bancaria_id: number | null;
  banco: string | null;
  iban: string | null;
  numero_conta: string | null;
  titular: string | null;
  contas: ContaBancariaBeneficiario[];
  /** Número da carta activa em que já está (o mesmo pagamento / o mesmo período). */
  ja_na_carta: string | null;
  avencado?: boolean;
}

export interface ContaEmissora {
  codigo: string;
  descricao: string | null;
  codigo_moeda: string;
  banco_nome: string | null;
  banco_balcao: string | null;
  banco_numero_conta: string | null;
  banco_iban: string | null;
  banco_swift: string | null;
  banco_titular: string | null;
  titular_efectivo: string | null;
  completa: boolean;
}

export interface ModeloTexto {
  id: number | null;
  nome: string | null;
  assunto: string;
  corpo: string;
  nome_assinatura: string;
  cargo_assinatura: string;
  local: string;
}

export interface ResumoDocumentoCarta {
  id: number;
  numero_documento: string | null;
  tipo: string;
  estado: string | null;
  data_documento: string;
  conta_financeira: string;
  descricao: string | null;
  referencia: string | null;
  valor_total: string;
  valor_total_moeda: string | null;
  codigo_moeda: string | null;
  periodo_processamento_salarial_id: number | null;
}

/** GET /tesouraria/cartas/documentos/{id}/elegibilidade */
export interface Elegibilidade {
  pode: boolean;
  codigo?: string;
  motivo?: string;
  sugestao?: string | null;
  tipo: TipoCarta;
  documento: ResumoDocumentoCarta;
  conta?: ContaEmissora;
  beneficiarios?: BeneficiarioCarta[];
  valor_documento?: string;
  valor_em_cartas?: string;
  disponivel?: string;
  modelo?: ModeloTexto;
  local_padrao?: string;
}

/** GET /tesouraria/cartas/documentos — pagamentos 42/43 não anulados. */
export interface DocumentoElegivel extends ResumoDocumentoCarta {
  tipo_carta: TipoCarta;
  conta_descricao: string | null;
  banco_nome: string | null;
  conta_com_iban: boolean;
  cartas_activas: number;
}

export interface ItemCarta {
  id: number;
  colaborador_id: number | null;
  terceiro_id: number | null;
  nome: string | null;
  nif: string | null;
  banco: string | null;
  iban: string | null;
  numero_conta: string | null;
  titular: string | null;
  referencia: string | null;
  montante: string;
}

export interface EmpresaCarta {
  nome: string | null;
  nif: string | null;
  endereco: string | null;
  municipio: string | null;
  provincia: string | null;
  telefone: string | null;
  email: string | null;
}

export interface CartaPagamento {
  id: number;
  tipo: TipoCarta | null;
  tipo_efectivo: TipoCarta;
  numero: string | null;
  sequencia: number | null;
  ano: number | null;
  estado: EstadoCarta | null;
  estado_efectivo: EstadoCarta;
  data: string;
  documento_tesouraria_id: number | null;
  periodo_processamento_salarial_id: number | null;
  mes_ano: string | null;
  referencia_movimento: string | null;
  codigo_conta_bancaria: string | null;
  banco_nome: string | null;
  banco_balcao: string | null;
  banco_numero_conta: string | null;
  banco_iban: string | null;
  banco_swift: string | null;
  banco_titular: string | null;
  codigo_moeda: string | null;
  assunto: string | null;
  corpo: string | null;
  nome_assinatura: string | null;
  cargo_assinatura: string | null;
  local: string | null;
  montante_total: string;
  criado_por: string | null;
  anulada_em: string | null;
  anulada_por: string | null;
  motivo_anulacao: string | null;
  n_beneficiarios?: number;
  documento?: { id: number; numero_documento: string | null; estado: string | null } | null;
  dados_banco_actuais?: boolean;
  itens?: ItemCarta[];
  empresa?: EmpresaCarta | null;
}

export interface ModeloCarta {
  id: number;
  tipo: TipoCarta;
  nome: string | null;
  assunto: string | null;
  corpo: string | null;
  nome_assinatura: string | null;
  cargo_assinatura: string | null;
  local: string | null;
  predefinido: boolean;
}

/** GET /tesouraria/cartas/modelos */
export interface ModelosCarta {
  modelos: ModeloCarta[];
  tipos: Record<TipoCarta, string>;
  padrao: Record<TipoCarta, { assunto: string; corpo: string; cargo_assinatura: string }>;
  variaveis: Record<string, string>;
}
