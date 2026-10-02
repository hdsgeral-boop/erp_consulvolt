import { tabelaHtml, type PedidoImpressao } from '@/componentes/impressao';
import { formatarData, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { dataDoc, type DadosDocumentoComercial } from '@/modulos/vendas/impressao/documentoComercial';
import { ROTULO_TIPO, type Disponibilidades, type DocumentoTesouraria, type ExtratoConta, type Pendente } from './api';

/**
 * Documento de tesouraria impresso (nota de pagamento / nota de recebimento), no formato do documento comercial,
 * A4 retrato: conta de banco/caixa, beneficiário/pagador, linhas (conta, terceiro, documento liquidado, débito/crédito),
 * valor por extenso, integração contabilística e assinaturas.
 */
export function dadosDocumentoTesouraria(d: DocumentoTesouraria): DadosDocumentoComercial {
  const pagamento = d.tipo === 'PAGAMENTO';
  const terceiros = [...new Map((d.linhas ?? []).filter((l) => l.terceiro).map((l) => [l.terceiro!.id, l.terceiro!])).values()];
  const estrangeira = d.codigo_moeda && d.codigo_moeda !== 'AOA';
  return {
    tipo: pagamento ? 'Nota de pagamento' : 'Nota de recebimento',
    numero: d.numero_documento ?? `#${d.id}`,
    estado: d.estado === 'ANULADO' ? `ANULADO${d.motivo_anulacao ? ` — ${d.motivo_anulacao}` : ''}` : null,
    entidade:
      terceiros.length === 1
        ? { rotulo: pagamento ? 'Beneficiário' : 'Pagador', nome: terceiros[0].nome.trim(), nif: terceiros[0].nif ?? null }
        : terceiros.length > 1
          ? { rotulo: pagamento ? 'Beneficiários' : 'Pagadores', nome: terceiros.map((t) => t.nome.trim()).join('; ') }
          : null,
    meta: [
      ['Data', dataDoc(d.data_documento)],
      ['Tipo', ROTULO_TIPO[d.tipo]],
      ['Conta de banco/caixa', d.conta_financeira],
      ['Referência', d.referencia],
      ['Moeda', estrangeira ? `${d.codigo_moeda} (câmbio ${d.taxa_cambio ?? '—'}; ${d.valor_total_moeda ?? ''} ${d.codigo_moeda})` : 'AOA'],
      ['Integração', d.numero_lan_contabilizacao ? `${d.numero_lan_contabilizacao} · ${formatarDataHora(d.integrado_em)}` : 'Por integrar'],
      ['Reconciliação', d.reconciliacao_codigo],
    ],
    colunas: [
      { titulo: 'Conta' },
      { titulo: 'Terceiro' },
      { titulo: 'Documento liquidado' },
      { titulo: 'Descrição' },
      { titulo: 'Débito (Kz)', alinhar: 'direita' },
      { titulo: 'Crédito (Kz)', alinhar: 'direita' },
    ],
    linhas: (d.linhas ?? []).map((l) => [
      l.codigo_conta,
      l.terceiro ? `${l.terceiro.nome.trim()}${l.terceiro.nif ? ` (NIF ${l.terceiro.nif})` : ''}` : l.terceiro_id ? `#${l.terceiro_id}` : '',
      l.numero_documento ?? '',
      l.descricao ?? '',
      l.tipo_dc === 'D' ? formatarKz(l.valor) : '',
      l.tipo_dc === 'C' ? formatarKz(l.valor) : '',
    ]),
    totais: { total: d.valor_total, rotuloTotal: pagamento ? 'Total pago' : 'Total recebido' },
    impostos: false,
    blocos: [['Descrição', d.descricao]],
    legal: ['Documento processado por computador.'],
    assinaturas: pagamento ? ['Elaborado / Aprovado', 'Recebi (beneficiário)'] : ['Entreguei (pagador)', 'Recebido por'],
  };
}

/** Mapa de disponibilidades (saldos 43/45 à data), agrupado por bancos e caixa, com subtotais e total. */
export function pedidoDisponibilidades(s: Disponibilidades): PedidoImpressao {
  return {
    titulo: 'Mapa de disponibilidades',
    periodo: `Saldos em ${formatarData(s.data)}`,
    filtros: [`Bancos (43): ${formatarKz(s.totais.bancos)} Kz`, `Caixa (45): ${formatarKz(s.totais.caixa)} Kz`],
    conteudo: tabelaHtml({
      colunas: [
        { titulo: 'Conta', valor: (l: Disponibilidades['contas'][number]) => l.codigo_conta },
        { titulo: 'Descrição', valor: (l) => l.descricao ?? '', quebrar: true },
        { titulo: 'Meio de pagamento', valor: (l) => (l.meio_pagamento ? `${l.meio_pagamento}${l.codigo_moeda && l.codigo_moeda !== 'AOA' ? ` (${l.codigo_moeda})` : ''}` : '') },
        { titulo: 'Saldo (Kz)', valor: (l) => l.saldo, formato: 'moeda', somar: true },
      ],
      linhas: [...s.contas].sort((a, b) => (a.tipo === b.tipo ? a.codigo_conta.localeCompare(b.codigo_conta) : a.tipo === 'BANCO' ? -1 : 1)),
      agrupar: { chave: (l) => (l.tipo === 'CAIXA' ? 'Caixa (45)' : 'Bancos (43)'), subtotais: true },
      totais: 'Total disponível',
    }),
  };
}

/** Extracto de uma conta de banco/caixa com saldo inicial, movimentos (saldo corrido) e totais. */
export function pedidoExtrato(e: ExtratoConta): PedidoImpressao {
  type M = ExtratoConta['movimentos'][number];
  return {
    titulo: `Extracto da conta ${e.codigo_conta}${e.descricao ? ` — ${e.descricao}` : ''}`,
    periodo: `${formatarData(e.data_inicio)} a ${formatarData(e.data_fim)}`,
    filtros: [`Saldo inicial: ${formatarKz(e.saldo_inicial)} Kz`, `Saldo final: ${formatarKz(e.saldo_final)} Kz`],
    conteudo: tabelaHtml({
      colunas: [
        { titulo: 'Data', valor: (l: M) => l.data_documento, formato: 'data' },
        { titulo: 'Diário', valor: (l) => l.diario ?? '' },
        { titulo: 'Lançamento', valor: (l) => l.numero_lan ?? '' },
        { titulo: 'Documento', valor: (l) => l.numero_documento ?? '' },
        { titulo: 'Terceiro', valor: (l) => l.terceiro?.nome?.trim() ?? '', quebrar: true },
        { titulo: 'Descrição', valor: (l) => l.descricao ?? '', quebrar: true },
        { titulo: 'Entrada (Kz)', valor: (l) => (l.tipo_dc === 'D' ? l.valor : null), formato: 'moeda', total: formatarKz(e.debito) },
        { titulo: 'Saída (Kz)', valor: (l) => (l.tipo_dc === 'C' ? l.valor : null), formato: 'moeda', total: formatarKz(e.credito) },
        { titulo: 'Saldo (Kz)', valor: (l) => l.saldo, formato: 'moeda', total: formatarKz(e.saldo_final) },
      ],
      linhas: e.movimentos,
      legenda: `Saldo inicial: ${formatarKz(e.saldo_inicial)} Kz`,
      totais: 'Totais do período / saldo final',
      vazio: 'Sem movimentos no período.',
    }),
  };
}

export const ROTULO_NATUREZA: Record<Pendente['natureza'], string> = { A_RECEBER: 'A receber', A_PAGAR: 'A pagar' };

/** Mapa de pendentes (documentos em aberto) agrupado por terceiro, com subtotais e total. */
export function pedidoPendentes(linhas: Pendente[], filtros: (string | false | null | undefined)[] = []): PedidoImpressao {
  return {
    titulo: 'Mapa de pendentes de terceiros',
    periodo: `Situação em ${formatarData(new Date().toISOString())}`,
    filtros,
    conteudo: tabelaHtml({
      colunas: [
        { titulo: 'Documento', valor: (p: Pendente) => p.numero_documento },
        { titulo: 'Data', valor: (p) => p.data_documento, formato: 'data' },
        { titulo: 'Natureza', valor: (p) => ROTULO_NATUREZA[p.natureza] },
        { titulo: 'Conta', valor: (p) => p.codigo_conta },
        { titulo: 'Total (Kz)', valor: (p) => p.total, formato: 'moeda', somar: true },
        { titulo: 'Liquidado (Kz)', valor: (p) => p.liquidado, formato: 'moeda', somar: true },
        { titulo: 'Em liquidação (Kz)', valor: (p) => p.em_liquidacao, formato: 'moeda', somar: true },
        { titulo: 'Saldo (Kz)', valor: (p) => p.saldo, formato: 'moeda', somar: true },
        { titulo: 'Moeda', valor: (p) => (p.codigo_moeda && p.codigo_moeda !== 'AOA' ? `${p.saldo_moeda ?? ''} ${p.codigo_moeda}` : '') },
      ],
      linhas: [...linhas].sort((a, b) => (a.terceiro ?? '').localeCompare(b.terceiro ?? '', 'pt') || a.data_documento.localeCompare(b.data_documento)),
      agrupar: { chave: (p) => p.terceiro?.trim() || `Terceiro #${p.terceiro_id}`, subtotais: true },
      totais: 'Total geral',
      vazio: 'Sem documentos em aberto.',
    }),
  };
}
