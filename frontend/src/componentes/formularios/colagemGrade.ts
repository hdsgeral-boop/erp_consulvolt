import { converterTexto, mapearColagem, partirTexto } from './conversoes';
import type { CampoFormulario } from './tipos';

/**
 * Colagem do Excel para grades que não usam o `PainelLinhas` (editores de linhas dentro de um `Form.List` próprio:
 * linhas de produtos de um documento, linhas a débito/crédito, contagens de inventário, etc.). Reaproveita as mesmas
 * conversões do `PainelLinhas` (`conversoes.ts`), para que datas, números e listas se leiam da mesma forma em todo o
 * sistema; só a gravação (linha a linha vs. formulário único) fica a cargo de cada ecrã.
 */
export interface ResultadoColagemGrade {
  /** Uma entrada por linha colada; cada uma só tem as colunas reconhecidas (as outras ficam por preencher). */
  linhas: Record<string, unknown>[];
  /** A 1.ª linha colada foi lida como cabeçalho (só possível na colagem em bloco, não na colagem numa célula). */
  comCabecalho: boolean;
  /** Quantos valores não vazios não foram reconhecidos (datas, números ou opções inválidas). */
  naoReconhecidos: number;
  /** Exemplos (até 5) dos valores não reconhecidos, por rótulo da coluna. */
  exemplos: Record<string, string[]>;
}

/**
 * Converte o texto colado em linhas de valores.
 * - Colagem em bloco (`aPartirDaColuna` omitido, botão «Colar do Excel»): sem cabeçalho, as colunas lêem-se pela
 *   ordem de `campos`; com a 1.ª linha colada a cabeçalho reconhecido, pelo nome (ordem livre).
 * - Colagem directa numa célula (`aPartirDaColuna` = índice da coluna clicada): sempre posicional a partir dessa
 *   coluna, sem detectar cabeçalho — como o `PainelLinhas`.
 */
export function colagemParaLinhas(texto: string, campos: CampoFormulario[], aPartirDaColuna?: number): ResultadoColagemGrade {
  const vazio: ResultadoColagemGrade = { linhas: [], comCabecalho: false, naoReconhecidos: 0, exemplos: {} };
  const celulas = partirTexto(texto);
  if (!celulas.length) return vazio;
  const posicional = aPartirDaColuna !== undefined;
  const alvo = posicional ? campos.slice(aPartirDaColuna) : campos;
  if (!alvo.length) return vazio;
  const { linhas: valoresTexto, comCabecalho } = posicional ? { linhas: celulas, comCabecalho: false } : mapearColagem(celulas, alvo);
  const exemplos: Record<string, string[]> = {};
  let naoReconhecidos = 0;
  const linhas = valoresTexto.map((vs) => {
    const valores: Record<string, unknown> = {};
    alvo.forEach((c, i) => {
      const t = vs[i];
      if (t === undefined || (!posicional && !t.trim())) return;
      const { ok, valor } = converterTexto(c, t);
      valores[c.nome] = valor;
      if (!ok) {
        naoReconhecidos += 1;
        const ex = (exemplos[c.rotulo] ??= []);
        if (ex.length < 5 && !ex.includes(t)) ex.push(t);
      }
    });
    return valores;
  });
  return { linhas, comCabecalho, naoReconhecidos, exemplos };
}

/** Linha e coluna da célula colada (procura `data-colar-linha`/`data-colar-coluna` a partir do alvo do evento). */
export function posicaoColagem(alvo: EventTarget | null): { linha: number; coluna: number } | null {
  const el = (alvo as HTMLElement | null)?.closest?.('[data-colar-coluna]') as HTMLElement | null;
  const linhaEl = el?.closest('[data-colar-linha]') as HTMLElement | null;
  if (!el || !linhaEl) return null;
  const coluna = Number(el.getAttribute('data-colar-coluna'));
  const linha = Number(linhaEl.getAttribute('data-colar-linha'));
  return Number.isFinite(coluna) && Number.isFinite(linha) ? { linha, coluna } : null;
}

/** O texto colado tem mais do que uma célula (tabulação, `|`, `;` ou várias linhas) — vale a pena tratá-lo como colagem de grade. */
export function pareceColagemDeGrade(texto: string): boolean {
  return /[\t\n|;]/.test(texto.replace(/\r?\n$/, ''));
}
