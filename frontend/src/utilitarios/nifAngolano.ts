/**
 * NIF angolano — ESPELHO de App\Support\Angola\NifAngolano (servidor, a autoridade).
 * Aceita: empresa de 10 dígitos; 9 dígitos (antigo/consumidor final); pessoa singular com o formato do BI
 * (9 dígitos + 2 letras + 3 dígitos, ex.: 000755848LA037). Recusa os impossíveis (todos os dígitos iguais) e,
 * por omissão, o 999999999 do «consumidor final».
 */
export const MENSAGEM_NIF =
  'NIF inválido: use 10 dígitos (empresa) ou, para pessoa singular, o número do BI (9 dígitos, 2 letras e 3 dígitos, ex.: 000755848LA037).';

/** Tira espaços (incluindo NBSP e invisíveis), pontos, hífenes e barras; letras em maiúsculas. */
export function normalizarNif(nif: string | null | undefined): string {
  return (nif ?? '').replace(/[\s ​-‏⁠﻿.\-/]+/gu, '').toUpperCase();
}

export type TipoNif = 'colectiva' | 'singular' | 'nove_digitos';

export function tipoNif(nif: string | null | undefined): TipoNif | null {
  const v = normalizarNif(nif);
  if (/^\d{10}$/.test(v)) return 'colectiva';
  if (/^\d{9}[A-Z]{2}\d{3}$/.test(v)) return 'singular';
  if (/^\d{9}$/.test(v)) return 'nove_digitos';
  return null;
}

export function nifValido(nif: string | null | undefined, permitirConsumidorFinal = false): boolean {
  const v = normalizarNif(nif);
  if (tipoNif(v) === null) return false;
  if (v === '999999999') return permitirConsumidorFinal;
  return !/^(\d)\1+$/.test(v);
}

/**
 * Regra do antd para os formulários: vazio passa (a obrigatoriedade é de outra regra); um NIF inalterado (`inalterado`:
 * o valor já gravado) também passa — um registo antigo, de antes da regra, não fica bloqueado para editar o resto.
 */
export const regraNifAngolano = (inalterado?: string | null) => ({
  validator: (_: unknown, v?: string) => {
    if (!v || !v.trim()) return Promise.resolve();
    if (inalterado && normalizarNif(v) === normalizarNif(inalterado)) return Promise.resolve();
    return nifValido(v) ? Promise.resolve() : Promise.reject(new Error(MENSAGEM_NIF));
  },
});
