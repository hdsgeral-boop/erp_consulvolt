import { enviar, obter } from '@/api/cliente';
import type { PedidoPortal, TipoPedido } from '../api';

/**
 * Portal do Colaborador — rotas da Fase J fora dos entregáveis (especificação §4.3; ServicoPortalColaborador::equipa,
 * feriasEquipa, analisePedido, comprasParaMim; PortalEntregaveisController::decidirCompra; ServicoPortalProjetos).
 */

const PORTAL = '/rh/portal';

export interface PeriodoFeriasEquipa {
  id: number;
  colaborador_id?: number;
  colaborador_nome?: string | null;
  ano?: number;
  data_inicio: string;
  data_fim: string;
  dias: number;
  estado: string;
  pedido_portal_colaborador_id?: number | null;
}

/** GET equipa — equipa directa (ServicoEstruturaOrg::equipaDirecta) com unidade, cargo, férias do ano e último pedido. */
export interface MembroEquipa {
  id: number;
  nome_completo: string;
  data_admissao: string | null;
  unidade_organica_id: number | null;
  unidade: string | null;
  cargo: string | null;
  ferias: { ano: number; direito: number | null; marcados: number; saldo: number | null; periodos: PeriodoFeriasEquipa[] };
  ultimo_pedido: { id: number; tipo: TipoPedido; estado: string; criado_em: string | null } | null;
}

/** GET equipa/ferias?ano — férias (não canceladas) da equipa directa, para a chefia (ServicoFerias::feriasDe). */
export interface FeriasEquipa {
  ano: number;
  ferias: PeriodoFeriasEquipa[];
}

/** GET pedidos/{id}/analise — o que a chefia precisa de ver antes de decidir (portal_ui.js:1303-1387). */
export interface AnalisePedido {
  pedido: { id: number; tipo: TipoPedido; estado: string; criado_em: string | null; dados: Record<string, unknown>; etapas: PedidoPortal['etapas'] };
  colaborador: { id: number; nome_completo: string; data_admissao: string | null; unidade: string | null; chefia_colaborador_id: number | null } | null;
  saldo_ferias: { ano: number; direito: number; marcados: number; gozados: number; saldo: number; dias_pedidos: number } | null;
  equipa_ausente: { colaborador_id: number; nome: string; tipo: 'FERIAS' | 'AUSENCIA' | string; estado: string; de: string; ate: string }[];
  ultimos_pedidos: { id: number; estado: string; criado_em: string | null; dados: Record<string, unknown> | null; decidido_em: string | null; decisao: string[] }[];
  avisos: string[];
  anexos: { rotulo: string; url: string }[];
}

/** GET compras/aprovacoes (existente: ServicoPortalColaborador::comprasParaMim). */
export interface CompraParaMim {
  id: number;
  numero_pedido: string | null;
  nome_requerente: string | null;
  data: string | null;
  valor_estimado: string | number | null;
  nivel_atual: string | null;
  niveis_aprovados: number;
  niveis_total: number;
}

/** GET projetos/tarefas (ServicoPortalProjetos::minhasTarefas). */
export interface TarefaProjectoPortal {
  id: number;
  codigo: string | null;
  nome: string;
  projeto_id: number;
  projeto_codigo: string | null;
  projeto_nome: string;
  estado: string;
  data_inicio: string | null;
  data_fim: string | null;
  tem_meta: boolean;
  meta_unidade: string | null;
  meta_quantidade: number;
  meta_realizado: number;
  pct_meta: number | null;
  por_posicao: boolean;
}

/** GET projetos/timesheets?mes (ServicoPortalProjetos::meusTimesheets). */
export interface HorasProjectoPortal {
  id: number;
  data: string | null;
  horas: number;
  estado: string | null;
  tarefa_projeto_id: number;
  tarefa_codigo: string | null;
  tarefa_nome: string | null;
}

export const equipaDirecta = () => obter<MembroEquipa[]>(`${PORTAL}/equipa`);
export const feriasDaEquipa = (ano: number) => obter<FeriasEquipa>(`${PORTAL}/equipa/ferias`, { ano });
export const analisePedido = (id: number) => obter<AnalisePedido>(`${PORTAL}/pedidos/${id}/analise`);
export const decidirPedido = (id: number, d: { decisao: 'APROVADO' | 'RECUSADO'; nota?: string; remunerada?: 'SIM' | 'NAO' }) => enviar<PedidoPortal>('post', `${PORTAL}/pedidos/${id}/decidir`, d);
export const comprasParaMim = () => obter<CompraParaMim[]>(`${PORTAL}/compras/aprovacoes`);
/** POST compras/{pedido}/decidir — as mesmas regras de /compras/pedidos/{id}/decidir (ServicoDeliberacaoCompras). */
export const decidirCompra = (pedidoId: number, d: { decisao: 'APROVAR' | 'RECUSAR'; nota?: string }) =>
  enviar<{ id: number; numero_pedido: string | null; estado: string; deliberacao: unknown }>('post', `${PORTAL}/compras/${pedidoId}/decidir`, d);
export const tarefasProjecto = () => obter<TarefaProjectoPortal[]>(`${PORTAL}/projetos/tarefas`);
export const horasProjecto = (mes: string) => obter<HorasProjectoPortal[]>(`${PORTAL}/projetos/timesheets`, { mes });
export const registarHoras = (d: { tarefa_projeto_id: number; data: string; horas: number }) => enviar<HorasProjectoPortal>('post', `${PORTAL}/projetos/timesheets`, d);
export const registarMedicao = (tarefaId: number, d: { quantidade: number; data?: string | null; nota?: string | null }) =>
  enviar<{ realizado: number; pct: number }>('post', `${PORTAL}/projetos/tarefas/${tarefaId}/medicoes`, d);
