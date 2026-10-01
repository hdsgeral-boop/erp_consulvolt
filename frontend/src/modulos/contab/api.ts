import type { TerceiroLinha } from './comum/terceiro';
/** Tipos da API de Contabilidade (backend: LancamentoResource, ServicoRelatoriosContabeis, ServicoDemonstracoesFinanceiras, ServicoEncerramento…). */

export interface ContaPlano {
  id: number;
  codigo: string;
  descricao: string | null;
  tipo: 'M' | 'T';
  codigo_moeda: string | null;
  natureza_conta?: string | null;
}

export interface Diario {
  id: number;
  codigo: string;
  descricao: string | null;
  nome?: string | null;
}

export interface RegistoAux {
  id: number;
  codigo: string;
  descricao: string | null;
}

export interface LinhaLancamento {
  id: number;
  diario_id: number;
  numero_lan: string;
  numero_documento: string | null;
  referencia: string | null;
  data_documento: string;
  data_lancamento: string | null;
  codigo_conta: string;
  tipo_dc: 'D' | 'C';
  valor: string;
  descricao: string | null;
  terceiro_id: number | null;
  /** {id, nome, nif} — presente nas linhas devolvidas pela API de lançamentos (null sem terceiro). */
  terceiro?: TerceiroLinha | null;
  centro_custo_id: number | null;
  unidade_negocio_id: number | null;
  projeto_id: number | null;
  nota_demonstracao_id: number | null;
  nota_fluxo_caixa_id: number | null;
  reconciliacao_codigo: string | null;
  tipo_origem: string | null;
  estorno_de_id: number | null;
  estornado_por_id: number | null;
  estornado_em: string | null;
  nome_utilizador: string | null;
}

export interface DocumentoLancamento {
  diario_id: number;
  numero_lan: string;
  numero_documento: string | null;
  data_documento: string;
  debito: string;
  credito: string;
  equilibrado: boolean;
  linhas: LinhaLancamento[];
}

export interface LinhaBalancete {
  codigo_conta: string;
  descricao: string | null;
  saldo_inicial: string;
  saldo_inicial_devedor: string;
  saldo_inicial_credor: string;
  debito: string;
  credito: string;
  saldo_final: string;
  saldo_devedor: string;
  saldo_credor: string;
  terceiro_id?: number | null;
  terceiro?: string | null;
}

export interface Balancete {
  linhas: LinhaBalancete[];
  totais: Record<string, string>;
}

export interface MovimentoRazao {
  id: number;
  data_documento: string;
  numero_lan: string;
  numero_documento: string | null;
  descricao: string | null;
  tipo_dc: 'D' | 'C';
  valor: string;
  terceiro_id: number | null;
  terceiro?: TerceiroLinha | null;
  diario: string | null;
  estorno_de_id: number | null;
  estornado_por_id: number | null;
  saldo: string;
}

export interface Razao {
  codigo_conta: string;
  saldo_inicial: string;
  debito: string;
  credito: string;
  saldo_final: string;
  movimentos: MovimentoRazao[];
}

export interface MovimentoExtrato {
  id: number;
  data_documento: string;
  codigo_conta: string;
  descricao_conta: string | null;
  terceiro_id: number | null;
  terceiro: string | null;
  nif_terceiro: string | null;
  diario: string | null;
  numero_lan: string;
  numero_documento: string | null;
  referencia: string | null;
  descricao: string | null;
  tipo_dc: 'D' | 'C';
  valor: string;
  reconciliacao_codigo: string | null;
  codigo_moeda: string | null;
  valor_moeda: string | null;
  contrapartidas: string | null;
  estorno_de_id: number | null;
  estornado_por_id: number | null;
  saldo: string;
}

export interface Extrato {
  data_inicio: string | null;
  data_fim: string | null;
  tipo: string;
  saldo_inicial: string;
  debito: string;
  credito: string;
  saldo_final: string;
  movimentos: MovimentoExtrato[];
}

export interface Evolucao {
  ano: number;
  meses_ativos: number[];
  contas: { codigo_conta: string; descricao: string | null; meses: Record<string, string>; saldo: string; terceiros?: { terceiro_id: number; terceiro: string | null; meses: Record<string, string>; saldo: string }[] }[];
  totais: Record<string, string>;
  saldo_total: string;
}

export interface LinhaIva {
  id: number;
  data_documento: string;
  diario: string | null;
  numero_documento: string | null;
  codigo_conta: string;
  descricao_conta: string | null;
  descricao: string | null;
  tipo_dc: 'D' | 'C';
  valor: string;
  nif: string | null;
  nome: string | null;
  total_documento: string;
  base: string;
  iva_debito: string;
  iva_credito: string;
}

export interface MapaIva {
  totais: { total_documento: string; base: string; iva_debito: string; iva_credito: string; linhas: number };
  linhas: LinhaIva[];
}

export interface ParComparativo {
  atual: string;
  anterior: string;
}

export interface LinhaDemonstracao extends ParComparativo {
  nota?: string | null;
  codigo?: string | null;
  descricao: string;
  subtotal?: boolean;
}

export interface Seccao {
  linhas: LinhaDemonstracao[];
  total: ParComparativo & { descricao?: string };
}

export interface Balanco {
  data_inicio: string;
  data_fim: string;
  ano: number;
  ano_anterior: number;
  seccoes: Record<'activo_nao_corrente' | 'activo_corrente' | 'capital_proprio' | 'passivo_nao_corrente' | 'passivo_corrente', Seccao>;
  totais: { atual: Record<string, string>; anterior: Record<string, string> };
  resultado_liquido: ParComparativo;
  controlo: Record<'atual' | 'anterior', { diferenca: string; equilibrado: boolean; sem_nota: { linhas: number; debito: string; credito: string }; notas_fora_da_estrutura: unknown[] }>;
  historico_anterior: boolean;
}

export interface LinhaTotal extends ParComparativo {
  descricao: string;
  nota?: string;
}

export interface DemonstracaoResultados {
  ano: number;
  proveitos_operacionais: Seccao;
  custos_operacionais: Seccao;
  resultados_operacionais: LinhaTotal;
  outros_resultados: LinhaTotal[];
  resultados_antes_impostos: LinhaTotal;
  imposto: LinhaTotal;
  resultado_actividades_correntes: LinhaTotal;
  resultados_extraordinarios: LinhaTotal;
  resultado_liquido: LinhaTotal;
  historico_anterior: boolean;
}

export interface FluxoCaixa {
  ano: number;
  seccoes: Record<'operacionais' | 'investimento' | 'financiamento', Seccao>;
  variacao_caixa: ParComparativo;
  caixa_inicial: ParComparativo;
  caixa_final: ParComparativo;
  controlo: { variacao_classe_4: string; nao_explicado: string };
  historico_anterior: boolean;
}

// ─────────── Encerramento ───────────

export interface ExercicioResumo {
  ano: number;
  encerrado: boolean;
  linhas: number;
  apuramento: boolean;
}

export interface PassoEstado {
  passo: number;
  titulo: string;
  diario: string;
  documento: string;
  numero_lan: string | null;
  linhas: number;
  resultado: string | null;
}

export interface EstadoExercicio {
  ano: number;
  encerrado: boolean;
  passos: PassoEstado[];
  resumo_classe_8: { codigo_conta: string; descricao: string | null; saldo_credor: string }[];
}

export interface PrevisualizacaoPasso {
  ano: number;
  passo: number;
  titulo: string;
  diario: string;
  documento: string;
  data_documento: string;
  periodo: number;
  linhas: number;
  movimentos: { descricao: string; conta_debito: string; conta_credito: string; valor: string }[];
  conta_resultado: string;
  resultado: string;
  contas_em_falta: string[];
  contas_totalizadoras: string[];
  numero_lan_anterior: string | null;
}

export interface Verificacao {
  tipo: string;
  ok: boolean;
  bloqueia: boolean;
  descricao: string;
  diferenca: string | null;
  detalhes: Record<string, unknown> | null;
}

export interface ValidacaoExercicio {
  ano: number;
  encerrado: boolean;
  pode_encerrar: boolean;
  verificacoes: Verificacao[];
  divergencias: Verificacao[];
  avisos: Verificacao[];
}

export interface MapaApuramento {
  ano: number;
  linhas: { id: number; data_documento: string; diario: string | null; numero_lan: string; numero_documento: string | null; codigo_conta: string; descricao: string | null; debito: string | null; credito: string | null; estornado: boolean; estorno: boolean }[];
  total_debito: string;
  total_credito: string;
}

export const NOMES_MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
