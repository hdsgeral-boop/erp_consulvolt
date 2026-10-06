import dayjs from 'dayjs';

/**
 * Agrupar milhares SEMPRE: o pt-PT do CLDR só agrupa a partir de 5 algarismos («1000,00» ao lado de «10 000,00»
 * na mesma coluna). `useGrouping: 'always'` (Intl.NumberFormat v3, ES2023) não existe na lib ES2022 do TypeScript,
 * que só conhece boolean — daí a conversão. Navegadores antigos interpretam-no como `true` (comportamento anterior).
 */
const AGRUPAR_SEMPRE = 'always' as unknown as boolean;

const kz = new Intl.NumberFormat('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2, useGrouping: AGRUPAR_SEMPRE });
const numero = new Intl.NumberFormat('pt-PT', { maximumFractionDigits: 3, useGrouping: AGRUPAR_SEMPRE });

/** Valores em Kz: a API devolve texto com 2 casas (decimal exacto); mostra-se «1 234 567,89». */
export function formatarKz(valor: string | number | null | undefined, comMoeda = false): string {
  if (valor === null || valor === undefined || valor === '') return '—';
  const n = typeof valor === 'number' ? valor : Number(valor);
  if (Number.isNaN(n)) return String(valor);
  return kz.format(n) + (comMoeda ? ' Kz' : '');
}

export function formatarNumero(valor: string | number | null | undefined): string {
  if (valor === null || valor === undefined || valor === '') return '—';
  const n = typeof valor === 'number' ? valor : Number(valor);
  return Number.isNaN(n) ? String(valor) : numero.format(n);
}

export function formatarData(valor: string | null | undefined): string {
  return valor ? dayjs(valor).format('DD/MM/YYYY') : '—';
}

export function formatarDataHora(valor: string | null | undefined): string {
  return valor ? dayjs(valor).format('DD/MM/YYYY HH:mm') : '—';
}

/** Data no formato da API (AAAA-MM-DD). */
export function dataApi(valor: dayjs.Dayjs | null | undefined): string | undefined {
  return valor ? valor.format('YYYY-MM-DD') : undefined;
}
