/**
 * Cálculos puros dos gráficos SVG (sem dependências): domínio, marcas «redondas» do eixo, escala linear,
 * formatação compacta e ângulos do donut. Os valores chegam da API como texto decimal ou número.
 */

/** Paleta categórica (ordem fixa, nunca reciclada; validada para daltonismo nos pares adjacentes). */
export const CORES_SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];

/** Cor de uma série pela sua posição; a partir da 9.ª série usa-se cinzento («Outros»). */
export function corSerie(indice: number): string {
  return CORES_SERIES[indice] ?? '#8c8c8c';
}

/** Converte o valor da API (texto decimal, número ou nulo) em número; inválido → 0. */
export function paraNumero(v: unknown): number {
  if (v === null || v === undefined || v === '') return 0;
  const n = typeof v === 'number' ? v : Number(v);
  return Number.isFinite(n) ? n : 0;
}

/** Passo «redondo» (1, 2, 2,5, 5 × 10^n) para cerca de `alvo` divisões. */
export function passoRedondo(amplitude: number, alvo = 5): number {
  if (!(amplitude > 0)) return 1;
  const bruto = amplitude / Math.max(1, alvo);
  const potencia = Math.pow(10, Math.floor(Math.log10(bruto)));
  const fraccao = bruto / potencia;
  const f = fraccao <= 1 ? 1 : fraccao <= 2 ? 2 : fraccao <= 2.5 ? 2.5 : fraccao <= 5 ? 5 : 10;
  return f * potencia;
}

export interface Eixo {
  minimo: number;
  maximo: number;
  marcas: number[];
}

/**
 * Domínio do eixo dos valores: inclui sempre o zero (barras ancoradas na base), arredonda aos passos e devolve as marcas.
 * Sem dados (ou tudo zero) devolve 0..1.
 */
export function eixoValores(valores: number[], alvo = 5): Eixo {
  const finitos = valores.filter((v) => Number.isFinite(v));
  let min = Math.min(0, ...finitos);
  let max = Math.max(0, ...finitos);
  if (min === max) {
    max = min === 0 ? 1 : max + Math.abs(max);
    if (min > 0) min = 0;
  }
  const passo = passoRedondo(max - min, alvo);
  const minimo = Math.floor(min / passo) * passo;
  const maximo = Math.ceil(max / passo) * passo;
  const marcas: number[] = [];
  for (let v = minimo; v <= maximo + passo / 2; v += passo) marcas.push(Number(v.toPrecision(12)));
  return { minimo, maximo, marcas };
}

/** Escala linear do domínio [d0, d1] para o intervalo [r0, r1] (em píxeis). */
export function escalaLinear(d0: number, d1: number, r0: number, r1: number): (v: number) => number {
  const amplitude = d1 - d0 || 1;
  return (v: number) => r0 + ((v - d0) / amplitude) * (r1 - r0);
}

const compacto = new Intl.NumberFormat('pt-PT', { maximumFractionDigits: 1 });

/** Formatação curta para eixos: 1 234 → «1,2 mil», 3 400 000 → «3,4 M», 2,1e9 → «2,1 mM». */
export function formatarCompacto(v: number): string {
  const a = Math.abs(v);
  if (a >= 1e9) return `${compacto.format(v / 1e9)} mM`;
  if (a >= 1e6) return `${compacto.format(v / 1e6)} M`;
  if (a >= 1e3) return `${compacto.format(v / 1e3)} mil`;
  return compacto.format(v);
}

export interface FatiaDonut {
  indice: number;
  rotulo: string;
  valor: number;
  fraccao: number;
  inicio: number;
  fim: number;
}

/**
 * Fatias de um donut (ângulos em radianos a partir das 12 horas, sentido horário). Valores negativos ou nulos ficam de fora
 * (não têm leitura numa parte-de-um-todo).
 */
export function fatiasDonut(rotulos: string[], valores: number[]): FatiaDonut[] {
  const positivos = valores.map((v, i) => ({ v, i })).filter((x) => x.v > 0);
  const total = positivos.reduce((s, x) => s + x.v, 0);
  if (total <= 0) return [];
  let angulo = 0;
  return positivos.map(({ v, i }) => {
    const fraccao = v / total;
    const fatia = { indice: i, rotulo: rotulos[i] ?? `#${i + 1}`, valor: v, fraccao, inicio: angulo, fim: angulo + fraccao * 2 * Math.PI };
    angulo = fatia.fim;
    return fatia;
  });
}

/** Caminho SVG de um sector de anel (donut) entre dois ângulos. */
export function caminhoSector(cx: number, cy: number, rExterior: number, rInterior: number, inicio: number, fim: number): string {
  // um sector de 360° não se desenha com um só arco: divide-se em dois
  if (fim - inicio >= 2 * Math.PI - 1e-6) {
    const meio = inicio + Math.PI;
    return `${caminhoSector(cx, cy, rExterior, rInterior, inicio, meio)} ${caminhoSector(cx, cy, rExterior, rInterior, meio, fim)}`;
  }
  const ponto = (r: number, a: number) => `${(cx + r * Math.sin(a)).toFixed(2)} ${(cy - r * Math.cos(a)).toFixed(2)}`;
  const grande = fim - inicio > Math.PI ? 1 : 0;
  return [
    `M ${ponto(rExterior, inicio)}`,
    `A ${rExterior} ${rExterior} 0 ${grande} 1 ${ponto(rExterior, fim)}`,
    `L ${ponto(rInterior, fim)}`,
    `A ${rInterior} ${rInterior} 0 ${grande} 0 ${ponto(rInterior, inicio)}`,
    'Z',
  ].join(' ');
}
