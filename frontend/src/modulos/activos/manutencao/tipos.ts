/** Tipos de /api/ativos/manutencao (planos, ordens, requisições — Fase F, Versão Actual). Valores em Kz e quantidades: texto decimal. */

export type TipoManutencao = 'PREVENTIVA' | 'CORRECTIVA' | 'LEGAL' | 'CALIBRACAO' | 'MELHORIA';
export type EstadoOrdem = 'PLANEADA' | 'AGUARDA_MATERIAL' | 'EM_CURSO' | 'CONCLUIDA' | 'CANCELADA';
export type EstadoItem = 'POR_PEDIR' | 'REQUISITADO' | 'EM_COMPRA' | 'RECEBIDO' | 'CANCELADO';

export interface Periodicidade {
  modo: 'TEMPO' | 'CONTADOR';
  intervalo: number;
  unidade: 'DIAS' | 'SEMANAS' | 'MESES' | 'ANOS' | 'HORAS' | 'KM';
}

export interface ItemPrevisto {
  tipo: 'MATERIAL' | 'SERVICO';
  produto_id: number | null;
  descricao: string;
  quantidade: number;
}

export interface SituacaoPlano {
  chave: 'VENCIDO' | 'A_VENCER' | 'EM_DIA' | 'SEM_DATA' | 'INACTIVO';
  rotulo: string;
}

export interface Plano {
  id: number;
  codigo: string | null;
  nome: string;
  ativo_imobilizado_id: number | null;
  categoria_ativo_id: number | null;
  tipo: TipoManutencao;
  periodicidade: Periodicidade;
  proxima_data: string | null;
  proximo_contador: string | null;
  contador_atual: string | null;
  antecedencia_dias: number | null;
  duracao_horas: string | null;
  responsavel: string | null;
  instrucoes: string | null;
  tarefas: string[] | null;
  itens: ItemPrevisto[] | null;
  ativo: boolean | null;
  ultima_data: string | null;
  execucoes: number | null;
  situacao: SituacaoPlano;
  alvo: string;
  ordens: number;
}

export interface Tarefa {
  texto: string;
  feito: boolean;
}

export interface Ordem {
  id: number;
  numero: string;
  ativo_imobilizado_id: number;
  plano_manutencao_id: number | null;
  tipo: TipoManutencao;
  prioridade: 'BAIXA' | 'NORMAL' | 'ALTA' | 'URGENTE';
  estado: EstadoOrdem;
  data_prevista: string | null;
  data_inicio: string | null;
  data_fim: string | null;
  descricao: string | null;
  responsavel: string | null;
  tarefas: Tarefa[] | null;
  horas: string | null;
  custo_mao_obra: string | null;
  custo_materiais: string | null;
  custo_servicos: string | null;
  resolucao: string | null;
  leitura_contador: string | null;
  motivo_cancelamento: string | null;
  custo_total: string;
  em_atraso?: boolean;
  ativo_imobilizado?: { id: number; codigo: string | null; descricao: string } | null;
  plano_manutencao?: { id: number; codigo: string | null; nome: string; periodicidade?: Periodicidade } | null;
}

export interface ItemOrdem {
  id: number;
  tipo: 'MATERIAL' | 'SERVICO';
  produto_id: number | null;
  descricao: string | null;
  quantidade: string;
  quantidade_recebida: string | null;
  estado: EstadoItem;
  destino: 'ARMAZEM' | 'COMPRA' | 'AMBOS' | null;
  custo: string | null;
  pedido_compra_id: number | null;
  requisicao_manutencao_id: number | null;
  produto?: { id: number; codigo: string | null; nome: string; movimenta_stock: boolean | null } | null;
}

export interface DetalheOrdem extends Ordem {
  itens: ItemOrdem[];
  por_pedir: number;
  requisicoes: { id: number; numero: string; estado: string; data: string | null }[];
  pedidos_compra: { id: number; numero_pedido: string | null; estado: string }[];
  custo_servicos_sugerido: string;
}

export interface LinhaPlanoRequisicao {
  item_id: number;
  nome: string;
  tipo: 'MATERIAL' | 'SERVICO';
  produto_id: number | null;
  quantidade: string;
  stock: string | null;
  para_armazem: string;
  para_compra: string;
  bloqueada: boolean;
  porque: string;
}

export interface Requisicao {
  id: number;
  numero: string;
  ordem_manutencao_id: number;
  ativo_imobilizado_id: number;
  armazem_id: number | null;
  requerente: string | null;
  data: string | null;
  estado: 'PENDENTE' | 'PARCIAL' | 'SATISFEITA' | 'CANCELADA';
  motivo_cancelamento: string | null;
  linhas_count?: number;
  ordem_manutencao?: { id: number; numero: string; estado: string } | null;
  ativo_imobilizado?: { id: number; codigo: string | null; descricao: string } | null;
  armazem?: { id: number; nome: string } | null;
}

export interface LinhaRequisicao {
  id: number;
  produto_id: number;
  descricao: string;
  quantidade: string;
  quantidade_entregue: string | null;
  estado: 'PENDENTE' | 'PARCIAL' | 'ENTREGUE' | 'CANCELADA';
  custo: string | null;
  falta: string;
  stock: string;
}

export interface DetalheRequisicao extends Requisicao {
  armazem_consulta_id: number | null;
  linhas: LinhaRequisicao[];
}

export interface ResumoManutencao {
  ordens: { ABERTAS: number; CONCLUIDA: number; CANCELADA: number; TODAS: number; EM_ATRASO: number };
  planos_por_vencer: number;
  planos_por_vencer_nomes: string[];
}

export interface Artigo {
  id: number;
  codigo: string | null;
  nome: string;
  movimenta_stock: boolean | null;
  quantidade_stock: string | null;
}
