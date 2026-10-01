/**
 * Exportação CSV no cliente (mapas e listagens). Separador «;» e vírgula decimal, com BOM UTF-8,
 * para abrir directamente no Excel em português.
 */

export interface ColunaCsv<T> {
  titulo: string;
  valor: (linha: T) => string | number | boolean | null | undefined;
  /** Valor monetário/numérico: escreve-se com vírgula decimal. */
  numerico?: boolean;
}

function celula(v: string | number | boolean | null | undefined, numerico?: boolean): string {
  if (v === null || v === undefined) return '';
  let s = typeof v === 'boolean' ? (v ? 'Sim' : 'Não') : String(v);
  if (numerico && s !== '' && !Number.isNaN(Number(s))) s = s.replace('.', ',');
  return /[";\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

export function gerarCsv<T>(colunas: ColunaCsv<T>[], linhas: T[]): string {
  const cabecalho = colunas.map((c) => celula(c.titulo)).join(';');
  const corpo = linhas.map((l) => colunas.map((c) => celula(c.valor(l), c.numerico)).join(';'));
  return [cabecalho, ...corpo].join('\r\n');
}

export function descarregarCsv(nomeFicheiro: string, conteudo: string): void {
  const blob = new Blob(['﻿' + conteudo], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = nomeFicheiro.endsWith('.csv') ? nomeFicheiro : `${nomeFicheiro}.csv`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}
