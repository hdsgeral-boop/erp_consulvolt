import { enviar, obter } from '@/api/cliente';
import type { Opcao } from './api';

export type Lado = 'SENHORIO' | 'INQUILINO';
export type EstadoContrato = 'RASCUNHO' | 'ACTIVO' | 'SUSPENSO' | 'TERMINADO' | 'DENUNCIADO';
export type EstadoRenda = 'PREVISTA' | 'APROVADA' | 'FACTURADA' | 'PAGA' | 'ANULADA';
export type TipoContrato = 'ARRENDAMENTO' | 'SUBARRENDAMENTO' | 'TOMADA';

export interface ListasContratos {
  tipos: Opcao[]; ambitos: Opcao[]; finalidades_fiscais: Opcao[]; periodicidades: Opcao[]; estados_contrato: Opcao[]; estados_renda: Opcao[];
  actualizacoes: Opcao[]; isencoes: Opcao[]; selo_encargo: Opcao[]; renovacoes: Opcao[]; doc_tipos: Opcao[]; tipos_caucao: Opcao[]; lados: Opcao[]; eventos: Opcao[];
}

export interface Encargo { id?: number; designacao: string; valor: number; incluido_na_renda: boolean; origem?: 'MANUAL' | 'PARQUE'; fracao_id?: number | null }

export interface Contrato {
  id: number; numero: string; tipo: TipoContrato; posicao: Lado; estado: EstadoContrato; ambito: string; imovel_id: number; fracao_id: number; terceiro_id: number;
  contrato_pai_id: number | null; permite_sub: boolean; finalidade_fiscal: string; inicio: string; fim: string | null; fim_real: string | null; motivo_saida: string | null;
  periodicidade: string; antecipada: boolean; dia_vencimento: number; renda: number; pro_rata: boolean; actualizacao: string; actualizacao_valor: number; actualizacao_meses: number;
  actualizacao_base: string | null; ultima_actualizacao: string | null; renda_anterior: number | null; caucao: number; caucao_recebida: number; caucao_devolvida: number;
  caucao_retida: number; retencao_ip: boolean; isencao_ip: string; selo_encargo: string; doc_tipo: string; renovacao: string; renovacao_meses: number; aviso_dias: number;
  conta_custo: string | null; conta_retencao: string | null; conta_financeira: string | null; mora_mensal: number | null; registo_agt_data: string | null;
  outra_parte_morada: string | null; outra_parte_doc: string | null; outra_parte_contacto: string | null; notas: string | null;
  terceiro_nome: string | null; imovel_codigo: string | null; imovel_nome: string | null; fracoes: string[]; contrato_pai_numero: string | null; caucao_em_poder: number;
}

export interface Aviso { tipo: string; contrato_id: number; numero: string; texto: string }

export interface MovimentoCaucao { id: number; tipo: 'RECEBIDA' | 'DEVOLVIDA' | 'RETIDA'; valor: number; data: string; conta_financeira: string | null; motivo: string | null; estado: 'VALIDO' | 'ANULADO'; lancamento_numero: string | null; estorno_numero: string | null; criado_por: string | null }
export interface ResumoCaucao { acordada: number; recebida: number; devolvida: number; retida: number; em_poder: number; por_receber: number; lado: Lado }
export interface Caucao { movimentos: MovimentoCaucao[]; resumo: ResumoCaucao }

export interface EventoContrato { id: number; evento: string; estado_de: string | null; estado_para: string | null; motivo: string | null; dados: Record<string, unknown> | null; em: string }

export interface TotaisContaCorrente { facturado: number; esperado: number; recebido: number; em_divida: number; juros: number; ip_retido: number; selo_senhorio: number; caucao_em_poder: number }

export interface Fiscalidade { periodo: string; ano: number; base: number; ip_valor: number; ip_retido: boolean; ip_efectivo: number; selo_pct: number; selo_valor: number; iva_taxa: number; iva_valor: number; total_facturar: number; total_receber: number }

export interface FichaContrato extends Contrato {
  encargos: Encargo[]; fracoes_detalhe: { id: number; codigo: string; designacao: string; estado: string; area_util: number | null; renda_referencia: number | null }[];
  terceiro: { id: number; nome: string; nif: string | null } | null; imovel: { id: number; codigo: string; nome: string; papel: string; morada: string | null } | null;
  historico: EventoContrato[]; filhos: { id: number; numero: string; estado: EstadoContrato }[]; renda_no_periodo: number; conta_corrente: TotaisContaCorrente;
  fiscalidade: Fiscalidade; caucao_ledger: Caucao; avisos: Aviso[];
}

export interface ResumoContratos {
  contratos: number; activos: number; renda_mensal: number; renda_mensal_paga: number; margem_mensal: number; caucoes: number; caucoes_entregues: number; periodo: string;
  mes_previsto: number; mes_facturado: number; mes_a_pagar: number; mes_pago: number; por_facturar: number; por_pagar: number; em_divida: number; rendas_em_atraso: number;
  juros_mora: number; a_expirar: number;
}

export interface Renda {
  id: number; contrato_id: number; periodo: string; posicao: Lado; de: string; ate: string; vencimento: string; estado: EstadoRenda; renda: number; factor: number; encargos: number;
  desconto: number; extras: { designacao: string; valor: string; data: string }[]; extras_total: number; base: number; ip_base: number; ip_valor: number; ip_retido: boolean;
  selo_pct: number; selo_valor: number; selo_encargo: string; iva_taxa: number; iva_valor: number; total_facturar: number; total_receber: number; venda_id: number | null;
  documento_tesouraria_id: number | null; numero_documento: string | null; retencao_lancamento: string | null; notas: string | null;
  contrato_numero?: string; contrato_estado?: string; terceiro_nome?: string | null; imovel_nome?: string | null; fracao_designacao?: string | null; ambito?: string;
  pago: number; divida: number; atraso: number; juros: number; documento: string | null; documento_estado: string | null;
}

export interface RendasDoPeriodo {
  linhas: Renda[]; outro_lado: number;
  totais: { previstas: number; aprovadas: number; facturadas: number; pagas: number; total_receber: number; selo_senhorio: number; rendas: number };
}

export interface ContaCorrente { linhas: Renda[]; totais: TotaisContaCorrente }

export interface LinhaMargem {
  fracao_id: number; fracao_designacao: string | null; imovel_nome: string | null; paga: string; recebe: string; margem: string; taxa: string | null; tomadas: string[]; subs: string[];
  negativa: boolean; vazia: boolean; periodos: { periodo: string; paga: string; recebe: string; margem: string }[];
}
export interface Margem { de: string; ate: string; linhas: LinhaMargem[]; totais: { paga: string; recebe: string; margem: string }; negativas: number; vazias: number }

export interface CadeiaTomada {
  id: number; numero: string; estado: EstadoContrato; inicio: string; fim: string | null; permite_sub: boolean; paga: number; recebe: number; margem: number; vivos: number;
  subarrendamentos: { id: number; numero: string; estado: EstadoContrato; inicio: string; fim: string | null; facturado: number; em_divida: number }[];
}

export interface ContaPlano { codigo: string; descricao: string | null }
export interface ArtigoRenda { id: number; codigo: string; nome: string; taxa_imposto: number }
export interface ConfiguracaoImo {
  produto_renda: { id: number; codigo: string; nome: string; taxa_imposto: number } | null; produto_renda_alojamento: { id: number; codigo: string; nome: string; taxa_imposto: number } | null;
  conta_caucao_passivo: ContaPlano | null; conta_caucao_activo: ContaPlano | null; conta_caucao_receita: ContaPlano | null; conta_ip_credito: ContaPlano | null;
  completa_facturacao: boolean; completa_caucao: boolean; aviso: string;
}

export interface ResultadoLote { feitas: { id: number; numero_documento?: string; documento_id?: number; repetida: boolean }[]; falhas: { id: number; erro: string }[] }

export const CHAVES_CT = {
  listas: ['imobiliario', 'contratos', 'listas'] as const,
  resumo: ['imobiliario', 'contratos', 'resumo'] as const,
  avisos: ['imobiliario', 'contratos', 'avisos'] as const,
  lista: ['imobiliario', 'contratos', 'lista'] as const,
  ficha: (id: number) => ['imobiliario', 'contratos', 'ficha', id] as const,
  rendas: (periodo: string, lado: Lado) => ['imobiliario', 'rendas', periodo, lado] as const,
  margem: (de: string, ate: string) => ['imobiliario', 'subarrendamento', 'margem', de, ate] as const,
  cadeia: (fracao: number) => ['imobiliario', 'subarrendamento', 'cadeia', fracao] as const,
  contas: ['imobiliario', 'contratos', 'contas'] as const,
  configuracao: ['imobiliario', 'configuracao'] as const,
  artigos: ['imobiliario', 'configuracao', 'artigos'] as const,
};

export const obterListasContratos = () => obter<ListasContratos>('/imobiliario/contratos/listas');
export const obterResumoContratos = () => obter<ResumoContratos>('/imobiliario/contratos/resumo');
export const obterAvisos = () => obter<Aviso[]>('/imobiliario/contratos/avisos');
export const listarContratos = () => obter<Contrato[]>('/imobiliario/contratos');
export const obterContrato = (id: number) => obter<FichaContrato>(`/imobiliario/contratos/${id}`);
export const listarContas = () => obter<ContaPlano[]>('/imobiliario/contratos/contas');
export const gravarContrato = async (dados: Record<string, unknown>, id?: number) =>
  (await enviar<FichaContrato>(id ? 'put' : 'post', id ? `/imobiliario/contratos/${id}` : '/imobiliario/contratos', dados));
export const eliminarContrato = (id: number) => enviar<null>('delete', `/imobiliario/contratos/${id}`);
export const activarContrato = (id: number) => enviar<FichaContrato>('post', `/imobiliario/contratos/${id}/activar`, {});
export const suspenderContrato = (id: number, motivo: string) => enviar<FichaContrato>('post', `/imobiliario/contratos/${id}/suspender`, { motivo });
export const terminarContrato = (id: number, dados: { data: string; motivo: string; denuncia: boolean; cascata: boolean }) => enviar<Record<string, unknown>>('post', `/imobiliario/contratos/${id}/terminar`, dados);
export const renovarContrato = (id: number, meses?: number) => enviar<FichaContrato>('post', `/imobiliario/contratos/${id}/renovar`, meses ? { meses } : {});
export const actualizarRenda = (id: number, dados: { renda_nova: number; data_efeito: string; recalcular: boolean }) => enviar<Record<string, unknown>>('post', `/imobiliario/contratos/${id}/actualizar-renda`, dados);
export const gerarRendasContrato = (id: number) => enviar<{ criadas: number }>('post', `/imobiliario/contratos/${id}/rendas/gerar`, {});
export const obterContaCorrente = (id: number) => obter<ContaCorrente>(`/imobiliario/contratos/${id}/conta-corrente`);
export const movimentarCaucao = (id: number, dados: { tipo: string; valor: number; data: string; conta_financeira?: string; motivo?: string; chave: string }) => enviar<Caucao>('post', `/imobiliario/contratos/${id}/caucao`, dados);
export const anularMovimentoCaucao = (id: number, motivo: string) => enviar<Caucao>('post', `/imobiliario/caucao/${id}/anular`, { motivo });

export const obterRendas = (periodo: string, posicao: Lado) => obter<RendasDoPeriodo>('/imobiliario/rendas', { periodo, posicao });
export const gerarRendasEmFalta = (ate: string) => enviar<{ criadas: number; problemas: { contrato: string; erro: string }[] }>('post', '/imobiliario/rendas/gerar-tudo', { ate });
export const aprovarRendas = (ids: number[]) => enviar<{ aprovadas: number; ignoradas: { id: number; motivo: string }[] }>('post', '/imobiliario/rendas/aprovar', { ids });
export const anularRenda = (id: number, motivo: string) => enviar<Renda>('post', `/imobiliario/rendas/${id}/anular`, { motivo });
export const recalcularRenda = (id: number, desconto?: number) => enviar<Renda>('post', `/imobiliario/rendas/${id}/recalcular`, desconto !== undefined ? { desconto } : {});
export const debitarExtra = (id: number, designacao: string, valor: number) => enviar<Renda>('post', `/imobiliario/rendas/${id}/extras`, { designacao, valor });
export const facturarRendas = (ids: number[], extra?: { conta_disponibilidade?: string }) => enviar<ResultadoLote>('post', '/imobiliario/rendas/facturar', { ids, ...extra });
export const pagarRendas = (ids: number[], dados: { conta_financeira: string; data?: string; referencia?: string }) => enviar<ResultadoLote>('post', '/imobiliario/rendas/pagar', { ids, ...dados });
export const desligarPagamento = (id: number, motivo: string) => enviar<Renda>('post', `/imobiliario/rendas/${id}/desligar-pagamento`, { motivo });
export const registarRetencao = (id: number) => enviar<Renda>('post', `/imobiliario/rendas/${id}/retencao`, {});

export const obterMargem = (de: string, ate: string) => obter<Margem>('/imobiliario/subarrendamento/margem', { de, ate });
export const obterCadeia = (fracao: number) => obter<CadeiaTomada[]>(`/imobiliario/subarrendamento/cadeia/${fracao}`);

export const obterConfiguracao = () => obter<ConfiguracaoImo>('/imobiliario/configuracao');
export const listarArtigos = () => obter<ArtigoRenda[]>('/imobiliario/configuracao/artigos');
export const gravarConfiguracao = (dados: Record<string, unknown>) => enviar<ConfiguracaoImo>('put', '/imobiliario/configuracao', dados);
