import { pares, tabelaHtml, type PedidoImpressao } from '@/componentes/impressao';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { rotuloEstadoPOS } from './estados';
import { DECISOES } from './regras';
import type { FechoTPA, PainelRelatorios, RelatorioX, SessaoPOS, TotalMeio, TransferenciaSessao, VendaSessao } from './tipos';

/**
 * Documentos A4 do POS para o motor comum de impressão (logótipo e nome da empresa são postos pelo motor).
 * Os talões térmicos (venda, relatório X/Z no rolo) continuam em `impressao.ts`, com o formato próprio.
 */

const estado = (v: string | null | undefined) => (v ? rotuloEstadoPOS(v) : '—');

/** Contagem de numerário (nota/moeda → quantidade) ordenada da maior para a menor. */
export function linhasContagem(contagens: Record<string, number> | null | undefined): { d: number; q: number }[] {
  return Object.entries(contagens ?? {})
    .map(([d, q]) => ({ d: Number(d), q: Number(q) }))
    .sort((a, b) => b.d - a.d);
}

/** Texto da deliberação do desvio (igual ao do ecrã). */
export function textoDeliberacao(s: SessaoPOS): string {
  const d = s.deliberacao;
  if (!d) return '';
  return [
    `${DECISOES[d.decisao]?.rotulo ?? rotuloEstadoPOS(d.decisao)}${d.automatica ? ' (automática, dentro da tolerância)' : ''}`,
    `${formatarKz(d.valor)} Kz`,
    d.nota,
    d.por,
    d.numero_lan ? `lançamento ${d.numero_lan}` : null,
  ]
    .filter(Boolean)
    .join(' · ');
}

/** Detalhe de uma sessão POS (fecho Z) em A4: resumo, meios de pagamento, talões TPA, contagem e vendas. */
export function documentoSessao(s: SessaoPOS): string {
  const resumo: [string, string | number | null][] = [
    ['Terminal', `${s.codigo_terminal} — ${s.nome_terminal}`],
    ['Operador', s.nome_operador ?? '—'],
    ['Estado', estado(s.estado)],
    ['Abertura', formatarDataHora(s.aberto_em)],
    ['Fecho', s.fechado_em ? `${formatarDataHora(s.fechado_em)} (${s.fechado_por ?? '—'})` : '—'],
    ['Integração', `${estado(s.estado_contabilizacao)}${s.lans_contabilizacao?.length ? ` (${s.lans_contabilizacao.join(', ')})` : ''}`],
    ['Desvio', estado(s.estado_desvio)],
    ['Prestação de contas', estado(s.estado_liquidacao)],
    ['N.º de vendas', s.numero_vendas ?? 0],
    ['Total de vendas', formatarKz(s.total_vendas)],
    ['Fundo de maneio', formatarKz(s.fundo_maneio_abertura)],
    ['Numerário esperado', formatarKz(s.numerario_esperado)],
    ['Numerário contado', formatarKz(s.numerario_contado)],
    ['Desvio (contado − esperado)', s.desvio === null || s.desvio === undefined ? '—' : formatarKz(s.desvio)],
  ];
  if (s.justificacao) resumo.push(['Justificação', s.justificacao]);
  if (s.deliberacao) resumo.push(['Deliberação', textoDeliberacao(s)]);

  const meios = tabelaMeios(s.totais_por_metodo ?? []);
  const tpa = tabelaHtml<FechoTPA>({
    legenda: 'Talões TPA',
    linhas: s.fechos_tpa ?? [],
    vazio: 'Sem TPA com movimento.',
    colunas: [
      { titulo: 'TPA', valor: (f) => `${f.nome}${f.codigo_tpa ? ` (${f.codigo_tpa})` : ''}` },
      { titulo: 'Sistema', valor: (f) => f.valor_sistema, formato: 'moeda' },
      { titulo: 'Op. sistema', valor: (f) => f.operacoes_sistema, formato: 'inteiro' },
      { titulo: 'Talão', valor: (f) => f.valor_talao, formato: 'moeda' },
      { titulo: 'Op. talão', valor: (f) => f.operacoes_talao, formato: 'inteiro' },
      { titulo: 'Lote', valor: (f) => f.referencia_lote ?? '—' },
      { titulo: 'Diferença', valor: (f) => f.diferenca, formato: 'moeda' },
    ],
  });
  const contagem = linhasContagem(s.contagens_numerario);
  const tabelaContagem = contagem.length
    ? tabelaHtml({
        legenda: 'Contagem de numerário',
        linhas: contagem,
        totais: true,
        colunas: [
          { titulo: 'Nota/moeda', valor: (l) => `${formatarKz(l.d)} Kz` },
          { titulo: 'Quantidade', valor: (l) => l.q, formato: 'inteiro' },
          { titulo: 'Subtotal', valor: (l) => (l.d * l.q).toFixed(2), formato: 'moeda', somar: true },
        ],
      })
    : '';
  const vendas = tabelaHtml<VendaSessao>({
    legenda: `Vendas (${s.vendas?.length ?? 0})`,
    linhas: s.vendas ?? [],
    totais: true,
    colunas: [
      { titulo: 'Documento', valor: (v) => v.numero_documento },
      { titulo: 'Data', valor: (v) => formatarDataHora(v.data_emissao) },
      { titulo: 'Operador', valor: (v) => v.pos_operador ?? '—' },
      { titulo: 'Pagamentos', valor: (v) => (v.pos_pagamentos ?? []).map((p) => `${p.nome ?? p.tipo}: ${formatarKz(p.valor)}`).join(' · ') || '—', quebrar: true },
      { titulo: 'Troco', valor: (v) => v.pos_troco, formato: 'moeda', somar: true },
      { titulo: 'Total', valor: (v) => v.total_bruto, formato: 'moeda', somar: true },
      { titulo: 'Estado', valor: (v) => estado(v.estado) },
    ],
  });
  return `${pares(resumo, 2)}${meios}${tpa}${tabelaContagem}${vendas}`;
}

/** Pedido de impressão (A4) do detalhe de uma sessão POS. */
export function pedidoSessao(s: SessaoPOS): PedidoImpressao {
  return {
    titulo: `Sessão POS ${s.codigo_sessao}${s.numero_z ? ` · ${s.numero_z}` : ''}`,
    subtitulo: `Terminal ${s.codigo_terminal} — ${s.nome_terminal}`,
    periodo: `${formatarDataHora(s.aberto_em)}${s.fechado_em ? ` a ${formatarDataHora(s.fechado_em)}` : ''}`,
    conteudo: documentoSessao(s),
  };
}

/** Totais por meio de pagamento (comum ao X e ao Z). */
export function tabelaMeios(linhas: TotalMeio[]): string {
  return tabelaHtml<TotalMeio>({
    legenda: 'Por meio de pagamento',
    linhas,
    totais: true,
    vazio: 'Sem movimentos.',
    colunas: [
      { titulo: 'Meio', valor: (m) => m.nome },
      { titulo: 'Natureza', valor: (m) => estado(m.tipo) },
      { titulo: 'Transitória', valor: (m) => m.conta_transitoria ?? '—' },
      { titulo: 'Operações', valor: (m) => m.quantidade, formato: 'inteiro', somar: true },
      { titulo: 'Valor', valor: (m) => m.valor, formato: 'moeda', somar: true },
    ],
  });
}

/** Relatório X (sessão aberta) em A4. */
export function pedidoRelatorioX(r: RelatorioX): PedidoImpressao {
  const resumo: [string, string | number][] = [
    ['Sessão', r.sessao.codigo_sessao],
    ['Operador', r.sessao.nome_operador ?? '—'],
    ['Abertura', formatarDataHora(r.sessao.aberto_em)],
    ['N.º de vendas', r.numero_vendas],
    ['Total de vendas', formatarKz(r.total_vendas)],
    ['Fundo de maneio', formatarKz(r.sessao.fundo_maneio_abertura)],
    ['Numerário de vendas', formatarKz(r.vendas_numerario)],
    ['Numerário esperado', formatarKz(r.numerario_esperado)],
  ];
  if (r.lavandaria.movimento)
    resumo.push(['Lavandaria', `${r.lavandaria.numero_recibos} recibo(s) · ${formatarKz(r.lavandaria.total_recibos)} Kz; ${r.lavandaria.numero_faturas} factura(s) · ${formatarKz(r.lavandaria.total_faturas)} Kz`]);
  const transf = r.transferencias.length
    ? tabelaHtml<TransferenciaSessao>({
        legenda: 'Transferências',
        linhas: r.transferencias,
        totais: true,
        colunas: [
          { titulo: 'Documento', valor: (t) => t.numero_documento },
          { titulo: 'Comprovativo', valor: (t) => t.referencia },
          { titulo: 'Valor', valor: (t) => t.valor, formato: 'moeda', somar: true },
        ],
      })
    : '';
  return {
    titulo: `Relatório X · ${r.sessao.codigo_sessao}`,
    subtitulo: `Terminal ${r.sessao.codigo_terminal} — ${r.sessao.nome_terminal}`,
    periodo: `${formatarDataHora(r.sessao.aberto_em)} a ${formatarDataHora(r.emitido_em)}`,
    conteudo: `${pares(resumo, 2)}${tabelaMeios(r.totais_por_metodo)}${transf}`,
  };
}

/** Relatórios POS (painel do período): indicadores, fechos Z, meios, produtos, terminais, operadores e diferenças TPA. */
export function documentoRelatorios(r: PainelRelatorios): string {
  const kpis = pares(
    [
      ['Facturação', `${formatarKz(r.kpis.facturacao)} Kz`],
      ['Documentos', r.kpis.documentos],
      ['Ticket médio', `${formatarKz(r.kpis.ticket_medio)} Kz`],
      ['Desvios (soma)', `${formatarKz(r.kpis.desvios)} Kz`],
      ['Sessões abertas', r.kpis.sessoes_abertas],
      ['Desvios por deliberar', r.kpis.desvios_por_deliberar],
      ['Sessões por integrar', r.kpis.sessoes_por_integrar],
      ['Sessões por prestar', r.kpis.sessoes_por_prestar],
    ],
    4,
  );
  const zs = tabelaHtml<PainelRelatorios['zs'][number]>({
    legenda: `Fechos Z (${r.zs.length})`,
    linhas: r.zs,
    totais: true,
    colunas: [
      { titulo: 'Z', valor: (z) => z.numero_z },
      { titulo: 'Terminal', valor: (z) => z.codigo_terminal },
      { titulo: 'Operador', valor: (z) => z.nome_operador },
      { titulo: 'Fecho', valor: (z) => formatarDataHora(z.fechado_em) },
      { titulo: 'Vendas', valor: (z) => z.numero_vendas, formato: 'inteiro', somar: true },
      { titulo: 'Total', valor: (z) => z.total_vendas, formato: 'moeda', somar: true },
      { titulo: 'Esperado', valor: (z) => z.numerario_esperado, formato: 'moeda', somar: true },
      { titulo: 'Contado', valor: (z) => z.numerario_contado, formato: 'moeda', somar: true },
      { titulo: 'Desvio', valor: (z) => z.desvio, formato: 'moeda', somar: true },
      { titulo: 'Estado do desvio', valor: (z) => estado(z.estado_desvio) },
      { titulo: 'Integração', valor: (z) => estado(z.estado_contabilizacao) },
      { titulo: 'Prestação', valor: (z) => estado(z.estado_liquidacao) },
    ],
  });
  const meios = tabelaHtml<PainelRelatorios['por_meio'][number]>({
    legenda: 'Por meio de pagamento',
    linhas: r.por_meio,
    totais: true,
    colunas: [
      { titulo: 'Meio', valor: (m) => m.nome },
      { titulo: 'Natureza', valor: (m) => (m.tipo ? estado(m.tipo) : 'Sem detalhe') },
      { titulo: 'Operações', valor: (m) => m.quantidade, formato: 'inteiro', somar: true },
      { titulo: 'Valor', valor: (m) => m.valor, formato: 'moeda', somar: true },
    ],
  });
  const produtos = tabelaHtml<PainelRelatorios['por_produto'][number]>({
    legenda: 'Vendas por produto',
    linhas: r.por_produto,
    totais: true,
    colunas: [
      { titulo: 'Código', valor: (p) => p.codigo },
      { titulo: 'Produto', valor: (p) => p.nome, quebrar: true },
      { titulo: 'Quantidade', valor: (p) => p.quantidade, formato: 'numero' },
      { titulo: 'Base', valor: (p) => p.total_liquido, formato: 'moeda', somar: true },
      { titulo: 'Total', valor: (p) => p.total_bruto, formato: 'moeda', somar: true },
    ],
  });
  const terminais = tabelaHtml<PainelRelatorios['por_terminal'][number]>({
    legenda: 'Por terminal',
    linhas: r.por_terminal,
    totais: true,
    colunas: [
      { titulo: 'Terminal', valor: (t) => `${t.codigo_terminal} — ${t.nome_terminal}` },
      { titulo: 'Documentos', valor: (t) => t.documentos, formato: 'inteiro', somar: true },
      { titulo: 'Total', valor: (t) => t.total, formato: 'moeda', somar: true },
    ],
  });
  const operadores = tabelaHtml<PainelRelatorios['por_operador'][number]>({
    legenda: 'Por operador',
    linhas: r.por_operador,
    totais: true,
    colunas: [
      { titulo: 'Operador', valor: (o) => o.operador ?? '—' },
      { titulo: 'Documentos', valor: (o) => o.documentos, formato: 'inteiro', somar: true },
      { titulo: 'Total', valor: (o) => o.total, formato: 'moeda', somar: true },
    ],
  });
  const tpa = tabelaHtml<PainelRelatorios['diferencas_tpa'][number]>({
    legenda: `Diferenças TPA (${r.diferencas_tpa.length})`,
    linhas: r.diferencas_tpa,
    vazio: 'Sem diferenças entre os talões TPA e o sistema.',
    colunas: [
      { titulo: 'Z', valor: (d) => d.numero_z },
      { titulo: 'TPA', valor: (d) => `${d.nome ?? '—'}${d.codigo_tpa ? ` (${d.codigo_tpa})` : ''}` },
      { titulo: 'Sistema', valor: (d) => d.valor_sistema, formato: 'moeda' },
      { titulo: 'Talão', valor: (d) => d.valor_talao, formato: 'moeda' },
      { titulo: 'Diferença', valor: (d) => d.diferenca, formato: 'moeda' },
      { titulo: 'Lote', valor: (d) => d.referencia_lote ?? '—' },
    ],
  });
  return `${kpis}${zs}${meios}${produtos}${terminais}${operadores}${tpa}`;
}
