/**
 * Mapa Fiscal de Reintegrações e Amortizações (modelo oficial; legado renderFiscalAssetMap, js/ui_assets.js:3625-3769).
 * Regras puras (texto das células, totais e HTML da impressão/Excel com o cabeçalho agrupado em 3 linhas do legado).
 */
import { esc } from '@/componentes/impressao';
import { paraCentimos, deCentimos } from '@/utilitarios/decimal';
import { formatarKz } from '@/utilitarios/formatacao';
import type { LinhaFiscal, MapaFiscal } from '../comum/tipos';

export type TotaisFiscal = MapaFiscal['totais'];

/** Anos do selector (legado: 2023…2027 fixos) — aqui os 5 anos à volta do ano actual e o ano escolhido. */
export function anosFiscais(actual: number, escolhido: number): number[] {
  const anos = new Set<number>([actual - 3, actual - 2, actual - 1, actual, actual + 1, escolhido]);
  return [...anos].sort((a, b) => a - b);
}

/** Percentagem «25,00%» (ou «—»). */
export function percentagem(v: string | number | null | undefined): string {
  if (v === null || v === undefined || v === '') return '—';
  const n = Number(v);
  return Number.isFinite(n) ? `${n.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%` : '—';
}

/**
 * «Taxa corrigida»: a taxa efectiva (12/vida útil em meses) quando difere da taxa da categoria; igual (ou sem vida útil) → «—».
 * O legado mostrava sempre «---»; o modelo oficial pede a taxa corrigida quando a vida útil do bem não é a da tabela.
 */
export function taxaCorrigida(l: Pick<LinhaFiscal, 'taxa_categoria' | 'taxa_efectiva'>): string {
  if (!l.taxa_efectiva) return '—';
  const cat = Number(l.taxa_categoria ?? 0);
  return Math.abs(Number(l.taxa_efectiva) - cat) < 0.005 ? '—' : percentagem(l.taxa_efectiva);
}

/** «4.0» → «4,0» (anos de utilidade esperada, 1 casa como no legado). */
export function anosUtilidade(v: string | null | undefined): string {
  if (v === null || v === undefined || v === '') return '—';
  const n = Number(v);
  return Number.isFinite(n) ? n.toLocaleString('pt-PT', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) : '—';
}

/** Filtro de texto (conta, código, descrição). */
export function filtrarFiscal(linhas: LinhaFiscal[], texto: string): LinhaFiscal[] {
  const t = texto.trim().toLowerCase();
  if (!t) return linhas;
  return linhas.filter((l) => [l.conta, l.codigo, l.descricao].some((x) => (x ?? '').toLowerCase().includes(t)));
}

/** Totais das colunas de valor (ao cêntimo) — para quando a lista está filtrada. */
export function totaisFiscal(linhas: LinhaFiscal[]): TotaisFiscal {
  const soma = (k: keyof TotaisFiscal) => deCentimos(linhas.reduce((t, l) => t + paraCentimos(l[k]), 0));
  return { valor_aquisicao: soma('valor_aquisicao'), anteriores: soma('anteriores'), exercicio: soma('exercicio'), acumuladas: soma('acumuladas'), liquido: soma('liquido') };
}

/** As 18 células de uma linha, pela ordem do modelo (texto já formatado). */
export function celulasFiscal(l: LinhaFiscal): string[] {
  const n = (v: number | null) => (v ? String(v) : '—');
  return [
    l.conta ?? '—', l.descricao, n(l.mes_aquisicao), n(l.ano_aquisicao), n(l.mes_inicio_utilizacao), n(l.ano_inicio_utilizacao), 'Não',
    formatarKz(l.valor_aquisicao), anosUtilidade(l.anos_vida), '—', '—', '—', formatarKz(l.anteriores), percentagem(l.taxa_categoria), taxaCorrigida(l),
    formatarKz(l.exercicio), formatarKz(l.acumuladas), formatarKz(l.liquido),
  ];
}

/** Índices (0..17) das colunas numéricas (alinhadas à direita). */
export const COLUNAS_VALOR = new Set([7, 12, 15, 16, 17]);

/** HTML da tabela do modelo (cabeçalho agrupado em 3 linhas, como o legado) para a impressão, PDF e Excel. */
export function htmlMapaFiscal(linhas: LinhaFiscal[], totais: TotaisFiscal, rotuloTotal = 'TOTAIS'): string {
  const th = (t: string, extra = '') => `<th${extra} style="text-align:center;vertical-align:middle">${t}</th>`;
  const cab = `<thead>
<tr>${th('Conta do Plano Geral de Contabilidade', ' rowspan="3"')}${th('Descrição do Activo Imobilizado', ' rowspan="3"')}${th('Datas', ' colspan="4"')}${th('Importado (Sim/Não)', ' rowspan="3"')}${th('Valor de Aquisição / Construção', ' rowspan="3"')}${th('Anos de Utilidade Esperada', ' rowspan="3"')}${th('Reavaliação', ' colspan="3"')}${th('Reintegrações e Amortizações', ' colspan="6"')}</tr>
<tr>${th('Aquisição / Construção', ' colspan="2"')}${th('Início de Utilização', ' colspan="2"')}${th('Ano da Reaval.', ' rowspan="2"')}${th('Valor Acréscimo', ' rowspan="2"')}${th('Valor Total', ' rowspan="2"')}${th('Exercícios Anteriores', ' rowspan="2"')}${th('Do Exercício', ' colspan="3"')}${th('Amortizações Acumuladas', ' rowspan="2"')}${th('Valor Contabilístico (Residual)', ' rowspan="2"')}</tr>
<tr>${th('Mês')}${th('Ano')}${th('Mês')}${th('Ano')}${th('Taxa %')}${th('Taxa Corrig.')}${th('Valor')}</tr>
</thead>`;
  const corpo = linhas.length
    ? linhas.map((l) => `<tr>${celulasFiscal(l).map((c, i) => `<td${COLUNAS_VALOR.has(i) ? ' class="imp-num"' : i === 1 ? '' : ' style="text-align:center"'}>${esc(c)}</td>`).join('')}</tr>`).join('')
    : '<tr><td class="imp-vazio" colspan="18">Sem activos para o ano.</td></tr>';
  const pe = `<tfoot><tr class="imp-total"><td colspan="7" style="text-align:right">${esc(rotuloTotal)}:</td><td class="imp-num">${esc(formatarKz(totais.valor_aquisicao))}</td><td colspan="4"></td><td class="imp-num">${esc(formatarKz(totais.anteriores))}</td><td colspan="2"></td><td class="imp-num">${esc(formatarKz(totais.exercicio))}</td><td class="imp-num">${esc(formatarKz(totais.acumuladas))}</td><td class="imp-num">${esc(formatarKz(totais.liquido))}</td></tr></tfoot>`;
  return `<table class="imp-tabela">${cab}<tbody>${corpo}</tbody>${pe}</table>`;
}
