/** Tipos das respostas de /api/projetos (confirmados com pedidos GET reais). Valores em Kz: texto decimal com 2 casas. */

export type EstadoProjecto = 'PREPARACAO' | 'ACTIVO' | 'EM_CURSO' | 'ENCERRADO' | 'CANCELADO';
export type EstadoTarefa = 'PENDENTE' | 'EM_CURSO' | 'CONCLUIDA' | 'BLOQUEADA';

export interface Projecto {
  id: number;
  codigo: string | null;
  nome: string;
  estado: EstadoProjecto | string;
  tipo: 'INTERNO' | 'EXTERNO' | string;
  unidade_negocio_id: number | null;
  centro_custo_id: number | null;
  cliente_id: number | null;
  encomenda_venda_id: number | null;
}

export interface FichaProjecto extends Projecto {
  execucao: number;
  encomenda: { id: number; numero_documento: string; cliente_id: number; total_liquido: string; estado: string | null } | null;
  cliente: { id: number; nome: string } | null;
}

export interface Alerta {
  codigo: string;
  nivel: 'ERRO' | 'AVISO' | 'INFO' | string;
  titulo: string;
  texto: string;
  separador?: string | null;
}

export interface ResumoProjecto {
  indicadores: {
    execucao: number;
    execucao_ponderada: number | null;
    estados: Record<EstadoTarefa, number>;
    tarefas: number;
    prazo: { inicio: string | null; fim: string | null; duracao_dias: number | null; decorridos_dias: number | null; restantes_dias: number | null; tempo_pct: number | null; desempenho: number | null };
    orcamento: string;
    custo: string;
    disponivel: string;
    consumo_pct: number | null;
    desvio_orcamental_pct: number | null;
    compromissos: string;
  };
  curva_s: { meses: string[]; previsto: number[]; realizado: number[]; faturado: number[] | null };
  orcado_realizado: { rubrica: string; orcado: string; realizado: string }[];
  origem_custos: Record<string, string>;
  orcamento_por_rubrica: { rubrica: string; montante: string }[] | Record<string, string>;
  execucao_por_marco: { marco: string; execucao: number; tarefas: number }[];
  horas_por_colaborador: { nome: string; horas: number }[] | Record<string, number>;
  equipa_por_papel: Record<string, number>;
  alertas: Alerta[];
  ficha: {
    id: number;
    codigo: string | null;
    nome: string;
    estado: string;
    tipo: string;
    cliente: string | { id: number; nome: string } | null;
    encomenda: string | { id: number; numero_documento: string } | null;
    unidade_negocio: { codigo: string | null; nome: string } | null;
    centro_custo: { codigo: string; descricao: string | null } | null;
    membros: number;
    internos: number;
    horas_dia_alocadas: number;
    horas_lancadas: number;
    marcos: number;
    revisoes: number;
    posicoes_organigrama: number;
  };
  contabilidade: { proveitos: string; custos: string; linhas: number };
}

export interface Marco {
  id: number;
  nome: string;
  estado: string;
  data: string | null;
}

export interface TarefaWbs {
  id: number;
  codigo: string | null;
  nome: string;
  tarefa_pai_id: number | null;
  marco_projeto_id: number | null;
  atribuido_a_id: number | null;
  valor_contrato: string | null;
  ordem: number | null;
  estado: EstadoTarefa | string;
  data_inicio: string | null;
  data_fim: string | null;
  percentagem_execucao: number | null;
  /** Execução efectiva (média das subtarefas quando as há). */
  execucao: number;
  nivel: number;
  horas: number;
  posicoes: { id: number; titulo: string }[] | number[];
  subtarefas: TarefaWbs[];
}

export interface Wbs {
  execucao_global: number;
  grupos: { marco: Marco | null; execucao: number; tarefas: TarefaWbs[] }[];
}

export interface TarefaKanban {
  id: number;
  codigo: string | null;
  nome: string;
  marco_projeto_id: number | null;
  atribuido_a_id: number | null;
  valor_contrato: string | null;
  estado: string;
  execucao: number;
  data_inicio: string | null;
  data_fim: string | null;
}

export interface ColunaKanban {
  id: string;
  titulo: string;
  cor: string;
  estado: EstadoTarefa | string | null;
  tarefas: TarefaKanban[];
}

export interface Kanban {
  colunas: ColunaKanban[];
  por_mapear: TarefaKanban[];
}

export interface Membro {
  id: number;
  equipa_projeto_id: number;
  colaborador_id: number | null;
  terceiro_id: number | null;
  nome_externo: string | null;
  papel: string | null;
  horas_alocadas: string | null;
  valor_contrato: string | null;
  no_organigrama_projeto_id: number | null;
  tipo: 'INTERNO' | 'TERCEIRO' | 'LIVRE' | string;
  nome: string;
  nif: string | null;
  posicao: string | { id: number; titulo: string } | null;
}

export interface ValoresPosicao {
  orcamento: string;
  executado: string;
  horas: number;
  requisicoes: number;
  desvio: string;
  consumo_pct: number | null;
}

export interface Posicao {
  id: number;
  no_pai_id: number | null;
  titulo: string;
  area: string | null;
  descricao: string | null;
  vagas: number | null;
  membro_responsavel_id: number | null;
  ordem: number | null;
  cor: string | null;
  apoio: boolean | null;
  membros: Membro[];
  valores: ValoresPosicao;
}

export interface Organigrama {
  posicoes: Posicao[];
  sem_posicao: Membro[];
  totais: ValoresPosicao & { tarefas_sem_posicao: number; orcamento_sem_responsavel: number; vagas: number; membros: number; alocados: number };
}

export interface LinhaOrcamento {
  id: number;
  tarefa_projeto_id: number | null;
  rubrica: string;
  montante: string;
  numero_conta: string | null;
  no_organigrama_projeto_id: number | null;
  membro_equipa_projeto_id: number | null;
}

export interface Aditamento {
  id: number;
  descricao: string;
  montante: string | null;
  estado: 'PENDENTE' | 'APROVADO' | 'REJEITADO' | string;
  data?: string | null;
  criado_em?: string | null;
}

export interface FolhaHoras {
  id: number;
  tarefa_projeto_id: number;
  colaborador_id: number;
  data: string;
  horas: string;
  estado: string;
}

export interface Requisicao {
  id: number;
  nome_requerente: string;
  data: string;
  estado: string;
  data_prevista: string | null;
  linhas: { id: number; tarefa_projeto_id: number | null; rubrica: string | null; descricao: string | null; quantidade: string; estado: string }[];
}

export interface Revisao {
  id: number;
  mes: number;
  ano: number;
  estado: string;
  referencia: string;
  total_subempreitadas: string;
  total_mao_obra: string;
  total_equipamentos: string;
  faturavel: boolean;
  faturada: string | { id: number; numero_documento: string } | null;
}

export interface DetalheRevisao {
  revisao: Revisao;
  projeto: { id: number; codigo: string | null; nome: string };
  cliente: string;
  subempreitadas: { id: number; terceiro_id: number | null; percentagem_anterior: string | null; percentagem_atual: string | null; valor_calculado: string; nome?: string; tarefa?: string | null }[];
  mao_obra: { id: number; colaborador_id: number | null; valor_calculado: string; nome: string }[];
  equipamentos: { codigo: string; descricao: string; valor: string }[];
  totais: { subempreitadas: string; mao_obra: string; equipamentos: string; geral: string };
}

export interface SimulacaoRevisao {
  mes: number;
  ano: number;
  revisao_existente: boolean;
  internos: { membro_id: number; colaborador_id: number; nome: string; horas_dia: number; fonte: string; custo_mensal: string; ja_imputado: string; custo: string }[];
  externos: { membro_id?: number; terceiro_id?: number; nome: string; percentagem_anterior?: number | string; percentagem_atual?: number | string; valor?: string; custo?: string }[];
  equipamentos: { linhas: { codigo: string; descricao: string; valor: string }[]; total: string };
}

export interface PropostaFaturacao {
  revisao_id: number;
  execucao_global: number;
  venda: string;
  faturado: string;
  sugerido: string;
  taxa_iva_encomenda: number;
  ja_faturado_por: string | null;
}

export interface CustosTarefa {
  orcamento: string;
  executado: string;
  horas: number;
  requisicoes: number;
}

export interface GanttGlobal {
  inicio: string | null;
  fim: string | null;
  segmentos: { tipo: string; projetos: { id: number; codigo: string | null; nome: string; inicio: string | null; fim: string | null; tarefas: { id: number; codigo: string | null; nome: string; inicio: string | null; fim: string | null }[] }[] }[];
}

export interface MovimentoExtracto {
  data: string;
  projeto_id: number;
  tarefa_projeto_id: number | null;
  natureza: 'CUSTO' | 'PROVEITO' | 'COMPROMISSO' | string;
  rubrica: string | null;
  categoria: string | null;
  origem: string | null;
  modulo: string | null;
  tipo_documento: string | null;
  documento: string | null;
  descricao: string | null;
  valor: string;
  fonte: string | null;
  fonte_id: number | null;
  valor_pendente: string | null;
  data_vencimento: string | null;
  total_bruto: string | null;
  contabilizado: boolean | null;
  codigo_projeto: string | null;
  tarefa: string | null;
  marco: string | null;
}

export interface Extracto {
  totais: { custos: string; proveitos: string; compromissos: string };
  movimentos: MovimentoExtracto[];
}
