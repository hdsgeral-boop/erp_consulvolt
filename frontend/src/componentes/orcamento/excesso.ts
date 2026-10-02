/**
 * Regras do pedido de aprovação de excesso orçamental no próprio documento (lacuna A-02).
 *
 * Quando a gravação de um documento sujeito ao controlo orçamental (lançamento manual, factura de compra directa,
 * adjudicação, pagamento de tesouraria) passa o limite de uma rubrica em modo APROVACAO, o servidor responde 422 com
 * o código ORCAMENTO_EXIGE_APROVACAO e, em `erros`, os `alertas` (por orçamento × rubrica) e a `chave_documento`
 * («ORIGEM|DOCUMENTO»). Há duas saídas (ServicoControloOrcamental):
 *   1. pedir a aprovação: POST /orcamento/pedidos-excesso com tipo, origem, documento, data, linhas e motivo; depois de
 *      aprovado por outro utilizador, a nova gravação do mesmo documento consome o pedido;
 *   2. aprovar no acto (quem tem `orc_aprovar_excesso`): repetir a gravação com `orcamento: { aprovar_excesso, motivo }`.
 */
import { ErroApi } from '@/api/tipos';

export const CODIGO_EXCESSO = 'ORCAMENTO_EXIGE_APROVACAO';

/** Permissões com que o servidor aceita o pedido de excesso (OrcamentoController@pedirExcesso). */
export const PERMISSOES_PEDIDO = ['compras_ped_criar', 'compras_enc_criar', 'compras_fact_registar', 'teso_doc_emitir', 'lancamentos_post', 'orc_alertas_view'];

export const MOTIVO_MINIMO = 5;
export const MOTIVO_MAXIMO = 1000;

export type TipoControlo = 'EXPLORACAO' | 'TESOURARIA';

/** Resultado da verificação por orçamento × rubrica (ServicoControloOrcamental::verificar). `documento` é o valor deste documento. */
export interface AlertaOrcamental {
  orcamento_anual_id: number;
  orcamento: string;
  rubrica_orcamental_id: number;
  rubrica: string;
  modo?: string;
  orcado: number;
  realizado?: number;
  compromissos?: number;
  consumido: number;
  documento: number;
  percentagem: number | null;
  excesso: number;
  estado: 'OK' | 'AVISO' | 'APROVACAO' | 'BLOQUEIO' | 'SEM_DOTACAO' | string;
  origem?: string;
}

/** Linha de controlo (valor com sinal: positivo consome a rubrica, negativo abate). */
export interface LinhaControlo {
  codigo_conta: string;
  valor: number;
  unidade_negocio_id?: number | null;
  centro_custo_id?: number | null;
  projeto_id?: number | null;
}

/** O que o ecrã sabe do documento que tentou gravar (para o pedido, quando o servidor não devolve as linhas). */
export interface ContextoExcesso {
  tipo: TipoControlo;
  data: string;
  linhas: LinhaControlo[];
  origem?: string;
  documento?: string;
}

/** Opções que a nova gravação envia ao servidor no campo `orcamento`. */
export interface OpcoesOrcamento {
  aprovar_excesso: true;
  motivo: string;
}

export interface DadosExcesso {
  mensagem: string;
  alertas: AlertaOrcamental[];
  /** Só os que exigem aprovação (estado APROVACAO). */
  pendentes: AlertaOrcamental[];
  chave: string | null;
  origem: string | null;
  documento: string | null;
  /** Presentes se o servidor os devolver em `erros` (patch proposto ao ServicoControloOrcamental). */
  tipo?: TipoControlo;
  data?: string;
  linhas?: LinhaControlo[];
}

export interface CorpoPedidoExcesso {
  tipo: TipoControlo;
  origem: string;
  documento: string;
  data: string;
  linhas: LinhaControlo[];
  motivo: string;
}

export function eExcessoOrcamental(e: unknown): e is ErroApi {
  return e instanceof ErroApi && e.codigo === CODIGO_EXCESSO;
}

/** «ORIGEM|DOCUMENTO» → partes. Corta-se só no primeiro «|», porque o n.º do documento do fornecedor pode contê-lo. */
export function partirChave(chave: string | null | undefined): { origem: string | null; documento: string | null } {
  if (!chave) return { origem: null, documento: null };
  const i = chave.indexOf('|');
  return i < 0 ? { origem: chave, documento: null } : { origem: chave.slice(0, i) || null, documento: chave.slice(i + 1) || null };
}

const numero = (v: unknown): number => {
  const n = typeof v === 'number' ? v : Number(v);
  return Number.isFinite(n) ? n : 0;
};

function normalizarAlerta(a: Record<string, unknown>): AlertaOrcamental {
  return {
    orcamento_anual_id: numero(a.orcamento_anual_id),
    orcamento: String(a.orcamento ?? ''),
    rubrica_orcamental_id: numero(a.rubrica_orcamental_id),
    rubrica: String(a.rubrica ?? ''),
    modo: a.modo !== undefined ? String(a.modo) : undefined,
    orcado: numero(a.orcado),
    realizado: a.realizado !== undefined ? numero(a.realizado) : undefined,
    compromissos: a.compromissos !== undefined ? numero(a.compromissos) : undefined,
    consumido: numero(a.consumido),
    documento: numero(a.documento),
    percentagem: a.percentagem === null || a.percentagem === undefined ? null : numero(a.percentagem),
    excesso: numero(a.excesso),
    estado: String(a.estado ?? ''),
    origem: a.origem !== undefined ? String(a.origem) : undefined,
  };
}

function normalizarLinhas(v: unknown): LinhaControlo[] | undefined {
  if (!Array.isArray(v)) return undefined;
  const linhas = v
    .filter((l): l is Record<string, unknown> => !!l && typeof l === 'object' && !!(l as Record<string, unknown>).codigo_conta)
    .map((l) => ({
      codigo_conta: String(l.codigo_conta),
      valor: numero(l.valor),
      unidade_negocio_id: (l.unidade_negocio_id as number | null | undefined) ?? null,
      centro_custo_id: (l.centro_custo_id as number | null | undefined) ?? null,
      projeto_id: (l.projeto_id as number | null | undefined) ?? null,
    }));
  return linhas.length ? linhas : undefined;
}

/** Lê os detalhes do erro devolvido pelo servidor. */
export function extrairExcesso(e: ErroApi): DadosExcesso {
  const erros = (e.erros ?? {}) as Record<string, unknown>;
  const alertas = Array.isArray(erros.alertas) ? (erros.alertas as Record<string, unknown>[]).filter((a) => a && typeof a === 'object').map(normalizarAlerta) : [];
  const chave = typeof erros.chave_documento === 'string' ? erros.chave_documento : null;
  const { origem, documento } = partirChave(chave);
  const tipo = erros.tipo === 'EXPLORACAO' || erros.tipo === 'TESOURARIA' ? erros.tipo : undefined;
  return {
    mensagem: e.message,
    alertas,
    pendentes: alertas.filter((a) => a.estado === 'APROVACAO'),
    chave,
    origem,
    documento,
    tipo,
    data: typeof erros.data === 'string' ? erros.data.slice(0, 10) : undefined,
    linhas: normalizarLinhas(erros.linhas),
  };
}

/**
 * Corpo do POST /orcamento/pedidos-excesso. Os dados devolvidos pelo servidor têm prioridade (são os que ele
 * controlou); na falta deles usa-se o contexto do ecrã. Devolve null se faltar alguma peça (tipo, data, linhas,
 * origem ou documento): nesse caso só resta aprovar no acto ou registar o pedido noutro sítio.
 */
export function corpoPedido(dados: DadosExcesso, contexto: ContextoExcesso | undefined, motivo: string): CorpoPedidoExcesso | null {
  const tipo = dados.tipo ?? contexto?.tipo;
  const data = dados.data ?? contexto?.data;
  const linhas = dados.linhas ?? (contexto?.linhas.length ? contexto.linhas : undefined);
  const origem = dados.origem ?? contexto?.origem ?? null;
  const documento = dados.documento ?? contexto?.documento ?? null;
  if (!tipo || !data || !linhas || !origem || !documento) return null;
  return { tipo, origem, documento, data, linhas: linhas.slice(0, 500), motivo: motivo.trim() };
}

/** Há tudo o que o pedido precisa (independentemente do motivo)? */
export function podePrepararPedido(dados: DadosExcesso, contexto: ContextoExcesso | undefined): boolean {
  return corpoPedido(dados, contexto, '') !== null;
}

/** Linhas D/C de um lançamento ou documento de tesouraria → linhas de controlo (débito consome, crédito abate). */
export function linhasDeMovimentos(
  linhas: ReadonlyArray<{ codigo_conta?: string | null; tipo_dc?: 'D' | 'C' | null; valor?: number | string | null; unidade_negocio_id?: number | null; centro_custo_id?: number | null; projeto_id?: number | null } | null | undefined>,
): LinhaControlo[] {
  return linhas
    .filter((l): l is NonNullable<typeof l> => !!l && !!l.codigo_conta && !!l.tipo_dc && numero(l.valor) !== 0)
    .map((l) => ({
      codigo_conta: String(l.codigo_conta),
      valor: Math.round((l.tipo_dc === 'D' ? 1 : -1) * numero(l.valor) * 100) / 100,
      unidade_negocio_id: l.unidade_negocio_id ?? null,
      centro_custo_id: l.centro_custo_id ?? null,
      projeto_id: l.projeto_id ?? null,
    }));
}

/** Mensagem de validação do motivo (null = válido). */
export function validarMotivo(motivo: string | null | undefined): string | null {
  const m = (motivo ?? '').trim();
  if (m.length < MOTIVO_MINIMO) return `Indique o motivo do excesso (pelo menos ${MOTIVO_MINIMO} caracteres).`;
  if (m.length > MOTIVO_MAXIMO) return `O motivo tem no máximo ${MOTIVO_MAXIMO} caracteres.`;
  return null;
}

export function opcoesAprovacaoNoActo(motivo: string): OpcoesOrcamento {
  return { aprovar_excesso: true, motivo: motivo.trim() };
}

/** Acrescenta as opções de orçamento ao corpo de um pedido de gravação (objecto ou FormData). */
export function comOpcoesOrcamento<T>(dados: T, opcoes: OpcoesOrcamento | undefined): T {
  if (!opcoes) return dados;
  if (typeof FormData !== 'undefined' && dados instanceof FormData) {
    dados.set('orcamento[aprovar_excesso]', '1');
    dados.set('orcamento[motivo]', opcoes.motivo);
    return dados;
  }
  return { ...(dados as object), orcamento: opcoes } as T;
}

/** O mesmo pedido de `useAccao` com as opções de orçamento acrescentadas aos dados (para repetir a gravação). */
export function comOpcoesOrcamentoPedido<P extends { dados?: unknown }>(pedido: P, opcoes: OpcoesOrcamento | undefined): P {
  return { ...pedido, dados: comOpcoesOrcamento(pedido.dados ?? {}, opcoes) };
}

export const ROTULO_ESTADO_PEDIDO: Record<string, string> = {
  PENDENTE: 'A aguardar aprovação',
  APROVADO: 'Aprovado — grave de novo o documento',
  REJEITADO: 'Rejeitado',
  UTILIZADO: 'Utilizado',
};
export const COR_ESTADO_PEDIDO: Record<string, string> = { PENDENTE: 'orange', APROVADO: 'green', REJEITADO: 'red', UTILIZADO: 'default' };
