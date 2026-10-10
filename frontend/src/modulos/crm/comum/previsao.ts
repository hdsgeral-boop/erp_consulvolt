import { somar } from '@/utilitarios/decimal';

/** Resposta de /crm/previsao (previsão por mês de fecho e por responsável). */
export interface PrevisaoCRM {
  por_mes: { mes: string; bruto: string; ponderado: string; n: number; compromisso: string }[];
  atrasadas: { bruto: string; ponderado: string; n: number };
  por_responsavel: { responsavel: string; bruto: string; ponderado: string; n: number }[];
  abertas: number;
}

/** Resposta de /crm/indicadores (indicadores do pipeline no período). */
export interface IndicadoresCRM {
  funil: { id: number; nome: string } | null;
  criadas: number;
  ganhas: number;
  perdidas: number;
  valor_ganho: string;
  valor_perdido: string;
  taxa_ganho: number | null;
  conversao_lead_ganho: number | null;
  conversao: { etapa: string; seguinte: string; entraram: number; avancaram: number; taxa: number | null }[];
  ciclo_medio: number | null;
  ciclo_mediana: number | null;
  valor_funil: string;
  ponderado: string;
  por_etapa: { etapa: string; cor: string | null; n: number; valor: string; ponderado: string }[];
  motivos: { motivo: string; n: number; valor: string }[];
  ticket_medio: string | null;
}

const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

/** «2026-10» → «Out/26» (como o antigo, crm_ui_gestao.js:257). */
export const rotuloMesPrevisao = (m: string) => `${MESES[Number(m.slice(5, 7)) - 1] ?? m}/${m.slice(2, 4)}`;

/** Linha «Total» da tabela «Receita prevista por mês de fecho». */
export function totaisPrevisao(p: PrevisaoCRM) {
  return {
    n: p.por_mes.reduce((s, m) => s + m.n, 0),
    bruto: somar(p.por_mes.map((m) => m.bruto)),
    ponderado: somar(p.por_mes.map((m) => m.ponderado)),
    compromisso: somar(p.por_mes.map((m) => m.compromisso)),
  };
}

/** «Previsão por responsável» ordenada pelo ponderado (maior primeiro), como no antigo. */
export function previsaoPorResponsavel(p: PrevisaoCRM) {
  return [...p.por_responsavel].sort((a, b) => Number(b.ponderado) - Number(a.ponderado));
}

/** «Motivos de perda» por valor perdido (maior primeiro) com a percentagem do valor perdido total. */
export function linhasMotivosPerda(k: IndicadoresCRM) {
  const total = Number(k.valor_perdido);
  return [...k.motivos]
    .sort((a, b) => Number(b.valor) - Number(a.valor))
    .map((m) => ({ ...m, percentagem: total ? (Number(m.valor) / total) * 100 : null }));
}
