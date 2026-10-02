import { tabelaHtml, type PedidoImpressao } from '@/componentes/impressao';
import { somar } from '@/utilitarios/decimal';
import { formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { dataDoc, type DadosDocumentoComercial } from '@/modulos/vendas/impressao/documentoComercial';
import { rotuloEstado } from '@/modulos/compras/comum/estados';
import { TIPOS_GUIA, TIPOS_MOVIMENTO, type Extracto, type GuiaSaida, type LinhaStock } from './tipos';

/**
 * Mapas e documentos impressos do Armazém (motor comum): stock por armazém, extracto do artigo, guia de saída.
 */

/** Mapa de stock por armazém (agrupado por armazém com subtotal do valor e total geral). */
export function pedidoStockPorArmazem(linhas: LinhaStock[], filtros: (string | false | null | undefined)[] = []): PedidoImpressao {
  return {
    titulo: 'Mapa de stock por armazém',
    periodo: `Situação em ${formatarData(new Date().toISOString())}`,
    filtros: [...filtros, `${linhas.length} linha(s)`],
    conteudo: tabelaHtml({
      colunas: [
        { titulo: 'Código', valor: (l: LinhaStock) => l.codigo ?? '' },
        { titulo: 'Produto', valor: (l) => l.nome, quebrar: true },
        { titulo: 'Quantidade', valor: (l) => l.quantidade, formato: 'numero' },
        { titulo: 'Mínimo', valor: (l) => l.stock_minimo, formato: 'numero' },
        { titulo: 'Custo médio (Kz)', valor: (l) => l.custo_medio, formato: 'moeda' },
        { titulo: 'Valor (Kz)', valor: (l) => l.valor, formato: 'moeda', somar: true },
        { titulo: 'Ruptura', valor: (l) => (l.ruptura ? 'Sim' : '') },
      ],
      linhas: [...linhas].sort((a, b) => a.armazem.localeCompare(b.armazem, 'pt') || a.nome.localeCompare(b.nome, 'pt')),
      agrupar: { chave: (l) => l.armazem, subtotais: true },
      totais: 'Valor total do stock',
      vazio: 'Sem artigos.',
    }),
  };
}

/** Extracto de um artigo: saldo inicial, movimentos com saldo corrido e saldo final. */
export function pedidoExtractoArtigo(e: Extracto, nomeArmazem: (id: number | null) => string): PedidoImpressao {
  type M = Extracto['movimentos'][number];
  return {
    titulo: `Extracto do artigo ${e.produto.codigo ? `${e.produto.codigo} — ` : ''}${e.produto.nome}`,
    periodo: `${formatarData(e.de)} a ${formatarData(e.ate)}`,
    filtros: [
      e.armazem_id ? `Armazém: ${nomeArmazem(e.armazem_id)}` : 'Todos os armazéns',
      `Saldo inicial: ${formatarNumero(e.saldo_inicial.quantidade)} · ${formatarKz(e.saldo_inicial.valor)} Kz`,
      `Saldo final: ${formatarNumero(e.saldo_final.quantidade)} · ${formatarKz(e.saldo_final.valor)} Kz`,
    ],
    conteudo: tabelaHtml({
      colunas: [
        { titulo: 'Data', valor: (m: M) => m.data, formato: 'data' },
        { titulo: 'Tipo', valor: (m) => TIPOS_MOVIMENTO[m.tipo] ?? m.tipo },
        { titulo: 'Armazém', valor: (m) => nomeArmazem(m.armazem_id) },
        { titulo: 'Referência', valor: (m) => m.referencia ?? '', quebrar: true },
        { titulo: 'Entrada', valor: (m) => m.entrada, formato: 'numero' },
        { titulo: 'Saída', valor: (m) => m.saida, formato: 'numero' },
        { titulo: 'Custo unit. (Kz)', valor: (m) => m.custo_unitario, formato: 'moeda' },
        { titulo: 'Valor (Kz)', valor: (m) => m.valor, formato: 'moeda' },
        { titulo: 'Saldo qtd.', valor: (m) => m.saldo_quantidade, formato: 'numero', total: formatarNumero(e.saldo_final.quantidade) },
        { titulo: 'Saldo valor (Kz)', valor: (m) => m.saldo_valor, formato: 'moeda', total: formatarKz(e.saldo_final.valor) },
      ],
      linhas: e.movimentos,
      legenda: `Saldo inicial: ${formatarNumero(e.saldo_inicial.quantidade)} · ${formatarKz(e.saldo_inicial.valor)} Kz`,
      totais: 'Saldo final',
      vazio: 'Sem movimentos no período.',
    }),
  };
}

export function rotuloTipoGuia(g: Pick<GuiaSaida, 'tipo' | 'tipo_original'>): string {
  if (g.tipo === 'VENDA' && g.tipo_original === 'VENDA_BALCAO') return TIPOS_GUIA.VENDA_BALCAO;
  return TIPOS_GUIA[g.tipo] ?? g.tipo;
}

/** Guia de saída de stock → documento impresso (A4 retrato) com artigos, custos e assinaturas. */
export function dadosGuiaSaida(g: GuiaSaida, armazem: string | null): DadosDocumentoComercial {
  return {
    tipo: 'Guia de saída',
    numero: g.numero_documento,
    estado: g.estado === 'ANULADA' ? `ANULADA${g.motivo_anulacao ? ` — ${g.motivo_anulacao}` : ''}` : null,
    avisos: ['Documento interno de movimentação de stock'],
    entidade: g.area_rececao
      ? { rotulo: 'Área / sector que recebe', nome: g.area_rececao }
      : g.terceiro
        ? { rotulo: 'Destinatário', nome: g.terceiro.nome.trim(), nif: g.terceiro.nif ?? null }
        : null,
    meta: [
      ['Data', dataDoc(g.data)],
      ['Tipo', rotuloTipoGuia(g)],
      ['Armazém', armazem],
      ['Estado', rotuloEstado(g.estado)],
      ['Contabilização', g.contabilizado ? `Contabilizada${g.numero_lan_contabilizacao ? ` (${g.numero_lan_contabilizacao})` : ''}` : 'Por contabilizar'],
      ['Emitida por', g.criado_por],
    ],
    colunas: [{ titulo: 'Produto' }, { titulo: 'Quantidade', alinhar: 'direita' }, { titulo: 'Custo unit. (Kz)', alinhar: 'direita' }, { titulo: 'Valor (Kz)', alinhar: 'direita' }],
    linhas: (g.linhas ?? []).map((l) => [
      `${l.produto?.codigo ? `${l.produto.codigo} — ` : ''}${l.produto?.nome ?? `Produto #${l.produto_id}`}`,
      formatarNumero(l.quantidade),
      l.custo_unitario_kz ? formatarKz(l.custo_unitario_kz) : '',
      l.valor_kz ? formatarKz(l.valor_kz) : '',
    ]),
    totais: { total: somar((g.linhas ?? []).map((l) => l.valor_kz)), rotuloTotal: 'Valor total (custo)' },
    extenso: false,
    impostos: false,
    blocos: [['Observações', g.observacoes]],
    assinaturas: ['Entregou (armazém)', 'Recebeu'],
  };
}
