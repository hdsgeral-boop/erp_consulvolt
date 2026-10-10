import dayjs from 'dayjs';
import { deCentimos, paraCentimos, type Valor } from '@/utilitarios/decimal';
import { formatarKz } from '@/utilitarios/formatacao';
import type { Candidato, Definicoes, FonteRecolha, ItemAD, LinhaProposta, LinhaReconciliacao, NaturezaAD, Origem, TipoAD } from './tipos';

/** Regras de apresentação dos Acréscimos e Diferimentos (o servidor valida e calcula sempre). */

type Pode = (...chaves: string[]) => boolean;

export const FECHADOS = ['REGULARIZADO', 'CONCLUIDO', 'ANULADO'];

/** Acções possíveis num registo, conforme o tipo, o estado e as permissões. */
export function accoesItem(it: Pick<ItemAD, 'tipo' | 'estado' | 'tem_lancamentos'> | null | undefined, pode: Pode, temLancamentos = it?.tem_lancamentos ?? false) {
  const editar = pode('ad_editar');
  const aberto = !!it && !FECHADOS.includes(it.estado);
  return {
    editar: editar && aberto,
    /** Com lançamentos só se alteram as notas e a data limite. */
    edicaoParcial: editar && aberto && temLancamentos,
    eliminar: editar && !!it && !temLancamentos,
    regularizar: editar && !!it && it.tipo === 'ACRESCIMO' && (it.estado === 'ACTIVO' || it.estado === 'A_REGULARIZAR'),
    terminar: editar && !!it && it.estado === 'ACTIVO',
    desfazer: editar && !!it && (it.estado === 'A_REGULARIZAR' || it.estado === 'A_TERMINAR'),
  };
}

/** Conta 37 por omissão para o tipo e a natureza (definições do módulo). */
export function contaBalancoPadrao(def: Pick<Definicoes, 'contas'> | undefined, tipo: TipoAD | undefined, natureza: NaturezaAD | undefined): string | undefined {
  if (!def || !tipo || !natureza) return undefined;
  return def.contas[`${tipo}_${natureza}` as keyof Definicoes['contas']] ?? undefined;
}

/** Soma das quotas do plano (deve igualar o valor do registo: a última absorve o arredondamento). */
export function somaQuotas(quotas: { valor: Valor }[]): string {
  return deCentimos(quotas.reduce((t, q) => t + paraCentimos(q.valor), 0));
}

/** Diferença entre o valor do registo e a soma das quotas (0 = plano equilibrado). */
export function diferencaPlano(valor: Valor, quotas: { valor: Valor }[]): string {
  return deCentimos(paraCentimos(valor) - paraCentimos(somaQuotas(quotas)));
}

/** Totais das linhas seleccionadas da proposta (número e valor). */
export function totaisSeleccao(linhas: LinhaProposta[], chaves: string[]): { n: number; total: string } {
  const s = new Set(chaves);
  const escolhidas = linhas.filter((l) => s.has(l.chave));
  return { n: escolhidas.length, total: deCentimos(escolhidas.reduce((t, l) => t + paraCentimos(l.valor), 0)) };
}

/** Mês da proposta (AAAA-MM) e rótulo legível. */
export function mesApi(d: dayjs.Dayjs): string {
  return d.format('YYYY-MM');
}

export function rotuloMes(mes: string): string {
  const d = dayjs(`${mes}-01`);
  return d.isValid() ? d.format('MMMM [de] YYYY') : mes;
}

/** Data de fim sugerida por um modelo (n meses a partir do início, até à véspera). */
export function fimPorMeses(inicio: dayjs.Dayjs, meses: number): dayjs.Dayjs {
  return inicio.add(Math.max(1, meses), 'month').subtract(1, 'day');
}

export interface SaldoContaAD {
  conta: string;
  /** «Saldo 3743 Fornecimento de serviços de terceiros» */
  titulo: string;
  /** Saldo esperado pelos registos do módulo (valor em destaque). */
  modulo: string;
  diario: string;
  diferenca: string;
  reconciliado: boolean;
  /** «Diário 1 100,00 Kz · reconciliado» ou «Diário … · diferença … (lançamentos fora do módulo)» */
  texto: string;
}

/**
 * Faixa de saldos das contas 37 por baixo dos cartões (legado ad_ui.js:153, a partir de reconciliacao de ad_dados.js:368):
 * só as contas com saldo no módulo ou no Diário; reconciliado quando a diferença é inferior a 0,01 (verde #15803d),
 * senão a diferença a vermelho (#b91c1c).
 */
export function faixaSaldosAD(linhas: LinhaReconciliacao[] | null | undefined): SaldoContaAD[] {
  return (linhas ?? [])
    .filter((l) => paraCentimos(l.modulo) !== 0 || paraCentimos(l.diario) !== 0)
    .map((l) => {
      const reconciliado = paraCentimos(l.diferenca) === 0;
      const descricao = (l.descricao ?? '').trim();
      return {
        conta: l.conta,
        titulo: `Saldo ${l.conta}${descricao ? ` ${descricao}` : ''}`,
        modulo: l.modulo,
        diario: l.diario,
        diferenca: l.diferenca,
        reconciliado,
        texto: `Diário ${formatarKz(l.diario, true)}${reconciliado ? ' · reconciliado' : ` · diferença ${formatarKz(l.diferenca, true)} (lançamentos fora do módulo)`}`,
      };
    });
}

/** Cartões da lista (legado ad_ui.js): n.º de acréscimos/diferimentos em curso, Kz já reconhecidos e Kz por reconhecer. */
export function indicadoresAD(itens: { tipo: string; valor: string; reconhecido?: string }[]) {
  const c = (v: string | undefined) => Math.round(Number(v ?? 0) * 100);
  let acrescimos = 0;
  let diferimentos = 0;
  let reconhecido = 0;
  let porReconhecer = 0;
  for (const i of itens) {
    if (i.tipo === 'ACRESCIMO') {
      acrescimos += 1;
      reconhecido += c(i.reconhecido);
    } else if (i.tipo === 'DIFERIMENTO') {
      diferimentos += 1;
      porReconhecer += c(i.valor) - c(i.reconhecido);
    }
  }
  return { acrescimos, diferimentos, acrescimosReconhecido: (reconhecido / 100).toFixed(2), diferimentosPorReconhecer: (porReconhecer / 100).toFixed(2) };
}

const MESES_CURTOS = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

/** Mês como no legado (mesPT de ad_ui.js): «2026-10» → «Out/2026». */
export function mesCurto(mes: string | null | undefined): string {
  if (!mes) return '';
  const m = /^(\d{4})-(\d{2})/.exec(mes);
  if (!m) return mes;
  const i = Number(m[2]) - 1;
  return i >= 0 && i < 12 ? `${MESES_CURTOS[i]}/${m[1]}` : mes;
}

/** «Efeito por conta (seleccionadas)» da proposta do legado: débitos e créditos por conta, ordenados pelo código. */
export function efeitoPorConta(linhas: LinhaProposta[], chaves: string[]): { conta: string; debito: string; credito: string; saldo: string }[] {
  const s = new Set(chaves);
  const m = new Map<string, { d: number; c: number }>();
  for (const l of linhas) {
    if (!s.has(l.chave)) continue;
    const v = paraCentimos(l.valor);
    const d = m.get(l.debito) ?? { d: 0, c: 0 };
    d.d += v;
    m.set(l.debito, d);
    const c = m.get(l.credito) ?? { d: 0, c: 0 };
    c.c += v;
    m.set(l.credito, c);
  }
  return [...m.entries()]
    .sort((a, b) => a[0].localeCompare(b[0], 'pt', { numeric: true }))
    .map(([conta, v]) => ({ conta, debito: deCentimos(v.d), credito: deCentimos(v.c), saldo: deCentimos(v.d - v.c) }));
}

/** Recolher documentos: por omissão o legado esconde os documentos já usados (origem/regularização de um registo). */
export function candidatosVisiveis<T extends Pick<Candidato, 'ligado'>>(lista: T[], mostrarUsados: boolean): T[] {
  return mostrarUsados ? lista : lista.filter((c) => !c.ligado);
}

/** Frase do legado quando não há documentos a mostrar. */
export function textoSemCandidatos(fonte: FonteRecolha, haUsados: boolean): string {
  const contas = fonte === 'VENDA' ? 'rendimentos' : fonte === 'COMPRA' ? 'gastos' : 'gastos ou rendimentos';
  return `Sem documentos com contas de ${contas} neste período${haUsados ? ' (os restantes já foram usados)' : ''}.`;
}

/**
 * «Diferir» (diferirDe do legado): diferimento a 12 meses a partir da data do documento, com o valor, a conta, o terceiro
 * e as dimensões da linha escolhida; se o documento já foi lançado na 37 (em_balanco) a conta da linha é a de balanço e
 * não há lançamento inicial.
 */
export function valoresDiferir(c: Candidato, linha: { conta: string; valor: string }, contaDiferimento: string | undefined) {
  const emBalanco = !!c.em_balanco;
  const origem: Origem = { fonte: c.fonte, id: c.id, doc: c.doc, data: c.data, conta: linha.conta };
  return {
    tipo: 'DIFERIMENTO' as TipoAD,
    natureza: c.natureza,
    descricao: `${c.doc}${c.terceiro?.trim() ? ` — ${c.terceiro.trim()}` : ''}`.slice(0, 1000),
    valor: linha.valor,
    conta_resultado: emBalanco ? '' : linha.conta,
    conta_balanco: emBalanco ? linha.conta : contaDiferimento ?? '',
    data_documento: c.data,
    data_inicio: c.data,
    data_fim: dayjs(c.data).add(12, 'month').subtract(1, 'day').format('YYYY-MM-DD'),
    documento_em_balanco: emBalanco,
    reparticao: 'DIAS' as const,
    terceiro_id: c.terceiro_id,
    terceiro: c.terceiro_id ? { id: c.terceiro_id, nome: c.terceiro } : null,
    unidade_negocio_id: c.unidade_negocio_id,
    centro_custo_id: c.centro_custo_id,
    projeto_id: c.projeto_id,
    origem,
  };
}

/** «Regularizar acréscimo»: valor da linha do documento na conta de resultados do acréscimo (ou o total do documento). */
export function valorRegularizacao(c: Pick<Candidato, 'linhas' | 'total'>, contaResultado: string): string {
  return c.linhas.find((l) => l.conta === contaResultado)?.valor ?? c.total;
}
