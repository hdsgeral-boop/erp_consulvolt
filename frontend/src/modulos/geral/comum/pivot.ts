/**
 * Análise dinâmica (cubo e BI): tipos da API (ServicoCubo::montar) e transformação do resultado agregado no servidor em
 * linhas/colunas de uma tabela, em séries de gráfico e em CSV. Tudo puro (testável).
 */

export interface DimensaoCubo {
  id: string;
  rotulo: string;
}

export interface MedidaCubo {
  id: string;
  rotulo: string;
  formato: string;
}

export interface ConjuntoCubo {
  id: string;
  nome: string;
  icone?: string | null;
  dimensoes: DimensaoCubo[];
  medidas: MedidaCubo[];
  agregacoes: string[];
  padrao: { linhas: string[]; colunas: string[]; medidas: PedidoMedida[] };
  opcoes: string[];
  holding?: boolean;
}

export interface PedidoMedida {
  medida: string | null;
  agregacao: string;
}

export interface ResultadoCubo {
  conjunto: { id: string; nome: string };
  periodo: { data_inicio: string | null; data_fim: string | null };
  linhas: DimensaoCubo[];
  colunas: DimensaoCubo[];
  medidas: { medida: string | null; agregacao: string; rotulo: string; formato: string }[];
  chaves_colunas: (string | null)[][];
  resultado: { chave: (string | null)[]; valores: ((string | number | null)[] | null)[]; total: (string | number | null)[] | null }[];
  totais_colunas: ((string | number | null)[] | null)[];
  total_geral: (string | number | null)[] | null;
  duracao_ms?: number;
}

export const AGREGACOES: Record<string, string> = { soma: 'Soma', media: 'Média', minimo: 'Mínimo', maximo: 'Máximo', contagem: 'Contagem' };

/** Limites do servidor (ServicoCubo::MAX_*). */
export const LIMITES_CUBO = { linhas: 4, colunas: 3, medidas: 6 };

export const VAZIO = '(vazio)';

export interface ColunaPivot {
  /** chave do valor na linha da tabela */
  chave: string;
  /** cabeçalho (valores da chave de coluna, unidos por « · ») */
  grupo: string;
  /** rótulo da medida */
  medida: string;
  formato: string;
  total: boolean;
}

export interface LinhaPivot {
  chave: string;
  dimensoes: string[];
  [coluna: string]: unknown;
}

export function rotuloChave(chave: (string | null)[]): string {
  return chave.map((c) => (c === null || c === '' ? VAZIO : c)).join(' · ');
}

/**
 * Converte o resultado do cubo em colunas e linhas: para cada chave de coluna × medida uma coluna (c{i}_{m}),
 * mais a coluna de total (t_{m}) quando há dimensões nas colunas; sem colunas, uma coluna por medida.
 * Acrescenta a linha de totais (total geral) no fim.
 */
export function construirPivot(r: ResultadoCubo): { colunas: ColunaPivot[]; linhas: LinhaPivot[]; totais: LinhaPivot | null } {
  const temColunas = r.colunas.length > 0;
  const colunas: ColunaPivot[] = [];
  if (temColunas) {
    r.chaves_colunas.forEach((k, i) =>
      r.medidas.forEach((m, j) => colunas.push({ chave: `c${i}_${j}`, grupo: rotuloChave(k), medida: m.rotulo, formato: m.formato, total: false })),
    );
  }
  r.medidas.forEach((m, j) => colunas.push({ chave: `t_${j}`, grupo: temColunas ? 'Total' : '', medida: m.rotulo, formato: m.formato, total: true }));

  const linhas: LinhaPivot[] = r.resultado.map((l, n) => {
    const linha: LinhaPivot = { chave: `${n}`, dimensoes: l.chave.map((c) => (c === null || c === '' ? VAZIO : c)) };
    if (temColunas) l.valores.forEach((v, i) => r.medidas.forEach((_, j) => (linha[`c${i}_${j}`] = v?.[j] ?? null)));
    r.medidas.forEach((_, j) => (linha[`t_${j}`] = l.total?.[j] ?? null));
    return linha;
  });

  let totais: LinhaPivot | null = null;
  if (r.total_geral || r.totais_colunas.length) {
    totais = { chave: 'total', dimensoes: r.linhas.length ? ['Total geral'] : ['Total'] };
    if (temColunas) r.totais_colunas.forEach((v, i) => r.medidas.forEach((_, j) => (totais![`c${i}_${j}`] = v?.[j] ?? null)));
    r.medidas.forEach((_, j) => (totais![`t_${j}`] = r.total_geral?.[j] ?? null));
  }
  return { colunas, linhas, totais };
}

/**
 * Séries para um gráfico de barras a partir da 1.ª medida: rótulos = linhas (até `maxLinhas`, por ordem do total);
 * séries = chaves de coluna (até 8) ou o total quando não há colunas.
 */
export function seriesPivot(r: ResultadoCubo, maxLinhas = 15): { rotulos: string[]; series: { rotulo: string; valores: (string | number | null)[] }[] } {
  const ordenadas = [...r.resultado].sort((a, b) => Math.abs(Number(b.total?.[0] ?? 0)) - Math.abs(Number(a.total?.[0] ?? 0))).slice(0, maxLinhas);
  const rotulos = ordenadas.map((l) => rotuloChave(l.chave) || 'Total');
  if (!r.colunas.length || r.chaves_colunas.length > 8) {
    return { rotulos, series: [{ rotulo: r.medidas[0]?.rotulo ?? 'Valor', valores: ordenadas.map((l) => l.total?.[0] ?? null) }] };
  }
  return {
    rotulos,
    series: r.chaves_colunas.map((k, i) => ({ rotulo: rotuloChave(k), valores: ordenadas.map((l) => l.valores[i]?.[0] ?? null) })),
  };
}

/** Linhas para CSV (dimensões + colunas da pivot), com cabeçalho. Números com ponto decimal (como a API). */
export function pivotParaCsv(r: ResultadoCubo): string[][] {
  const { colunas, linhas, totais } = construirPivot(r);
  const cabecalho = [...r.linhas.map((d) => d.rotulo), ...colunas.map((c) => (c.grupo ? `${c.grupo} — ${c.medida}` : c.medida))];
  const corpo = [...linhas, ...(totais ? [totais] : [])].map((l) => [
    ...r.linhas.map((_, i) => l.dimensoes[i] ?? ''),
    ...colunas.map((c) => (l[c.chave] === null || l[c.chave] === undefined ? '' : String(l[c.chave]))),
  ]);
  return [cabecalho, ...corpo];
}

/** Move um item de uma lista de dimensões para outra (ou reordena) respeitando o limite de destino. */
export function moverDimensao(
  estado: { linhas: string[]; colunas: string[] },
  dimensao: string,
  destino: 'linhas' | 'colunas' | 'nenhum',
): { linhas: string[]; colunas: string[] } {
  const linhas = estado.linhas.filter((d) => d !== dimensao);
  const colunas = estado.colunas.filter((d) => d !== dimensao);
  if (destino === 'linhas' && linhas.length < LIMITES_CUBO.linhas) linhas.push(dimensao);
  if (destino === 'colunas' && colunas.length < LIMITES_CUBO.colunas) colunas.push(dimensao);
  return { linhas, colunas };
}
