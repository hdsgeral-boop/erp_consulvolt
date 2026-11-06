import { CheckCircleFilled, ClockCircleOutlined, CloseCircleFilled, MinusCircleOutlined, WarningFilled } from '@ant-design/icons';
import { Tag } from 'antd';
import type { ReactNode } from 'react';
import type { Semaforo } from './api';

/** Semáforo = cor + ícone + texto (nunca só cor: WCAG 1.4.1). */
const SEMAFOROS: Record<Semaforo, { cor: string; rotulo: string; icone: ReactNode }> = {
  VERDE: { cor: 'success', rotulo: 'Cumprida', icone: <CheckCircleFilled /> },
  AMARELO: { cor: 'warning', rotulo: 'Em risco', icone: <WarningFilled /> },
  VERMELHO: { cor: 'error', rotulo: 'Não cumprida', icone: <CloseCircleFilled /> },
  SEM_META: { cor: 'default', rotulo: 'Sem meta', icone: <MinusCircleOutlined /> },
  SEM_MEDIDA: { cor: 'processing', rotulo: 'Por medir', icone: <ClockCircleOutlined /> },
};

export function PastilhaSemaforo({ semaforo }: { semaforo: Semaforo }) {
  const s = SEMAFOROS[semaforo] ?? SEMAFOROS.SEM_META;
  return <Tag color={s.cor} icon={s.icone}>{s.rotulo}</Tag>;
}

export const ROTULO_ESTADO_PLANO: Record<string, string> = { RASCUNHO: 'Em elaboração', APROVADO: 'Aprovado', EM_EXECUCAO: 'Em execução', ENCERRADO: 'Encerrado' };
export const COR_ESTADO_PLANO: Record<string, string> = { RASCUNHO: 'default', APROVADO: 'blue', EM_EXECUCAO: 'green', ENCERRADO: 'purple' };

const MESES = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

/** Chave de período (`2027-03`, `2027-T1`, `2027-S2`, `2027`) → rótulo («Março de 2027», «1.º trimestre de 2027»…). */
export function rotuloPeriodo(chave: string): string {
  const m = /^(\d{4})(?:-(0[1-9]|1[0-2]|T[1-4]|S[12]))?$/.exec(chave);
  if (!m) return chave;
  const s = m[2];
  if (!s) return m[1];
  if (s[0] === 'T') return `${s[1]}.º trimestre de ${m[1]}`;
  if (s[0] === 'S') return `${s[1]}.º semestre de ${m[1]}`;
  return `${MESES[Number(s)]} de ${m[1]}`;
}

/** Trimestres de um ciclo (ano_inicio…ano_fim) para o campo «Trimestre do OKR». */
export function trimestresDoCiclo(inicio: number, fim: number): { value: string; label: string }[] {
  const saida: { value: string; label: string }[] = [];
  for (let a = inicio; a <= fim; a++) for (let t = 1; t <= 4; t++) saida.push({ value: `${a}-T${t}`, label: rotuloPeriodo(`${a}-T${t}`) });
  return saida;
}

/** Formata o texto decimal da API para o ecrã (pt-AO); «—» quando não há valor (ou está oculto). */
export function formatarValorIndicador(valor: string | null | undefined, formato: string, oculto = false): string {
  if (oculto) return 'Reservado';
  if (valor === null || valor === undefined || valor === '') return '—';
  const n = Number(valor);
  if (!Number.isFinite(n)) return valor;
  const num = new Intl.NumberFormat('pt-AO', { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(n);
  const sufixo: Record<string, string> = { kz: ' Kz', pct: ' %', dias: ' dias', horas: ' h', meses: ' meses', nota: ' / 20' };
  return `${num}${sufixo[formato] ?? ''}`;
}

const ESCALA = 12;
const UM = 10n ** BigInt(ESCALA);

function paraFixo(v: string): bigint | null {
  const m = /^([+-]?)(\d*)(?:[.,](\d+))?$/.exec(v.trim());
  if (!m || (m[2] === '' && !m[3])) return null;
  const frac = (m[3] ?? '').slice(0, ESCALA).padEnd(ESCALA, '0');
  const n = BigInt((m[2] || '0') + frac);
  return m[1] === '-' ? -n : n;
}

function deFixo(n: bigint, casas: number): string {
  const neg = n < 0n;
  const abs = neg ? -n : n;
  const passo = 10n ** BigInt(ESCALA - casas);
  const arred = (abs + passo / 2n) / passo;            // meio para longe do zero, como o servidor
  const s = arred.toString().padStart(casas + 1, '0');
  const texto = casas === 0 ? s : `${s.slice(0, s.length - casas)}.${s.slice(s.length - casas)}`;
  return neg && arred !== 0n ? `-${texto}` : texto;
}

/**
 * Metas «em escada» (EST-C-22): v0; passo absoluto (v += p) ou percentual (v ×= 1 + p/100). Aritmética decimal (BigInt), igual à do
 * servidor (CalculadoraDesempenho::escada): acumula sem arredondar e mostra a 2 casas. O servidor valida sempre o que se grava.
 */
export function escada(inicial: string, passo: string, n: number, percentual: boolean): string[] {
  let v = paraFixo(inicial) ?? 0n;
  const p = paraFixo(passo) ?? 0n;
  const saida: string[] = [];
  for (let k = 0; k < n; k++) {
    if (k > 0) v = percentual ? (v * (UM + p / 100n)) / UM : v + p;
    saida.push(deFixo(v, 2));
  }
  return saida;
}

/** «5%» → percentual; «5» → absoluto. */
export function lerPasso(texto: string): { passo: string; percentual: boolean } {
  const t = texto.trim();
  return t.endsWith('%') ? { passo: t.slice(0, -1), percentual: true } : { passo: t, percentual: false };
}
