/**
 * Organigrama do projecto em esquema (renderProjectOrganigrama, js/projectos_organigrama.js:236-360): a árvore das
 * posições desenhada em caixas e conectores, de cima para baixo (vertical) ou da esquerda para a direita (horizontal),
 * com os assistentes/apoio primeiro em cada nível e, na vista vertical, as subposições de uma posição «em coluna»
 * (disposicao = COLUNA) umas por baixo das outras, como no legado (.porg-arvore ul.coluna). Cálculo puro: posições
 * (x, y) de cada caixa, ligações e tamanho total — o desenho (HTML, SVG para impressão/PNG) usa o mesmo resultado.
 */
export type Orientacao = 'vertical' | 'horizontal';

export interface NoEntrada {
  id: number;
  pai: number | null;
  ordem?: number | null;
  apoio?: boolean | null;
  coluna?: boolean;
}

export interface NoPosto {
  id: number;
  pai: number | null;
  nivel: number;
  x: number;
  y: number;
  /** Altura do cartão (o texto quebra em linhas: os cartões crescem em vez de cortar o texto). */
  altura: number;
  filhos: number[];
  /** Tem filhos escondidos (ramo recolhido). */
  recolhido: boolean;
}

export interface Ligacao {
  de: number;
  para: number;
  /** Caminho SVG (cotovelos). */
  d: string;
}

export interface Disposicao {
  nos: Map<number, NoPosto>;
  ligacoes: Ligacao[];
  largura: number;
  altura: number;
  raizes: number[];
}

export interface Medidas {
  largura: number;
  altura: number;
  /** Espaço entre irmãos. */
  irmaos: number;
  /** Espaço entre níveis. */
  niveis: number;
  /** Recuo dos filhos em coluna. */
  recuo: number;
  margem: number;
}

export const MEDIDAS: Medidas = { largura: 236, altura: 128, irmaos: 22, niveis: 56, recuo: 28, margem: 24 };

/** Filhos de cada nó (pais inexistentes contam como raiz; ciclos são quebrados), apoio primeiro e depois a ordem. */
export function filhosPorPai(nos: NoEntrada[]): Map<number | null, NoEntrada[]> {
  const ids = new Set(nos.map((n) => n.id));
  const porId = new Map(nos.map((n) => [n.id, n]));
  const temCiclo = (n: NoEntrada) => {
    const vistos = new Set<number>([n.id]);
    let p = n.pai;
    while (p !== null && ids.has(p)) {
      if (vistos.has(p)) return true;
      vistos.add(p);
      p = porId.get(p)?.pai ?? null;
    }
    return false;
  };
  const mapa = new Map<number | null, NoEntrada[]>();
  for (const n of nos) {
    const pai = n.pai !== null && ids.has(n.pai) && n.pai !== n.id && !temCiclo(n) ? n.pai : null;
    mapa.set(pai, [...(mapa.get(pai) ?? []), n]);
  }
  for (const l of mapa.values()) l.sort((a, b) => Number(!!b.apoio) - Number(!!a.apoio) || (a.ordem ?? 0) - (b.ordem ?? 0) || a.id - b.id);
  return mapa;
}

/** Antepassados de um nó (do pai até à raiz). */
export function antepassados(id: number, pais: Map<number, number | null>): number[] {
  const saida: number[] = [];
  let p = pais.get(id) ?? null;
  let guarda = 0;
  while (p !== null && guarda++ < 500) {
    saida.push(p);
    p = pais.get(p) ?? null;
  }
  return saida;
}

/** Descendentes (todos os níveis) de um nó. */
export function descendentes(id: number, filhos: Map<number | null, NoEntrada[]>): number[] {
  const saida: number[] = [];
  const pilha = [...(filhos.get(id) ?? [])];
  while (pilha.length) {
    const n = pilha.pop()!;
    saida.push(n.id);
    pilha.push(...(filhos.get(n.id) ?? []));
  }
  return saida;
}

/**
 * Disposição da árvore. Vertical: níveis de cima para baixo, irmãos lado a lado, o pai centrado sobre os filhos; cada nível
 * tem a altura do cartão mais alto (os cartões do nível alinham pelo topo). Horizontal: níveis da esquerda para a direita,
 * irmãos uns por baixo dos outros, cada um com a sua altura. `recolhidos`: ramos fechados. `alturas`: altura de cada cartão
 * (omissão: `m.altura`).
 */
export function disporArvore(nos: NoEntrada[], orientacao: Orientacao, recolhidos: Set<number> = new Set(), m: Medidas = MEDIDAS,
  alturas?: Map<number, number>): Disposicao {
  const filhos = filhosPorPai(nos);
  const porId = new Map(nos.map((n) => [n.id, n]));
  const visiveis = (id: number) => (recolhidos.has(id) ? [] : filhos.get(id) ?? []);
  const vertical = orientacao === 'vertical';
  const alt = (id: number) => Math.max(m.altura, alturas?.get(id) ?? m.altura);
  // amplitude (ao longo do nível) de um cartão
  const ampNo = (id: number) => (vertical ? m.largura : alt(id));
  const emColuna = (id: number) => vertical && !!porId.get(id)?.coluna && visiveis(id).length > 0 && visiveis(id).every((f) => visiveis(f.id).length === 0);
  const raizes = filhos.get(null) ?? [];

  // vertical: altura de cada nível = o cartão mais alto do nível (as subposições «em coluna» empilham-se e não contam)
  const alturaNivel: number[] = [];
  if (vertical) {
    const percorrer = (n: NoEntrada, nivel: number) => {
      alturaNivel[nivel] = Math.max(alturaNivel[nivel] ?? 0, alt(n.id));
      if (!emColuna(n.id)) visiveis(n.id).forEach((f) => percorrer(f, nivel + 1));
    };
    raizes.forEach((r) => percorrer(r, 0));
  }
  const profNivel = (nivel: number) => (vertical ? alturaNivel[nivel] ?? m.altura : m.largura);

  // amplitude ocupada por cada sub-árvore
  const ampl = new Map<number, number>();
  const medir = (id: number): number => {
    const fs = visiveis(id);
    const proprio = ampNo(id);
    let a = proprio;
    if (fs.length) {
      if (emColuna(id)) a = Math.max(proprio, m.recuo + m.largura);
      else a = Math.max(proprio, fs.reduce((s, f) => s + medir(f.id), 0) + m.irmaos * (fs.length - 1));
    }
    ampl.set(id, a);
    return a;
  };
  raizes.forEach((r) => medir(r.id));

  const postos = new Map<number, NoPosto>();
  const ligacoes: Ligacao[] = [];
  // posiciona a sub-árvore com início `ini` na amplitude e o nível em `p` na profundidade (canto superior esquerdo)
  const colocar = (n: NoEntrada, ini: number, p: number, nivel: number, fixo?: { x: number; y: number }) => {
    const proprio = ampNo(n.id);
    const a = ampl.get(n.id) ?? proprio;
    const fs = visiveis(n.id);
    const coluna = emColuna(n.id);
    const centro = coluna ? ini + proprio / 2 : ini + a / 2;
    const xy = fixo ?? (vertical ? { x: centro - proprio / 2, y: p } : { x: p, y: centro - proprio / 2 });
    postos.set(n.id, { id: n.id, pai: n.pai !== null && porId.has(n.pai) ? n.pai : null, nivel, ...xy, altura: alt(n.id), filhos: fs.map((f) => f.id),
      recolhido: recolhidos.has(n.id) && (filhos.get(n.id)?.length ?? 0) > 0 });
    if (coluna) {
      // filhos em coluna (vertical): uns por baixo dos outros, recuados à direita da linha que desce do pai
      let y = xy.y + alt(n.id) + m.niveis / 2;
      fs.forEach((f) => {
        colocar(f, 0, 0, nivel + 1, { x: xy.x + m.recuo, y });
        y += alt(f.id) + m.irmaos / 2;
      });
      return;
    }
    let cursor = ini + (a - (fs.reduce((s, f) => s + (ampl.get(f.id) ?? ampNo(f.id)), 0) + m.irmaos * Math.max(0, fs.length - 1))) / 2;
    fs.forEach((f) => {
      colocar(f, cursor, p + profNivel(nivel) + m.niveis, nivel + 1);
      cursor += (ampl.get(f.id) ?? ampNo(f.id)) + m.irmaos;
    });
  };
  let cursor = m.margem;
  raizes.forEach((r) => {
    colocar(r, cursor, m.margem, 0);
    cursor += (ampl.get(r.id) ?? ampNo(r.id)) + m.irmaos * 2;
  });

  // ligações (cotovelos)
  for (const n of postos.values()) {
    const coluna = emColuna(n.id);
    for (const fid of n.filhos) {
      const f = postos.get(fid);
      if (!f) continue;
      let d: string;
      if (coluna) {
        const x0 = n.x + m.recuo / 2;
        d = `M${x0},${n.y + n.altura} V${f.y + f.altura / 2} H${f.x}`;
      } else if (vertical) {
        const x0 = n.x + m.largura / 2;
        const x1 = f.x + m.largura / 2;
        const ym = f.y - m.niveis / 2;
        d = `M${x0},${n.y + n.altura} V${ym} H${x1} V${f.y}`;
      } else {
        const y0 = n.y + n.altura / 2;
        const y1 = f.y + f.altura / 2;
        const xm = n.x + m.largura + m.niveis / 2;
        d = `M${n.x + m.largura},${y0} H${xm} V${y1} H${f.x}`;
      }
      ligacoes.push({ de: n.id, para: fid, d });
    }
  }
  const xs = [...postos.values()];
  const largura = xs.length ? Math.max(...xs.map((n) => n.x + m.largura)) + m.margem : 0;
  const altura = xs.length ? Math.max(...xs.map((n) => n.y + n.altura)) + m.margem : 0;
  return { nos: postos, ligacoes, largura, altura, raizes: raizes.map((r) => r.id) };
}

/** Escala para caber a árvore na área visível (nunca acima de 1 nem abaixo de 0,2). */
export function escalaAjustada(largura: number, altura: number, areaL: number, areaA: number): number {
  if (!largura || !altura || !areaL || !areaA) return 1;
  return Math.max(0.2, Math.min(1, (areaL - 16) / largura, (areaA - 16) / altura));
}

/** Iniciais para o avatar (primeiro e último nome). */
export function iniciais(nome: string | null | undefined): string {
  const p = String(nome ?? '').trim().split(/\s+/).filter(Boolean);
  if (!p.length) return '?';
  return ((p[0][0] ?? '') + (p.length > 1 ? p[p.length - 1][0] ?? '' : '')).toUpperCase();
}
