/**
 * Tipos das respostas de /api/orcamento (confirmados com pedidos GET reais). Atenção: neste módulo os valores
 * mensais e os totais do controlo vêm como NÚMEROS (jsonb/float), não como texto decimal; os totais das linhas vêm como texto.
 */

export type TipoOrcamento = 'EXPLORACAO' | 'TESOURARIA';
export type NaturezaRubrica = 'PROVEITO' | 'CUSTO' | 'RECEBIMENTO' | 'PAGAMENTO';
export type EstadoOrcamento = 'RASCUNHO' | 'SUBMETIDO' | 'APROVADO' | 'SUBSTITUIDO';
export type ModoControlo = 'NENHUM' | 'AVISAR' | 'APROVACAO' | 'BLOQUEAR';

export interface Rubrica {
  id: number;
  tipo: TipoOrcamento;
  codigo: string;
  nome: string;
  natureza: NaturezaRubrica;
  grupo: string | null;
  contas: { codigo: string; prefixo?: boolean | null }[];
  ordem: number | null;
  ativo: boolean | null;
  descricao: string | null;
  controlo: { modo?: ModoControlo; aviso_pct?: number; limite_pct?: number; base?: 'ACUMULADO' | 'ANO' } | null;
  indutor: string | null;
  cambial_pct: number | string | null;
  variavel_pct: number | string | null;
}

export interface Orcamento {
  id: number;
  ano: number;
  tipo: TipoOrcamento;
  nome: string | null;
  descricao: string | null;
  versao: number;
  versao_origem_id: number | null;
  estado: EstadoOrcamento | string;
  unidade_negocio_id: number | null;
  centro_custo_id: number | null;
  projeto_id: number | null;
  abordagem: 'TOP_DOWN' | 'BOTTOM_UP' | null;
  dimensao_filhos: 'UN' | 'CC' | 'PROJETO' | null;
  metodo: 'HISTORICO' | 'BASE_ZERO' | null;
  origem: string | null;
  crescimento_proveitos_pct: string | number | null;
  crescimento_custos_pct: string | number | null;
  inflacao_pct: string | number | null;
  responsavel: string | null;
  orcamento_pai_id: number | null;
  saldo_inicial: string | number | null;
  criado_por: string | null;
  submetido_por: string | null;
  submetido_em: string | null;
  aprovado_por: string | null;
  aprovado_em: string | null;
  substituido_por_id: number | null;
  prazo_contributo: string | null;
  consolidado_em: string | null;
  rejeicoes: { por: string | null; em: string; motivo: string }[] | null;
}

export interface LinhaOrcamento {
  id: number;
  rubrica_orcamental_id: number;
  valores: number[];
  total: string | null;
  notas: string | null;
}

export interface FichaOrcamento extends Orcamento {
  linhas: LinhaOrcamento[];
  filhos: Pick<Orcamento, 'id' | 'nome' | 'versao' | 'estado' | 'unidade_negocio_id' | 'centro_custo_id' | 'projeto_id' | 'responsavel'>[];
}

export interface LinhaControlo {
  rubrica_id: number;
  codigo: string;
  nome: string;
  natureza: NaturezaRubrica;
  grupo: string | null;
  orcado: number;
  orcado_inicial: number | null;
  realizado: number;
  desvio: number;
  execucao_pct: number | null;
  desvio_pct: number | null;
  favoravel: boolean;
  desvio_significativo: boolean;
  mensal: { orcado: number[]; realizado: number[] };
}

export interface Controlo {
  orcamento: Pick<Orcamento, 'id' | 'ano' | 'tipo' | 'nome' | 'versao' | 'estado' | 'unidade_negocio_id' | 'centro_custo_id' | 'projeto_id'>;
  mes: number;
  vista: 'MES' | 'ACUMULADO' | 'ANO';
  linhas: LinhaControlo[];
  piores_desvios: LinhaControlo[];
  /** Contas 6/7 (ou contrapartidas na tesouraria) com movimento e sem rubrica: valores mensais. */
  sem_rubrica: { conta: string; valores: number[] }[];
  totais: { orcado: number; realizado: number };
  /** Só na tesouraria. */
  saldo_inicial?: number;
  saldos_fim_mes?: number[];
}

export interface Desvio {
  rubrica: { id: number; codigo: string; nome: string; natureza: NaturezaRubrica };
  mensal: { mes: number; orcado: number; real: number; desvio: number; acumulado: number; desfavoravel: boolean }[];
  desvio_total: number;
  classificacao: 'SEM_DESVIO' | 'TEMPORAL' | 'PONTUAL' | 'ESTRUTURAL' | 'MISTO' | string;
  desfavoraveis: number;
  contas: { conta: string | number; real: number; anterior: number; variacao: number }[];
  maiores_movimentos: { data_documento: string; numero_documento: string | null; codigo_conta: string; descricao: string | null; tipo_dc: 'D' | 'C'; valor: string; terceiro_id: number | null }[];
  fecho_estimado: { revisao: number; mes_referencia: string; orcado_ano: number; valor: number } | null;
}

export interface Previsao {
  id: number;
  tipo: TipoOrcamento;
  unidade_negocio_id: number | null;
  centro_custo_id: number | null;
  projeto_id: number | null;
  nome: string | null;
  mes_referencia: string;
  revisao: number;
  estado: 'RASCUNHO' | 'PUBLICADA' | string;
  metodo: string | null;
  crescimento_pct: string | number | null;
  notas: string | null;
  criado_por: string | null;
  publicado_por: string | null;
  publicado_em: string | null;
}

export interface ResumoPrevisao {
  previsao: Previsao;
  meses: string[];
  meses_reais: string[];
  ano_fecho: number;
  orcamento_id: number | null;
  linhas: {
    rubrica_id: number;
    codigo: string;
    nome: string;
    real_recente: number[];
    previsao: Record<string, number | string>;
    total_12: number;
    real_ano: number;
    previsto_ano: number;
    fecho_estimado: number;
    orcado: number | null;
  }[];
}

export interface Cenario {
  id: number;
  orcamento_anual_id: number;
  nome: string;
  tipo: 'OTIMISTA' | 'REALISTA' | 'PESSIMISTA' | 'PERSONALIZADO' | string | null;
  variaveis: Record<string, number> | null;
  ajustes: Record<string, number> | null;
  notas: string | null;
}

export interface CalculoCenario {
  cenario: Cenario;
  orcamento_id: number;
  linhas: { rubrica_id: number; codigo: string; nome: string; natureza: NaturezaRubrica; indutor: string | null; base: number; cenario: number; variacao: number; valores: number[] }[];
  resultado_base: number;
  resultado_cenario: number;
}

export interface PedidoExcesso {
  id: number;
  estado: 'PENDENTE' | 'APROVADO' | 'REJEITADO' | 'UTILIZADO' | string;
  rubrica_orcamental_id: number | null;
  orcamento_anual_id: number | null;
  origem: string | null;
  documento: string | null;
  data_documento: string | null;
  valor: string | null;
  valor_orcado: string | null;
  valor_consumido: string | null;
  percentagem: string | null;
  valor_excesso: string | null;
  motivo: string | null;
  pedido_por: string | null;
  pedido_em: string | null;
  decidido_por: string | null;
  decidido_em: string | null;
  nota_decisao: string | null;
  autoaprovado: boolean | null;
  utilizado_em: string | null;
}

export interface AlertaOrcamental {
  id: number;
  em: string | null;
  por: string | null;
  origem: string | null;
  documento: string | null;
  rubrica_orcamental_id: number | null;
  orcamento_anual_id: number | null;
  percentagem: string | null;
  estado: string | null;
  acao: string | null;
  valor: string | null;
}

export interface LinhaMonitor {
  orcamento_anual_id: number;
  orcamento: string;
  rubrica_orcamental_id: number;
  rubrica: string;
  modo: ModoControlo | string;
  orcado: number;
  compromissos: number;
  consumido: number;
  disponivel: number;
  percentagem: number | null;
  estado: 'SEM_DOTACAO' | 'EXCEDIDO' | 'AVISO' | 'OK' | string;
}
