import { enviar, obter } from '@/api/cliente';
import type {
  AccaoArrumar, AnaliseEntregaveis, Atraso, DadosElegibilidade, DadosEntregavel, DadosExecucao, DadosImpedimento, DadosTarefa, DecisaoValidacao, Elegibilidade, Entregavel, EstadoCRM,
  EstadoImpedimento, Execucao, Impedimento, ItemProdutividadeRef, LinhaElegibilidade, MeusEntregaveis, ResultadoConversao, ResultadoSincronizacaoCRM, TarefaUnidade, TarefasDaUnidade,
} from './tipos';

/**
 * Cliente tipado da Fase J (especificação §4): estrutura (`/rh/estrutura`), produtividade (`/rh/produtividade`) e portal
 * (`/rh/portal`). As rotas, os campos e os nomes são os do contrato — o servidor valida e aplica as regras (§3).
 */

const EST = '/rh/estrutura';
const PROD = '/rh/produtividade';
const PORTAL = '/rh/portal';

/** Chaves das consultas (TanStack Query) para invalidar depois de cada acção. */
export const CHAVE_TAREFAS = ['rh', 'estrutura', 'tarefas'];
export const CHAVE_EXECUCOES = ['rh', 'estrutura', 'execucoes'];
export const CHAVE_IMPEDIMENTOS = ['rh', 'estrutura', 'impedimentos'];
export const CHAVE_ATRASOS = ['rh', 'estrutura', 'atrasos'];
export const CHAVE_PORTAL_ENTREGAVEIS = ['rh', 'portal', 'entregaveis'];
export const CHAVE_PORTAL_APROVACOES = ['rh', 'portal', 'aprovacoes'];

/* ---------------------------------------------------------------- estrutura (§4.1) */

export const tarefasDaUnidade = (unidadeId: number) => obter<TarefasDaUnidade>(`${EST}/unidades/${unidadeId}/tarefas`);
export const criarTarefa = (d: DadosTarefa) => enviar<TarefaUnidade>('post', `${EST}/tarefas`, d);
export const actualizarTarefa = (id: number, d: Partial<DadosTarefa>) => enviar<TarefaUnidade>('put', `${EST}/tarefas/${id}`, d);
export const eliminarTarefa = (id: number) => enviar<null>('delete', `${EST}/tarefas/${id}`);

export const criarEntregavel = (tarefaId: number, d: DadosEntregavel) => enviar<Entregavel>('post', `${EST}/tarefas/${tarefaId}/entregaveis`, d);
export const actualizarEntregavel = (id: number, d: Partial<DadosEntregavel>) => enviar<Entregavel>('put', `${EST}/entregaveis/${id}`, d);
export const eliminarEntregavel = (id: number) => enviar<null>('delete', `${EST}/entregaveis/${id}`);
export const copiarEntregavel = (id: number, d: { tarefa_destino_id: number; nome?: string | null }) => enviar<Entregavel>('post', `${EST}/entregaveis/${id}/copiar`, d);
export const criarItemDoEntregavel = (id: number, d: { codigo?: string | null; preco_unitario: number; infotipo_salarial_id: number }) =>
  enviar<{ item: ItemProdutividadeRef; entregavel: Entregavel }>('post', `${EST}/entregaveis/${id}/criar-item`, d);

export const execucoesDoEntregavel = (id: number, filtro?: { de?: string; ate?: string; validacao?: string }) => obter<Execucao[]>(`${EST}/entregaveis/${id}/execucoes`, filtro);
export const registarExecucao = (entregavelId: number, d: DadosExecucao) => enviar<Execucao>('post', `${EST}/entregaveis/${entregavelId}/execucoes`, d);
export const actualizarExecucao = (id: number, d: Partial<DadosExecucao>) => enviar<Execucao>('put', `${EST}/execucoes/${id}`, d);
export const eliminarExecucao = (id: number) => enviar<null>('delete', `${EST}/execucoes/${id}`);
export const validarExecucao = (id: number, d: { decisao: DecisaoValidacao; motivo?: string | null }) => enviar<Execucao>('post', `${EST}/execucoes/${id}/validar`, d);
export const reenviarExecucao = (id: number) => enviar<Execucao>('post', `${EST}/execucoes/${id}/reenviar`);
export const reporValidacao = (id: number) => enviar<Execucao>('post', `${EST}/execucoes/${id}/repor`);

export const impedimentosDoEntregavel = (id: number) => obter<Impedimento[]>(`${EST}/entregaveis/${id}/impedimentos`);
export const registarImpedimento = (entregavelId: number, d: DadosImpedimento) => enviar<Impedimento>('post', `${EST}/entregaveis/${entregavelId}/impedimentos`, d);
export const decidirImpedimento = (id: number, d: { estado: Exclude<EstadoImpedimento, 'ABERTO'>; nota?: string | null }) => enviar<Impedimento>('post', `${EST}/impedimentos/${id}/decidir`, d);

export const atrasosDaUnidade = (unidadeId?: number, soComAlerta = true) => obter<Atraso[]>(`${EST}/entregaveis/atrasos`, { unidade_id: unidadeId, so_com_alerta: soComAlerta ? 1 : 0 });

export const estadoCRM = (entregavelId: number, filtro?: { de?: string; ate?: string }) => obter<EstadoCRM>(`${EST}/entregaveis/${entregavelId}/crm/estado`, filtro);
export const sincronizarCRM = (entregavelId: number) => enviar<ResultadoSincronizacaoCRM>('post', `${EST}/entregaveis/${entregavelId}/crm/sincronizar`);

export const arrumarUnidade = (unidadeId: number, accao: AccaoArrumar) => enviar<null>('post', `${EST}/unidades/${unidadeId}/arrumar`, { accao });

/* ---------------------------------------------------------------- produtividade (§4.2) */

export const analisarEntregaveis = (periodoId: number, unidades?: number[]) =>
  obter<AnaliseEntregaveis>(`${PROD}/periodos/${periodoId}/entregaveis/analise`, unidades?.length ? { unidades } : undefined);
export const converterEntregaveis = (periodoId: number, unidades?: number[]) =>
  enviar<ResultadoConversao>('post', `${PROD}/periodos/${periodoId}/entregaveis/converter`, unidades?.length ? { unidades } : {});
export const anularConversao = (periodoId: number) => enviar<null>('post', `${PROD}/periodos/${periodoId}/entregaveis/anular`);

export const elegibilidade = (filtro?: { colaborador_id?: number; item_id?: number }) => obter<Elegibilidade>(`${PROD}/elegibilidade`, filtro);
export const gravarElegibilidade = (d: DadosElegibilidade) => enviar<LinhaElegibilidade>('post', `${PROD}/elegibilidade`, d);
export const eliminarElegibilidade = (id: number) => enviar<null>('delete', `${PROD}/elegibilidade/${id}`);

/* ---------------------------------------------------------------- portal (§4.3, entregáveis) */

export const meusEntregaveis = () => obter<MeusEntregaveis>(`${PORTAL}/entregaveis`);
export const registarEntregaPortal = (entregavelId: number, d: DadosExecucao) => enviar<Execucao>('post', `${PORTAL}/entregaveis/${entregavelId}/execucoes`, d);
export const reenviarEntregaPortal = (execucaoId: number) => enviar<Execucao>('post', `${PORTAL}/execucoes/${execucaoId}/reenviar`);
export const registarImpedimentoPortal = (entregavelId: number, d: DadosImpedimento) => enviar<Impedimento>('post', `${PORTAL}/entregaveis/${entregavelId}/impedimentos`, d);
export const entregasParaValidar = () => obter<Execucao[]>(`${PORTAL}/aprovacoes/entregas`);
export const validarEntregaPortal = (execucaoId: number, d: { decisao: DecisaoValidacao; motivo?: string | null }) => enviar<Execucao>('post', `${PORTAL}/execucoes/${execucaoId}/validar`, d);
export const impedimentosParaDecidir = () => obter<Impedimento[]>(`${PORTAL}/aprovacoes/impedimentos`);
export const decidirImpedimentoPortal = (id: number, d: { estado: Exclude<EstadoImpedimento, 'ABERTO'>; nota?: string | null }) => enviar<Impedimento>('post', `${PORTAL}/impedimentos/${id}/decidir`, d);
export const atrasosDasMinhasUnidades = () => obter<Atraso[]>(`${PORTAL}/aprovacoes/atrasos`);
