/**
 * Apoio à impressão/PDF do Orçamento com o motor comum (`@/componentes/impressao`): grelha mensal (orçamentos e
 * previsões) e listas já carregadas. O logótipo e o nome da empresa são postos pelo motor.
 */
import { esc, prepararTexto, tabelaHtml, type ColunaImpressao, type PedidoImpressao } from '@/componentes/impressao';
import { colunasParaImpressao, type ColunaApi, type ExtrasColunaImpressao } from '@/componentes/TabelaApi';
import { MESES, sinal, totalAnual, totaisMensais } from './regras';
import type { Rubrica, TipoOrcamento } from './tipos';

interface LinhaGrelha {
  rotulo: string;
  grupo?: boolean;
  total?: boolean;
  valores: number[];
  notas?: string | null;
}

/** Linhas da grelha mensal (grupos, rubricas e totais de entradas, saídas e resultado/saldo), como no ecrã. */
export function linhasGrelha(tipo: TipoOrcamento, rubricas: Rubrica[], valores: Record<number, { valores: number[]; notas: string | null }>): LinhaGrelha[] {
  const res: LinhaGrelha[] = [];
  let grupo: string | null | undefined;
  const doGrupo = rubricas.filter((r) => r.ativo !== false || valores[r.id]);
  for (const r of doGrupo) {
    if (r.grupo !== grupo) {
      grupo = r.grupo;
      if (grupo) res.push({ rotulo: grupo, grupo: true, valores: [] });
    }
    res.push({ rotulo: `${r.codigo} ${r.nome}`, valores: valores[r.id]?.valores ?? Array(12).fill(0), notas: valores[r.id]?.notas ?? null });
  }
  const comNatureza = doGrupo.map((r) => ({ valores: valores[r.id]?.valores ?? [], natureza: r.natureza }));
  const exploracao = tipo === 'EXPLORACAO';
  res.push(
    { rotulo: exploracao ? 'Total proveitos' : 'Total recebimentos', total: true, valores: totaisMensais(comNatureza.filter((x) => sinal(x.natureza) > 0)).slice(0, 12) },
    { rotulo: exploracao ? 'Total custos' : 'Total pagamentos', total: true, valores: totaisMensais(comNatureza.filter((x) => sinal(x.natureza) < 0)).slice(0, 12) },
    { rotulo: exploracao ? 'Resultado' : 'Saldo do período', total: true, valores: totaisMensais(comNatureza, true).slice(0, 12) },
  );
  return res;
}

/** Grelha mensal impressa (12 meses + total; notas opcionais). Documento largo: paisagem/A3 automáticos. */
export function grelhaHtml(tipo: TipoOrcamento, rubricas: Rubrica[], valores: Record<number, { valores: number[]; notas: string | null }>, comNotas = false, rotulosMeses = MESES): string {
  const linhas = linhasGrelha(tipo, rubricas, valores);
  const vazio = (l: LinhaGrelha) => l.grupo;
  const colunas: ColunaImpressao<LinhaGrelha>[] = [
    { titulo: 'Rubrica', valor: (l) => l.rotulo },
    ...rotulosMeses.map((m, i): ColunaImpressao<LinhaGrelha> => ({ titulo: m, valor: (l) => (vazio(l) ? '' : l.valores[i] ?? 0), formato: 'moeda' })),
    { titulo: 'Total', valor: (l) => (vazio(l) ? '' : totalAnual(l.valores)), formato: 'moeda' },
    ...(comNotas ? [{ titulo: 'Notas', valor: (l: LinhaGrelha) => l.notas ?? '', quebrar: true } as ColunaImpressao<LinhaGrelha>] : []),
  ];
  // Grupos e totais em negrito (classe da linha de grupo/total do motor).
  return marcarLinhas(tabelaHtml({ colunas, linhas }), linhas);
}

/** Acrescenta as classes de grupo/total do motor (imp-grupo / imp-subtotal) às linhas correspondentes. */
function marcarLinhas(html: string, linhas: LinhaGrelha[]): string {
  let n = -1;
  return html.replace(/<tbody>([\s\S]*)<\/tbody>/, (_, corpo: string) =>
    `<tbody>${corpo.replace(/<tr>/g, () => {
      n += 1;
      const l = linhas[n];
      return l?.grupo ? '<tr class="imp-grupo">' : l?.total ? '<tr class="imp-subtotal">' : '<tr>';
    })}</tbody>`);
}

/** HTML de uma tabela do ecrã (colunas Ant Design → texto do `render`; colunas sem título ficam de fora). */
export async function htmlTabela<T>(colunas: ColunaApi<T>[], linhas: T[], totais?: string): Promise<string> {
  await prepararTexto();
  const temTotais = colunas.some((c) => (c as ExtrasColunaImpressao<T>).totalImpressao);
  return tabelaHtml({ colunas: colunasParaImpressao(colunas, linhas), linhas, totais: temTotais ? totais ?? 'Total' : false });
}

/** Pedido de impressão de uma tabela já carregada no ecrã. */
export async function pedidoTabela<T>(o: Omit<PedidoImpressao, 'conteudo'> & { colunas: ColunaApi<T>[]; linhas: T[]; totais?: string; antes?: string }): Promise<PedidoImpressao> {
  const { colunas, linhas, totais, antes, ...resto } = o;
  return { ...resto, conteudo: (antes ?? '') + (await htmlTabela(colunas, linhas, totais)) };
}

/** Secção com título. */
export function seccaoHtml(titulo: string, html: string): string {
  return `<section><h3 style="font-size:10.5pt;margin:4mm 0 1.5mm">${esc(titulo)}</h3>${html}</section>`;
}
