import type { FormatoPagina, Orientacao, Papel } from './tipos';

/**
 * Decisão do papel/orientação/escala a partir da largura natural do conteúdo.
 *
 * Ordem (melhoria do legado js/shared/impressao.js, que só ia até A4 horizontal):
 *   A4 retrato (190 mm úteis) → A4 paisagem (277 mm, admitindo até 85 % de redução — muitos postos não têm
 *   impressora A3) → A3 paisagem (400 mm) → só então reduz a escala (mínimo 0,55). Tabelas com 9 ou mais colunas passam logo a paisagem. O resultado cabe sempre em largura:
 *   se nem a 55 % couber, deixa-se o texto das células quebrar e, em último caso, reduz-se abaixo de 55 %.
 */

export const PX_POR_MM = 96 / 25.4;
/** Margens da página (mm): laterais/topo 10 mm; em baixo 14 mm para o rodapé com «Página X de Y». */
export const MARGENS_MM = { topo: 10, direita: 10, baixo: 14, esquerda: 10 } as const;
export const COLUNAS_PAISAGEM = 9;
export const ESCALA_MINIMA = 0.55;
/** Redução aceite em A4 paisagem antes de passar a A3 (legível e imprimível em qualquer impressora). */
export const ESCALA_A4_ANTES_A3 = 0.85;
/** Folga de medição (px) para arredondamentos de sub-píxel. */
export const TOLERANCIA_PX = 8;

interface Candidato {
  papel: 'A4' | 'A3';
  orientacao: 'retrato' | 'paisagem';
  larguraUtilMm: number;
}

const DIMENSOES = { A4: { curto: 210, longo: 297 }, A3: { curto: 297, longo: 420 } } as const;

function candidato(papel: 'A4' | 'A3', orientacao: 'retrato' | 'paisagem'): Candidato {
  const d = DIMENSOES[papel];
  const largura = orientacao === 'retrato' ? d.curto : d.longo;
  return { papel, orientacao, larguraUtilMm: largura - MARGENS_MM.esquerda - MARGENS_MM.direita };
}

export const mmParaPx = (mm: number) => Math.round(mm * PX_POR_MM);

export const FORMATO_PADRAO: FormatoPagina = { ...candidato('A4', 'retrato'), escala: 1, quebrarTexto: false };

/** Formatos possíveis, pela ordem de preferência, para as opções pedidas. */
export function candidatos(papel: Papel = 'auto', orientacao: Orientacao = 'auto', colunas = 0): Candidato[] {
  let lista: Candidato[];
  if (papel === 'A4') lista = [candidato('A4', 'retrato'), candidato('A4', 'paisagem')];
  else if (papel === 'A3') lista = [candidato('A3', 'retrato'), candidato('A3', 'paisagem')];
  else if (orientacao === 'retrato') lista = [candidato('A4', 'retrato'), candidato('A3', 'retrato')];
  // A3 retrato tem a mesma largura útil que A4 paisagem: na escolha automática fica de fora.
  else lista = [candidato('A4', 'retrato'), candidato('A4', 'paisagem'), candidato('A3', 'paisagem')];

  if (orientacao !== 'auto') lista = lista.filter((c) => c.orientacao === orientacao);
  else if (colunas >= COLUNAS_PAISAGEM) {
    const paisagem = lista.filter((c) => c.orientacao === 'paisagem');
    if (paisagem.length) lista = paisagem;
  }
  return lista;
}

export interface EntradaDecisao {
  /** Largura natural (px) do conteúdo posto numa coluna com `larguraPx`; `quebrar` = células com quebra de linha. */
  medir: (larguraPx: number, quebrar: boolean) => number;
  colunas?: number;
  papel?: Papel;
  orientacao?: Orientacao;
}

/** Escolhe o primeiro formato onde o conteúdo cabe; se nenhum serve, reduz a escala no maior. */
export function decidirFormato({ medir, colunas = 0, papel = 'auto', orientacao = 'auto' }: EntradaDecisao): FormatoPagina {
  const lista = candidatos(papel, orientacao, colunas);
  for (const [i, c] of lista.entries()) {
    const px = mmParaPx(c.larguraUtilMm);
    const natural = medir(px, false);
    if (natural <= px + TOLERANCIA_PX) return { ...c, escala: 1, quebrarTexto: false };
    // A4 paisagem com uma pequena redução antes de saltar para A3
    const seguinte = lista[i + 1];
    if (c.papel === 'A4' && c.orientacao === 'paisagem' && seguinte?.papel === 'A3' && px / natural >= ESCALA_A4_ANTES_A3) {
      return { ...c, escala: Math.floor((px / natural) * 100) / 100, quebrarTexto: false };
    }
  }
  const maior = lista[lista.length - 1];
  const px = mmParaPx(maior.larguraUtilMm);
  const natural = medir(px, false);
  let escala = px / natural;
  let quebrarTexto = false;
  if (escala < ESCALA_MINIMA) {
    // Nem a 55 % cabe: deixa o texto quebrar numa coluna com a largura equivalente à escala mínima.
    quebrarTexto = true;
    const larguraEscalada = Math.floor(px / ESCALA_MINIMA);
    const comQuebra = medir(larguraEscalada, true);
    escala = comQuebra <= larguraEscalada + TOLERANCIA_PX ? ESCALA_MINIMA : px / comQuebra;
  }
  // Arredonda para baixo (nunca ultrapassar a largura útil).
  return { ...maior, escala: Math.max(0.2, Math.floor(Math.min(1, escala) * 100) / 100), quebrarTexto };
}

/** Texto CSS seguro para `content: "…"`. */
export function textoCss(texto: string): string {
  return `"${texto.replace(/[\\"]/g, (c) => `\\${c}`).replace(/[\r\n]+/g, ' ').replace(/[<>]/g, ' ')}"`;
}

/** Regra `@page` do formato (com «Página X de Y» e o rodapé nas caixas de margem, onde o navegador suportar). */
export function cssPagina(f: FormatoPagina, rodape?: string | null): string {
  const m = MARGENS_MM;
  const caixaRodape = rodape ? `@bottom-left { content: ${textoCss(rodape.slice(0, 300))}; font: 7.5pt Arial, sans-serif; color: #555; vertical-align: top; padding-top: 3mm; }` : '';
  return `@page {
  size: ${f.papel} ${f.orientacao === 'retrato' ? 'portrait' : 'landscape'} !important;
  margin: ${m.topo}mm ${m.direita}mm ${m.baixo}mm ${m.esquerda}mm !important;
  ${caixaRodape}
  @bottom-right { content: "Página " counter(page) " de " counter(pages); font: 7.5pt Arial, sans-serif; color: #555; vertical-align: top; padding-top: 3mm; }
}`;
}

export function descreverFormato(f: FormatoPagina): string {
  return `${f.papel} ${f.orientacao}${f.escala < 1 ? ` · ${Math.round(f.escala * 100)} %` : ''}`;
}
