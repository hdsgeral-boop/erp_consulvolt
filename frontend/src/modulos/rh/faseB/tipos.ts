/**
 * Tipos da Fase B (Versão Actual do legado): horário do contrato, suspensão e cessação (Lei 12/23), escala do mês,
 * empréstimos a colaboradores e separadores novos do portal. Backend: app/Services/RH/{RegrasContrato, ServicoEstadoContrato,
 * ServicoEscalas, ServicoEmprestimos}.php. Valores em Kz chegam como texto com 2 casas.
 */

/** contracts.horario (assiduidade.js:135): 1 período = 2 picagens (intervalo fixo descontado); 2 períodos = 4 picagens. */
export interface HorarioTrabalho {
  entrada: string;
  saida: string;
  periodos: 1 | 2;
  saida_intervalo?: string | null;
  entrada_intervalo?: string | null;
  intervalo_min?: number | null;
  tolerancia_min?: number | null;
  /** Dias da semana (0 = domingo … 6 = sábado); vazio = dias úteis da empresa. */
  dias?: number[] | null;
  horas_previstas?: number | null;
}

export interface RegistoSuspensao {
  de: string;
  ate: string | null;
  motivo_codigo: string;
  motivo: string;
  artigo: string;
  lado: string;
  observacoes?: string;
  registado_em?: string;
  registado_por?: string | null;
  terminada_em?: string;
  terminada_por?: string | null;
  terminada_observacoes?: string;
}

export interface RegistoCessacao {
  data: string;
  modalidade: string;
  motivo_codigo: string;
  motivo: string;
  artigo: string;
  aviso_previo_dias: number | null;
  observacoes?: string;
  antiguidade_anos: number;
  salario_base: string;
  compensacao_valor: string;
  compensacao_proposta: string;
  compensacao_artigo: string;
  compensacao_formula: string;
  processamentos_posteriores?: ProcessamentoPosterior[];
  registado_em?: string;
  registado_por?: string | null;
  anulada_em?: string;
  anulada_por?: string | null;
  anulada_observacoes?: string;
}

export interface ProcessamentoPosterior {
  periodo: string;
  lancamentos: number;
  estado: string | null;
}

export interface FundamentoSuspensao {
  codigo: string;
  lado: string;
  artigo: string;
  nome: string;
}

export interface FundamentoCessacao {
  codigo: string;
  modalidade: string;
  artigo: string;
  nome: string;
  compensacao: string;
  nota?: string;
  aviso_previo?: number;
}

export interface Fundamentos {
  suspensao: FundamentoSuspensao[];
  cessacao: FundamentoCessacao[];
  regras_compensacao: Record<string, { artigo: string; texto: string }>;
}

export interface PropostaCompensacao {
  valor: string;
  anos: number;
  salario_base: string;
  artigo: string;
  formula: string;
  aplicavel: boolean;
  nota: string;
  processamentos_posteriores: ProcessamentoPosterior[];
}

export interface EstadoContratoDetalhe {
  contrato_id: number;
  estado: string;
  suspensao: RegistoSuspensao | null;
  suspensoes: RegistoSuspensao[];
  cessacao: RegistoCessacao | null;
  cessacoes_anuladas: RegistoCessacao[];
  suspenso_hoje: boolean;
  salario_base: string;
  antiguidade_anos: number;
}

// ───────────── Escala do mês ─────────────

export interface CelulaEscala {
  id: number;
  entrada: string | null;
  saida: string | null;
  periodos: 1 | 2;
  saida_intervalo: string | null;
  entrada_intervalo: string | null;
  intervalo_minutos: number;
  tolerancia_min: number;
  folga: boolean;
  prioritaria: boolean;
  origem: string | null;
  nota: string | null;
  horas: number;
}

export interface LinhaGrelhaEscala {
  colaborador_id: number;
  nome: string;
  nif: string | null;
  horario_contrato: HorarioTrabalho | null;
  horario_contrato_texto: string | null;
  celulas: { dia: string; escala: CelulaEscala | null }[];
  dias_trabalho: number;
  folgas: number;
  prioritarias: number;
  horas: number;
  sem_escala: number;
}

export interface GrelhaEscala {
  mes: string;
  dias: string[];
  linhas: LinhaGrelhaEscala[];
  total: { linhas: number; prioritarias: number };
  fechado: boolean;
}

export interface ResultadoEscala {
  criados: number;
  actualizados: number;
  protegidos: number;
  sem_horario?: string[];
  erros?: string[];
  gravado?: boolean;
}

// ───────────── Empréstimos ─────────────

export type EstadoEmprestimo = 'PENDENTE_CHEFIA' | 'PENDENTE_RH' | 'APROVADO' | 'RECUSADO' | 'CANCELADO' | 'LIQUIDADO';

export interface PrestacaoPlano {
  id?: number;
  numero: number;
  mes_ano: string;
  capital: string;
  juro: string;
  total: string;
  saldo: string;
  estado?: 'PENDENTE' | 'DESCONTADO' | 'ISENTO';
  descontado_em?: string | null;
}

export interface Emprestimo {
  id: number;
  colaborador_id: number;
  nome?: string | null;
  numero: string;
  capital: string;
  prestacoes: number;
  taxa_juro: string;
  valor_prestacao: string;
  inicio_desconto: string;
  motivo: string | null;
  estado: EstadoEmprestimo;
  total_juros: string | null;
  total_a_pagar: string | null;
  aprovado_em?: string | null;
  lancamento_referencia?: string | null;
  lancado_em?: string | null;
  motivo_cancelamento?: string | null;
  criado_em?: string | null;
  em_divida?: string;
  descontadas?: number;
  prestacoes_plano?: PrestacaoPlano[];
  plano?: PrestacaoPlano[];
}

export interface ConfiguracaoEmprestimos {
  conta_emprestimo: string;
  conta_contrapartida: string;
  diario_id: number | null;
  infotipo_desconto_id: number | null;
  prestacoes_max: number;
  multiplo_salario_max: string;
  taxa_juro_padrao: string;
  exige_chefia: boolean;
  por_configurar: string[];
}

export interface LimitesEmprestimo {
  prestacoes_max: number;
  multiplo_salario_max: string;
  taxa_juro: string;
  salario_base: string;
  tecto: string | null;
  por_configurar: string[];
  exige_chefia: boolean;
  em_curso: string | null;
}

export interface PrestacaoDoMes extends PrestacaoPlano {
  emprestimo_id: number;
  colaborador_id: number;
  nome: string;
  numero_emprestimo: string;
  prestacoes: number;
}

// ───────────── Portal ─────────────

export interface MinhaEscala {
  mes: string;
  dias: { dia: string; folga: boolean; entrada: string | null; saida: string | null; periodos: 1 | 2; saida_intervalo: string | null; entrada_intervalo: string | null;
    intervalo_minutos: number; prioritaria: boolean; nota: string | null; horas: number }[];
  dias_trabalho: number;
  folgas: number;
  horas: number;
  horario_contrato: HorarioTrabalho | null;
}

export interface MeuContrato {
  contrato: null | {
    id: number;
    estado: string;
    data_inicio: string | null;
    data_fim: string | null;
    dias_contrato_mes: number | null;
    horas_por_dia: number;
    codigo_moeda: string;
    suspensao: { de?: string; ate?: string | null; motivo?: string; artigo?: string } | null;
    cessacao: { data?: string; modalidade?: string; motivo?: string; artigo?: string } | null;
    horario: HorarioTrabalho | null;
    remuneracoes: { infotipo_id: number; nome: string; valor_mes: string }[];
    total_mensal: string;
    valor_dia: string;
    valor_hora: string;
    produtividade: { item_id: number; codigo: string | null; descricao: string | null; unidade: string | null; preco_unitario: string; preco_proprio: boolean }[];
  };
}
