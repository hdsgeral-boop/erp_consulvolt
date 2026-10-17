/**
 * Regras da Tesouraria no cliente: totais dos documentos e visibilidade das acções (espelham o servidor, que valida sempre).
 */
import { deCentimos, paraCentimos, type LinhaDC } from '@/utilitarios/decimal';
import { formatarKz } from '@/utilitarios/formatacao';
import type { Pode } from '../contab/comum/regras';
import type { ConferenciaCaixa, DocumentoTesouraria, ExtratoConta, ResultadoIntegracaoLote, SessaoCaixa, TipoDocumento } from './api';
import { DENOMINACOES } from './api';

/**
 * Valor de um documento de tesouraria = movimento da conta de banco/caixa (ServicoDocumentosTesouraria::validarLinhas):
 * pagamento = Σ D − Σ C das linhas (tem de ser > 0); recebimento = Σ C − Σ D.
 */
export function totalDocumento(tipo: TipoDocumento, linhas: (LinhaDC | null | undefined)[]): { total: string; valido: boolean } {
  let d = 0;
  let c = 0;
  for (const l of linhas) {
    if (!l) continue;
    const v = paraCentimos(l.valor);
    if (v <= 0) continue;
    if (l.tipo_dc === 'D') d += v;
    else if (l.tipo_dc === 'C') c += v;
  }
  const t = tipo === 'PAGAMENTO' ? d - c : c - d;
  return { total: deCentimos(t), valido: t > 0 };
}

/** Sentido por omissão das linhas: um pagamento debita a contrapartida (fornecedor, custo); um recebimento credita-a. */
export function sentidoPorOmissao(tipo: TipoDocumento): 'D' | 'C' {
  return tipo === 'PAGAMENTO' ? 'D' : 'C';
}

export function accoesDocumento(doc: (Pick<DocumentoTesouraria, 'estado' | 'periodo_processamento_salarial_id'> & Partial<Pick<DocumentoTesouraria, 'recibo_venda_id'>>) | undefined, pode: Pode) {
  const pendente = doc?.estado === 'PENDENTE';
  // ronda 3: o recebimento de um recibo de venda edita-se e anula-se no recibo; integrar/desintegrar = (des)contabilizar o recibo
  const doRecibo = !!doc?.recibo_venda_id;
  return {
    podeEditar: pendente && !doRecibo && pode('teso_doc_emitir'),
    podeAnular: pendente && !doRecibo && pode('teso_doc_eliminar'),
    podeIntegrar: pendente && pode('teso_integrar'),
    podeDesintegrar: doc?.estado === 'INTEGRADO' && pode('teso_desintegrar'),
  };
}

export function accoesSessao(s: (Pick<SessaoCaixa, 'estado'> & Partial<Pick<SessaoCaixa, 'movimentos' | 'n_movimentos'>>) | undefined, pode: Pode) {
  const aberta = s?.estado === 'ABERTA';
  // detalhe: a lista de movimentos; lista de sessões: a contagem do servidor; sem nenhuma das duas não se sabe → não elimina
  const nMovimentos = s?.movimentos ? s.movimentos.length : s?.n_movimentos;
  return {
    podeRegistar: aberta && pode('teso_caixa_operar'),
    podeFechar: aberta && pode('teso_caixa_fechar'),
    podeContabilizar: s?.estado === 'FECHADA' && pode('teso_caixa_contabilizar'),
    podeDescontabilizar: s?.estado === 'CONTABILIZADA' && pode('teso_caixa_contabilizar'),
    /** O servidor só elimina sessões abertas sem movimentos. */
    podeEliminar: aberta && nMovimentos === 0 && pode('teso_caixa_eliminar'),
    /** Reabrir: só sessões fechadas e por contabilizar, com a permissão de fechar (ServicoCaixaAjustes::reabrir; A-11). */
    podeReabrir: s?.estado === 'FECHADA' && pode('teso_caixa_fechar'),
    /** Notas DEMO/fluxo, UN e CC dos movimentos: enquanto a sessão não está contabilizada. */
    podeClassificar: (aberta || s?.estado === 'FECHADA') && !!(nMovimentos ?? 0) && pode('teso_caixa_operar'),
  };
}

/** Nomes comparados sem maiúsculas nem espaços a mais (o operador é texto livre, como no legado). */
const mesmoNome = (a: string | null | undefined, b: string | null | undefined) => {
  const n = (x: string | null | undefined) => (x ?? '').trim().replace(/\s+/g, ' ').toLowerCase();
  return !!n(a) && n(a) === n(b);
};

/**
 * Acções da conferência. O gerente que assina tem de ser outra pessoa: nem quem a registou (registado_por) nem a pessoa
 * escrita como operador (o servidor confirma — ServicoConferenciaCaixa::eDoOperador).
 */
export function accoesConferencia(
  c: Pick<ConferenciaCaixa, 'estado' | 'nome_operador' | 'nome_gerente'> & { registado_por?: string | null } | undefined,
  pode: Pode,
  utilizador: string | null | undefined,
  nomeCompleto?: string | null,
) {
  const rascunho = c?.estado === 'RASCUNHO';
  const finalizado = c?.estado === 'FINALIZADO';
  const eOperador = mesmoNome(c?.registado_por, utilizador) || mesmoNome(c?.nome_operador, utilizador) || mesmoNome(c?.nome_operador, nomeCompleto);
  return {
    podeEditar: rascunho && pode('teso_conf_registar'),
    podeFinalizar: rascunho && pode('teso_conf_registar'),
    /** A assinatura do gerente tem de ser de outra pessoa que não o operador. */
    podeAssinar: finalizado && !c?.nome_gerente && pode('teso_conf_assinar') && !!utilizador && !eOperador,
    podeReabrir: finalizado && pode('teso_conf_reabrir'),
  };
}

/** Total físico contado a partir das quantidades por denominação. */
export function totalContado(quantidades: Record<string, number | null | undefined>): string {
  const c = DENOMINACOES.reduce((t, d) => t + Math.max(0, Math.trunc(quantidades[d.chave] ?? 0)) * d.valor * 100, 0);
  return deCentimos(c);
}

/**
 * Correspondência manual extracto × diário: o extracto está na óptica do banco (crédito = entrada de dinheiro),
 * o diário na óptica da empresa (débito na conta 43 = entrada). Casam quando os líquidos coincidem.
 */
export function somaCorrespondencia(extrato: LinhaDC[], diario: LinhaDC[]): { extrato: string; diario: string; diferenca: string; casa: boolean } {
  const liq = (ls: LinhaDC[], positivo: 'D' | 'C') => ls.reduce((t, l) => t + (l.tipo_dc === positivo ? 1 : -1) * paraCentimos(l.valor), 0);
  const e = liq(extrato, 'C');
  const d = liq(diario, 'D');
  return { extrato: deCentimos(e), diario: deCentimos(d), diferenca: deCentimos(e - d), casa: extrato.length > 0 && diario.length > 0 && e === d };
}

export function lerDenominacoes(v: ConferenciaCaixa['denominacoes']): Record<string, number> {
  if (!v) return {};
  if (typeof v === 'string') {
    try {
      const o = JSON.parse(v) as Record<string, number>;
      return o && typeof o === 'object' ? o : {};
    } catch {
      return {};
    }
  }
  return v;
}

/** Máximo de ids por pedido de integração em lote (TesourariaController::integrarLote valida max:200). */
export const LOTE_INTEGRACAO = 200;

/** Mensagens dos documentos que não foram integrados no lote ("n.º: mensagem do servidor"). */
export function errosDoLote(r: ResultadoIntegracaoLote | null | undefined): string[] {
  return (r?.erros ?? []).map((e) => `${e.numero_documento ?? `#${e.id}`}: ${e.mensagem}`);
}

/** Confere o saldo corrido do extracto: saldo inicial + Σ D − Σ C deve dar o saldo final (em cêntimos). */
export function extratoConfere(e: Pick<ExtratoConta, 'saldo_inicial' | 'saldo_final' | 'movimentos'>): boolean {
  let s = paraCentimos(e.saldo_inicial);
  for (const m of e.movimentos) s += m.tipo_dc === 'D' ? paraCentimos(m.valor) : -paraCentimos(m.valor);
  return s === paraCentimos(e.saldo_final);
}

/** Símbolo da moeda como no legado (PayrollEngine.formatMoney / Moedas.formatar): sem moeda ou AOA → «Kz», USD → «US$». */
const SIMBOLOS_MOEDA: Record<string, string> = { AOA: 'Kz', USD: 'US$', EUR: '€' };
export function simboloMoeda(codigo: string | null | undefined): string {
  return !codigo ? 'Kz' : SIMBOLOS_MOEDA[codigo] ?? codigo;
}

/** Valor da lista de sessões com o sufixo da moeda da sessão («1 234,00 Kz» / «50,00 US$»); vazio → «—». */
export function valorComMoeda(valor: string | number | null | undefined, codigo: string | null | undefined): string {
  return valor === null || valor === undefined || valor === '' ? '—' : `${formatarKz(valor)} ${simboloMoeda(codigo)}`;
}

/** Sessão para o sub-separador «Caixa Aberta» do legado: a aberta mais recente (o legado só tinha uma por empresa). */
export function sessaoAbertaRecente<T extends Pick<SessaoCaixa, 'estado' | 'data_abertura' | 'id'>>(sessoes: T[] | undefined): T | undefined {
  return (sessoes ?? []).filter((s) => s.estado === 'ABERTA').sort((a, b) => b.data_abertura.localeCompare(a.data_abertura) || b.id - a.id)[0];
}
