/** Tipos das respostas de /api/ativos (contratos confirmados com pedidos GET reais). Valores em Kz: texto decimal com 2 casas. */

export type EstadoActivo = 'ACTIVO' | 'INACTIVO' | 'ABATIDO';

export interface CategoriaActivo {
  id: number;
  nome: string;
  taxa_anual: string | null;
  vida_util_padrao: number | null;
  conta_gasto: string | null;
  conta_amortizacao_acumulada: string | null;
  conta_venda: string | null;
  conta_perda: string | null;
  conta_ativo: string | null;
  /** N.º de activos na categoria (só na listagem). */
  ativos?: number;
}

export interface Activo {
  id: number;
  codigo: string | null;
  descricao: string;
  categoria_ativo_id: number | null;
  centro_custo_id: number | null;
  unidade_negocio_id: number | null;
  fornecedor_id: number | null;
  valor_aquisicao: string;
  valor_residual: string | null;
  vida_util: number | null;
  vida_util_restante: number | null;
  quota_fixa: string | null;
  amortizacao_acumulada_inicial: string | null;
  acumulado_fim_ano: number | null;
  data_aquisicao: string | null;
  estado: EstadoActivo | string;
  lancamento_contabil_id: number | null;
  amortizacao_acumulada: string | null;
  valor_liquido?: string | null;
  taxa?: string | null;
  /** A ficha tem amortizações integradas ou lançamento de compra: campos de valor bloqueados. */
  bloqueado?: boolean;
  categoria_ativo?: Partial<CategoriaActivo> & { id: number; nome: string } | null;
  centro_custo?: { id: number; codigo: string; descricao?: string | null } | null;
  unidade_negocio?: { id: number; codigo: string | null } | null;
  fornecedor?: { id: number; nome: string } | null;
}

export interface AmortizacaoRegisto {
  id: number;
  ativo_imobilizado_id: number;
  periodo_codigo: string;
  contabilizado: boolean;
  valor: string;
  data: string | null;
}

export interface Transferencia {
  id: number;
  ativo_imobilizado_id: number;
  data: string;
  projeto_id?: number | null;
  centro_custo_origem_id?: number | null;
  centro_custo_destino_id?: number | null;
  ativo_imobilizado?: { id: number; codigo: string | null; descricao: string } | null;
  centro_custo_origem?: { id: number; codigo: string; descricao: string | null } | null;
  centro_custo_destino?: { id: number; codigo: string; descricao: string | null } | null;
}

export interface Afectacao {
  id: number;
  ativo_imobilizado_id: number;
  projeto_id: number;
  data_inicio: string;
  data_fim: string | null;
  ativo_imobilizado?: { id: number; codigo: string | null; descricao: string } | null;
  projeto?: { id: number; codigo: string | null; nome: string } | null;
}

export interface Manutencao {
  id: number;
  ativo_imobilizado_id: number;
  tipo: 'PREVENTIVA' | 'CORRECTIVA' | string;
  data: string;
  descricao: string | null;
  custo: string | null;
  estado: string;
  resolucao: string | null;
  data_execucao: string | null;
  ativo_imobilizado?: { id: number; codigo: string | null; descricao: string } | null;
}

export interface Abate {
  id: number;
  ativo_imobilizado_id: number;
  tipo: 'SINISTRO' | 'VENDA' | 'FIM_VIDA' | string;
  data: string;
  descricao: string | null;
  valor: string | null;
  terceiro_id: number | null;
  conta_terceiro: string | null;
  numero_documento?: string | null;
  ativo_imobilizado?: { id: number; codigo: string | null; descricao: string; valor_aquisicao?: string } | null;
  terceiro?: { id: number; nome: string } | null;
}

export interface FichaActivo extends Activo {
  origem: {
    id: number;
    numero_lan: string | null;
    numero_documento: string | null;
    data_documento: string | null;
    codigo_conta: string;
    valor: string;
    descricao: string | null;
    diario?: { codigo: string; nome: string | null } | null;
  } | null;
  transferencias: Transferencia[];
  manutencoes: Manutencao[];
  amortizacoes: AmortizacaoRegisto[];
  abates: Abate[];
  afetacoes: Afectacao[];
}

export type EstadoQuota = 'POR_CALCULAR' | 'RASCUNHO' | 'INTEGRADO';
export type EstadoPeriodo = 'ABERTO' | 'CALCULADO' | 'PARCIAL' | 'INTEGRADO';

export interface LinhaPeriodo {
  ativo_imobilizado_id: number;
  codigo: string | null;
  descricao: string;
  categoria: string | null;
  centro_custo: string | null;
  unidade_negocio: string | null;
  valor_aquisicao: string;
  acumulado_anterior: string;
  quota: string;
  quota_calculada: string | null;
  estado: EstadoQuota | string;
  amortizacao_id: number | null;
}

export interface PeriodoAmortizacoes {
  periodo: string;
  data: string;
  estado: EstadoPeriodo | string;
  totais: { integrado: string; rascunho: string; por_calcular: string };
  contagens: Partial<Record<EstadoQuota, number>>;
  linhas: LinhaPeriodo[];
}

export interface MesPendente {
  periodo: string;
  ativos: number;
  valor: string;
}

export interface PreVisualizacao {
  periodo: string;
  numero_documento: string;
  movimentos: { ativo_imobilizado_id: number; codigo: string | null; descricao: string; conta_debito: string; conta_credito: string; valor: string }[];
  linhas: { codigo_conta: string; tipo_dc: 'D' | 'C'; valor: string; unidade_negocio_id: number | null; centro_custo_id: number | null }[];
  total: string;
}

export interface Verificacao {
  registos: number;
  quotas_divergentes: { ativo_imobilizado_id: number; codigo: string | null; periodo: string; registado: string; esperado: string; diferenca: string; contabilizado: boolean }[];
  acumulados_divergentes: { ativo_imobilizado_id: number; codigo: string | null; ficha: string; derivado: string }[];
}

export interface MesMapa {
  valor: string;
  contabilizado: boolean;
  amortizacao_id: number;
}

export interface LinhaMapa {
  ativo_imobilizado_id: number;
  codigo: string | null;
  descricao: string;
  estado: string;
  categoria: string | null;
  taxa: string | null;
  anos_vida: string | null;
  data_aquisicao: string | null;
  aquisicao_anos_anteriores: string;
  aquisicao_ano: string;
  acumulado_anterior: string;
  meses: Record<string, MesMapa | null>;
  ano: string;
  acumulado: string;
  liquido: string;
}

export interface MapaAmortizacoes {
  ano: number;
  linhas: LinhaMapa[];
  totais: Omit<LinhaMapa, 'ativo_imobilizado_id' | 'codigo' | 'descricao' | 'estado' | 'categoria' | 'taxa' | 'anos_vida' | 'data_aquisicao' | 'meses'> & { meses: Record<string, string> };
}

export interface LinhaFiscal {
  ativo_imobilizado_id: number;
  codigo: string | null;
  descricao: string;
  conta: string | null;
  mes_aquisicao: number | null;
  ano_aquisicao: number | null;
  mes_inicio_utilizacao: number | null;
  ano_inicio_utilizacao: number | null;
  valor_aquisicao: string;
  anos_vida: string | null;
  taxa_categoria: string | null;
  taxa_efectiva: string | null;
  anteriores: string;
  exercicio: string;
  acumuladas: string;
  liquido: string;
}

export interface MapaFiscal {
  ano: number;
  linhas: LinhaFiscal[];
  totais: { valor_aquisicao: string; anteriores: string; exercicio: string; acumuladas: string; liquido: string };
}

export interface ResumoCategoria {
  categoria_ativo_id: number;
  categoria: string;
  ativos: number;
  bruto: string;
  acumulada: string;
  liquido: string;
}

export interface LinhaPendente {
  id: number;
  numero_lan: string | null;
  numero_documento: string | null;
  data_documento: string | null;
  codigo_conta: string;
  descricao: string | null;
  valor: string;
  terceiro_id: number | null;
  unidade_negocio_id: number | null;
  centro_custo_id: number | null;
  diario: string | null;
  terceiro: string | null;
  inventariado: string;
  por_inventariar: string;
  ativos: number;
  estado: 'PENDENTE' | 'PARCIAL' | string;
}

export interface AquisicoesPendentes {
  linhas: LinhaPendente[];
  total_por_inventariar: string;
  ativos_sem_lancamento: { id: number; codigo: string | null; descricao: string; valor_aquisicao: string; data_aquisicao: string | null; categoria_ativo_id: number | null; estado: string }[];
}

export interface SimulacaoAbate {
  linhas: { codigo_conta: string; tipo_dc: 'D' | 'C'; valor: string; descricao: string; terceiro_id?: number | null }[];
  valor_aquisicao: string;
  amortizacao_acumulada: string;
  valor_liquido: string;
  resultado: string;
}

/** Linha da importação de activos (POST /ativos/bens/importar). */
export interface LinhaImportacao {
  codigo?: string;
  descricao?: string;
  valor_aquisicao?: number;
  categoria?: string;
  vida_util?: number;
  anos_amortizados?: number;
  amortizacao_acumulada?: number;
  ano_amortizacao_acumulada?: number;
  data_aquisicao?: string;
}
