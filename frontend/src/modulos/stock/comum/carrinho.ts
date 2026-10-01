/** Carrinho da venda ao balcão do POS de armazém (só no cliente; o servidor valida o stock ao emitir a guia). */
export interface ItemCarrinho {
  produto_id: number;
  codigo: string | null;
  nome: string;
  quantidade: number;
  disponivel: number;
}

const r3 = (v: number) => Math.round(v * 1000) / 1000;

/** Acrescenta 1 unidade (ou soma à linha existente), sem ultrapassar o stock disponível. */
export function adicionarAoCarrinho(carrinho: ItemCarrinho[], p: Omit<ItemCarrinho, 'quantidade'>, quantidade = 1): ItemCarrinho[] {
  const existente = carrinho.find((i) => i.produto_id === p.produto_id);
  if (existente) return alterarQuantidade(carrinho, p.produto_id, existente.quantidade + quantidade);
  const q = r3(Math.min(quantidade, p.disponivel));
  return q > 0 ? [...carrinho, { ...p, quantidade: q }] : carrinho;
}

/** Muda a quantidade (limitada a [0, disponível]); quantidade 0 retira a linha. */
export function alterarQuantidade(carrinho: ItemCarrinho[], produtoId: number, quantidade: number): ItemCarrinho[] {
  return carrinho
    .map((i) => (i.produto_id === produtoId ? { ...i, quantidade: r3(Math.max(0, Math.min(quantidade, i.disponivel))) } : i))
    .filter((i) => i.quantidade > 0);
}

export function totalUnidades(carrinho: ItemCarrinho[]): number {
  return r3(carrinho.reduce((t, i) => t + i.quantidade, 0));
}
