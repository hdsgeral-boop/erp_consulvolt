/** M-15 — mesas do POS restaurante (contas por mesa no servidor). Tipos e regras puras, testáveis. */
import { deCentimos, paraCentimos } from '@/utilitarios/decimal';
import { itemDeProduto, type ItemCarrinho } from '../comum/calculos';
import type { ProdutoPOS } from '../comum/tipos';

export interface ResumoConta {
  id: number;
  versao: number;
  itens: number;
  total: string;
  operador: string | null;
  aberta_em: string | null;
  atualizado_em: string | null;
}

export interface MesaPOS {
  id: number;
  terminal_pos_id: number;
  nome: string;
  ordem: number;
  ativo: boolean;
  conta: ResumoConta | null;
}

export interface LinhaConta {
  produto_id: number;
  quantidade: string | number;
  preco_unitario?: string | null;
}

export interface ContaMesa extends ResumoConta {
  mesa: string;
  mesa_pos_id: number;
  estado: 'ABERTA' | 'FECHADA' | 'ANULADA';
  linhas: LinhaConta[];
  percentagem_desconto: string;
  cliente_id: number | null;
  observacoes: string | null;
}

/** Linhas guardadas na conta → carrinho do POS (produtos do catálogo; os que já não existem ficam de fora e contam-se). */
export function carrinhoDaConta(linhas: LinhaConta[], produtos: ProdutoPOS[]): { carrinho: ItemCarrinho[]; emFalta: number } {
  const porId = new Map(produtos.map((p) => [p.id, p]));
  let emFalta = 0;
  const carrinho: ItemCarrinho[] = [];
  for (const l of linhas) {
    const p = porId.get(Number(l.produto_id));
    if (!p) {
      emFalta += 1;
      continue;
    }
    const item = itemDeProduto(p, Number(l.quantidade));
    carrinho.push(l.preco_unitario !== undefined && l.preco_unitario !== null && l.preco_unitario !== '' ? { ...item, preco: paraCentimos(l.preco_unitario) } : item);
  }
  return { carrinho, emFalta };
}

/** Carrinho → linhas da conta (o preço só segue quando difere do catálogo, como na venda). */
export function linhasDaConta(carrinho: ItemCarrinho[]): LinhaConta[] {
  return carrinho.map((i) => ({ produto_id: i.produto_id, quantidade: i.quantidade, ...(i.preco !== i.preco_catalogo ? { preco_unitario: deCentimos(i.preco) } : {}) }));
}

/** Estado visual de uma mesa no mapa (como o legado: activa, ocupada ou livre). */
export function estadoMesa(m: MesaPOS, activa: number | null): 'ACTIVA' | 'OCUPADA' | 'LIVRE' {
  if (m.id === activa) return 'ACTIVA';
  return m.conta && m.conta.itens > 0 ? 'OCUPADA' : 'LIVRE';
}
