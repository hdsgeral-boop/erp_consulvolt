import { enviar, obter } from '@/api/cliente';

export type TipoObra = 'APETRECHAMENTO' | 'PREVENTIVA' | 'CORRECTIVA' | 'MELHORIA' | 'VISTORIA';
export type EstadoObra = 'ABERTA' | 'AGUARDA_MATERIAL' | 'EM_CURSO' | 'CONCLUIDA' | 'CANCELADA';
export type EstadoNecessidade = 'POR_PEDIR' | 'REQUISITADO' | 'PEDIDO_COMPRA' | 'RECEBIDO' | 'CANCELADO';
export type EstadoBem = 'NOVO' | 'BOM' | 'USADO' | 'DANIFICADO' | 'EM_FALTA';

export interface OpcaoObra { chave: string; rotulo: string; ajuda?: string }
export interface ListasObras {
  tipos: OpcaoObra[]; estados: OpcaoObra[]; prioridades: OpcaoObra[]; quem_paga: OpcaoObra[]; estados_item: OpcaoObra[]; estados_bem: OpcaoObra[]; periodicidades: OpcaoObra[]; tipos_planeaveis: string[];
}
export interface Artigo { id: number; codigo: string | null; nome: string; de_armazem: boolean }
export interface Armazem { id: number; nome: string }

export interface ResumoObras {
  total: number; abertas: number; aguarda_material: number; em_curso: number; concluidas: number; urgentes: number; por_debitar: number; atrasadas: number; por_aprovar: number;
  planos_activos: number; planos_em_falta: number; planos_por_gerar: number;
}
export interface AvisoObra { tipo: string; texto: string; ordem_id?: number; plano_id?: number }

export interface OrdemLinha {
  id: number; numero: string; tipo: TipoObra; descricao: string; imovel_id: number; fracao_id: number | null; prioridade: string; quem_paga: string; debitavel: boolean; data_pedido: string;
  data_prevista: string | null; data_conclusao: string | null; responsavel: string | null; pedido_por: string | null; custo_estimado: number | null; ficha_id: number | null; plano_id: number | null;
  plano_data: string | null; vistoria_reserva_id: number | null; estado: EstadoObra; motivo_cancelamento: string | null; aprovada_em: string | null; aprovada_por: string | null; debitado_em: string | null;
  debitado_renda_id: number | null; debitado_valor: number | null; notas: string | null; imovel_nome: string | null; imovel_codigo: string | null; fracao_designacao: string | null;
  necessidades?: number; por_pedir?: number; em_atraso?: boolean; aprovada?: boolean; por_debitar?: boolean;
}
export interface Necessidade {
  id: number; ordem_id: number; produto_id: number | null; descricao: string | null; quantidade: string; estado: EstadoNecessidade; quantidade_recebida: string; quantidade_armazem: string;
  quantidade_compra: string; nome: string; de_armazem: boolean; requisicao_numero: string | null; requisicao_estado: string | null; pedido_numero: string | null; pedido_estado: string | null; falta_receber: string;
}
export interface EventoOrdem { evento: string; estado_de: string | null; estado_para: string | null; motivo: string | null; em: string }
export interface FichaOrdem extends OrdemLinha {
  necessidades_lista: Necessidade[]; por_pedir: number; custo_real: { armazem: string; compras: string; total: string }; historico: EventoOrdem[]; aprovada: boolean; por_debitar: boolean;
}
export interface LinhaPlanoPedido { necessidade_id: number; nome: string; produto_id: number | null; quantidade: string; stock: string | null; para_armazem: string; para_compra: string; porque: string; bloqueada: boolean }
export interface ResultadoPedido { requisicao_id: number | null; requisicao_numero: string | null; pedido_compra_id: number | null; pedido_compra_numero: string | null; armazem: number; compra: number }
export interface ResultadoDebito { ordem: OrdemLinha; renda: { id: number; periodo: string; total_facturar: number } | null; valor: number; repetido: boolean }

export interface ItemFicha { id?: number; produto_id: number | null; descricao: string | null; quantidade: number; obrigatorio: boolean; notas?: string | null; nome?: string; de_armazem?: boolean }
export interface Ficha { id: number; nome: string; tipologia: string | null; finalidade: string | null; activo: boolean; notas: string | null; itens: ItemFicha[]; n_itens: number }
export interface Bem {
  id: number; fracao_id: number; produto_id: number | null; descricao: string | null; quantidade: number; ativo_id: number | null; estado: EstadoBem; origem: string; data: string; notas: string | null; nome: string;
}
export interface BensDaFracao { fracao: { id: number; designacao: string; imovel_id: number; imovel_nome: string | null; imovel_codigo: string | null; ligada_a_porta: boolean }; bens: Bem[] }
export interface LinhaComparacao { nome: string; produto_id: number | null; descricao: string | null; obrigatorio: boolean; precisa: string; tem: string; falta: string; danificados: number }
export interface Comparacao { ficha: { id: number; nome: string }; linhas: LinhaComparacao[]; faltam: number; faltam_obrigatorios: number; completa: boolean }

export interface Plano {
  id: number; nome: string; tipo: 'PREVENTIVA' | 'VISTORIA'; descricao: string; imovel_id: number; fracao_id: number | null; periodicidade: string; primeira_data: string | null; quantas: number | null; datas: string[];
  prioridade: string; quem_paga: string; debitavel: boolean; responsavel: string | null; antecedencia_dias: number; custo_estimado: number | null; notas: string | null; activo: boolean; proxima: string | null;
  ultima_geracao: string | null; imovel_nome?: string | null; imovel_codigo?: string | null; fracao_designacao?: string | null; n_datas?: number; concluidas?: number; em_falta?: number; por_gerar?: number; proxima_data?: string | null;
}
export interface LinhaCalendario { data: string; estado: string; passada: boolean; ordem_id: number | null; ordem_numero: string | null }
export interface CalendarioPlano { plano: Plano; antecedencia_dias: number; horizonte: string; linhas: LinhaCalendario[]; geradas: number; por_gerar: number; em_falta: number; concluidas: number; proxima: string | null }
export interface ResultadoGeracao { criadas: { id: number; numero: string; data: string }[]; saltadas: number; horizonte: string }

export const CHAVES_OB = {
  listas: ['imobiliario', 'obras', 'listas'] as const,
  resumo: ['imobiliario', 'obras', 'resumo'] as const,
  avisos: ['imobiliario', 'obras', 'avisos'] as const,
  ordens: (estado: string, texto: string, imovel: number | null) => ['imobiliario', 'obras', 'ordens', estado, texto, imovel] as const,
  ordem: (id: number) => ['imobiliario', 'obras', 'ordem', id] as const,
  armazens: ['imobiliario', 'obras', 'armazens'] as const,
  artigos: (texto: string) => ['imobiliario', 'obras', 'artigos', texto] as const,
  planoPedido: (id: number, armazem: number | null) => ['imobiliario', 'obras', 'plano-pedido', id, armazem] as const,
  fichas: ['imobiliario', 'fichas'] as const,
  bens: (fracao: number) => ['imobiliario', 'fracoes', 'bens', fracao] as const,
  comparacao: (fracao: number, ficha: number) => ['imobiliario', 'fracoes', 'comparar', fracao, ficha] as const,
  planos: ['imobiliario', 'planos'] as const,
  calendario: (id: number) => ['imobiliario', 'planos', 'calendario', id] as const,
};

export const obterListasObras = () => obter<ListasObras>('/imobiliario/obras/listas');
export const obterResumoObras = () => obter<ResumoObras>('/imobiliario/obras/resumo');
export const obterAvisosObras = () => obter<AvisoObra[]>('/imobiliario/obras/avisos');
export const listarOrdens = (f: { estado?: string; texto?: string; imovel_id?: number | null }) =>
  obter<OrdemLinha[]>('/imobiliario/obras/ordens', Object.fromEntries(Object.entries(f).filter(([, v]) => v !== null && v !== undefined && v !== '')));
export const obterOrdem = (id: number) => obter<FichaOrdem>(`/imobiliario/obras/ordens/${id}`);
export const gravarOrdem = (dados: Record<string, unknown>, id?: number) => enviar<FichaOrdem>(id ? 'put' : 'post', id ? `/imobiliario/obras/ordens/${id}` : '/imobiliario/obras/ordens', dados);
export const apagarOrdem = (id: number) => enviar<null>('delete', `/imobiliario/obras/ordens/${id}`);
export const aprovarOrdem = (id: number) => enviar<OrdemLinha>('post', `/imobiliario/obras/ordens/${id}/aprovar`, {});
export const iniciarOrdem = (id: number) => enviar<OrdemLinha>('post', `/imobiliario/obras/ordens/${id}/iniciar`, {});
export const concluirOrdem = (id: number, cancelar_pendentes = false) => enviar<OrdemLinha>('post', `/imobiliario/obras/ordens/${id}/concluir`, { cancelar_pendentes });
export const cancelarOrdem = (id: number, motivo: string) => enviar<OrdemLinha>('post', `/imobiliario/obras/ordens/${id}/cancelar`, { motivo });
export const gravarNecessidades = (id: number, linhas: { produto_id?: number | null; descricao?: string | null; quantidade: number }[]) => enviar<Necessidade[]>('put', `/imobiliario/obras/ordens/${id}/necessidades`, { linhas });
export const obterPlanoPedido = (id: number, armazem: number | null) => obter<LinhaPlanoPedido[]>(`/imobiliario/obras/ordens/${id}/plano-pedido`, armazem ? { armazem_id: armazem } : undefined);
export const pedirMaterial = (id: number, dados: { armazem_id?: number | null; requerente?: string }) => enviar<ResultadoPedido>('post', `/imobiliario/obras/ordens/${id}/pedir`, dados);
export const sincronizarOrdem = (id: number) => enviar<{ recebido: string; bens_criados: number; necessidades: number }>('post', `/imobiliario/obras/ordens/${id}/sincronizar`, {});
export const debitarOrdem = (id: number, dados: { renda_id?: number; valor?: number } = {}) => enviar<ResultadoDebito>('post', `/imobiliario/obras/ordens/${id}/debitar`, dados);
export const desfazerDebito = (id: number) => enviar<OrdemLinha>('post', `/imobiliario/obras/ordens/${id}/desfazer-debito`, {});
export const abrirApetrechamento = (fracao: number, ficha_id: number, prioridade?: string) => enviar<{ ordem: OrdemLinha; necessidades: number }>('post', `/imobiliario/obras/fracoes/${fracao}/abrir-apetrechamento`, { ficha_id, prioridade });
export const ordemDaVistoria = (vistoria: number) => enviar<{ ordem: OrdemLinha; nova: boolean }>('post', `/imobiliario/obras/vistorias/${vistoria}/ordem`, {});
export const listarArmazens = () => obter<Armazem[]>('/imobiliario/obras/armazens');
export const procurarArtigos = (texto: string) => obter<Artigo[]>('/imobiliario/obras/artigos', texto ? { texto } : undefined);
export const artigosPorId = (ids: number[]) => obter<Artigo[]>('/imobiliario/obras/artigos', { ids });

export const listarFichas = () => obter<Ficha[]>('/imobiliario/fichas');
export const gravarFicha = (dados: Record<string, unknown>, id?: number) => enviar<Ficha>(id ? 'put' : 'post', id ? `/imobiliario/fichas/${id}` : '/imobiliario/fichas', dados);
export const apagarFicha = (id: number) => enviar<null>('delete', `/imobiliario/fichas/${id}`);
export const obterBens = (fracao: number) => obter<BensDaFracao>(`/imobiliario/fracoes/${fracao}/bens`);
export const gravarBem = (fracao: number, dados: Record<string, unknown>, id?: number) => enviar<Bem>(id ? 'put' : 'post', id ? `/imobiliario/fracoes/${fracao}/bens/${id}` : `/imobiliario/fracoes/${fracao}/bens`, dados);
export const apagarBem = (fracao: number, id: number) => enviar<null>('delete', `/imobiliario/fracoes/${fracao}/bens/${id}`);
export const trazerDoImobilizado = (fracao: number) => enviar<{ criados: number; encontrados: number }>('post', `/imobiliario/fracoes/${fracao}/bens/trazer-imobilizado`, {});
export const compararFicha = (fracao: number, ficha: number) => obter<Comparacao>(`/imobiliario/fracoes/${fracao}/comparar-ficha`, { ficha_id: ficha });

export const listarPlanos = () => obter<Plano[]>('/imobiliario/planos');
export const gravarPlano = (dados: Record<string, unknown>, id?: number) => enviar<Plano>(id ? 'put' : 'post', id ? `/imobiliario/planos/${id}` : '/imobiliario/planos', dados);
export const eliminarPlano = (id: number) => enviar<{ ordens_historicas: number }>('delete', `/imobiliario/planos/${id}`);
export const obterCalendarioPlano = (id: number) => obter<CalendarioPlano>(`/imobiliario/planos/${id}/calendario`);
export const proporDatasPlano = (primeira: string, periodicidade: string, quantas: number) => obter<string[]>('/imobiliario/planos/propor-datas', { primeira, periodicidade, quantas });
export const gerarOrdensPlano = (id: number, todas: boolean) => enviar<ResultadoGeracao>('post', `/imobiliario/planos/${id}/gerar`, { todas });
export const gerarTodosPlanos = () => enviar<{ criadas: number; planos: number; falhas: { plano: string; erro: string }[] }>('post', '/imobiliario/planos/gerar-todos', {});
