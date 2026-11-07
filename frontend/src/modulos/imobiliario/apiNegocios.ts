import { enviar, obter } from '@/api/cliente';

export type TipoNegocio = 'COMPRA' | 'VENDA';
export type FaseNegocio = 'NEGOCIACAO' | 'PROMESSA' | 'ESCRITURA' | 'REGISTADO' | 'CONCLUIDO' | 'CANCELADO';
export type IsencaoNegocio = 'NAO' | 'METADE' | 'TOTAL';

export interface OpcaoNegocio { chave: string; rotulo: string; capitaliza?: boolean }
export interface ListasNegocios {
  tipos: OpcaoNegocio[]; fases: OpcaoNegocio[]; tipos_custo: OpcaoNegocio[]; isencoes: OpcaoNegocio[]; finalidades: OpcaoNegocio[]; destinos_contratos: OpcaoNegocio[]; tipos_pagamento: OpcaoNegocio[];
}
export interface CategoriaActivo { id: number; nome: string; conta_ativo: string | null }

export interface Negocio {
  id: number; numero: string; tipo: TipoNegocio; estado: FaseNegocio; imovel_id: number | null; fracao_id: number | null; terceiro_id: number; preco: number; vpt: number; sinal: number; finalidade: string;
  isencao: IsencaoNegocio; data_promessa: string | null; data_escritura: string | null; data_registo: string | null; notario: string | null; base: number; base_e_vpt: boolean; ip_taxa: number; ip_valor: number;
  selo_pct: number; selo_valor: number; escritura_valor: number; total_impostos: number; impostos_ano: number | null; impostos_fixados_em: string | null; aprovado_em: string | null; aprovado_por: string | null;
  motivo_cancelamento: string | null; cancelado_em: string | null; contratos_destino: string | null; concluido_em: string | null; custo_aquisicao: number | null; ativo_id: number | null;
  valor_contabilistico: number | null; mais_valia: number | null; resultado_contabilistico: number | null; quota: number | null; abate_parcial: boolean; venda_id: number | null; lancamento_compra: string | null;
  lancamento_venda: string | null; abatido_em: string | null; notas: string | null;
  imovel_nome: string | null; imovel_codigo: string | null; imovel_papel: string | null; fracao_designacao: string | null; terceiro_nome: string | null; terceiro_nif: string | null; pago_total?: number;
}
export interface CustoNegocio { id: number; tipo: string; designacao: string; valor: number; capitaliza: boolean; pago: boolean; data: string }
export interface PagamentoNegocio { id: number; tipo: string; valor: number; data: string; meio: string | null; referencia: string | null; estado: 'VALIDO' | 'ANULADO'; anulado_motivo: string | null }
export interface EventoNegocio { evento: string; estado_de: string | null; estado_para: string | null; motivo: string | null; em: string }
export interface ContratoVigor { id: number; numero: string; fim: string | null; renda: number; estado: string }
export interface CustoAquisicao { preco: string; impostos: string; encargos: string; total: string; gasto_periodo: string }
export interface ResultadoVenda {
  conhecido: boolean; motivo?: string; custo?: string; amortizacoes?: string; valor_liquido?: string; resultado_contabilistico?: string; mais_valia?: string; tipo?: 'MAIS_VALIA' | 'MENOS_VALIA';
  quota?: string; criterio_quota?: string; parcial?: boolean; custo_total?: string; amortizacoes_total?: string; encargos_venda?: string; ativo_codigo?: string;
}
export interface FichaNegocio extends Negocio {
  aprovado: boolean; custos: CustoNegocio[]; pagamentos: PagamentoNegocio[]; historico: EventoNegocio[]; pago_total: number; por_liquidar: number; fases_seguintes: FaseNegocio[];
  contratos_em_vigor: ContratoVigor[]; custo_aquisicao_calculado: CustoAquisicao | null; resultado_venda: ResultadoVenda | null; faltas: string[];
}
export interface ResumoNegocios {
  ano: number; compras: number; investido: number; vendas: number; encaixado: number; mais_valias: number; menos_valias: number; impostos: number; impostos_compras: number; impostos_vendas: number;
  sinais_em_curso: number; total: number; abertos: number; por_aprovar: number; abates_pendentes: number;
}
export interface AvisoNegocio { tipo: string; texto: string; negocio_id: number }
export interface Simulacao {
  base: string; base_e_vpt: boolean; ip_taxa: string; ip_valor: string; selo_pct: string; selo_valor: string; escritura_valor: string; total_impostos: string; ano: number;
  sugestao: { isencao: IsencaoNegocio; texto: string }; estado_validacao: string; aviso: string;
}

export const CHAVES_NEG = {
  listas: ['imobiliario', 'negocios', 'listas'] as const,
  resumo: (ano: number) => ['imobiliario', 'negocios', 'resumo', ano] as const,
  avisos: ['imobiliario', 'negocios', 'avisos'] as const,
  lista: (tipo: string, estado: string, texto: string) => ['imobiliario', 'negocios', 'lista', tipo, estado, texto] as const,
  negocio: (id: number) => ['imobiliario', 'negocios', 'ficha', id] as const,
  categorias: ['imobiliario', 'negocios', 'categorias'] as const,
  simulacao: (k: string) => ['imobiliario', 'negocios', 'simular', k] as const,
};

export const obterListasNegocios = () => obter<ListasNegocios>('/imobiliario/negocios/listas');
export const obterResumoNegocios = (ano: number) => obter<ResumoNegocios>('/imobiliario/negocios/resumo', { ano });
export const obterAvisosNegocios = () => obter<AvisoNegocio[]>('/imobiliario/negocios/avisos');
export const listarNegocios = (f: { tipo?: string; estado?: string; texto?: string }) => obter<Negocio[]>('/imobiliario/negocios', Object.fromEntries(Object.entries(f).filter(([, v]) => v)));
export const obterNegocio = (id: number) => obter<FichaNegocio>(`/imobiliario/negocios/${id}`);
export const listarCategoriasActivo = () => obter<CategoriaActivo[]>('/imobiliario/negocios/categorias');
export const simularImpostos = (d: Record<string, unknown>) => obter<Simulacao>('/imobiliario/negocios/simular', Object.fromEntries(Object.entries(d).filter(([, v]) => v !== undefined && v !== null && v !== '')));
export const gravarNegocio = (dados: Record<string, unknown>, id?: number) => enviar<FichaNegocio>(id ? 'put' : 'post', id ? `/imobiliario/negocios/${id}` : '/imobiliario/negocios', dados);
export const eliminarNegocio = (id: number) => enviar<null>('delete', `/imobiliario/negocios/${id}`);
export const gravarCustosNegocio = (id: number, linhas: Record<string, unknown>[]) => enviar<FichaNegocio>('put', `/imobiliario/negocios/${id}/custos`, { linhas });
export const aprovarNegocio = (id: number) => enviar<FichaNegocio>('post', `/imobiliario/negocios/${id}/aprovar`, {});
export const avancarNegocio = (id: number, fase: string) => enviar<FichaNegocio>('post', `/imobiliario/negocios/${id}/avancar`, { fase });
export const cancelarNegocio = (id: number, motivo: string) => enviar<FichaNegocio>('post', `/imobiliario/negocios/${id}/cancelar`, { motivo });
export const tratarContratosNegocio = (id: number, destino: string) => enviar<{ terminados: number; negocio: FichaNegocio }>('post', `/imobiliario/negocios/${id}/contratos`, { destino });
export const concluirNegocio = (id: number, d: { categoria_ativo_id?: number | null } = {}) =>
  enviar<{ negocio: FichaNegocio; repetida: boolean; avisos: string[]; venda?: { id: number; numero: string }; ativo?: { id: number; codigo?: string; criado?: boolean } | null; lancamento?: string | null }>('post', `/imobiliario/negocios/${id}/concluir`, d);
export const abaterNegocio = (id: number) => enviar<{ negocio: FichaNegocio; repetida: boolean; lancamento: string | null; avisos: string[] }>('post', `/imobiliario/negocios/${id}/abater`, {});
export const registarPagamentoNegocio = (id: number, d: Record<string, unknown>) => enviar<FichaNegocio>('post', `/imobiliario/negocios/${id}/pagamentos`, d);
export const anularPagamentoNegocio = (id: number, pagamento: number, motivo: string) => enviar<FichaNegocio>('post', `/imobiliario/negocios/${id}/pagamentos/${pagamento}/anular`, { motivo });
