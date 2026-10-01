/** Tipos das respostas de /api/acrescimos (confirmados com pedidos GET reais). Valores em Kz: texto decimal com 2 casas. */

export type TipoAD = 'ACRESCIMO' | 'DIFERIMENTO';
export type NaturezaAD = 'CUSTO' | 'PROVEITO';
export type EstadoAD = 'ACTIVO' | 'A_REGULARIZAR' | 'REGULARIZADO' | 'A_TERMINAR' | 'CONCLUIDO' | 'ANULADO';
export type FonteRecolha = 'COMPRA' | 'VENDA' | 'DIARIO' | 'TESOURARIA';

export interface Origem {
  fonte?: string | null;
  id?: number | string | null;
  doc?: string | null;
  data?: string | null;
  conta?: string | null;
}

export interface ItemAD {
  id: number;
  tipo: TipoAD;
  natureza: NaturezaAD;
  descricao: string;
  valor: string;
  conta_resultado: string;
  conta_balanco: string;
  data_inicio: string;
  data_fim: string;
  reparticao: 'DIAS' | 'MESES' | null;
  data_documento: string | null;
  data_limite: string | null;
  documento_em_balanco: boolean | null;
  terceiro_id: number | null;
  unidade_negocio_id: number | null;
  centro_custo_id: number | null;
  projeto_id: number | null;
  origem: Origem | null;
  notas: string | null;
  estado: EstadoAD | string;
  estado_rotulo?: string;
  regularizacao: (Origem & { valor?: string | null; anulacao?: boolean; motivo?: string | null }) | null;
  termino: { data: string; motivo?: string | null } | null;
  criado_por?: string | null;
  atualizado_por?: string | null;
  terceiro?: { id: number; nome: string } | null;
  /** Só na listagem. */
  reconhecido?: string;
  tem_lancamentos?: boolean;
  sem_documento?: boolean;
}

export interface LancamentoAD {
  id: number;
  item_acrescimo_diferimento_id: number;
  periodo: string;
  tipo: 'INICIAL' | 'RECONHECIMENTO' | 'REGULARIZACAO' | 'ANULACAO' | 'TERMINO' | string;
  valor: string;
  diario_id: number | null;
  numero_lan: string | null;
  numero_documento: string | null;
  data_documento: string | null;
  estado: 'CONTABILIZADO' | 'ANULADO' | string;
  por: string | null;
  em: string | null;
  diferenca: string | null;
  anulado_por: string | null;
  anulado_em: string | null;
}

export interface Quota {
  periodo: string;
  valor: string;
  peso: number;
  lancamento?: LancamentoAD | null;
}

export interface MapaItem {
  item: ItemAD;
  quotas: Quota[];
  lancamentos: LancamentoAD[];
  reconhecido: string;
  saldo_balanco: string;
}

export interface Definicoes {
  id?: number;
  contas: Record<'ACRESCIMO_PROVEITO' | 'ACRESCIMO_CUSTO' | 'DIFERIMENTO_CUSTO' | 'DIFERIMENTO_PROVEITO', string | null>;
  diario_id: number | null;
  prazo_documento_dias: number | null;
  rotulos_contas: Record<string, string>;
  modelos: { id: string; rotulo: string; tipo: TipoAD; natureza: NaturezaAD; meses: number; prefixo: string }[];
  tipos: Record<string, string>;
  naturezas: Record<string, string>;
  estados: Record<string, string>;
  tipos_linha: Record<string, string>;
}

export interface LinhaProposta {
  chave: string;
  tipo: string;
  tipo_rotulo: string;
  periodo: string;
  valor: string;
  data: string;
  atrasada: boolean;
  debito: string;
  credito: string;
  diferenca?: string | null;
  item: Pick<ItemAD, 'id' | 'tipo' | 'natureza' | 'descricao' | 'valor' | 'estado' | 'terceiro_id' | 'conta_resultado' | 'conta_balanco'>;
}

export interface Proposta {
  mes: string;
  linhas: LinhaProposta[];
  alertas: { id: number; descricao: string; valor: string; terceiro_id: number | null; data_limite: string }[];
  total: string;
}

export interface ResultadoContabilizacao {
  ok: { chave: string; numero_lan?: string; [k: string]: unknown }[];
  erros: { chave: string; mensagem: string; codigo?: string }[];
}

export interface LinhaReconciliacao {
  conta: string;
  modulo: string;
  diario: string;
  diferenca: string;
}

export interface Candidato {
  fonte: FonteRecolha;
  id: number | string;
  doc: string;
  lan?: string | null;
  data: string;
  terceiro_id: number | null;
  terceiro: string;
  descricao?: string | null;
  natureza: NaturezaAD;
  contabilizado: boolean;
  unidade_negocio_id: number | null;
  centro_custo_id: number | null;
  projeto_id: number | null;
  linhas: { conta: string; valor: string }[];
  total: string;
  ligado: boolean;
}
