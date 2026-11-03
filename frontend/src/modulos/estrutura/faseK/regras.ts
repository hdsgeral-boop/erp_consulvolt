import dayjs from 'dayjs';
import { num } from '../tarefas/regras';
import type {
  AmbitoDelegacao, DelegacaoChefia, EstadoMulta, MinhasDelegacoes, MultaEntregavel, PorDelegadoDe, RelatorioImportacaoEntregas, ResultadoProposta,
} from './tipos';

/**
 * Regras puras da Fase K (K2 importação, K3 multas, K5 delegações): só servem para mostrar, explicar e travar o óbvio
 * antes do pedido. O servidor valida sempre.
 */

/* ---------------------------------------------------------------- K2 importação */

/** «Importar» só fica activo depois de validar e sem erros; linhas duplicadas são ignoradas (não são erro). */
export function podeImportar(r: RelatorioImportacaoEntregas | null | undefined): boolean {
  if (!r) return false;
  return r.resumo.erros === 0 && r.resumo.validas > 0;
}

export function textoResumoImportacao(r: RelatorioImportacaoEntregas['resumo']): string {
  const partes = [`${r.total} linha(s)`, `${r.validas} válida(s)`];
  if (r.duplicadas) partes.push(`${r.duplicadas} já importada(s), ignorada(s)`);
  if (r.erros) partes.push(`${r.erros} com erro`);
  return partes.join(' · ');
}

/** Ficheiros aceites e limites do servidor (K2): .xlsx/.xls/.csv até 5 MB. */
export const LIMITE_FICHEIRO_BYTES = 5 * 1024 * 1024;
export function validarFicheiroImportacao(f: { name: string; size: number }): string | null {
  if (!/\.(xlsx|xls|csv)$/i.test(f.name)) return 'Escolha um ficheiro Excel (.xlsx, .xls) ou CSV.';
  if (f.size > LIMITE_FICHEIRO_BYTES) return 'O ficheiro excede 5 MB.';
  return null;
}

/* ---------------------------------------------------------------- K3 multas */

export const ESTADO_MULTA: Record<EstadoMulta, { rotulo: string; cor: string; dica: string }> = {
  PROPOSTA: { rotulo: 'proposta', cor: 'orange', dica: 'Calculada a partir do atraso; só se desconta depois de aplicada por uma pessoa' },
  APLICADA: { rotulo: 'aplicada', cor: 'red', dica: 'Decidida; entra como desconto na folha quando for lançada' },
  DISPENSADA: { rotulo: 'dispensada', cor: 'default', dica: 'Dispensada com motivo; não se desconta' },
  DESCONTADA: { rotulo: 'descontada', cor: 'blue', dica: 'Já lançada na folha' },
};

export const contar = (v: number | unknown[] | undefined): number => (Array.isArray(v) ? v.length : typeof v === 'number' ? v : 0);

export function textoProposta(r: ResultadoProposta | null | undefined): string {
  const criadas = contar(r?.criadas);
  const ignoradas = contar(r?.ignoradas);
  return `${criadas} multa(s) proposta(s)${ignoradas ? `, ${ignoradas} já existente(s)` : ''}.`;
}

export const nomeMulta = {
  entregavel: (m: MultaEntregavel) => m.entregavel_nome ?? m.entregavel?.nome ?? `#${m.entregavel_id}`,
  colaborador: (m: MultaEntregavel) => m.colaborador_nome ?? m.colaborador?.nome ?? `#${m.colaborador_id}`,
  unidade: (m: MultaEntregavel) => m.unidade_nome ?? m.unidade?.nome ?? (m.unidade_organica_id ? `#${m.unidade_organica_id}` : '—'),
};

/** Acções possíveis numa multa. Ninguém decide a sua própria multa (AUTO_DECISAO — o servidor confirma). */
export function accoesMulta(m: Pick<MultaEntregavel, 'estado' | 'colaborador_id'>, meuColaboradorId: number | null | undefined) {
  const propria = meuColaboradorId != null && m.colaborador_id === meuColaboradorId;
  return { aplicar: m.estado === 'PROPOSTA' && !propria, dispensar: m.estado === 'PROPOSTA' && !propria, repor: m.estado === 'DISPENSADA' && !propria, propria };
}

/** Totais por estado (valor e n.º) para a faixa da lista. */
export function totaisMultas(multas: Pick<MultaEntregavel, 'estado' | 'valor'>[]): Record<EstadoMulta, { n: number; valor: number }> {
  const t: Record<EstadoMulta, { n: number; valor: number }> = { PROPOSTA: { n: 0, valor: 0 }, APLICADA: { n: 0, valor: 0 }, DISPENSADA: { n: 0, valor: 0 }, DESCONTADA: { n: 0, valor: 0 } };
  multas.forEach((m) => {
    const x = t[m.estado];
    if (!x) return;
    x.n += 1;
    x.valor += num(m.valor);
  });
  return t;
}

/** Tecto opcional em % do salário base (0 = sem tecto). */
export function validarTectoMulta(v: number | null | undefined): string | null {
  if (v == null) return null;
  if (!Number.isFinite(v) || v < 0 || v > 100) return 'O tecto é uma percentagem entre 0 e 100 (0 = sem tecto).';
  return null;
}

/* ---------------------------------------------------------------- K5 delegações */

export const AMBITOS: Record<AmbitoDelegacao, { rotulo: string; dica: string }> = {
  PORTAL: { rotulo: 'Pedidos do portal', dica: 'Férias, ausências e outros pedidos da equipa na etapa da chefia' },
  METODO: { rotulo: 'Método de trabalho', dica: 'Assuntos e deliberações do Método de Trabalho' },
  ENTREGAVEIS: { rotulo: 'Entregáveis', dica: 'Validar entregas, decidir impedimentos e acompanhar atrasos' },
};
export const TODOS_AMBITOS: AmbitoDelegacao[] = ['PORTAL', 'METODO', 'ENTREGAVEIS'];
export const MAX_DIAS_DELEGACAO = 90;

export const textoAmbito = (a: AmbitoDelegacao[] | null | undefined): string => (a?.length ? a : TODOS_AMBITOS).map((x) => AMBITOS[x]?.rotulo ?? x).join(', ');

const hojeIso = () => dayjs().format('YYYY-MM-DD');

/**
 * Pré-validação do período (K5): início não anterior a hoje, fim não anterior ao início, máximo 90 dias.
 * Datas AAAA-MM-DD; devolve o motivo ou null.
 */
export function validarPeriodoDelegacao(inicio: string | null | undefined, fim: string | null | undefined, hoje: string = hojeIso()): string | null {
  if (!inicio || !fim) return 'Indique o início e o fim.';
  if (dayjs(inicio).isBefore(dayjs(hoje), 'day')) return 'A delegação não pode começar no passado.';
  if (dayjs(fim).isBefore(dayjs(inicio), 'day')) return 'O fim não pode ser anterior ao início.';
  if (dayjs(fim).diff(dayjs(inicio), 'day') + 1 > MAX_DIAS_DELEGACAO) return `O período máximo é de ${MAX_DIAS_DELEGACAO} dias.`;
  return null;
}

export type SituacaoDelegacao = 'vigente' | 'futura' | 'terminada' | 'revogada';
export function situacaoDelegacao(d: Pick<DelegacaoChefia, 'estado' | 'data_inicio' | 'data_fim'>, hoje: string = hojeIso()): SituacaoDelegacao {
  if (d.estado === 'REVOGADA') return 'revogada';
  if (dayjs(hoje).isBefore(dayjs(d.data_inicio), 'day')) return 'futura';
  if (dayjs(hoje).isAfter(dayjs(d.data_fim), 'day')) return 'terminada';
  return 'vigente';
}
export const SITUACAO_DELEGACAO: Record<SituacaoDelegacao, { rotulo: string; cor: string }> = {
  vigente: { rotulo: 'em vigor', cor: 'green' },
  futura: { rotulo: 'agendada', cor: 'blue' },
  terminada: { rotulo: 'terminada', cor: 'default' },
  revogada: { rotulo: 'revogada', cor: 'red' },
};
/** Só se revoga o que ainda não terminou. */
export const podeRevogar = (d: Pick<DelegacaoChefia, 'estado' | 'data_inicio' | 'data_fim'>, hoje?: string): boolean => ['vigente', 'futura'].includes(situacaoDelegacao(d, hoje));

/** A resposta do portal é {dadas, recebidas}; aceita-se também uma lista simples (tudo «dadas»). */
export function normalizarMinhasDelegacoes(r: MinhasDelegacoes | DelegacaoChefia[] | null | undefined): MinhasDelegacoes {
  if (Array.isArray(r)) return { dadas: r, recebidas: [] };
  return { dadas: r?.dadas ?? [], recebidas: r?.recebidas ?? [] };
}

/** Etiqueta «em nome de X» das decisões tomadas por delegação (null quando foi decisão própria). */
export function etiquetaEmNomeDe(p: PorDelegadoDe | null | undefined): string | null {
  if (!p || (!p.nome && !p.colaborador_id)) return null;
  return `em nome de ${p.nome ?? `#${p.colaborador_id}`}`;
}
