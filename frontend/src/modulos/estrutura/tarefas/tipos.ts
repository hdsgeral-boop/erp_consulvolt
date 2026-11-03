import type { EstadoMulta, PorDelegadoDe } from '../faseK/tipos';
/**
 * Tipos da Fase J — tarefas das unidades orgânicas com entregáveis mensuráveis, execuções (entregas), validação,
 * impedimentos, atrasos, ponte CRM e ponte para a produtividade (rotas `/rh/estrutura/*`, `/rh/produtividade/*`
 * e `/rh/portal/*` da especificação, secções 2 a 4). Os nomes dos campos são os que o servidor devolve
 * (ServicoEntregaveis, ServicoEntregaveisCRM, ServicoEntregaveisProdutividade, ServicoProdutividade::elegibilidade).
 */

export type Periodicidade = 'PONTUAL' | 'DIARIA' | 'SEMANAL' | 'MENSAL' | 'TRIMESTRAL' | 'ANUAL';
export type MetricaEntregavel = 'QUANTIDADE' | 'HORAS' | 'OBJECTIVO' | 'PONTOS' | 'TAREFAS';
export type OrigemDados = 'MANUAL' | 'CRM';
export type OrigemExecucao = 'MANUAL' | 'EXCEL' | 'PORTAL' | 'CRM';
export type EstadoValidacao = 'PENDENTE' | 'VALIDADA' | 'DEVOLVIDA' | 'RECUSADA';
export type DecisaoValidacao = 'VALIDADA' | 'DEVOLVIDA' | 'RECUSADA';
export type ValidadaComo = 'RESPONSAVEL' | 'PERMISSAO';
export type EstadoImpedimento = 'ABERTO' | 'ACEITE' | 'RECUSADO';
export type EstadoPeriodoEntregavel = 'SEM_PRAZO' | 'DENTRO_DO_PRAZO' | 'EM_ATRASO' | 'CUMPRIDO' | 'JUSTIFICADO';
export type AccaoArrumar = 'TRAS' | 'FRENTE' | 'SUBIR' | 'DESCER' | 'APOIO';
export type OrigemElegibilidade = 'CONTRATO' | 'MANUAL' | 'ENTREGAVEL';

/** Valores decimais: o Laravel serializa-os como texto; o ecrã converte com Number(). */
export type Decimal = string | number;

export interface FiltroCRM {
  tipos?: string[];
  funil_vendas_crm_id?: number | null;
  so_ganhas?: boolean;
}

/** Resumo do período servido actual de um entregável (§3.7 `resumoPeriodo`). */
export interface ResumoPeriodo {
  periodo_de: string;
  periodo_ate: string;
  limite: string | null;
  realizado: Decimal;
  meta: Decimal | null;
  pct: number | null;
  estado: EstadoPeriodoEntregavel;
  pendentes: number;
  devolvidas: number;
  recusadas: number;
  prazo_texto?: string | null;
}

export interface ReferenciaNome {
  id: number;
  nome: string;
}

export interface ItemProdutividadeRef {
  id: number;
  codigo: string;
  descricao: string;
  metrica: string | null;
  unidade: string | null;
  preco_unitario?: Decimal;
  ativo?: boolean;
}

/** Referência a um entregável nas listas: objecto (estrutura) ou só o nome (listas do portal). */
export type RefEntregavel = string | { id: number; nome: string; unidade?: string } | null;
export const nomeDe = (v: RefEntregavel | { id: number; nome: string; codigo?: string | null } | undefined): string => (typeof v === 'string' ? v : v?.nome ?? '—');

export interface Impedimento {
  id: number;
  entregavel_id: number;
  tarefa_unidade_id: number;
  unidade_organica_id: number;
  colaborador_id: number;
  colaborador_nome?: string | null;
  periodo_de: string;
  prazo: string | null;
  motivo: string;
  url_documento: string | null;
  estado: EstadoImpedimento;
  decisao_nota: string | null;
  decidido_por: string | null;
  decidido_em: string | null;
  criado_em?: string | null;
  rotulo_estado?: string;
  /** listas do portal (aprovações): o nome do entregável e da unidade orgânica */
  entregavel?: RefEntregavel;
  unidade_nome?: string | null;
  /** quando o servidor o diz: quem lê pode decidir (responsável da cadeia ou est_validar_entregas, nunca o autor) */
  pode_decidir?: boolean;
}

export interface Entregavel {
  id: number;
  tarefa_unidade_id: number;
  nome: string;
  descricao: string | null;
  metrica: MetricaEntregavel;
  unidade: string;
  meta: Decimal | null;
  item_produtividade_id: number | null;
  colaborador_id: number | null;
  origem_dados: OrigemDados;
  crm_filtro: FiltroCRM | null;
  prazo_dia: number | null;
  prazo_mes: number;
  paga_fora_prazo: boolean;
  alerta: boolean;
  multa_valor: Decimal | null;
  multa_descricao: string | null;
  avaliacao: boolean;
  peso_avaliacao: Decimal;
  ativo: boolean;
  ordem: number;
  copiado_de_id: number | null;
  /** GET unidades/{u}/tarefas: resumo do período, item ligado, dono e impedimento aberto */
  resumo?: ResumoPeriodo | null;
  item?: ItemProdutividadeRef | null;
  dono?: ReferenciaNome | null;
  impedimento_aberto?: Pick<Impedimento, 'id' | 'periodo_de' | 'motivo' | 'estado' | 'colaborador_id'> | null;
  execucoes?: number;
  por_validar?: number;
}

export interface TarefaUnidade {
  id: number;
  unidade_organica_id: number;
  codigo: string | null;
  nome: string;
  descricao: string | null;
  url_instrucoes: string | null;
  periodicidade: Periodicidade;
  colaborador_responsavel_id: number | null;
  posto_trabalho_id: number | null;
  ativo: boolean;
  ordem: number;
  entregaveis: Entregavel[];
}

/** Atraso de um entregável (§3.6 `entregaveisEmAtraso`). */
export interface Atraso {
  entregavel_id?: number;
  entregavel: { id: number; nome: string; unidade: string; metrica?: string; alerta?: boolean; paga_fora_prazo?: boolean; prazo_texto?: string | null; prazo_dia?: number | null; prazo_mes?: number };
  tarefa: { id: number; nome: string; codigo: string | null; periodicidade?: Periodicidade };
  unidade: ReferenciaNome;
  unidade_organica_id?: number;
  periodo_de: string;
  periodo_ate: string;
  limite: string;
  dias_atraso: number;
  realizado: Decimal;
  meta: Decimal | null;
  falta: Decimal | null;
  multa_valor: Decimal | null;
  multa_descricao: string | null;
  impedimento: Pick<Impedimento, 'id' | 'estado' | 'motivo' | 'periodo_de'> | null;
  dono: ReferenciaNome | null;
  /** Fase K (K3): proposta de multa já criada para este atraso, se existir. */
  multa?: { id: number; estado: EstadoMulta } | null;
}

/** Resposta de GET /rh/estrutura/unidades/{u}/tarefas. */
export interface TarefasDaUnidade {
  tarefas: TarefaUnidade[];
  atrasos: Atraso[];
  unidade?: { id: number; codigo: string | null; nome: string; colaborador_responsavel_id: number | null };
}

export interface Execucao {
  id: number;
  entregavel_id: number;
  tarefa_unidade_id: number;
  unidade_organica_id: number;
  colaborador_id: number;
  colaborador_nome?: string | null;
  quantidade: Decimal;
  data: string;
  observacoes: string | null;
  url_evidencia: string | null;
  registado_por: string | null;
  registado_por_colaborador_id?: number | null;
  origem: OrigemExecucao;
  origem_chave?: string | null;
  validacao: EstadoValidacao;
  validacao_motivo: string | null;
  validada_por: string | null;
  validada_por_colaborador_id?: number | null;
  validada_em: string | null;
  validada_como: ValidadaComo | null;
  /** Fase K (K5): decidida por delegação — em nome de quem. */
  por_delegado_de?: PorDelegadoDe | null;
  periodo_produtividade_id: number | null;
  convertida_em?: string | null;
  convertida_por?: string | null;
  criado_em?: string | null;
  /** calculados pelo servidor para quem lê */
  rotulo_validacao?: string;
  pode_validar?: boolean;
  motivo_nao_valida?: string | null;
  pode_reenviar?: boolean;
  convertida?: boolean;
  /** listas do portal (aprovações): nome do entregável, unidade de medida, tarefa e unidade orgânica */
  entregavel?: RefEntregavel;
  unidade_medida?: string | null;
  tarefa?: string | { id: number; nome: string; codigo?: string | null } | null;
  unidade_nome?: string | null;
  item_produtividade_id?: number | null;
}

export interface DadosExecucao {
  colaborador_id?: number;
  quantidade: number;
  data: string;
  observacoes?: string | null;
  url_evidencia?: string | null;
}

export interface DadosImpedimento {
  periodo_de: string;
  prazo?: string | null;
  motivo: string;
  url_documento?: string | null;
}

export interface DadosTarefa {
  unidade_organica_id: number;
  nome: string;
  codigo?: string | null;
  descricao?: string | null;
  url_instrucoes?: string | null;
  periodicidade: Periodicidade;
  colaborador_responsavel_id?: number | null;
  posto_trabalho_id?: number | null;
  ativo?: boolean;
  ordem?: number;
}

export interface DadosEntregavel {
  nome: string;
  descricao?: string | null;
  metrica: MetricaEntregavel;
  unidade: string;
  meta?: number | null;
  item_produtividade_id?: number | null;
  colaborador_id?: number | null;
  origem_dados: OrigemDados;
  crm_filtro?: FiltroCRM | null;
  prazo_dia?: number | null;
  prazo_mes?: number;
  paga_fora_prazo?: boolean;
  alerta?: boolean;
  multa_valor?: number | null;
  multa_descricao?: string | null;
  avaliacao?: boolean;
  peso_avaliacao?: number;
  ativo?: boolean;
  ordem?: number;
}

/** Ponte CRM → entregáveis (§3.9, ServicoEntregaveisCRM::analisar). */
export interface ActividadeCRM {
  actividade_id: number;
  chave?: string;
  tipo?: string | null;
  titulo: string;
  data: string | null;
  concluida?: boolean;
  utilizador?: string | null;
  colaborador_id?: number | null;
  colaborador_nome?: string | null;
  oportunidade?: { id: number; titulo: string; estado: string } | null;
  resultado?: string | null;
  atrasada?: boolean;
}

export interface OrfaCRM {
  execucao_id: number;
  origem_chave: string;
  data: string;
  quantidade: Decimal;
  validacao: EstadoValidacao;
  convertida: boolean;
  motivo: string;
}

export interface EstadoCRM {
  entregavel_id: number;
  origem_dados: OrigemDados;
  previstas: number;
  feitas: number;
  importadas: number;
  por_fazer: ActividadeCRM[];
  por_criar: ActividadeCRM[];
  orfas_removiveis: OrfaCRM[];
  orfas_presas: OrfaCRM[];
  por_actualizar: { execucao_id: number; actividade_id: number; data_actual: string; data_nova: string }[];
  sem_colaborador: ActividadeCRM[];
}

export interface ResultadoSincronizacaoCRM {
  criadas: number;
  actualizadas: number;
  removidas: number;
  presas: OrfaCRM[];
  sem_colaborador: number;
}

/** Ponte para a produtividade (§3.10, ServicoEntregaveisProdutividade::analisar). */
export type MotivoExclusao = 'JA_CONVERTIDA' | 'NAO_VALIDADA' | 'SEM_ITEM' | 'FORA_DO_PRAZO' | string;

export interface GrupoValido {
  entregavel_id: number;
  entregavel: string;
  tarefa_unidade_id: number;
  colaborador_id: number;
  colaborador_nome: string;
  item_produtividade_id: number;
  item_codigo: string;
  quantidade: Decimal;
  registos: number;
  data?: string | null;
  preco: Decimal;
  origem_chave: string;
  registo_existente_id: number | null;
  /** quantidade que fica no período (soma à existente) */
  quantidade_total: Decimal;
  /** acréscimo de subsídio desta conversão e subsídio total do período */
  valor: Decimal;
  valor_total: Decimal;
  ja_elegivel: boolean;
  sem_evidencia?: number;
  exec_ids?: number[];
  evidencias?: string[];
  explicacao?: unknown;
}

export interface GrupoExcluido {
  entregavel_id: number;
  entregavel: string;
  colaborador_id: number;
  colaborador_nome: string;
  codigo: MotivoExclusao;
  motivo: string;
  quantidade: Decimal;
  registos: number;
  execucoes: number[];
}

export interface ErroAnalise {
  entregavel_id: number;
  entregavel: string;
  colaborador_id: number;
  colaborador_nome: string;
  quantidade: Decimal;
  mensagem: string;
}

export interface PorElegibilizar {
  colaborador_id: number;
  colaborador_nome: string;
  item_produtividade_id: number;
  item_codigo: string;
  preco: Decimal;
}

export interface AnaliseEntregaveis {
  periodo: { id: number; mes: string; data_inicio: string; data_fim: string; estado: string };
  validas: GrupoValido[];
  excluidas: GrupoExcluido[];
  erros: ErroAnalise[];
  por_elegibilizar: PorElegibilizar[];
  total: Decimal;
  convertidas: number;
}

export interface ResultadoConversao {
  criados: number;
  actualizados: number;
  marcadas: number;
  elegiveis: number;
  total: Decimal;
}

/** Linha da elegibilidade (tabela própria) ou do contrato (sem `id`, só leitura). */
export interface LinhaElegibilidade {
  id?: number | null;
  colaborador_id: number;
  colaborador_nome: string | null;
  item_produtividade_id: number;
  item_codigo: string | null;
  item_descricao: string | null;
  origem: OrigemElegibilidade;
  ativo: boolean;
  preco_unitario?: Decimal | null;
  preco_aplicado?: Decimal | null;
  nota?: string | null;
  contrato_id?: number;
}

export interface Elegibilidade {
  linhas: LinhaElegibilidade[];
  contrato: LinhaElegibilidade[];
}

export interface DadosElegibilidade {
  colaborador_id: number;
  item_produtividade_id: number;
  ativo: boolean;
  preco_unitario?: number | null;
  nota?: string | null;
}

/** Portal (§3.8 / §4.3, ServicoEntregaveis::meusEntregaveis). */
export type PapelEntregavel = 'DONO' | 'RESPONSAVEL';

export interface MeuEntregavel extends Omit<Entregavel, 'execucoes'> {
  papel: PapelEntregavel;
  tarefa: { id: number; codigo: string | null; nome: string; periodicidade: Periodicidade; url_instrucoes: string | null };
  /** a unidade orgânica (o campo `unidade` é a unidade de medida); o servidor pode ainda não o enviar */
  unidade_organica?: ReferenciaNome | null;
  unidade_nome?: string | null;
  resumo: ResumoPeriodo;
  periodico?: boolean;
  execucoes: Execucao[];
  impedimentos?: (Pick<Impedimento, 'id' | 'periodo_de' | 'prazo' | 'motivo' | 'estado' | 'decisao_nota' | 'colaborador_id'> & { rotulo_estado?: string; url_documento?: string | null })[];
  /** membros da unidade, para o responsável registar em nome de outro executor (quando o servidor os enviar) */
  executores?: ReferenciaNome[];
}

export interface MeusEntregaveis {
  entregaveis: MeuEntregavel[];
  contadores: { recusadas: number; devolvidas: number };
}
