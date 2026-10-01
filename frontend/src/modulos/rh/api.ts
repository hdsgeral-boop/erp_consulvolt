/**
 * Tipos e constantes da API de RH e Salários (backend: controllers em app/Http/Controllers/Api/RH, ADR-036 a 041).
 * Os modelos devolvem as colunas da tabela (toArray); os valores em Kz chegam como texto com 2 casas.
 */

export type EstadoColaborador = 'ACTIVO' | 'INACTIVO' | 'SUSPENSO';

export interface Colaborador {
  id: number;
  nome_completo: string;
  nif: string;
  numero_inss: string | null;
  cargo_funcao_id: number | null;
  tipo_organizacao_id: number | null;
  estado: EstadoColaborador | null;
  dias_uteis_mes: number | null;
  reformado: boolean | null;
  avencado: boolean | null;
  unidade_negocio_id: number | null;
  centro_custo_id: number | null;
  unidade_organica_id: number | null;
  posto_trabalho_id: number | null;
  colaborador_gestor_id: number | null;
  sexo: 'M' | 'F' | null;
  data_nascimento: string | null;
  estado_civil: string | null;
  nacionalidade: string | null;
  naturalidade: string | null;
  provincia_naturalidade: string | null;
  documento_identificacao: string | null;
  documento_validade: string | null;
  data_admissao: string | null;
  endereco: string | null;
  bairro: string | null;
  municipio: string | null;
  provincia: string | null;
  telefone: string | null;
  telefone_alternativo: string | null;
  email: string | null;
  emergencia_nome: string | null;
  emergencia_telefone: string | null;
  emergencia_parentesco: string | null;
  habilitacao_maxima: string | null;
  [chave: string]: unknown;
}

export interface Dependente {
  id?: number;
  nome: string;
  parentesco: string | null;
  data_nascimento: string | null;
  sexo: 'M' | 'F' | null;
  dependente_fiscal: boolean | null;
}

export interface Habilitacao {
  id?: number;
  nivel: string;
  curso: string | null;
  instituicao: string | null;
  ano_conclusao: string | number | null;
  estado: string | null;
}

export interface CoordenadaBancaria {
  id: number;
  colaborador_id: number;
  banco_id: number;
  iban: string;
  banco?: { id: number; nome: string } | null;
}

export interface FichaColaborador extends Colaborador {
  dependentes: Dependente[];
  habilitacoes: Habilitacao[];
  coordenada_bancaria: CoordenadaBancaria | null;
  /** Referências por nome (ADR-064). */
  unidade_organica?: ReferenciaNome | null;
  unidade_negocio?: ReferenciaNome | null;
  centro_custo?: ReferenciaNome | null;
}

export interface ReferenciaNome {
  id: number;
  codigo: string | null;
  nome: string | null;
}

/** Utilizador da empresa activa e o colaborador a que está ligado (GET /rh/portal/utilizadores). */
export interface UtilizadorEmpresa {
  id: number;
  nome_utilizador: string;
  nome_completo: string | null;
  ativo: boolean;
  colaborador_id: number | null;
  colaborador_nome: string | null;
}

/** Ausência do próprio no portal (GET /rh/portal/ausencias). */
export interface MinhaAusencia {
  id: number;
  tipo: string | null;
  data_inicio: string;
  data_fim: string;
  dias: number | null;
  dias_uteis: number | null;
  horas: string | null;
  horas_falta: string | null;
  ocorrencia: string | null;
  estado: string;
  detectada: boolean | null;
  mes: string | null;
  motivo: string | null;
  documento_url: string | null;
  remunerada: string | null;
  pedido_portal_colaborador_id: number | null;
  nota_decisao: string | null;
  pode_justificar: boolean;
}

/** A minha avaliação (GET /rh/portal/avaliacoes). */
export interface MinhaAvaliacao {
  colaborador_id: number;
  avaliacoes: (Avaliacao & {
    classificacao_final: string | null;
    prazo_contestacao: string | null;
    pode_tomar_conhecimento: boolean;
    pode_contestar: boolean;
    tem_resultado_360: boolean;
  })[];
  feedbacks: {
    id: number;
    ciclo_avaliacao_id: number | null;
    periodo_referencia: string | null;
    data: string | null;
    objetivos: unknown;
    positivos: string | null;
    melhorar: string | null;
    acordos: string | null;
    registado_por: string | null;
    confirmacao: { em: string; comentario?: string | null } | null;
  }[];
  ciclo_aberto: {
    id: number;
    nome: string | null;
    ano: number;
    periodo: PeriodoAvaliacao;
    prazos: Ciclo360['prazos'];
    criterios: { chave: string; nome: string; peso?: number }[];
    comunicado: { titulo?: string | null; texto?: string | null } | null;
    comunicado_confirmado: boolean;
  } | null;
  tarefas_360: { ciclo_avaliacao_id: number; colaborador_avaliado_id: number; nome: string | null; grupo: 'PARES' | 'SUBORDINADOS' }[];
  ascendente: { chefia_colaborador_id: number | null; chefia_nome: string | null; questoes: { chave: string; nome: string }[]; respondida: boolean } | null;
}

/** GET /rh/avaliacao/autoavaliacao. */
export interface Autoavaliacao {
  autoavaliacao: {
    id: number;
    criterios: { chave: string; nome: string; nota: number | null; comentario: string | null }[] | null;
    objetivos: { chave: string; descricao: string; resultado: number | null }[] | null;
    realizacoes: string | null;
    dificuldades: string | null;
    formacao: string | null;
    estado: 'RASCUNHO' | 'SUBMETIDA';
    submetida_em: string | null;
  } | null;
  criterios: { chave: string; nome: string; descricao?: string | null }[];
  objetivos: { chave: string; nome: string; descricao?: string | null; meta?: string | null; unidade?: string | null }[];
}

/** GET /rh/avaliacao/ascendente/{colaborador}. */
export interface ResultadoAscendente {
  respostas: number;
  minimo: number;
  liberado: boolean;
  questoes?: Record<string, { nome: string; media: number | null }>;
  media?: number | null;
  comentarios?: string[];
}

/** Remuneração do contrato: as novas usam chaves em português; as migradas mantêm as do legado (ADR-037). */
export interface RemuneracaoContratoBruta {
  infotipo_id?: number;
  valor_mes?: number | string;
  valor_dia?: number | string;
  infotype_id?: number;
  value_month?: number | string;
  value_per_day?: number | string;
}

export interface ContratoTrabalho {
  id: number;
  colaborador_id: number;
  remuneracoes: RemuneracaoContratoBruta[] | null;
  dias_contrato_mes: number | null;
  data_inicio: string | null;
  data_fim: string | null;
  estado: EstadoColaborador | null;
  horas_por_dia: string | null;
  codigo_moeda: string | null;
  produtividade: { item_id: number; preco_unitario: number | null }[] | null;
}

export type TipoRubrica = 'VENCIMENTO' | 'DESCONTO' | 'OUTROS';

export interface Infotipo {
  id: number;
  nome: string;
  tipo: TipoRubrica;
  sujeito_inss: boolean | null;
  irt: 'true' | 'false' | 'conditional_30k' | string | null;
  base_horaria: boolean | null;
  calculo_horas: 'EXTRA' | 'FALTA' | 'NAO' | '' | null;
}

export interface Cadastro {
  id: number;
  nome: string;
  descricao?: string | null;
}

export interface Banco {
  id: number;
  nome: string;
  codigo: string | null;
  nif: string | null;
  endereco: string | null;
  codigo_conta: string | null;
}

export type EstadoPeriodo = 'ABERTO' | 'FECHADO' | 'VALIDADO';

export interface PeriodoSalarial {
  id: number;
  mes_ano: string;
  estado: EstadoPeriodo;
  contabilizado: boolean | null;
  fechado_em: string | null;
  fechado_por: string | null;
  validado_em: string | null;
  validado_por: string | null;
  numero_lan_contabilizacao: string | null;
  modo_calculo: string | null;
}

export interface RubricaResultado {
  nome: string;
  tipo: TipoRubrica;
  valor: string;
  infotipo_id: number;
  horas?: string;
  falta?: boolean;
  informativa?: boolean;
  isento_irt?: boolean;
}

export interface ResultadoSalarial {
  colaborador_id: number;
  /** Identificação do colaborador (também nos resultados fotografados, ADR-064). */
  nome?: string | null;
  nif?: string | null;
  numero_inss?: string | null;
  tipo_organizacao_id: number | null;
  unidade_negocio_id: number | null;
  centro_custo_id: number | null;
  avencado: boolean;
  reformado: boolean;
  dias_contrato: string;
  dias_trabalhados: string;
  bruto: string;
  base_inss: string;
  inss_trabalhador: string;
  inss_patronal: string;
  isencoes: string;
  base_irt: string;
  irt: string;
  descontos: string;
  liquido: string;
  rubricas: RubricaResultado[];
  avisos: string[];
  modo_calculo: string;
  mes_ano?: string;
  numero_recibo?: string;
  periodo_processamento_salarial_id?: number;
}

export type ChaveTotal = 'bruto' | 'inss_trabalhador' | 'inss_patronal' | 'irt' | 'descontos' | 'liquido';

export interface DetalhePeriodo extends PeriodoSalarial {
  resultados: ResultadoSalarial[];
  totais: Record<ChaveTotal, string>;
  fotografia: boolean;
}

export interface Lancamento {
  id: number;
  colaborador_id: number;
  infotipo_salarial_id: number;
  valor: string;
  dias_trabalhados: string | null;
  horas: string | null;
  origem: string | null;
  bonificacao_avaliacao_id: number | null;
}

export interface OrdemPagamento {
  linhas: { colaborador_id: number; nome: string | null; avencado: boolean; banco: string | null; iban: string | null; liquido: string; carta_pagamento_id: number | null }[];
  total: string;
  sem_iban: number;
}

export interface CartaPagamento {
  id: number;
  mes_ano: string;
  periodo_processamento_salarial_id: number;
  grupo: string;
  codigo_conta_bancaria: string;
  nome_assinatura: string | null;
  data: string;
  montante_total: string;
  documento_tesouraria_id: number | null;
  criado_por: string | null;
  itens?: { id: number; colaborador_id: number; montante: string; iban: string; nome: string | null }[];
  documento_tesouraria?: { id: number; numero_documento: string; estado: string; valor_total: string } | null;
}

// ───────────── Assiduidade ─────────────

export interface ConfigAssiduidade {
  dias_uteis: number[];
  tolerancia_min: number;
  arredondamento_min: number;
  extras_min_minutos: number;
  feriados: string[];
  relogio: { url?: string | null; formato?: string | null } | null;
  modo_compensacao: 'DIA' | 'MENSAL' | 'LIMITE';
  limite_compensacao_h: number | null;
  extra_nao_util_exige_autorizacao: boolean;
}

export interface RegistoEfectividade {
  id: number;
  data: string;
  colaborador_id: number;
  entrada: string | null;
  saida: string | null;
  horas: string | null;
  origem: string | null;
  fonte: string | null;
  observacoes: string | null;
  autorizado_extra: boolean | null;
}

export interface LinhaApuramento {
  employee_id: number;
  nome: string;
  nif?: string;
  diasUteis: number;
  diasComRegisto: number;
  diasFalta: number;
  horasTrabalhadas: number;
  horasExtra: number;
  horasFalta: number;
  horasCompensadas?: number;
  horasNaoAutorizadas?: number;
  diasFeriasAusencia?: number;
  avisos: string[];
}

export interface ApuramentoMes {
  mes: string;
  dias_uteis: number;
  apurado_ate: string | null;
  linhas: LinhaApuramento[];
  totais: { diasFalta: number; horasExtra: number; horasFalta: number };
  estado: 'ABERTO' | 'FECHADO' | 'REABERTO';
  fecho: { id: number; fechado_em: string | null; fechado_por: string | null; reaberto_em: string | null; motivo_reabertura: string | null; lancado_em: string | null; periodo_processamento_salarial_id: number | null; ausencias_geradas: number | null } | null;
}

export interface FechoMensal {
  id: number;
  mes: string;
  estado: string;
  dias_uteis: number;
  totais: { diasFalta: number; horasExtra: number; horasFalta: number } | null;
  apurado_ate: string | null;
  fechado_em: string | null;
  fechado_por: string | null;
  lancado_em: string | null;
  periodo_processamento_salarial_id: number | null;
  ausencias_geradas: number | null;
}

export interface TipoAusencia {
  nome: string;
  artigo: string;
  unidade: 'DIAS_CALENDARIO' | 'DIAS_UTEIS' | 'HORAS';
  remunerada: 'SIM' | 'NAO' | 'EMPREGADOR';
  max_seguidos?: number;
  max_mes?: number;
  max_ano?: number;
  prova_opcional?: boolean;
}

export interface Ausencia {
  id: number;
  colaborador_id: number;
  tipo: string | null;
  data_inicio: string;
  data_fim: string;
  dias_uteis: number | null;
  dias: number | null;
  horas: string | null;
  horas_falta: string | null;
  ocorrencia: string | null;
  estado: string;
  detectada: boolean | null;
  mes: string | null;
  motivo: string | null;
  documento_url: string | null;
  remunerada: string | null;
  avisos: string[] | null;
  pedido_portal_colaborador_id: number | null;
  nota_decisao: string | null;
  decidido_por: string | null;
}

// ───────────── Férias e produtividade ─────────────

export interface ResumoFerias {
  colaborador_id: number;
  nome: string;
  direito: number;
  marcados: number;
  aprovados: number;
  gozados: number;
  pedidos: number;
  saldo: number;
}

export interface PeriodoFerias {
  id: number;
  colaborador_id: number;
  ano: number;
  data_inicio: string;
  data_fim: string;
  dias: number;
  direito: number | null;
  estado: string;
  observacoes: string | null;
  pedido_portal_colaborador_id: number | null;
}

export interface ItemProdutividade {
  id: number;
  codigo: string;
  descricao: string;
  metrica: string | null;
  unidade: string | null;
  preco_unitario: string;
  infotipo_salarial_id: number;
  minimo: string | null;
  maximo: string | null;
  ativo: boolean;
}

export interface PeriodoProdutividade {
  id: number;
  mes: string;
  data_inicio: string;
  data_fim: string;
  observacoes: string | null;
  estado: string;
  fechado_em: string | null;
  fechado_por: string | null;
  total_fecho: string | null;
  registos_fecho: number | null;
  lancado_em: string | null;
  periodo_processamento_salarial_id: number | null;
  motivo_reabertura: string | null;
}

export interface RegistoProdutividade {
  id: number;
  colaborador_id: number;
  item_produtividade_id: number;
  data: string | null;
  quantidade: string;
  preco_unitario: string;
  valor: string;
  quantidade_considerada: string | null;
  observacoes: string | null;
  origem: string | null;
}

export interface DetalheProdutividade extends PeriodoProdutividade {
  elegiveis: { colaborador_id: number; nome: string; itens: Record<string, { item_id: number; codigo: string; preco: string }> }[];
  registos: RegistoProdutividade[];
}

// ───────────── Portal ─────────────

export type TipoPedido = 'FERIAS' | 'AUSENCIA' | 'DOCUMENTO' | 'AGREGADO';

export interface EtapaPedido {
  nivel: 'CHEFIA' | 'RH';
  estado: string;
  por?: string;
  em?: string;
  nota?: string | null;
  aprovador_nome?: string;
}

export interface DocumentoEmitido {
  numero: string;
  titulo: string;
  texto: string;
  local: string | null;
  data: string;
  assinante: string;
  cargo_assinante: string | null;
  emitido_por?: string;
}

export interface PedidoPortal {
  id: number;
  colaborador_id: number;
  tipo: TipoPedido;
  dados: Record<string, unknown> | null;
  etapas: EtapaPedido[] | null;
  estado: string;
  criado_por: string | null;
  decidido_em: string | null;
  documento: DocumentoEmitido | null;
  criado_em: string | null;
}

export interface ResumoPortal {
  colaborador: { id: number; nome_completo: string; nif: string; estado: string; cargo_funcao_id: number | null; unidade_organica_id: number | null; data_admissao: string | null };
  chefia_colaborador_id: number | null;
  ferias: { direito: number; marcados: number; saldo: number; aprovados?: number; gozados?: number };
  ano: number;
  faltas_por_justificar: number;
  pedidos_pendentes: number;
  aprovacoes_para_mim: number;
}

export interface ModeloDocumento {
  codigo: string;
  padrao: boolean;
  personalizado: boolean;
  ativo: boolean;
  nome: string;
  titulo: string;
  texto: string;
  auto_emitir: boolean;
  assinante: string | null;
  cargo_assinante: string | null;
  local: string | null;
}

export interface PropostaDocumento {
  texto: string;
  titulo: string;
  assinante: string | null;
  cargo_assinante: string | null;
  local: string | null;
  auto_emitir: boolean;
  faltas: string[];
  modelo: string;
}

// ───────────── Avaliação ─────────────

export type PeriodoAvaliacao = 'ANUAL' | 'S1' | 'S2' | 'T1' | 'T2' | 'T3' | 'T4';

export interface ItemAvaliacao {
  id: number;
  ambito: 'COMUM' | 'ESPECIFICO';
  colaborador_id: number | null;
  tipo: 'CRITERIO' | 'OBJECTIVO';
  chave: string;
  nome: string;
  descricao: string | null;
  peso: string | null;
  ordem: number | null;
  ativo: boolean;
  natureza: 'QUANTITATIVO' | 'QUALITATIVO' | null;
  meta: string | null;
  unidade: string | null;
  sentido: 'MAIOR' | 'MENOR' | null;
}

export interface CriterioAvaliado {
  chave: string;
  nome: string;
  peso: number;
  nota: number | null;
  comentario: string | null;
}

export interface ObjectivoAvaliado {
  chave: string;
  descricao: string;
  peso: number;
  natureza: 'QUANTITATIVO' | 'QUALITATIVO';
  meta: number | null;
  unidade: string | null;
  sentido: 'MAIOR' | 'MENOR';
  atingido: number | null;
  nota_qual: number | null;
  comentario: string | null;
  resultado: number | null;
}

export interface Avaliacao {
  id: number;
  colaborador_id: number;
  ano: number;
  periodo: PeriodoAvaliacao;
  ciclo_avaliacao_id: number | null;
  criterios: CriterioAvaliado[] | null;
  objetivos: ObjectivoAvaliado[] | null;
  peso_objetivos: string | null;
  pontuacao: string | null;
  pontuacao_criterios: string | null;
  pontuacao_objetivos: string | null;
  classificacao: string | null;
  nota_360: string | null;
  classificacao_360: string | null;
  estado: 'RASCUNHO' | 'CONCLUIDA';
  avaliador: string | null;
  data_avaliacao: string | null;
  pontos_fortes: string | null;
  pontos_melhorar: string | null;
  plano_desenvolvimento: string | null;
  conhecimento: { em: string; por?: string; comentario?: string | null } | null;
  comentario_colaborador: string | null;
  contestacao: {
    fundamentacao?: string;
    em?: string;
    parecer_rh?: { texto: string; por?: string; em?: string } | null;
    decisao?: { resultado: 'MANTIDA' | 'ALTERADA'; justificacao: string; nota?: number | null; por?: string; em?: string } | null;
  } | null;
  fase: string;
  nota_final: number | null;
}

export interface Ciclo360 {
  id: number;
  nome: string | null;
  ano: number;
  periodo: PeriodoAvaliacao;
  data_inicio: string | null;
  data_fim: string | null;
  estado: 'RASCUNHO' | 'ABERTO' | 'FECHADO';
  prazos: { chefia_ate?: string | null; respostas_ate?: string | null; dias_contestacao?: number | null; dias_conhecimento?: number | null } | null;
  pesos: Partial<Record<'CHEFIA' | 'AUTO' | 'PARES' | 'SUBORDINADOS', number>> | null;
  minimo_anonimato: number | null;
  max_pares: number | null;
  feedback: { periodicidade?: string | null } | null;
  bonificacao: {
    metodo?: string | null;
    tabela?: Record<string, number> | null;
    meses_base?: number | null;
    bolsa?: { montante?: number | null; nota_minima?: number | null } | null;
    infotipo_salarial_id?: number | null;
    infotype_id?: number | null;
    mes_lancamento?: string | null;
  } | null;
  comunicado: { titulo?: string | null; texto?: string | null } | null;
  participantes: { employee_id: number; nome: string; chefia_id: number | null; pares: number[]; subordinados: number[] }[] | null;
  criterios: { chave: string; nome: string; peso: number }[] | null;
  aberto_em: string | null;
  aberto_por: string | null;
  fechado_em: string | null;
}

export interface Bonificacao {
  id: number;
  ciclo_avaliacao_id: number;
  colaborador_id: number;
  avaliacao_desempenho_id: number | null;
  metodo: string;
  classificacao: string | null;
  nota: string | null;
  base: string | null;
  valor: string;
  estado: 'PROPOSTA' | 'APROVADA' | 'LANCADA';
  calculado_por: string | null;
  aprovado_por: string | null;
  lancado_em: string | null;
  periodo_processamento_salarial_id: number | null;
}

// ───────────── Constantes ─────────────

export const ESTADOS_COLABORADOR: { value: EstadoColaborador; label: string }[] = [
  { value: 'ACTIVO', label: 'Activo' },
  { value: 'INACTIVO', label: 'Inactivo' },
  { value: 'SUSPENSO', label: 'Suspenso' },
];

export const CORES_ESTADO: Record<string, string> = {
  ACTIVO: 'green', INACTIVO: 'default', SUSPENSO: 'orange',
  ABERTO: 'blue', FECHADO: 'gold', VALIDADO: 'green', REABERTO: 'purple', RASCUNHO: 'default',
  PEDIDO: 'cyan', PLANEADO: 'blue', APROVADO: 'green', APROVADA: 'green', GOZADO: 'geekblue', CANCELADO: 'default', RECUSADO: 'red',
  PENDENTE_CHEFIA: 'orange', PENDENTE_RH: 'gold', EMITIDO: 'green', POR_JUSTIFICAR: 'volcano', CONCLUIDA: 'green',
  PROPOSTA: 'blue', LANCADA: 'purple', DISPENSADA: 'default', AGUARDA: 'default', PENDENTE: 'orange',
};

export const ROTULOS_ESTADO: Record<string, string> = {
  ACTIVO: 'Activo', INACTIVO: 'Inactivo', SUSPENSO: 'Suspenso', ABERTO: 'Aberto', FECHADO: 'Fechado', VALIDADO: 'Validado', REABERTO: 'Reaberto',
  RASCUNHO: 'Rascunho', PEDIDO: 'Pedido (portal)', PLANEADO: 'Planeado', APROVADO: 'Aprovado', APROVADA: 'Aprovada', GOZADO: 'Gozado',
  CANCELADO: 'Cancelado', RECUSADO: 'Recusado', PENDENTE_CHEFIA: 'Pendente (chefia)', PENDENTE_RH: 'Pendente (RH)', EMITIDO: 'Emitido',
  POR_JUSTIFICAR: 'Por justificar', CONCLUIDA: 'Concluída', PROPOSTA: 'Proposta', LANCADA: 'Lançada', DISPENSADA: 'Dispensada',
  AGUARDA: 'Aguarda', PENDENTE: 'Pendente',
};

export const TIPOS_RUBRICA: { value: TipoRubrica; label: string }[] = [
  { value: 'VENCIMENTO', label: 'Vencimento' },
  { value: 'DESCONTO', label: 'Desconto' },
  { value: 'OUTROS', label: 'Outros (informativa)' },
];

export const REGIMES_IRT = [
  { value: 'true', label: 'Sujeito a IRT' },
  { value: 'false', label: 'Isento de IRT' },
  { value: 'conditional_30k', label: 'Isento até 30 000 Kz (alimentação/transporte)' },
];

export const CALCULO_HORAS = [
  { value: '', label: '—' },
  { value: 'EXTRA', label: 'Horas extra' },
  { value: 'FALTA', label: 'Faltas (horas)' },
  { value: 'NAO', label: 'Não' },
];

export const NIVEIS_HABILITACAO = ['Ensino primário', 'Ensino secundário (I ciclo)', 'Ensino médio (II ciclo)', 'Técnico médio', 'Bacharelato', 'Licenciatura',
  'Pós-graduação', 'Mestrado', 'Doutoramento', 'Outro'];

export const ESTADOS_CIVIS = [
  { value: 'SOLTEIRO', label: 'Solteiro(a)' },
  { value: 'CASADO', label: 'Casado(a)' },
  { value: 'DIVORCIADO', label: 'Divorciado(a)' },
  { value: 'VIUVO', label: 'Viúvo(a)' },
  { value: 'UNIAO_FACTO', label: 'União de facto' },
];

export const PARENTESCOS = [
  { value: 'FILHO', label: 'Filho(a)' },
  { value: 'CONJUGE', label: 'Cônjuge' },
  { value: 'PAI', label: 'Pai' },
  { value: 'MAE', label: 'Mãe' },
  { value: 'OUTRO', label: 'Outro' },
];

export const GRUPOS_PAGAMENTO = [
  { value: 'TODOS', label: 'Todos' },
  { value: 'COLABORADORES', label: 'Colaboradores' },
  { value: 'AVENCADOS', label: 'Avençados' },
];

export const ESTADOS_FERIAS_RH = ['PLANEADO', 'APROVADO', 'GOZADO', 'CANCELADO'] as const;

export const TIPOS_PEDIDO: Record<TipoPedido, string> = {
  FERIAS: 'Férias',
  AUSENCIA: 'Ausência / justificação de falta',
  DOCUMENTO: 'Documento (declaração)',
  AGREGADO: 'Alteração do agregado familiar',
};

export const PERIODOS_AVALIACAO: { value: PeriodoAvaliacao; label: string }[] = [
  { value: 'ANUAL', label: 'Anual' },
  { value: 'S1', label: '1.º semestre' },
  { value: 'S2', label: '2.º semestre' },
  { value: 'T1', label: '1.º trimestre' },
  { value: 'T2', label: '2.º trimestre' },
  { value: 'T3', label: '3.º trimestre' },
  { value: 'T4', label: '4.º trimestre' },
];

export const FASES_AVALIACAO: Record<string, { rotulo: string; cor: string }> = {
  POR_AVALIAR: { rotulo: 'Por avaliar', cor: 'default' },
  EM_AVALIACAO: { rotulo: 'Em avaliação', cor: 'blue' },
  AGUARDA_CONHECIMENTO: { rotulo: 'Aguarda conhecimento', cor: 'gold' },
  PRAZO_CONTESTACAO: { rotulo: 'Prazo de contestação', cor: 'orange' },
  CONTESTADA: { rotulo: 'Contestada', cor: 'red' },
  FINAL: { rotulo: 'Final', cor: 'green' },
};

export const METODOS_BONIFICACAO = [
  { value: 'NENHUM', label: 'Sem bonificação' },
  { value: 'PERCENTAGEM', label: 'Percentagem do salário (base × % × meses)' },
  { value: 'FIXO', label: 'Valor fixo por classificação' },
  { value: 'BOLSA', label: 'Bolsa distribuída pela nota' },
];

export const CLASSES_AVALIACAO = ['Excelente', 'Muito Bom', 'Bom', 'Suficiente', 'Insuficiente'];

export const METRICAS_PRODUTIVIDADE = ['QUANTIDADE', 'HORAS', 'OBJECTIVO', 'PONTOS', 'TAREFAS'];

export const ARREDONDAMENTOS = [0, 5, 10, 15, 30, 60];

export const DIAS_SEMANA = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
