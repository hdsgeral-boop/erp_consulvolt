import { contemTexto, normalizar } from '@/modulos/compras/comum/lista';
import type { ProdutoPOS } from './tipos';

/** Produtos vendáveis na frente de caixa: exclui os quartos (vendem-se pelo check-out da hotelaria). */
export function produtosVendaveis(produtos: ProdutoPOS[]): ProdutoPOS[] {
  return produtos.filter((p) => !p.e_quarto);
}

/** Pesquisa por código ou nome (sem acentos), opcionalmente por categoria. Os códigos exactos aparecem primeiro. */
export function filtrarProdutos(produtos: ProdutoPOS[], pesquisa: string, categoria?: number | null): ProdutoPOS[] {
  const p = normalizar(pesquisa.trim());
  const lista = produtos.filter((x) => (!categoria || x.categoria_produto_id === categoria) && contemTexto(pesquisa, x.codigo, x.nome));
  if (!p) return lista;
  const exacto = (x: ProdutoPOS) => (x.codigo && normalizar(x.codigo.trim()) === p ? 0 : 1);
  return [...lista].sort((a, b) => exacto(a) - exacto(b));
}

/**
 * Leitura rápida (leitor de código de barras ou Enter na pesquisa): «3*COD» ou «COD*3» indica a quantidade.
 * Devolve o produto com o código exacto ou, se a pesquisa só tiver um resultado, esse.
 */
export function lerCodigo(produtos: ProdutoPOS[], texto: string): { produto: ProdutoPOS; quantidade: number } | null {
  let t = texto.trim();
  let quantidade = 1;
  const m = /^(\d+(?:[.,]\d+)?)\s*\*\s*(.+)$/.exec(t) ?? /^(.+?)\s*\*\s*(\d+(?:[.,]\d+)?)$/.exec(t);
  if (m) {
    const [a, b] = [m[1], m[2]];
    const numA = /^\d+(?:[.,]\d+)?$/.test(a);
    quantidade = Number((numA ? a : b).replace(',', '.'));
    t = (numA ? b : a).trim();
  }
  if (!t || !(quantidade > 0)) return null;
  const alvo = normalizar(t);
  const exacto = produtos.find((p) => p.codigo && normalizar(p.codigo.trim()) === alvo);
  if (exacto) return { produto: exacto, quantidade };
  const candidatos = filtrarProdutos(produtos, t);
  return candidatos.length === 1 ? { produto: candidatos[0], quantidade } : null;
}
