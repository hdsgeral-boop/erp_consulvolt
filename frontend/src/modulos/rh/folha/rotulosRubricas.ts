/**
 * Rótulos das rubricas nos mapas salariais (legado js/app_v2.js `calculatePeriodData`: «Salário Base» → «Sal. Base»,
 * «Subsídio de transporte» → «S. Transp», «Subsídio de alimentação» → «S. Alim», «Subsídio de férias» → «S. Férias»,
 * «…adiantamento…» → «Adiant.»; as restantes ficam com o nome por extenso). Usados pela «Folha de Colaboradores»
 * (Processamentos) e pelo «Mapa Detalhado de Remunerações (Colunas)» (Relatórios e Recibos). Só apresentação.
 */

/** Rubricas automáticas do motor (MotorSalarial): horas extra e faltas (pro-rata ou por hora). */
export const RUBRICA_HORAS_EXTRA = 'H. Extras';
export const RUBRICA_FALTAS = 'Desc. Falta';
export const RUBRICA_ADIANTAMENTO = 'Adiant.';

const ABREVIATURAS: Record<string, string> = {
  'salário base': 'Sal. Base',
  'subsídio de transporte': 'S. Transp',
  'subsídio de alimentação': 'S. Alim',
  'subsídio de férias': 'S. Férias',
};

/** Rótulo abreviado da rubrica (o legado comparava o nome exacto; aqui sem distinguir maiúsculas). */
export function rotuloRubrica(nome: string): string {
  const n = String(nome ?? '').trim().toLowerCase();
  if (ABREVIATURAS[n]) return ABREVIATURAS[n];
  if (n.includes('adiantamento')) return RUBRICA_ADIANTAMENTO;
  return String(nome ?? '').trim();
}

/** Ordem da «Folha de Colaboradores» (legado js/modules/rh/folha_salarios.js `PRIORIDADE`). */
export const PRIORIDADE_FOLHA = ['Sal. Base', 'S. Alim', 'S. Transp', 'S. Férias', RUBRICA_HORAS_EXTRA];
/** Ordem do «Mapa Detalhado de Remunerações» (legado js/app_v2.js `loadReportTabs`: `priority`). */
export const PRIORIDADE_REMUNERACOES = ['Sal. Base', 'S. Transp', 'S. Férias', RUBRICA_HORAS_EXTRA];

/** Rótulos pela prioridade do mapa e depois por ordem alfabética (pt). */
export function ordenarRotulos(rotulos: Iterable<string>, prioridade: string[]): string[] {
  const p = (n: string) => {
    const i = prioridade.indexOf(n);
    return i === -1 ? 99 : i;
  };
  return [...new Set(rotulos)].sort((a, b) => p(a) - p(b) || a.localeCompare(b, 'pt', { numeric: true }));
}
