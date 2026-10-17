import type { EstadoPeriodo, PeriodoSalarial } from '../api';
import { chaveMesAno, mesPorExtenso } from '../comum/regras';
import { normalizarMesAno } from './mesAno';

/**
 * Passo 1 do Calcular: em vez de escrever o Mês/Ano (legado renderCalcular, js/app_v2.js:5312-5345, «Digitar Mês/Ano»
 * + «Ou consultar existente»), escolhe-se numa lista os períodos que o sistema já tem, agrupados pelo estado. Um mês
 * novo abre-se pela acção própria «Abrir período» (o servidor valida o exercício e a unicidade do mês).
 */
export const GRUPOS_ESTADO: { estado: EstadoPeriodo; rotulo: string }[] = [
  { estado: 'ABERTO', rotulo: 'Abertos (em cálculo)' },
  { estado: 'FECHADO', rotulo: 'Encerrados' },
  { estado: 'VALIDADO', rotulo: 'Validados' },
];

/** Grupos não vazios, cada um do mês mais recente para o mais antigo. */
export function gruposPeriodos(periodos: PeriodoSalarial[] | undefined): { estado: EstadoPeriodo; rotulo: string; periodos: PeriodoSalarial[] }[] {
  const lista = [...(periodos ?? [])].sort((a, b) => chaveMesAno(b.mes_ano).localeCompare(chaveMesAno(a.mes_ano)));
  return GRUPOS_ESTADO.map((g) => ({ ...g, periodos: lista.filter((p) => p.estado === g.estado) })).filter((g) => g.periodos.length > 0);
}

/** Texto pesquisável de um período: «07/2026», «2026-07», «Julho de 2026» e o estado. */
export function textoPesquisaPeriodo(p: Pick<PeriodoSalarial, 'mes_ano' | 'estado'>): string {
  const [m, a] = p.mes_ano.split('/');
  return `${p.mes_ano} ${a}-${m} ${mesPorExtenso(p.mes_ano)} ${p.estado}`.toLowerCase();
}

/** Filtro do selector: aceita «7/2026», «072026», «julho», «2026», «fechado»… */
export function periodoCorresponde(termo: string, p: Pick<PeriodoSalarial, 'mes_ano' | 'estado'>): boolean {
  const t = termo.trim().toLowerCase();
  if (!t) return true;
  const normal = normalizarMesAno(t);
  if (normal) return p.mes_ano === normal;
  const texto = textoPesquisaPeriodo(p).normalize('NFD').replace(/[̀-ͯ]/g, '');
  return t.normalize('NFD').replace(/[̀-ͯ]/g, '').split(/\s+/).every((parte) => texto.includes(parte));
}

/** Período por omissão: o aberto mais recente; senão o mais recente de todos. */
export function periodoPorOmissao(periodos: PeriodoSalarial[] | undefined): PeriodoSalarial | undefined {
  const g = gruposPeriodos(periodos);
  return g.find((x) => x.estado === 'ABERTO')?.periodos[0] ?? g.flatMap((x) => x.periodos).sort((a, b) => chaveMesAno(b.mes_ano).localeCompare(chaveMesAno(a.mes_ano)))[0];
}
