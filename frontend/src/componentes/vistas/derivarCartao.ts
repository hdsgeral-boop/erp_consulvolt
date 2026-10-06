import type { ColumnGroupType, ColumnType, ColumnsType } from 'antd/es/table';
import { Children, isValidElement, type ReactElement, type ReactNode } from 'react';

/**
 * Extras por coluna para a vista em grade (todos opcionais: sem nada, o cartão é derivado automaticamente).
 *
 * - título do cartão = coluna `principal`, senão a 1.ª coluna (se for um código/n.º e houver uma 2.ª, o código passa
 *   a subtítulo e a 2.ª a título);
 * - etiquetas (canto superior direito) = colunas `etiqueta` ou com título «Estado»/«Situação»;
 * - acções (rodapé) = coluna `accoes`, ou a coluna sem título com `render` (convenção dos ecrãs), ou chave «accoes»;
 * - campos = as restantes, pela ordem, até `maxCampos`.
 */
export interface ExtrasColunaVista {
  /** Título do cartão. */
  principal?: boolean;
  /** Subtítulo (linha pequena por cima do título). */
  subtitulo?: boolean;
  /** Etiqueta/estado no canto do cartão. */
  etiqueta?: boolean;
  /** Coluna de acções (vai para o rodapé do cartão). */
  accoes?: boolean;
  /** `false` = não aparece no cartão; `true` = aparece sempre (mesmo para além de `maxCampos`). */
  noCartao?: boolean;
  /** Rótulo no cartão (por omissão, o título da coluna). */
  rotuloCartao?: ReactNode;
}

export type ColunaVista<T> = ColumnsType<T>[number] & ExtrasColunaVista;
type Folha<T> = ColumnType<T> & ExtrasColunaVista;

export interface EstruturaCartao<T> {
  titulo?: Folha<T>;
  subtitulo?: Folha<T>;
  etiquetas: Folha<T>[];
  campos: Folha<T>[];
  accoes?: Folha<T>;
}

/** Texto simples de um nó React (títulos de colunas, rótulos acessíveis). */
export function textoSimples(no: ReactNode): string {
  if (no === null || no === undefined || typeof no === 'boolean') return '';
  if (typeof no === 'string' || typeof no === 'number') return String(no);
  if (Array.isArray(no)) return no.map(textoSimples).join('');
  if (isValidElement(no)) {
    const el = no as ReactElement<{ children?: ReactNode; title?: ReactNode }>;
    const filhos = Children.toArray(el.props.children);
    if (filhos.length) return filhos.map(textoSimples).join('');
    return typeof el.props.title === 'string' ? el.props.title : '';
  }
  return '';
}

export function folhasVista<T>(colunas: readonly ColunaVista<T>[]): Folha<T>[] {
  return colunas.flatMap((c) => {
    const filhos = (c as ColumnGroupType<T>).children;
    return filhos?.length ? folhasVista(filhos as ColunaVista<T>[]) : [c as Folha<T>];
  });
}

export function tituloColuna<T>(c: Folha<T>): string {
  return typeof c.title === 'function' ? '' : textoSimples(c.title as ReactNode).trim();
}

const RE_ACCOES = /^(ac?[cç][oõ]es|acoes|actions?|op[cç][oõ]es)$/i;
const RE_CODIGO = /^(c[oó]d(igo)?\.?|n\.?\s?[ºo°]\.?|n[uú]mero|ref(\.|er[eê]ncia)?|sigla|id)$/i;
const RE_ESTADO = /^(estado|situa[cç][aã]o|status)$/i;

export function eColunaAccoes<T>(c: Folha<T>): boolean {
  if (c.accoes !== undefined) return c.accoes;
  const chave = String(c.key ?? (Array.isArray(c.dataIndex) ? c.dataIndex.join('.') : c.dataIndex ?? ''));
  if (RE_ACCOES.test(chave)) return true;
  const titulo = tituloColuna(c);
  if (RE_ACCOES.test(titulo)) return true;
  return !titulo && !!c.render && (c.dataIndex === undefined || c.dataIndex === null);
}

/** Estrutura do cartão a partir das colunas da tabela (ver `ExtrasColunaVista`). */
export function derivarEstrutura<T>(colunas: readonly ColunaVista<T>[], maxCampos = 6): EstruturaCartao<T> {
  const visiveis = folhasVista(colunas).filter((c) => !c.hidden && c.noCartao !== false);
  const accoes = visiveis.find((c) => eColunaAccoes(c));
  const dados = visiveis.filter((c) => c !== accoes && !eColunaAccoes(c));

  let titulo = dados.find((c) => c.principal);
  let subtitulo = dados.find((c) => c.subtitulo && c !== titulo);
  if (!titulo) {
    const [primeira, segunda] = dados;
    if (primeira && segunda && RE_CODIGO.test(tituloColuna(primeira)) && !segunda.etiqueta && !RE_ESTADO.test(tituloColuna(segunda))) {
      titulo = segunda;
      subtitulo ??= primeira;
    } else titulo = primeira;
  }
  const resto = dados.filter((c) => c !== titulo && c !== subtitulo);
  const etiquetas = resto.filter((c) => c.etiqueta || (c.etiqueta === undefined && RE_ESTADO.test(tituloColuna(c)))).slice(0, 2);
  const semEtiquetas = resto.filter((c) => !etiquetas.includes(c));
  const forcados = semEtiquetas.filter((c) => c.noCartao === true);
  const livres = semEtiquetas.filter((c) => c.noCartao !== true);
  const vagas = Math.max(0, maxCampos - forcados.length);
  const escolhidos = new Set([...forcados, ...livres.slice(0, vagas)]);
  return { titulo, subtitulo, etiquetas, campos: semEtiquetas.filter((c) => escolhidos.has(c)), accoes };
}

export function valorDeCaminho<T>(linha: T, dataIndex: ColumnType<T>['dataIndex']): unknown {
  if (dataIndex === undefined || dataIndex === null) return undefined;
  const caminho = Array.isArray(dataIndex) ? dataIndex : [dataIndex];
  return caminho.reduce<unknown>((v, k) => (v && typeof v === 'object' ? (v as Record<string | number, unknown>)[k as string | number] : undefined), linha);
}

/** Conteúdo de uma célula tal como a tabela o mostra (`render` da coluna, ou o valor de `dataIndex`). */
export function conteudoCelula<T>(c: Folha<T>, linha: T, indice: number): ReactNode {
  const bruto = valorDeCaminho(linha, c.dataIndex);
  if (!c.render) {
    if (bruto === null || bruto === undefined || bruto === '') return null;
    if (typeof bruto === 'boolean') return bruto ? 'Sim' : 'Não';
    return typeof bruto === 'object' ? (isValidElement(bruto) ? bruto : JSON.stringify(bruto)) : String(bruto);
  }
  const r = c.render(bruto, linha, indice) as ReactNode | { children?: ReactNode };
  // render pode devolver { children, props } (RenderedCell)
  if (r && typeof r === 'object' && !Array.isArray(r) && !isValidElement(r) && 'children' in r) return r.children ?? null;
  return r as ReactNode;
}

/** `true` se o conteúdo não mostra nada (para escrever «—»). */
export function conteudoVazio(no: ReactNode): boolean {
  return no === null || no === undefined || no === '' || no === false || (Array.isArray(no) && no.every(conteudoVazio));
}
