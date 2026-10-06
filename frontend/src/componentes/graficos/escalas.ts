/**
 * Cálculos puros dos gráficos SVG (sem dependências): domínio, marcas «redondas» do eixo, escala linear,
 * formatação compacta e ângulos do donut. Os valores chegam da API como texto decimal ou número.
 */

/**
 * Paleta categórica do sistema anterior (PALETA, js/ui_painel_modulos.js:15): azul, verde, âmbar, vermelho, violeta, ciano,
 * rosa, lima, ardósia e laranja — pela mesma ordem, para as séries terem as mesmas cores nos dois sistemas. Quando a série
 * tem significado próprio (vendas, custos…) a API ou o ecrã passam `cor` (as cores do legado), que prevalece.
 */
export const CORES_SERIES = ['#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16', '#64748b', '#f97316'];

/** Cinzento para «Outros» e séries para lá da paleta (nunca se recicla uma cor já usada). */
export const COR_OUTROS = '#94a3b8';

/** Cor de uma série pela sua posição; a partir da 11.ª série usa-se cinzento («Outros»). */
export function corSerie(indice: number, propria?: string | null): string {
  return propria || (CORES_SERIES[indice] ?? COR_OUTROS);
}

/** Largura aproximada (px) de um texto na fonte da interface (média de 0,6 em por carácter; dígitos tabulares). */
export function larguraTexto(texto: string, tamanho = 11): number {
  return texto.length * tamanho * 0.6;
}

/** Corta um texto para caber em `largura` px (com reticências). */
export function truncarTexto(texto: string, largura: number, tamanho = 11): string {
  const max = Math.max(3, Math.floor(largura / (tamanho * 0.6)));
  return texto.length > max ? `${texto.slice(0, max - 1)}…` : texto;
}

/** Número de divisões do eixo de valores para um comprimento em px (≈ uma marca a cada 56 px, entre 2 e 8). */
export function alvoMarcas(comprimento: number): number {
  return Math.max(2, Math.min(8, Math.round(comprimento / 56)));
}

/** De quantos em quantos rótulos do eixo das categorias se mostra um, para não se sobreporem. */
export function passoRotulos(n: number, banda: number, larguraRotulo: number): number {
  if (n <= 1 || banda <= 0) return 1;
  return Math.max(1, Math.ceil((larguraRotulo + 6) / banda));
}

/**
 * Caminho SVG suave que passa por todos os pontos sem «ultrapassar» os valores (interpolação monótona de Fritsch–Carlson,
 * equivalente à `tension` do Chart.js usada no legado, mas sem criar máximos/mínimos falsos).
 */
export function caminhoSuave(pontos: [number, number][]): string {
  const n = pontos.length;
  if (n === 0) return '';
  if (n < 3) return pontos.map(([x, y], i) => `${i ? 'L' : 'M'} ${x.toFixed(1)} ${y.toFixed(1)}`).join(' ');
  const dx: number[] = [];
  const m: number[] = [];
  for (let i = 0; i < n - 1; i++) {
    dx.push(pontos[i + 1][0] - pontos[i][0]);
    m.push(dx[i] === 0 ? 0 : (pontos[i + 1][1] - pontos[i][1]) / dx[i]);
  }
  const t: number[] = [m[0]];
  for (let i = 1; i < n - 1; i++) t.push(m[i - 1] * m[i] <= 0 ? 0 : (3 * (dx[i - 1] + dx[i])) / ((2 * dx[i] + dx[i - 1]) / m[i - 1] + (dx[i] + 2 * dx[i - 1]) / m[i]));
  t.push(m[n - 2]);
  let d = `M ${pontos[0][0].toFixed(1)} ${pontos[0][1].toFixed(1)}`;
  for (let i = 0; i < n - 1; i++) {
    const [x0, y0] = pontos[i];
    const [x1, y1] = pontos[i + 1];
    const h = dx[i] / 3;
    d += ` C ${(x0 + h).toFixed(1)} ${(y0 + t[i] * h).toFixed(1)} ${(x1 - h).toFixed(1)} ${(y1 - t[i + 1] * h).toFixed(1)} ${x1.toFixed(1)} ${y1.toFixed(1)}`;
  }
  return d;
}

/** Junta as fatias para lá das `max - 1` maiores numa fatia «Outros» (um donut com dezenas de cores não se lê). */
export function agruparOutros(rotulos: string[], valores: number[], max = 10): { rotulos: string[]; valores: number[]; outros: boolean } {
  const pares = rotulos.map((r, i) => ({ r, v: valores[i] ?? 0 })).filter((p) => p.v > 0);
  if (pares.length <= max) return { rotulos: pares.map((p) => p.r), valores: pares.map((p) => p.v), outros: false };
  const ordenados = [...pares].sort((a, b) => b.v - a.v);
  const ficam = ordenados.slice(0, max - 1);
  const resto = ordenados.slice(max - 1).reduce((s, p) => s + p.v, 0);
  return { rotulos: [...ficam.map((p) => p.r), 'Outros'], valores: [...ficam.map((p) => p.v), resto], outros: true };
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
 * Sem dados (ou tudo zero) devolve 0..1. Com `inteiros` (contagens) o passo nunca é fraccionário.
 */
export function eixoValores(valores: number[], alvo = 5, inteiros = false): Eixo {
  const finitos = valores.filter((v) => Number.isFinite(v));
  let min = Math.min(0, ...finitos);
  let max = Math.max(0, ...finitos);
  if (min === max) {
    max = min === 0 ? 1 : max + Math.abs(max);
    if (min > 0) min = 0;
  }
  const passo = inteiros ? Math.max(1, Math.round(passoRedondo(max - min, alvo))) : passoRedondo(max - min, alvo);
  const minimo = Math.floor(min / passo) * passo;
  const maximo = Math.ceil(max / passo) * passo;
  const marcas: number[] = [];
  for (let v = minimo; v <= maximo + passo / 2; v += passo) marcas.push(Number(v.toPrecision(12)));
  return { minimo, maximo, marcas };
}

/** Todos os valores são inteiros (contagens) — o eixo não deve mostrar «0,2», «0,4»… */
export function saoInteiros(valores: number[]): boolean {
  return valores.every((v) => Number.isInteger(v));
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
