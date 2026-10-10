/**
 * Regras do Controlo Orçamental como no sistema anterior (orcamento_ui.js:732-821 e orcamento_planeamento_ui.js:228-257):
 * período da vista, cartões Proveitos/Custos/Resultado, leitura de cada rubrica, principais desvios desfavoráveis e as séries
 * do gráfico «Resultado mensal — orçado vs realizado». Somas ao cêntimo (os valores chegam em texto decimal, ADR-064).
 */

import { MESES, diferenca, doze, sinal, somaValores } from './regras';
import type { Controlo, LinhaControlo, NaturezaRubrica, TipoOrcamento } from './tipos';

export type VistaControlo = 'MES' | 'ACUMULADO' | 'ANO';
export type Leitura = 'boa' | 'atencao' | 'ma';

/** Meses (1–12) abrangidos pela vista, como `meses` do legado. */
export function mesesDaVista(vista: VistaControlo, mes: number): [number, number] {
  if (vista === 'ANO') return [1, 12];
  if (vista === 'MES') return [mes, mes];
  return [1, mes];
}

/** «Out 2026» ou «Jan–Out 2026». */
export function rotuloPeriodo(vista: VistaControlo, mes: number, ano: number): string {
  const [a, b] = mesesDaVista(vista, mes);
  return a === b ? `${MESES[a - 1]} ${ano}` : `${MESES[a - 1]}–${MESES[b - 1]} ${ano}`;
}

/** Naturezas de entrada e de saída do tipo de orçamento. */
export function naturezas(tipo: TipoOrcamento | string): [NaturezaRubrica, NaturezaRubrica] {
  return tipo === 'TESOURARIA' ? ['RECEBIMENTO', 'PAGAMENTO'] : ['PROVEITO', 'CUSTO'];
}

export const NOME_NATUREZA: Record<NaturezaRubrica, string> = { PROVEITO: 'Proveitos', CUSTO: 'Custos', RECEBIMENTO: 'Recebimentos', PAGAMENTO: 'Pagamentos' };

/** Leitura do legado: favorável (ou sem desvio) → boa; desfavorável > 10 % ou sem orçamento → má; o resto → atenção. */
export function leitura(l: Pick<LinhaControlo, 'favoravel' | 'desvio_significativo' | 'desvio'>): Leitura {
  if (Math.abs(Number(l.desvio)) < 0.005 || l.favoravel) return 'boa';
  return l.desvio_significativo ? 'ma' : 'atencao';
}

export const TEXTO_LEITURA: Record<Leitura, string> = { boa: 'Favorável', atencao: 'Atenção', ma: 'Desfavorável' };
export const COR_LEITURA: Record<Leitura, string> = { boa: '#15803d', atencao: '#b45309', ma: '#b91c1c' };
export const COR_BARRA: Record<Leitura, string> = { boa: '#15803d', atencao: '#d97706', ma: '#dc2626' };

export interface TotalNatureza {
  natureza: NaturezaRubrica;
  orcado: number;
  realizado: number;
  inicial: number;
  /** realizado / orçado × 100 (null sem orçamento) */
  execucao: number | null;
}

/** Totais por natureza (a primeira é a entrada, a segunda a saída). */
export function totaisPorNatureza(c: Pick<Controlo, 'linhas' | 'orcamento'>): [TotalNatureza, TotalNatureza] {
  return naturezas(c.orcamento.tipo).map((n) => {
    const ls = c.linhas.filter((l) => l.natureza === n);
    const orcado = somaValores(ls.map((l) => l.orcado));
    const realizado = somaValores(ls.map((l) => l.realizado));
    return { natureza: n, orcado, realizado, inicial: somaValores(ls.map((l) => l.orcado_inicial ?? l.orcado)), execucao: orcado ? (realizado / orcado) * 100 : null };
  }) as [TotalNatureza, TotalNatureza];
}

/** Resultado (ou fluxo de caixa) orçado e realizado: entradas − saídas. */
export function resultado(c: Pick<Controlo, 'linhas' | 'orcamento'>): { orcado: number; realizado: number; desvio: number } {
  const [a, b] = totaisPorNatureza(c);
  const orcado = diferenca(a.orcado, b.orcado);
  const realizado = diferenca(a.realizado, b.realizado);
  return { orcado, realizado, desvio: diferenca(realizado, orcado) };
}

/** Principais desvios desfavoráveis do período (até 5, maior |desvio| primeiro), como no legado. */
export function principaisDesvios(linhas: LinhaControlo[]): LinhaControlo[] {
  return linhas.filter((l) => leitura(l) !== 'boa' && Math.abs(Number(l.desvio)) >= 0.01)
    .sort((x, y) => Math.abs(Number(y.desvio)) - Math.abs(Number(x.desvio)))
    .slice(0, 5);
}

/** «abaixo» nas entradas (proveitos/recebimentos), «acima» nas saídas. */
export function sentidoDesvio(natureza: NaturezaRubrica): 'abaixo' | 'acima' {
  return sinal(natureza) === 1 ? 'abaixo' : 'acima';
}

/**
 * Séries do gráfico mensal: resultado orçado (12 meses) e realizado até ao último mês da vista (depois fica vazio).
 */
export function seriesMensais(c: Pick<Controlo, 'linhas' | 'orcamento'>, vista: VistaControlo, mes: number): { orcado: number[]; realizado: (number | null)[] } {
  const [entrada] = naturezas(c.orcamento.tipo);
  const soma = (k: 'orcado' | 'realizado') => Array.from({ length: 12 }, (_, m) => somaValores(c.linhas.map((l) => (l.natureza === entrada ? 1 : -1) * doze(l.mensal[k])[m])));
  const ultimo = vista === 'ANO' ? 12 : mesesDaVista(vista, mes)[1];
  return { orcado: soma('orcado'), realizado: soma('realizado').map((v, m) => (m < ultimo ? v : null)) };
}

/** Percentagem pt-PT com uma casa («12,5 %»), ou «—». */
export function pct(v: number | null | undefined, comSinal = false): string {
  if (v === null || v === undefined || !Number.isFinite(v)) return '—';
  return `${comSinal && v > 0 ? '+' : ''}${v.toLocaleString('pt-PT', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %`;
}

/** Textos da classificação do desvio (orcamento_planeamento.js:592-597). */
export const TEXTO_CLASSIFICACAO: Record<string, string> = {
  SEM_DESVIO: 'Sem desvio no período.',
  TEMPORAL: 'Desfasamento temporal: desvios de sinal contrário compensam-se ao longo dos meses (o valor ocorreu noutra data que a prevista).',
  PONTUAL: 'Desvio pontual: concentrado em 1–2 meses — verifique os maiores movimentos desses meses.',
  ESTRUTURAL: 'Desvio estrutural: repete-se na maioria dos meses — o pressuposto do orçamento deve ser revisto (nova versão ou previsão).',
  MISTO: 'Desvio misto: combina variações pontuais e recorrentes.',
};

/** Cor do desvio na análise: verde se favorável, vermelho se desfavorável, neutro se nulo. */
export function corDesvio(natureza: NaturezaRubrica, d: number | string): string | undefined {
  const n = Number(d);
  if (Math.abs(n) < 0.005) return undefined;
  return sinal(natureza) * n >= 0 ? '#15803d' : '#b91c1c';
}
