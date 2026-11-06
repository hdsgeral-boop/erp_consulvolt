/** Tipos da API da lavandaria e alfaiataria (LavandariaController, ADR-049). */

export interface ClienteLav {
  id: number;
  nome: string;
  nif: string | null;
  telefone: string | null;
}

export interface IndicadoresOrdem {
  responsavel: string | null;
  atribuida_em: string | null;
  pronta_em: string | null;
  entregue_em: string | null;
  dias_aberta: number | null;
  atraso: number | null;
  no_prazo: boolean | null;
  situacao: string | null;
  execucao?: number | null;
  espera_levantamento?: number | null;
}

export interface OrdemResumo {
  id: number;
  numero_encomenda: string;
  codigo_terminal: string;
  terminal_pos_id: number;
  cliente_id: number;
  recebido_em: string;
  data_prometida: string | null;
  urgente: boolean;
  estado: string;
  nome_atribuido: string | null;
  atribuido_em?: string | null;
  modo_faturacao: 'RECEPCAO' | 'ENTREGA';
  /** Entrega ao domicílio (data prevista). */
  entrega?: { ativa?: boolean; morada?: string; data?: string; taxa?: number | null } | null;
  cliente: ClienteLav | null;
  total: string;
  saldo: string;
  indicadores: IndicadoresOrdem;
  nao_levantada: boolean;
}

export interface ListaOrdens {
  contadores: Record<string, number>;
  /** Prazo de levantamento (dias) — rótulo «Não levantadas (+N dias)». */
  dias_armazenagem_gratis?: number;
  ordens: OrdemResumo[];
  por_responsavel: CargaResponsavel[];
}

/** Carga e desempenho por responsável (concluídas nos últimos 30 dias). */
export interface CargaResponsavel {
  responsavel: string | null;
  em_execucao: number;
  atrasadas: number;
  por_levantar: number;
  concluidas: number;
  no_prazo: number;
  percentagem_no_prazo: number | null;
  execucao_media: number | null;
  ciclo_medio: number | null;
}

export interface ItemOrdem {
  linha_id: number;
  peca_id: number | null;
  nome_peca: string | null;
  codigo_peca?: string | null;
  produto_id: number;
  nome: string;
  codigo_servico?: string | null;
  grupo: string;
  unidade: string;
  /** «Cobrar por» da linha (opção de preço da peça): Peça, Quilograma ou Tamanho; ausente nas ordens anteriores às opções. */
  modo?: ModoPreco | null;
  tamanho?: string | null;
  quantidade: number | string;
  numero_pecas?: number | null;
  preco: number | string;
  valor: string;
  taxa_imposto?: number | string;
  estado: string;
  cor?: string | null;
  tecido?: string | null;
  estado_entrada?: string | null;
  notas_entrada?: string | null;
  etiquetas?: string[];
  requer_orcamento?: boolean;
  estado_orcamento?: string | null;
  valor_orcamento?: number | string | null;
  executado_por?: string | null;
  materiais?: { produto_id: number; quantidade: number | string; nome?: string | null }[];
}

export interface PedidoLav {
  id: number;
  numero_encomenda: string;
  terminal_pos_id: number;
  codigo_terminal: string;
  cliente_id: number;
  recebido_em: string;
  recebido_por: string | null;
  modo_faturacao: 'RECEPCAO' | 'ENTREGA';
  urgente: boolean;
  data_prometida: string | null;
  observacoes: string | null;
  recolha: { ativa?: boolean; morada?: string; data?: string; taxa?: number | null } | null;
  entrega: { ativa?: boolean; morada?: string; data?: string; taxa?: number | null } | null;
  extras: { id?: string; nome: string; valor: string | number }[] | null;
  historico_alteracoes: { em: string; por: string; texto: string }[] | null;
  itens: ItemOrdem[];
  estado: string;
  nome_atribuido: string | null;
  entregue_em: string | null;
  motivo_cancelamento: string | null;
}

export interface PagamentoLav {
  id: number;
  numero_recibo: string | null;
  data: string;
  montante: string;
  troco: string | null;
  natureza_registo: string;
  estado: string;
  pos_pagamentos: { nome?: string; tipo: string; valor: number | string; referencia?: string | null }[] | null;
  criado_por: string | null;
  venda_id: number | null;
}

export interface DetalheOrdem {
  pedido: PedidoLav;
  cliente: ClienteLav | null;
  totais: { servicos: string; extras: string; total: string; facturado: string; pago: string; saldo: string; por_facturar: string; saldo_facturado: string };
  indicadores: IndicadoresOrdem;
  faturas: { id: number; numero_documento: string; tipo_documento?: string; total_bruto: string; estado: string | null; data_emissao?: string }[];
  pagamentos: PagamentoLav[];
  reclamacoes: Reclamacao[];
  linhas_por_facturar: { ref: string; id: number | string; produto_id: number; descricao: string; quantidade: string; preco: string; total: string }[];
}

export type ModoPreco = 'PECA' | 'KG' | 'TAMANHO';

/** Opção de preço da peça (legado: lav_prices): à peça, ao quilo ou por tamanho; a 1.ª manda. */
export interface OpcaoPreco {
  modo: ModoPreco;
  tamanho: string;
  preco: number | string;
}

/** Preço específico de um serviço para uma opção da peça (sem modo = vale para a opção única, como as entradas antigas). */
export interface PrecoServicoPeca {
  produto_id: number;
  modo?: ModoPreco | null;
  tamanho?: string | null;
  preco: number | string | null;
}

export interface Peca {
  id: number;
  codigo: string | null;
  nome: string;
  tecido: string | null;
  cor: string | null;
  unidade: 'PECA' | 'KG';
  ativo: boolean | null;
  preco: string;
  precos_servico: PrecoServicoPeca[] | null;
  opcoes_preco?: OpcaoPreco[] | null;
  opcoes_preco_origem?: 'LEGADO' | 'DERIVADA' | 'NOVO' | null;
}

export interface ServicoLav {
  id: number;
  codigo: string | null;
  nome: string;
  lavandaria_grupo: 'LAVANDARIA' | 'ALFAIATARIA';
  codigo_conta: string | null;
  taxa_imposto: string;
  lavandaria_dias_entrega: number | null;
  lavandaria_requer_orcamento: boolean | null;
  lavandaria_ativa: boolean | null;
}

export interface DefinicoesLav {
  taxa_armazenagem_ativa: boolean;
  dias_armazenagem_gratis: number;
  percentagem_armazenagem_dia: string;
  percentagem_adiantamento: string;
  percentagem_urgencia: string;
  fator_prazo_urgencia: number;
  valor_taxa_recolha: string;
  valor_taxa_entrega: string;
  faturar_no_adiantamento: boolean;
  conta_compensacao: string | null;
  conta_extras: string | null;
  taxa_extras: string;
  dias_reclamacao: number;
  estados_entrada: string[];
}

export interface Reclamacao {
  id: number;
  pedido_lavandaria_id?: number;
  numero_encomenda?: string | null;
  linha_pedido_id?: number | null;
  nome_item?: string | null;
  descricao_peca?: string | null;
  estado_entrada?: string | null;
  data_comprovativo?: string | null;
  cliente_id?: number | null;
  documento_tesouraria_id?: number | null;
  pago_em?: string | null;
  descricao: string;
  valor_declarado: string | null;
  estado: string;
  referencia_comprovativo: string | null;
  valor_comprovativo: string | null;
  valor_compensacao?: string | null;
  nota_decisao: string | null;
  decidido_por: string | null;
  decidido_em?: string | null;
  criado_em?: string | null;
  criado_por?: string | null;
}

export interface SimulacaoEntrega {
  linhas: number[];
  taxa_armazenagem: { valor: string; dias?: number } | null;
  por_facturar: string;
  saldo_facturado: string;
  venda_directa_possivel: boolean;
  consumidor_final: boolean;
}

export interface RelatorioLav {
  periodo: { de: string; ate: string };
  resumo: Record<string, number | string>;
  documentos: { data: string; numero: string; tipo: string; ordem: string | null; cliente: string | null; terminal: string | null; base: string; iva: string; total: string; estado: string | null }[];
  por_meio: { meio: string; numero: number; total: string }[];
  por_servico: { servico: string; grupo: string; quantidade: string; total: string }[];
  por_peca: { peca: string; unidade: string; quantidade: string; etiquetas: number; valor: string }[];
  por_dia: { dia: string; recebidas: number; etiquetas: number; entregues: number; documentos: number; facturado: string; recebido: string }[];
  por_terminal: { terminal: string; ordens: number; documentos: number; facturado: string; recebido: string }[];
}

/** Peça da linha com o tamanho cobrado, quando o houver: «Camisa (M)». */
export const pecaComTamanho = (i: { nome_peca?: string | null; modo?: string | null; tamanho?: string | null }): string =>
  `${i.nome_peca ?? ''}${i.modo === 'TAMANHO' && i.tamanho ? ` (${i.tamanho})` : ''}`;

/** O que a regra de preço lê da peça. */
export type PecaPreco = Pick<Peca, 'preco'> & Partial<Pick<Peca, 'unidade' | 'precos_servico' | 'opcoes_preco'>>;

export const MODOS_PRECO: Record<ModoPreco, string> = { PECA: 'Peça', KG: 'Quilograma (kg)', TAMANHO: 'Tamanho' };

/** Opções de preço da peça (opcoesPeca, lavandaria.js:146): as guardadas, sem tamanhos sem nome; na falta, a única de unidade+preço. */
export function opcoesPeca(peca: PecaPreco | undefined): OpcaoPreco[] {
  const lista = (peca?.opcoes_preco ?? [])
    .map((o): OpcaoPreco => {
      const modo: ModoPreco = o.modo === 'KG' ? 'KG' : o.modo === 'TAMANHO' ? 'TAMANHO' : 'PECA';
      return { modo, tamanho: modo === 'TAMANHO' ? String(o.tamanho ?? '').trim() : '', preco: o.preco };
    })
    .filter((o) => o.modo !== 'TAMANHO' || o.tamanho);
  return lista.length ? lista : [{ modo: peca?.unidade === 'KG' ? 'KG' : 'PECA', tamanho: '', preco: peca?.preco ?? 0 }];
}

/** Chave de uma opção: modo e tamanho (o tamanho não distingue maiúsculas). */
export const chaveOpcao = (o: { modo?: string | null; tamanho?: string | null } | undefined): string => `${o?.modo || 'PECA'}|${String(o?.tamanho ?? '').trim().toLowerCase()}`;

export const rotuloOpcao = (o: { modo?: string | null; tamanho?: string | null } | undefined): string => (o?.modo === 'TAMANHO' ? `Tamanho ${o.tamanho ?? ''}` : o?.modo === 'KG' ? 'Quilograma (kg)' : 'Peça');

/** Unidade de venda da opção: kg só no modo quilograma. */
export const unidadeOpcao = (o: { modo?: string | null } | undefined): 'PECA' | 'KG' => (o?.modo === 'KG' ? 'KG' : 'PECA');

/**
 * Preço da peça para o serviço e a opção (precoPecaServico, lavandaria.js:162): a entrada da opção ou, na falta, a antiga sem modo
 * (valia para a opção única que a peça tinha); sem preço específico, o da própria opção. Sem opção, a 1.ª.
 */
export function precoPecaServico(peca: PecaPreco | undefined, produtoId: number | undefined, opcao?: Pick<OpcaoPreco, 'modo' | 'tamanho'>): number {
  if (!peca) return 0;
  const o = opcao ? opcoesPeca(peca).find((x) => chaveOpcao(x) === chaveOpcao(opcao)) ?? opcoesPeca(peca)[0] : opcoesPeca(peca)[0];
  const lista = (peca.precos_servico ?? []).filter((p) => p.produto_id === produtoId && p.preco !== null && p.preco !== undefined);
  const especifico = lista.find((p) => !!p.modo && chaveOpcao(p) === chaveOpcao(o)) ?? lista.find((p) => !p.modo);
  const v = Number(especifico?.preco ?? 0);
  return v > 0 ? v : Number(o.preco ?? 0) || 0;
}
