/**
 * Aritmética de valores em Kz no cliente (estimativas e totais de ecrã). A API devolve texto com 2 casas;
 * aqui trabalha-se em cêntimos inteiros para não acumular erros de vírgula flutuante. O servidor recalcula sempre.
 */

export type Valor = string | number | null | undefined;

/** Converte um valor (texto da API ou número do formulário) em cêntimos inteiros; vazio/inválido = 0. */
export function paraCentimos(v: Valor): number {
  if (v === null || v === undefined || v === '') return 0;
  const n = typeof v === 'number' ? v : Number(String(v).replace(',', '.'));
  return Number.isFinite(n) ? Math.round(n * 100) : 0;
}

/** Cêntimos → texto decimal com 2 casas (formato da API: «1234.50»). */
export function deCentimos(c: number): string {
  const sinal = c < 0 ? '-' : '';
  const a = Math.abs(Math.round(c));
  return `${sinal}${Math.floor(a / 100)}.${String(a % 100).padStart(2, '0')}`;
}

export function somar(valores: Valor[]): string {
  return deCentimos(valores.reduce<number>((t, v) => t + paraCentimos(v), 0));
}

export interface LinhaDC {
  tipo_dc?: 'D' | 'C' | string | null;
  valor?: Valor;
}

export interface Equilibrio {
  debito: string;
  credito: string;
  /** Débito − crédito. */
  diferenca: string;
  equilibrado: boolean;
  /** Pronto a gravar: ≥ 2 linhas com valor > 0, há débitos e créditos e D = C. */
  valido: boolean;
}

/** Equilíbrio de um lançamento em partida dobrada (regra do servidor: Σ D = Σ C, ao cêntimo). */
export function equilibrio(linhas: (LinhaDC | null | undefined)[]): Equilibrio {
  let d = 0;
  let c = 0;
  let comValor = 0;
  for (const l of linhas) {
    if (!l) continue;
    const v = paraCentimos(l.valor);
    if (v <= 0) continue;
    comValor++;
    if (l.tipo_dc === 'D') d += v;
    else if (l.tipo_dc === 'C') c += v;
  }
  const equilibrado = d === c;
  return { debito: deCentimos(d), credito: deCentimos(c), diferenca: deCentimos(d - c), equilibrado, valido: equilibrado && comValor >= 2 && d > 0 };
}

/** Soma por coluna de uma lista de linhas (totais dos mapas). Valores ausentes contam como 0. */
export function somarColunas<T extends object>(linhas: T[], chaves: (keyof T & string)[]): Record<string, string> {
  const t: Record<string, number> = Object.fromEntries(chaves.map((k) => [k, 0]));
  for (const l of linhas) for (const k of chaves) t[k] += paraCentimos(l[k] as Valor);
  return Object.fromEntries(chaves.map((k) => [k, deCentimos(t[k])]));
}

export function eZero(v: Valor): boolean {
  return paraCentimos(v) === 0;
}

export function negativo(v: Valor): boolean {
  return paraCentimos(v) < 0;
}
