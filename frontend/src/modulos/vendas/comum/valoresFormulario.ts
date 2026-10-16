import dayjs from 'dayjs';
import { dataApi } from '@/utilitarios/formatacao';
import type { CampoFormulario } from '@/componentes/formularios';

/**
 * Conversões comuns às vistas «Grade» (valores do `Form`, datas em `dayjs`) e «Linhas» (valores simples, datas em
 * 'AAAA-MM-DD') dos formulários de criação de Compras, Vendas e CRM: as duas vistas passam pela mesma função pura que
 * monta o corpo do pedido, e por isso seguem as mesmas regras.
 */

/** Data da grade (`dayjs`) ou das linhas ('AAAA-MM-DD') → 'AAAA-MM-DD' ou `null`. */
export const dataDe = (d: unknown): string | null => (dayjs.isDayjs(d) ? dataApi(d) ?? null : typeof d === 'string' && d ? d.slice(0, 10) : null);

/** Texto aparado ou `null` (campos opcionais). */
export const textoOuNull = (v: unknown): string | null => {
  const t = typeof v === 'string' ? v.trim() : v == null ? '' : String(v);
  return t ? t : null;
};

/** Número ou `null` (campos opcionais vazios). */
export const numeroOuNull = (v: unknown): number | null => (v === null || v === undefined || v === '' ? null : Number(v));

/** Validação «fim anterior ao início» das linhas (as datas vêm em 'AAAA-MM-DD'). */
export const fimAntesDe =
  (campoInicio: string, mensagem = 'O fim é anterior ao início.'): NonNullable<CampoFormulario['validar']> =>
  (fim, r) => {
    const ini = r[campoInicio];
    return typeof fim === 'string' && fim && typeof ini === 'string' && ini && fim < ini ? mensagem : null;
  };

/** Validação de e-mail das linhas (a mesma regra `type: 'email'` da grade, simplificada). */
export const emailInvalido: NonNullable<CampoFormulario['validar']> = (v) =>
  typeof v === 'string' && v.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()) ? 'E-mail inválido.' : null;
