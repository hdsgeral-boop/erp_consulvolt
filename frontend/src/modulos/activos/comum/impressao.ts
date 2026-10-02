/**
 * Apoio à impressão/PDF do módulo Activos com o motor comum (`@/componentes/impressao`): fichas, mapas e listas
 * já carregadas. O logótipo e o nome da empresa são postos pelo motor — aqui só se monta o conteúdo.
 */
import { esc, prepararTexto, tabelaHtml, type PedidoImpressao } from '@/componentes/impressao';
import { colunasParaImpressao, obterTodasAsPaginas, type ColunaApi, type ExtrasColunaImpressao } from '@/componentes/TabelaApi';

/** Secção com título (ficha do activo: dados, amortizações, transferências…). */
export function seccaoHtml(titulo: string, html: string): string {
  return `<section><h3 style="font-size:10.5pt;margin:4mm 0 1.5mm">${esc(titulo)}</h3>${html}</section>`;
}

/** HTML de uma tabela do ecrã (colunas Ant Design → texto do `render`; colunas sem título ficam de fora). */
export async function htmlTabela<T>(colunas: ColunaApi<T>[], linhas: T[], totais?: string, vazio?: string): Promise<string> {
  await prepararTexto();
  const temTotais = colunas.some((c) => (c as ExtrasColunaImpressao<T>).totalImpressao);
  return tabelaHtml({ colunas: colunasParaImpressao(colunas, linhas), linhas, totais: temTotais ? totais ?? 'Total' : false, vazio });
}

/** Pedido de impressão de uma tabela já carregada no ecrã. */
export async function pedidoTabela<T>(o: Omit<PedidoImpressao, 'conteudo'> & { colunas: ColunaApi<T>[]; linhas: T[]; totais?: string }): Promise<PedidoImpressao> {
  const { colunas, linhas, totais, ...resto } = o;
  return { ...resto, conteudo: await htmlTabela(colunas, linhas, totais) };
}

/**
 * Pedido de impressão de uma lista paginada no servidor (useListaPaginada): lê TODAS as páginas com os filtros
 * actuais (até 5 000 linhas, como a TabelaApi) e imprime-as com as colunas do ecrã.
 */
export async function pedidoTodasPaginas<T>(url: string, filtros: Record<string, unknown>, o: Omit<PedidoImpressao, 'conteudo'> & { colunas: ColunaApi<T>[]; totais?: string }): Promise<PedidoImpressao> {
  const { colunas, totais, ...resto } = o;
  const dados = await obterTodasAsPaginas<T>(url, filtros);
  const aviso = dados.truncado ? `<p style="color:#a8071a">Mostradas ${dados.itens.length} de ${dados.total} linhas (limite de impressão).</p>` : '';
  return { ...resto, conteudo: aviso + (await htmlTabela(colunas, dados.itens, totais)) };
}
