/**
 * Regras puras da Fase B, iguais às do servidor (que é quem decide): horário (assiduidade.js:135-200), plano de
 * amortização francês (emprestimos.js:75), antiguidade e compensação da Lei 12/23 (contrato_estado.js:78-118) e a
 * normalização das linhas do apuramento (o servidor devolve snake_case; os fechos migrados do legado vêm em camelCase).
 * Só servem para mostrar simulações ao vivo; os valores gravados são sempre os do servidor.
 */
import type { LinhaApuramento } from '../api';
import type { PrestacaoPlano } from './tipos';

/** Horário ou turno da escala (aceita os campos nulos que o servidor devolve). */
export interface HorarioSolto {
  entrada?: string | null;
  saida?: string | null;
  periodos?: number | null;
  saida_intervalo?: string | null;
  entrada_intervalo?: string | null;
  intervalo_min?: number | null;
}

const HORA = /^([01]?\d|2[0-3]):([0-5]\d)$/;

export function minutos(h: string | null | undefined): number | null {
  const m = HORA.exec(String(h ?? '').trim());
  return m ? Number(m[1]) * 60 + Number(m[2]) : null;
}

/** Horas entre duas horas (turno que passa da meia-noite soma 24 h). */
export function horasEntre(de: string | null | undefined, ate: string | null | undefined): number | null {
  const a = minutos(de);
  const b = minutos(ate);
  if (a === null || b === null) return null;
  return Math.round(((b > a ? b - a : 1440 - a + b) / 60) * 100) / 100;
}

/** Horas previstas: 1 período = saída − entrada − intervalo; 2 períodos = soma dos dois períodos. */
export function horasPrevistas(h: HorarioSolto | null | undefined): number | null {
  if (!h || minutos(h.entrada) === null || minutos(h.saida) === null) return null;
  if (Number(h.periodos) === 2 && minutos(h.saida_intervalo) !== null && minutos(h.entrada_intervalo) !== null) {
    const m = horasEntre(h.entrada, h.saida_intervalo) ?? 0;
    const t = horasEntre(h.entrada_intervalo, h.saida) ?? 0;
    return m + t > 0 ? Math.round((m + t) * 100) / 100 : null;
  }
  const liq = (horasEntre(h.entrada, h.saida) ?? 0) - Number(h.intervalo_min ?? 0) / 60;
  return liq > 0 ? Math.round(liq * 100) / 100 : null;
}

export const DIAS_CURTOS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

/** «08:00–12:00 e 13:00–17:30» ou «08:00–17:00 (−60 min)». */
export function textoHorario(h: HorarioSolto | null | undefined): string {
  if (!h || !h.entrada || !h.saida) return '—';
  if (Number(h.periodos) === 2 && h.saida_intervalo && h.entrada_intervalo) return `${h.entrada}–${h.saida_intervalo} e ${h.entrada_intervalo}–${h.saida}`;
  return `${h.entrada}–${h.saida}${Number(h.intervalo_min) ? ` (−${h.intervalo_min} min)` : ''}`;
}

// ───────────── Empréstimos ─────────────

export function mesMais(mes: string, k: number): string {
  const m = /^(\d{4})-(\d{2})$/.exec(mes);
  if (!m) return mes;
  const t = Number(m[1]) * 12 + Number(m[2]) - 1 + k;
  return `${Math.floor(t / 12)}-${String((t % 12) + 1).padStart(2, '0')}`;
}

const kz = (centimos: number) => (centimos / 100).toFixed(2);

/** Plano de prestação constante (sistema francês); a última prestação fecha o saldo ao cêntimo. */
export function planoEmprestimo(capital: number, prestacoes: number, taxaAnual: number, inicio: string): { prestacao: string; linhas: PrestacaoPlano[]; total_juros: string; total: string } {
  const c = Math.round(capital * 100);
  const n = Math.max(1, Math.floor(prestacoes));
  const i = Math.max(0, taxaAnual) / 1200;
  const prest = i > 0 ? Math.round((c * i) / (1 - (1 + i) ** -n)) : Math.round(c / n);
  let saldo = c;
  let juros = 0;
  let total = 0;
  const linhas: PrestacaoPlano[] = [];
  for (let k = 1; k <= n; k++) {
    const juro = i > 0 ? Math.round(saldo * i) : 0;
    let amort = prest - juro;
    let valor = prest;
    if (k === n || amort > saldo) {
      amort = saldo;
      valor = amort + juro;
    }
    saldo -= amort;
    linhas.push({ numero: k, mes_ano: mesMais(inicio, k - 1), capital: kz(amort), juro: kz(juro), total: kz(valor), saldo: kz(Math.max(0, saldo)) });
    juros += juro;
    total += valor;
    if (k < n && saldo <= 0) break;
  }
  return { prestacao: kz(prest), linhas, total_juros: kz(juros), total: kz(total) };
}

// ───────────── Lei 12/23: antiguidade e compensação ─────────────

/** Art. 311.º: anos completos, mais 1 se a fracção for ≥ 3 meses. */
export function antiguidadeAnos(inicio: string | null | undefined, fim: string): number {
  if (!inicio || fim < inicio) return 0;
  const [ai, mi, di] = inicio.slice(0, 10).split('-').map(Number);
  const [af, mf, df] = fim.slice(0, 10).split('-').map(Number);
  let anos = af - ai;
  let meses = mf - mi;
  if (df < di) meses -= 1;
  if (meses < 0) {
    anos -= 1;
    meses += 12;
  }
  return Math.max(0, anos) + (meses >= 3 ? 1 : 0);
}

/** ART307 = 0,5 × SB × anos; ART308 = SB × min(anos, 5) + 0,5 × SB × max(anos − 5, 0); ART310 = max(SB × anos, 3 × SB). */
export function valorCompensacao(regra: string, salarioBase: number, anos: number): string {
  const sb = Math.round(salarioBase * 100);
  const v = regra === 'ART307' ? Math.round(sb * 0.5 * anos)
    : regra === 'ART308' ? sb * Math.min(anos, 5) + Math.round(sb * 0.5 * Math.max(anos - 5, 0))
      : regra === 'ART310' ? Math.max(sb * anos, sb * 3) : 0;
  return kz(v);
}

// ───────────── Apuramento ─────────────

const camel = (k: string) => k.replace(/_([a-z])/g, (_, c: string) => c.toUpperCase());

/** Linha do apuramento em camelCase, venha do servidor (snake_case) ou de um fecho migrado do legado (camelCase). */
export function normalizarLinhaApuramento(l: Record<string, unknown>): LinhaApuramento {
  const saida: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(l)) saida[camel(k)] = v;
  if (saida.employeeId === undefined && saida.colaboradorId !== undefined) saida.employeeId = saida.colaboradorId;
  return { ...saida, avisos: Array.isArray(saida.avisos) ? saida.avisos : [] } as unknown as LinhaApuramento;
}

/** Totais do apuramento em camelCase (o servidor devolve horas_extra/horas_falta/dias_falta). */
export function normalizarTotais(t: Record<string, unknown> | null | undefined): { diasFalta: number; horasExtra: number; horasFalta: number } {
  const x = t ?? {};
  return { diasFalta: Number(x.diasFalta ?? x.dias_falta ?? 0), horasExtra: Number(x.horasExtra ?? x.horas_extra ?? 0), horasFalta: Number(x.horasFalta ?? x.horas_falta ?? 0) };
}
