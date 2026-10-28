/** Tipos das respostas de /api/projetos (confirmados com pedidos GET reais). Valores em Kz: texto decimal com 2 casas. */

export type EstadoProjecto = 'PREPARACAO' | 'ACTIVO' | 'EM_CURSO' | 'ENCERRADO' | 'CANCELADO';
export type EstadoTarefa = 'PENDENTE' | 'EM_CURSO' | 'CONCLUIDA' | 'BLOQUEADA';
/** Bandeira de atenção manual, ver `./flags.ts`. */
export type CorFlag = 'verde' | 'amarelo' | 'vermelho' | 'preto';

/** Item do catálogo partilhado (por empresa, com cor) — etiqueta ou sinalização. */
export interface ItemCatalogoProjeto {
  id: number;
  nome: string;
  cor: string;
}

/** Sinalização atribuída a um projecto: motivo, quem a levantou e quando (tabela de associação). */
export interface SinalizacaoAtribuida extends ItemCatalogoProjeto {
  pivot: { motivo: string | null; levantada_por: string | null; levantada_em: string };
}

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
  /** Na carteira: o cliente por nome (ADR-064). */
  cliente?: { id: number; nome: string; nif?: string | null } | null;
  /** Na carteira: indicadores de prazo e orçamento para o semáforo (ver comum/regraSemaforo.ts). */
  semaforo?: IndicadoresSemaforo | null;
  /** Catálogo partilhado (por empresa, com cor) — classificam o projecto (sector, tipo de obra, cliente…). */
  etiquetas?: ItemCatalogoProjeto[];
  /** Catálogo partilhado (por empresa, com cor) — chamam a atenção e guardam o motivo, quem a levantou e quando. */
  sinalizacoes?: SinalizacaoAtribuida[];
}

/** GET /projetos (carteira): os números dos alertas de prazo e orçamento do Resumo, por projecto. */
export interface IndicadoresSemaforo {
  tarefas: number;
  tarefas_atrasadas: number;
  execucao: number;
  /** Execução ÷ tempo decorrido (null sem datas ou antes do início). */
  desempenho: number | null;
  restantes_dias: number | null;
  orcamento: number;
  consumo_pct: number;
  rubricas_excedidas: number;
}

export interface FichaProjecto extends Projecto {
  execucao: number;
  /** Versão Actual: calendário de trabalho (dias 0 = domingo … 6 = sábado com 0, 0,5 ou 1) e descrição. */
  calendario?: Calendario | null;
  descricao?: string | null;
  encomenda: { id: number; numero_documento: string; cliente_id: number; total_liquido: string; estado: string | null } | null;
  cliente: { id: number; nome: string } | null;
  /** Versão Actual (#25b): origem do projecto e assunto do Método de Trabalho de onde nasceu. */
  origem?: string | null;
  assunto_metodo_id?: number | null;
  assunto_metodo?: { id: number; numero: string | null; titulo: string | null; estado: string | null } | null;
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
    // só nos projectos EXTERNOS (projectos_dashboard.js:337-343, 437-457)
    venda?: string;
    aditamentos_aprovados?: string;
    venda_global?: string;
    faturado?: string;
    faturado_pct?: number;
    margem_prevista?: string;
    margem_prevista_pct?: number;
    margem_real?: string;
    margem_real_pct?: number;
  };
  curva_s: { meses: string[]; previsto: number[]; realizado: number[]; faturado: number[] | null };
  orcado_realizado: { rubrica: string; orcado: string; realizado: string }[];
  origem_custos: Record<string, string>;
  orcamento_por_rubrica: Record<string, string>;
  execucao_por_marco: { marco: string; execucao: number; tarefas: number }[];
  horas_por_colaborador: { colaborador_id: number; nome: string; horas: number }[];
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
  /** Versão Actual: data de início e meta da fase. */
  data_inicio?: string | null;
  meta_unidade?: string | null;
  meta_quantidade?: string | null;
  /** Ordem entre os marcos do projecto (botões ↑/↓); nulo nos marcos antigos (caem no fim, pela criação). */
  ordem?: number | null;
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
  posicoes: { id: number; titulo: string }[] | number[] | string[];
  subtarefas: TarefaWbs[];
  // Versão Actual (Fase E)
  tarefa_predecessora_id?: number | null;
  tipo_dependencia?: 'FS' | 'SS' | 'FF' | string | null;
  desfasamento_dias?: number | null;
  meta_unidade?: string | null;
  meta_quantidade?: string | null;
  meta_realizado?: string | null;
  concluida_em?: string | null;
  horas_previstas?: number;
  posicoes_ids?: number[];
  meta?: MetaRamo | null;
  /** Tem meta própria e não tem subtarefas com meta: é aqui que se regista a quantidade executada. */
  mede_se?: boolean;
  n_medicoes?: number;
  precedencia?: Precedencia;
  /** Bandeira de atenção manual (tarefas e subtarefas), ver `../comum/flags.ts`. */
  flag?: CorFlag | null;
}

export interface Wbs {
  execucao_global: number;
  calendario?: Calendario;
  grupos: { marco: Marco | null; execucao: number; meta?: MetaFase | null; tarefas: TarefaWbs[] }[];
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
  flag?: CorFlag | null;
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
  /** Versão Actual: orçamento mapeado pelas quantidades que chega à posição pelas tarefas. */
  orcamento_qtd?: number;
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
  /** Versão Actual: valores da posição com as subposições (união das tarefas e linhas, sem contar duas vezes). */
  valores_ramo?: ValoresPosicao & { subposicoes: number };
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
  /** Nomes (ADR-064). */
  tarefa_codigo?: string | null;
  tarefa_nome?: string | null;
  posicao_titulo?: string | null;
  membro_nome?: string | null;
  colaborador_id?: number | null;
  /** Versão Actual: mapeamento pelas quantidades. */
  mapeado_qtd?: number;
  saldo_por_mapear?: number;
  n_mapas?: number;
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
  colaborador_id: number | null;
  /** Versão Actual: membro da equipa (externos sem colaborador). */
  membro_equipa_projeto_id?: number | null;
  data: string;
  horas: string;
  estado: string;
  /** Nomes (ADR-064). */
  colaborador_nome?: string | null;
  tarefa_codigo?: string | null;
  tarefa_nome?: string | null;
}

/** GET /projetos/{id}/equipamentos (ADR-064). */
export interface UsoEquipamento {
  id: number;
  data: string | null;
  tarefa_projeto_id: number | null;
  tarefa_codigo: string | null;
  tarefa_nome: string | null;
  ativo_imobilizado_id: number | null;
  ativo_codigo: string | null;
  ativo_descricao: string | null;
  descricao: string | null;
  montante: string;
  lancamento_contabil_id: number | null;
}

export interface EquipamentosProjecto {
  usos: UsoEquipamento[];
  total: string;
  /** Versão Actual (projectos_dashboard.js:381-407): agregado por equipamento. */
  por_equipamento?: { ativo_codigo: string | null; ativo_imobilizado_id: number | null; ativo_descricao: string | null; ativo_categoria?: string | null; ativo_estado?: string | null; usos: number; horas: number; custo: string; custo_hora_medio: number | null; de: string | null; ate: string | null; tarefas: string[]; peso_pct: number | null }[];
  afetacoes: { id: number; ativo_imobilizado_id: number; ativo_codigo: string | null; ativo_descricao: string | null; data_inicio: string | null; data_fim: string | null }[];
  /** Custo total do projecto (razão analítico), para o peso dos equipamentos. */
  custo_projeto?: string;
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

// ───────────── Versão Actual (Fase E): execução medida, mapa, carga, orçamento por quantidades, ponte RH ─────────────

export interface Calendario {
  horas_dia: number;
  dias: Record<string, number>;
  modelo?: string | null;
}

export interface MetaRamo {
  meta: number;
  feito: number;
  unidade: string;
  /** A quantidade sobe das subtarefas (o realizado do pai não se escreve). */
  dos_filhos: boolean;
}

export interface Precedencia {
  predecessora: { id: number; nome: string; tipo: 'FS' | 'SS' | 'FF' | string; desf: number; rotulo: string } | null;
  n_seguintes: number;
  seguintes: string[];
}

export interface MetaFase {
  meta: number | null;
  unidade: string | null;
  repartido: number;
  feito: number;
  unidade_tarefas: string;
  aviso: string | null;
}

export interface Medicao {
  q: number;
  data: string;
  nota: string;
  por: string;
  em: string;
  /** Executante (membro da equipa) — folha de medição por pessoa. */
  membro_id?: number;
}

export interface LinhaFolhaMedicao {
  id: number;
  codigo: string | null;
  nome: string;
  fase: string | null;
  atribuido_a_id: number | null;
  /** Pessoas da tarefa: o responsável directo ou os membros da área do organigrama. */
  pessoas: number[];
  unidade: string;
  meta: number;
  realizado: number;
  falta: number;
  medicoes: Medicao[];
}

export interface ResultadoLote {
  gravadas: number;
  falhadas: { tarefa_id: number; nome: string | null; erro: string; codigo: string }[];
  resultados: { tarefa_id: number; realizado: number; pct: number }[];
}

export interface ColunaEscala {
  de: string;
  ate: string;
  rotulo: string;
  titulo: string;
  fds: boolean;
}

export interface LinhaMapa {
  chave: string;
  tipo: 'fase' | 'tarefa';
  id: number | null;
  nivel: number;
  nome: string;
  previsto: number[];
  realizado: number[];
  total_previsto: number;
  total_realizado: number;
}

export interface TabelaMapa {
  linhas: LinhaMapa[];
  totais: { previsto: number[]; realizado: number[]; total_previsto: number; total_realizado: number; rendimento_pct?: number | null };
}

export interface MapaQuantidades {
  escala: string;
  de: string | null;
  ate: string | null;
  colunas: ColunaEscala[];
  calendario: Calendario;
  quantidades: (TabelaMapa & { unidade: string; meta: number })[];
  horas: TabelaMapa;
  valor: TabelaMapa & { total_mapeado: number };
}

export interface Ritmo {
  dias: number;
  corridos: number;
  uteis: number;
  horas: number;
  por_calendario: boolean;
  dia: number;
  semana: number;
  mes: number;
  hora: number;
  unidade: string;
}

export interface TarefaCarga {
  tarefa_id: number;
  codigo: string | null;
  nome: string;
  fase: string | null;
  caminho: string[];
  nivel: number;
  inicio: string | null;
  fim: string | null;
  estado: string;
  execucao: number;
  quota: number;
  da_area: boolean;
  areas: string[];
  areas_sem_gente: string[];
  meta: { unidade: string; quantidade: number; realizado: number } | null;
  horas_previstas: number;
  horas_lancadas: number;
  ritmo: Ritmo | null;
}

export interface GrupoCarga {
  chave: string;
  membro_id: number | null;
  nome: string;
  tipo: string | null;
  papel: string | null;
  horas_alocadas: number;
  posicao: string | { id: number; titulo: string } | null;
  tarefas: TarefaCarga[];
  n_tarefas: number;
  horas_previstas: number;
  horas_lancadas: number;
  quantidades: Record<string, number>;
}

export interface CargaTrabalho {
  projeto: { id: number; codigo: string | null; nome: string };
  calendario: Calendario;
  grupos: GrupoCarga[];
  areas_sem_gente: string[];
}

export interface LinhaRecolha {
  tipo: 'HORAS' | 'QUANTIDADE' | 'TAREFAS';
  unidade: string;
  colaborador_id?: number;
  nome?: string;
  motivo?: string;
  quantidade: number;
  registos: number;
  ultima: string;
  projetos: number[];
  tarefas: number[];
}

export interface ExecucaoProjecto {
  de: string;
  ate: string;
  cartoes: {
    horas: { total: number; atribuivel: number; registos: number };
    quantidades: { unidade: string; total: number; atribuivel: number }[];
    tarefas: { total: number; atribuivel: number };
  };
  por_colaborador: { colaborador_id: number; nome: string; horas: number; tarefas: number; quantidades: Record<string, number> }[];
  sem_dono: LinhaRecolha[];
  folhas: { id: number; data: string; tarefa_id: number; tarefa: string; colaborador: string | null; horas: number; estado: string }[];
  medicoes: { tarefa_id: number; tarefa: string; unidade: string; data: string; quantidade: number; nota: string; por: string; responsavel: string | null }[];
  concluidas: { tarefa_id: number; tarefa: string; concluida_em: string; responsavel: string | null }[];
}

export interface GrupoRecolha {
  chave: string;
  tipo: LinhaRecolha['tipo'];
  unidade: string;
  colaboradores: number;
  quantidade: number;
  item_sugerido_id: number | null;
  candidatos: { id: number; codigo: string; descricao: string | null; unidade: string | null }[];
}

export interface RecolhaProdutividade {
  de: string;
  ate: string;
  projetos: { id: number; nome: string }[];
  linhas: LinhaRecolha[];
  orfas: LinhaRecolha[];
  grupos: GrupoRecolha[];
  periodos: { id: number; mes: string; estado: string; data_inicio: string; data_fim: string }[];
}

export interface AnaliseProdutividade {
  periodo: { id: number; mes: string; estado: string; data_inicio: string; data_fim: string };
  validas: { linha: LinhaRecolha; item: { id: number; codigo: string; descricao: string | null; unidade: string | null }; chave: string; data: string; preco_unitario: number; valor: number; registo_existente_id: number | null }[];
  erros: { linha: LinhaRecolha; item: { id: number; codigo: string }; erro: string }[];
  sem_item: LinhaRecolha[];
  orfas: LinhaRecolha[];
  total: number;
}

export interface MapaOrcamentoQtd {
  indice: number;
  unidade: string;
  base: 'META' | 'EXECUTADO' | string;
  escopo: { tipo: 'UNIDADE' | 'FASE' | 'TAREFAS' | string; ids: number[] };
  valor: number;
  preco_unitario: number;
  quantidade_mapeada: number;
  criado_em?: string;
  criado_por?: string;
}

export interface ResumoOrcamentoQtd {
  unidades: string[];
  rubricas: { linha_id: number; rubrica: string; montante: number; mapeado: number; saldo: number; pct_mapeado: number; totalmente_mapeada: boolean; mapas: MapaOrcamentoQtd[] }[];
  por_tarefa: { tarefa_id: number; codigo: string | null; nome: string; unidade: string; quantidade: number; valor: number }[];
  por_fase: { nome: string; valor: number; quantidade: number }[];
  por_area: { nome: string; valor: number; quantidade: number }[];
  por_pessoa: { nome: string; valor: number; quantidade: number }[];
  total_mapeado: number;
}

export interface DistribuicaoQtd {
  unidade: string;
  base: string;
  quantidade: number;
  valor: number;
  preco_unitario: number;
  linhas: { tarefa_id: number; codigo: string | null; nome: string; quantidade: number; valor: number }[];
  saldo: number;
  sobra?: number;
}
