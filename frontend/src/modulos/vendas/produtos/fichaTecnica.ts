import { esc, pares, tabelaHtml, type PedidoImpressao } from '@/componentes/impressao';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { CONTAS_CATEGORIA, NIVEIS, type ProdutoFicha } from './formularioProduto';

/** Dimensões «C × L × A unidade» (só as preenchidas); vazio quando não há nenhuma. */
export function textoDimensoes(f: ProdutoFicha['ficha_tecnica']): string {
  if (!f) return '';
  const medidas = [f.comprimento, f.largura, f.altura].map((x) => (x === null || x === undefined || x === '' ? null : formatarNumero(x)));
  if (medidas.every((x) => x === null)) return '';
  return `${medidas.map((x) => x ?? '—').join(' × ')}${f.unidade_dimensao ? ` ${f.unidade_dimensao}` : ''}`;
}

/** Peso «x kg»; vazio quando não há. */
const peso = (x: string | null | undefined) => (x === null || x === undefined || x === '' ? '' : `${formatarNumero(x)} kg`);

/**
 * Ficha técnica do produto para impressão/PDF (motor comum): identificação, especificações, características, níveis de
 * stock, contas herdadas da categoria e observações.
 */
export function pedidoFichaTecnica(p: ProdutoFicha, stockActual?: string | null): PedidoImpressao {
  const f = p.ficha_tecnica;
  const blocos: string[] = [];
  blocos.push('<h3>Identificação</h3>');
  blocos.push(pares([
    ['Código', p.codigo ?? '—'],
    ['Designação', p.nome],
    ['Categoria', p.categoria ?? '—'],
    ['Unidade', p.unidade_fe ?? 'UN'],
    ['Preço unitário', `${formatarKz(p.preco_unitario ?? 0)} Kz`],
    ['IVA', `${formatarNumero(p.taxa_imposto ?? 0)}%${p.codigo_isencao_fe ? ` (${p.codigo_isencao_fe})` : ''}`],
  ]));
  blocos.push('<h3>Especificações</h3>');
  blocos.push(pares([
    ['Marca', f?.marca || '—'],
    ['Modelo', f?.modelo || '—'],
    ['Dimensões (C × L × A)', textoDimensoes(f) || '—'],
    ['Peso líquido', peso(f?.peso_liquido) || '—'],
    ['Peso bruto', peso(f?.peso_bruto) || '—'],
    ['Tipo', [p.movimenta_stock && 'Artigo de stock', p.e_servico && 'Serviço', p.e_ativo_imobilizado && 'Activo imobilizado'].filter(Boolean).join(', ') || '—'],
  ]));
  if (f?.caracteristicas?.length) {
    blocos.push('<h3>Composição / características</h3>');
    blocos.push(tabelaHtml({ colunas: [{ titulo: 'Característica', valor: (c: { chave: string; valor: string }) => c.chave }, { titulo: 'Valor', valor: (c) => c.valor, quebrar: true }], linhas: f.caracteristicas }));
  }
  if (p.movimenta_stock) {
    blocos.push('<h3>Níveis de stock</h3>');
    blocos.push(pares([
      ...NIVEIS.map(([k, rotulo]): [string, string] => [rotulo, p.niveis_stock?.[k] ? formatarNumero(p.niveis_stock[k]) : '—']),
      ...(stockActual !== undefined && stockActual !== null ? [['Stock actual', formatarNumero(stockActual)] as [string, string]] : []),
    ], 4));
  }
  blocos.push('<h3>Contas contabilísticas (da categoria)</h3>');
  blocos.push(pares(CONTAS_CATEGORIA.map((c): [string, string] => [c.rotulo, p.contas?.[c.chave] ?? '—'])));
  if (f?.observacoes_tecnicas) blocos.push(`<h3>Observações</h3><p style="white-space:pre-wrap">${esc(f.observacoes_tecnicas)}</p>`);
  return {
    titulo: `Ficha técnica — ${p.codigo ? `${p.codigo} ` : ''}${p.nome}`,
    conteudo: blocos.join(''),
    orientacao: 'retrato',
  };
}
