import dayjs from 'dayjs';

const kz = new Intl.NumberFormat('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const numero = new Intl.NumberFormat('pt-PT', { maximumFractionDigits: 3 });

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
