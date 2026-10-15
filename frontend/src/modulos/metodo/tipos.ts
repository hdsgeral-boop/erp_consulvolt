/** Tipos do Método de Trabalho — Registo de Assunto 5W1H (/api/metodo/assuntos). */

export type EstadoAssunto = 'RASCUNHO' | 'EM_CURSO' | 'CONCLUIDO' | 'CANCELADO';
export type TipoResponsavel = 'COLABORADOR' | 'EXTERNO' | 'FORNECEDOR';
export type LocalTipo = 'INTERNO' | 'EXTERNO';
export type MetodoResolucao = 'ACCAO' | 'PROJECTO';
export type ModoProjecto = 'NOVO' | 'EXISTENTE';
export type EstadoDeliberacao = 'PENDENTE' | 'APROVADO' | 'DEVOLVIDO' | 'RECUSADO';
export type Vista = 'lista' | 'departamento' | 'cliente' | 'responsavel' | 'prazo' | 'custo';

export interface Responsavel {
  tipo: TipoResponsavel;
  ref_id: number | null;
  nome: string;
}

export interface Anexo {
  nome: string;
  url: string;
}

export interface LinhaPedido {
  produto_id: number;
  quantidade: number;
  preco_unitario?: number | null;
  descricao?: string | null;
  /** só no ecrã: código e nome do artigo */
  rotulo?: string;
}

export interface Deliberacao {
  estado: EstadoDeliberacao;
  aprovador_colaborador_id?: number | null;
  aprovador_nome?: string | null;
  aprovador_utilizador?: string | null;
  motivo_aprovador?: string | null;
  automatica?: boolean;
  pedida_em?: string;
  pedida_por?: string;
  decidida_em?: string;
  decidido_por?: string;
  nota?: string | null;
}

/** Linha da lista (resumo devolvido pelo servidor). */
export interface ResumoAssunto {
  id: number;
  numero: string;
  titulo: string;
  estado: EstadoAssunto;
  responsavel_principal_nome: string | null;
  local_tipo: LocalTipo | null;
  unidade_negocio_id: number | null;
  unidade_negocio_nome: string | null;
  cliente_id: number | null;
  cliente_nome: string | null;
  encomenda: string | null;
  data_inicio: string | null;
  data_fim: string | null;
  custo_estimado: string | null;
  codigo_moeda: string | null;
  metodo_resolucao: MetodoResolucao | null;
  projeto_id: number | null;
  projeto: { id: number; codigo: string; nome: string; estado: string } | null;
  pedido_compra_id: number | null;
  pedido_compra: { id: number; numero: string; estado: string } | null;
  deliberacao_estado: EstadoDeliberacao | null;
  em_atraso: boolean;
  pode_decidir?: boolean;
  criado_em: string | null;
  criado_por: string | null;
}

export interface Alertas {
  por_vencer: string[];
  atrasados: string[];
  bloqueados: string[];
  aguardam_minha_decisao: string[];
  horas_aviso: number;
}

export interface ListaAssuntos {
  assuntos: ResumoAssunto[];
  alertas: Alertas;
}

export interface PermissoesAssunto {
  ver: boolean;
  editar: boolean;
  submeter: boolean;
  decidir: boolean;
  concluir: boolean;
  cancelar: boolean;
  reabrir: boolean;
  gestor: boolean;
}

export interface Assunto extends ResumoAssunto {
  descricao: string | null;
  responsaveis: Responsavel[] | null;
  responsavel_principal: string | null;
  morada: string | null;
  fundamentacao: string | null;
  causa_raiz: string | null;
  recursos: string | null;
  projeto_modo: ModoProjecto | null;
  centro_custo_id: number | null;
  encomenda_venda_id: number | null;
  gerar_pedido_compra: boolean | null;
  linhas_pedido_compra: LinhaPedido[] | null;
  anexos: Anexo[] | null;
  deliberacao: Deliberacao | null;
  motivo_cancelamento: string | null;
  permissoes: PermissoesAssunto;
}

export interface OpcoesAssunto {
  colaboradores: { id: number; nome_completo: string }[];
  fornecedores: { id: number; nome: string; nif: string | null }[];
  clientes: { id: number; nome: string; nif: string | null }[];
  unidades_negocio: { id: number; codigo: string; nome: string }[];
  centros_custo: { id: number; codigo: string; descricao: string }[];
  pode_criar: boolean;
  gestor: boolean;
}

export interface Aprovador {
  colaborador_id: number | null;
  nome: string;
  utilizador: string | null;
  proprio: boolean;
  motivo: string;
}

export interface RegistoHistorico {
  id: number;
  acao: string;
  detalhe: string | null;
  por: string | null;
  em: string;
}

/** Dados do formulário (o que se envia ao servidor). */
export interface DadosAssunto {
  titulo: string;
  descricao?: string | null;
  responsaveis: Responsavel[];
  responsavel_principal: string | null;
  data_inicio?: string | null;
  data_fim?: string | null;
  local_tipo?: LocalTipo | null;
  unidade_negocio_id?: number | null;
  cliente_id?: number | null;
  encomenda?: string | null;
  morada?: string | null;
  fundamentacao?: string | null;
  causa_raiz?: string | null;
  recursos?: string | null;
  custo_estimado?: number | null;
  codigo_moeda?: string | null;
  metodo_resolucao: MetodoResolucao;
  projeto_modo?: ModoProjecto | null;
  projeto_id?: number | null;
  centro_custo_id?: number | null;
  encomenda_venda_id?: number | null;
  gerar_pedido_compra: boolean;
  linhas_pedido_compra: LinhaPedido[];
  anexos: Anexo[];
  validar_tudo?: boolean;
}
