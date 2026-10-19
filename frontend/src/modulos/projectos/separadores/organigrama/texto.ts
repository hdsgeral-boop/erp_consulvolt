import { MEDIDAS } from './layout';

/**
 * Texto dos cartões do organigrama sem reticências: o título da posição, o nome e o cargo dos ocupantes quebram em até
 * 3 linhas; só se ainda não couberem a letra reduz ligeiramente (até um mínimo) e, no limite, continuam a quebrar — nunca
 * se cortam com «…». A altura de cada cartão sai daqui e entra na disposição da árvore (as ligações recalculam-se), e o
 * mesmo resultado serve ao ecrã e ao SVG da impressão/PNG.
 */

/** Largura (px) de um texto num tamanho de letra. */
export type Medidor = (texto: string, tamanho: number, negrito: boolean) => number;

/** Medição aproximada (sem canvas: testes, servidor): larguras médias por tipo de carácter, por excesso. */
export const medidorAproximado: Medidor = (texto, tamanho, negrito) => [...texto].reduce((s, c) => {
  const k = c === ' ' ? 0.3 : /[il.,;:'|!()[\]]/.test(c) ? 0.32 : /[mwMW]/.test(c) ? 0.86 : /[A-ZÀ-Ý0-9]/.test(c) ? 0.68 : 0.56;
  return s + k * tamanho * (negrito ? 1.07 : 1);
}, 0);

const FAMILIA = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";
let canvas: CanvasRenderingContext2D | null | undefined;

/** Medição real com o canvas do navegador (a mesma família de letra do tema); sem canvas, a aproximada. */
export function medidorTexto(familia = FAMILIA): Medidor {
  if (canvas === undefined) {
    try {
      canvas = typeof document !== 'undefined' && !/jsdom/i.test(typeof navigator !== 'undefined' ? navigator.userAgent : '')
        ? document.createElement('canvas').getContext('2d') : null;
    } catch {
      canvas = null;
    }
  }
  const ctx = canvas;
  if (!ctx) return medidorAproximado;
  return (texto, tamanho, negrito) => {
    ctx.font = `${negrito ? 600 : 400} ${tamanho}px ${familia}`;
    return ctx.measureText(texto).width;
  };
}

/** Quebra por palavras numa largura; uma palavra maior do que a linha parte-se pelos caracteres (sem perder nada). */
export function quebrarLinhas(texto: string, largura: number, tamanho: number, negrito: boolean, medir: Medidor): string[] {
  const linhas: string[] = [];
  let actual = '';
  for (const palavra of texto.trim().split(/\s+/).filter(Boolean)) {
    const tentativa = actual ? `${actual} ${palavra}` : palavra;
    if (medir(tentativa, tamanho, negrito) <= largura) { actual = tentativa; continue; }
    if (actual) linhas.push(actual);
    actual = '';
    let resto = palavra;
    while (medir(resto, tamanho, negrito) > largura && resto.length > 1) {
      let n = resto.length - 1;
      while (n > 1 && medir(resto.slice(0, n), tamanho, negrito) > largura) n -= 1;
      linhas.push(resto.slice(0, n));
      resto = resto.slice(n);
    }
    actual = resto;
  }
  if (actual) linhas.push(actual);
  return linhas;
}

export interface TextoAjustado {
  linhas: string[];
  tamanho: number;
}

/** Até `maxLinhas` linhas no tamanho normal; se exceder, reduz a letra em passos de 0,5 px até ao mínimo. */
export function ajustarTexto(texto: string, largura: number, o: { tamanho: number; minimo: number; negrito?: boolean; maxLinhas?: number; medir?: Medidor }): TextoAjustado {
  const medir = o.medir ?? medidorAproximado;
  const max = o.maxLinhas ?? 3;
  let ultimo: TextoAjustado = { linhas: [], tamanho: o.tamanho };
  for (let t = o.tamanho; t >= o.minimo - 1e-9; t = Math.round((t - 0.5) * 10) / 10) {
    ultimo = { linhas: quebrarLinhas(texto, largura, t, !!o.negrito, medir), tamanho: t };
    if (ultimo.linhas.length <= max) return ultimo;
  }
  return ultimo;   // nem no mínimo cabe em `max` linhas: continua a quebrar (nunca «…»)
}

/** Medidas internas do cartão (px), partilhadas pelo ecrã e pelo SVG. */
export const CARTAO = {
  padH: 10, padTopo: 8, padBase: 6, faixa: 4,
  tituloLargura: MEDIDAS.largura - 20 - 26,   // menos o botão ⋯
  pessoaLargura: MEDIDAS.largura - 20 - 22 - 6,   // menos o avatar
  areaLargura: MEDIDAS.largura - 20 - 84,   // menos as vagas («10/10 vaga(s)»)
  linhaArea: 16, rodape: 20, semOcupante: 18, entrePessoas: 4, avatar: 22,
  alturaLinha: 1.3,
} as const;

export interface PessoaMedida {
  nome: TextoAjustado;
  cargo: TextoAjustado | null;
  altura: number;
}

export interface CartaoMedido {
  titulo: TextoAjustado;
  area: TextoAjustado | null;
  /** Altura da linha da área e das vagas. */
  linhaArea: number;
  pessoas: PessoaMedida[];
  altura: number;
}

export const alturaTexto = (t: TextoAjustado | null) => (t ? t.linhas.length * Math.ceil(t.tamanho * CARTAO.alturaLinha) : 0);

/** Mede o cartão de uma posição: título, área e os 2 primeiros ocupantes (nome e cargo), sem cortar texto; altura ≥ a mínima. */
export function medirCartao(d: { titulo: string; area?: string | null; pessoas: { nome: string; cargo?: string | null }[]; rodape?: string | null },
  medir: Medidor = medidorAproximado): CartaoMedido {
  const t = ajustarTexto(d.titulo, CARTAO.tituloLargura, { tamanho: 13, minimo: 11, negrito: true, medir });
  const area = d.area ? ajustarTexto(d.area, CARTAO.areaLargura, { tamanho: 11, minimo: 10, medir }) : null;
  const ps = d.pessoas.slice(0, 2).map((p) => {
    const nome = ajustarTexto(p.nome, CARTAO.pessoaLargura, { tamanho: 12, minimo: 10.5, medir });
    const cargo = p.cargo ? ajustarTexto(p.cargo, CARTAO.pessoaLargura, { tamanho: 10, minimo: 9, medir }) : null;
    return { nome, cargo, altura: Math.max(CARTAO.avatar, alturaTexto(nome) + alturaTexto(cargo)) };
  });
  const corpo = ps.length ? ps.reduce((s, p) => s + p.altura + CARTAO.entrePessoas, 0) : CARTAO.semOcupante;
  // rodapé (contacto no SVG): linhas a mais do que uma acrescentam altura
  const extraRodape = d.rodape ? (quebrarLinhas(d.rodape, MEDIDAS.largura - 2 * CARTAO.padH, 8.5, false, medidorAproximado).length - 1) * 11 : 0;
  const linhaArea = Math.max(CARTAO.linhaArea, alturaTexto(area));
  const altura = CARTAO.faixa + CARTAO.padTopo + alturaTexto(t) + linhaArea + corpo + CARTAO.rodape + Math.max(0, extraRodape) + CARTAO.padBase;
  return { titulo: t, area, linhaArea, pessoas: ps, altura: Math.max(MEDIDAS.altura, Math.ceil(altura)) };
}
