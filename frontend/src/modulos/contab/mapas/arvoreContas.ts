/**
 * Árvore das contas dos mapas (pedido: «exibir cálculos detalhados além das contas totalizadoras; abrir as contas de
 * movimento»): cada totalizadora abre até às contas de movimento e estas aos terceiros/movimentos. Regras puras (testáveis);
 * os valores vêm do servidor e não são recalculados aqui — só se arrumam em árvore.
 */
import { somar } from '@/utilitarios/decimal';

const semPontos = (c: string) => String(c ?? '').replace(/\./g, '');

/** Nó genérico com filhos opcionais (a coluna `filhos` das tabelas em árvore do Ant Design). */
export interface NoArvore {
  chave: string;
  filhos?: NoArvore[];
}

/**
 * Linhas planas do balancete (ordenadas por código; cada totalizadora antes das suas contas) → árvore: uma conta fica
 * debaixo da totalizadora mais próxima cujo código (sem pontos) é prefixo próprio do seu. As linhas sem totalizadora
 * ficam na raiz. Os filhos que a linha já traz (terceiros da conta de movimento) mantêm-se.
 */
export function arvorePorPrefixo<T extends { codigo_conta: string; totalizadora?: boolean; chave: string; filhos?: T[] }>(linhas: T[]): T[] {
  const raiz: T[] = [];
  const pilha: { prefixo: string; no: T }[] = [];
  for (const l of linhas) {
    const cod = semPontos(l.codigo_conta);
    while (pilha.length && !(cod.startsWith(pilha[pilha.length - 1].prefixo) && cod.length > pilha[pilha.length - 1].prefixo.length)) pilha.pop();
    const no = (l.totalizadora ? { ...l, filhos: [] as T[] } : { ...l }) as T;
    const pai = pilha[pilha.length - 1]?.no;
    if (pai) (pai.filhos as T[]).push(no);
    else raiz.push(no);
    if (l.totalizadora) pilha.push({ prefixo: cod, no });
  }
  // totalizadoras sem contas visíveis não mostram o botão de abrir
  const limpar = (nos: T[]): T[] =>
    nos.map((n) => (n.filhos === undefined ? n : n.filhos.length ? ({ ...n, filhos: limpar(n.filhos) } as T) : ({ ...n, filhos: undefined } as T)));
  return limpar(raiz);
}

/** Chaves de todos os nós com filhos (para «Expandir tudo»). */
export function chavesExpansiveis(nos: NoArvore[]): string[] {
  const saida: string[] = [];
  const visitar = (lista: NoArvore[]) => {
    for (const n of lista) {
      if (n.filhos?.length) {
        saida.push(n.chave);
        visitar(n.filhos);
      }
    }
  };
  visitar(nos);
  return saida;
}

/** Linhas da árvore pela ordem em que aparecem (impressão/Excel/CSV com todos os níveis), com a profundidade. */
export function achatar<T extends { filhos?: T[] }>(nos: T[], profundidade = 0): (T & { nivelArvore: number })[] {
  return nos.flatMap((n) => [{ ...n, nivelArvore: profundidade }, ...achatar(n.filhos ?? [], profundidade + 1)]);
}

/**
 * Contas de movimento agrupadas pela conta agregada do mapa (Balancete do Razão e Evolução por nível): a mesma regra do
 * servidor — os primeiros `nivel` caracteres do código (`left(codigo_conta, nivel)`).
 */
export function agruparPorNivel<T extends { codigo_conta: string }>(linhas: T[], nivel: number): Map<string, T[]> {
  const m = new Map<string, T[]>();
  for (const l of linhas) {
    const k = String(l.codigo_conta).slice(0, nivel);
    const lista = m.get(k);
    if (lista) lista.push(l);
    else m.set(k, [l]);
  }
  return m;
}

export interface ContaDoExtrato<M> {
  chave: string;
  codigo_conta: string;
  descricao_conta: string | null;
  movimentos: number;
  debito: string;
  credito: string;
  /** Movimento do período (débito − crédito). */
  saldo: string;
  filhos: M[];
}

/** Extracto de várias contas arrumado por conta (subtotais de débito, crédito e movimento líquido, e os movimentos de cada uma). */
export function extratoPorConta<M extends { codigo_conta: string; descricao_conta?: string | null; tipo_dc: 'D' | 'C'; valor: string }>(movimentos: M[]): ContaDoExtrato<M>[] {
  const porConta = new Map<string, M[]>();
  for (const m of movimentos) {
    const lista = porConta.get(m.codigo_conta);
    if (lista) lista.push(m);
    else porConta.set(m.codigo_conta, [m]);
  }
  return [...porConta.entries()]
    .sort(([a], [b]) => a.localeCompare(b, 'pt', { numeric: false }))
    .map(([codigo, ms]) => {
      const debito = somar(ms.filter((m) => m.tipo_dc === 'D').map((m) => m.valor));
      const credito = somar(ms.filter((m) => m.tipo_dc === 'C').map((m) => m.valor));
      return {
        chave: `conta:${codigo}`,
        codigo_conta: codigo,
        descricao_conta: ms[0]?.descricao_conta ?? null,
        movimentos: ms.length,
        debito,
        credito,
        saldo: (Math.round(Number(debito) * 100 - Number(credito) * 100) / 100).toFixed(2),
        filhos: ms,
      };
    });
}
