import dayjs, { type Dayjs } from 'dayjs';

/**
 * Período por omissão do Balancete e do Balancete do Razão: o ano civil completo (01/01 a 31/12), como o legado
 * (window.lastReportFilters, js/ui_reports.js:4-8). Com «até hoje» ficavam de fora os lançamentos do ano já gravados
 * com data futura (ex.: reconhecimentos mensais de acréscimos e diferimentos) e os totais não batiam com os do legado.
 */
export function periodoPorOmissao(hoje: Dayjs = dayjs()): [Dayjs, Dayjs] {
  return [hoje.startOf('year'), hoje.endOf('year').startOf('day')];
}
