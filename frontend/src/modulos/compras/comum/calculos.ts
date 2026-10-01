/**
 * Cálculos de apoio aos formulários. São ESTIMATIVAS para o utilizador: o servidor recalcula tudo
 * (câmbios, arredondamentos, IVA) e é o que fica gravado.
 */

export interface LinhaValor {
  quantidade?: number | string | null;
  preco_unitario?: number | string | null;
  taxa_imposto?: number | string | null;
}

export function numero(v: number | string | null | undefined): number {
  if (v === null || v === undefined || v === '') return 0;
  const n = typeof v === 'number' ? v : Number(v);
  return Number.isFinite(n) ? n : 0;
}

/** Arredonda a 2 casas (meio para cima, como o servidor em Kz). */
export function arredondar2(v: number): number {
  return Math.round((v + Number.EPSILON) * 100) / 100;
}

/** Totais estimados de um conjunto de linhas: líquido, IVA e total. */
export function totaisLinhas(linhas: (LinhaValor | null | undefined)[]): { liquido: number; imposto: number; total: number } {
  let liquido = 0;
  let imposto = 0;
  for (const l of linhas) {
    if (!l) continue;
    const base = arredondar2(numero(l.quantidade) * numero(l.preco_unitario));
    liquido += base;
    imposto += arredondar2((base * numero(l.taxa_imposto)) / 100);
  }
  liquido = arredondar2(liquido);
  imposto = arredondar2(imposto);
  return { liquido, imposto, total: arredondar2(liquido + imposto) };
}

/** Quantidade ainda por receber/facturar numa linha de encomenda (nunca negativa). */
export function pendente(quantidade: number | string | null | undefined, jaFeito: number | string | null | undefined): number {
  return Math.max(0, Math.round((numero(quantidade) - numero(jaFeito)) * 1000) / 1000);
}
