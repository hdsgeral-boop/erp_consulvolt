import { Tag, Tooltip } from 'antd';
import type { ReactNode } from 'react';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { somar } from '@/utilitarios/decimal';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { ContrapartidaLista, DocumentoTesouraria, TipoDocumento } from './api';

/**
 * Colunas das listas de documentos de tesouraria como no sistema anterior (verificação visual 7.1.1, 7.3.1 e 7.4.1):
 * renderMovimentosTesouraria (Operações), renderAccountingIntegration (Integração) e renderAccountingHistory
 * (Histórico / Descontabilização), js/ui_tesouraria.js. Os dados vêm agregados por página do servidor
 * (ServicoListagemTesouraria); aqui só há formatação — funções puras exportadas para teste.
 */

const linhas = (d: DocumentoTesouraria): ContrapartidaLista[] => d.contrapartidas ?? [];

/** «Nome (NIF)», «NIF: x» ou «-» (entStr do legado), uma por entidade distinta. */
export function textosEntidade(d: DocumentoTesouraria, comNif = true): string[] {
  return (d.entidades ?? []).map((e) => (e.nome ? (comNif ? `${e.nome} (${e.nif || '-'})` : e.nome) : e.nif ? `NIF: ${e.nif}` : '-'));
}

/** «N.º | DD/MM/AAAA» (origStr do legado) por linha com documento de origem. */
export function textoDocOrigem(c: ContrapartidaLista): string {
  if (!c.numero_documento) return '-';
  return c.data_documento_original ? `${c.numero_documento} | ${formatarData(c.data_documento_original)}` : c.numero_documento;
}

/** «UN / CC» com «---» quando falta (legado). */
export function textoUnCc(c: ContrapartidaLista): string {
  return `${c.unidade_negocio ?? '---'} / ${c.centro_custo ?? '---'}`;
}

/** «Nota DF / Nota fluxo» com «-» quando falta (legado). */
export function textoNotas(c: ContrapartidaLista): string {
  return `${c.nota_demonstracao ?? '-'} / ${c.nota_fluxo_caixa ?? '-'}`;
}

/** Documentos de origem distintos (coluna «Doc. Origem» do Histórico e da Integração). */
export function docsOrigem(d: DocumentoTesouraria): string[] {
  return [...new Set(linhas(d).map((c) => c.numero_documento).filter((n): n is string => !!n))];
}

/** Só ligações http(s) abrem o «documento externo» (nunca javascript:/data: vindos dos dados). */
export function urlSegura(url: string | null | undefined): string | null {
  if (!url) return null;
  try {
    const u = new URL(url.trim());
    return u.protocol === 'http:' || u.protocol === 'https:' ? u.href : null;
  } catch {
    return null;
  }
}

/** Recebimento gerado por um recibo de venda (ronda 3): segue o recibo — altera-se e anula-se em Vendas › Recibos. */
export const TEXTO_RECIBO_VENDA = 'Recebimento do recibo de venda — altere ou anule o recibo em Vendas › Recibos';

/** Motivo pelo qual o documento não se edita/elimina (dica do botão desactivado), ou `false`. */
export function bloqueioEdicao(d: DocumentoTesouraria): string | false {
  if (d.recibo_venda_id && d.estado !== 'ANULADO') return TEXTO_RECIBO_VENDA;
  if (d.estado === 'INTEGRADO') return 'Integrado na Contabilidade — apenas leitura';
  if (d.estado === 'ANULADO') return 'Documento anulado';
  return false;
}

export function bloqueioExclusao(d: DocumentoTesouraria): string | false {
  if (d.recibo_venda_id && d.estado !== 'ANULADO') return TEXTO_RECIBO_VENDA;
  if (d.estado === 'INTEGRADO') return 'Não pode apagar — documento integrado';
  if (d.estado === 'ANULADO') return 'Documento já anulado';
  return false;
}

/** Anulação (descontabilização) bloqueada por reconciliação bancária activa (legado: botão «Bloqueado»). */
export function bloqueioAnulacao(d: DocumentoTesouraria): string | false {
  return d.reconciliacao_bancaria
    ? 'Anulação bloqueada: o documento está incluído numa reconciliação bancária activa. Reverta a reconciliação em Tesouraria › Conciliação Bancária e volte aqui para anular.'
    : false;
}

/** Total da coluna Valor: soma (um só tipo) ou saldo recebimentos − pagamentos («Todos»), sem anulados. */
export function totalValor(ls: DocumentoTesouraria[], tipo?: TipoDocumento): string {
  const validos = ls.filter((l) => l.estado !== 'ANULADO');
  if (tipo) return formatarKz(somar(validos.map((l) => l.valor_total)));
  return formatarKz(somar(validos.map((l) => (l.tipo === 'PAGAMENTO' ? `-${l.valor_total}` : l.valor_total))));
}

/** Cor do valor na lista de Operações (legado, ui_tesouraria.js:550): débito da contrapartida a verde, crédito (ou sem linhas) a vermelho. */
export function corValorDc(dc: 'D' | 'C' | null | undefined): string {
  return dc === 'D' ? '#059669' : '#dc2626';
}

const pilha = (itens: ReactNode[], vazio: ReactNode = '-') => (itens.length ? <>{itens.map((i, k) => <div key={k} style={{ fontSize: 12, whiteSpace: 'nowrap' }}>{i}</div>)}</> : vazio);
const etiquetaConta = (c: string, cor?: string) => <Tag style={{ fontFamily: 'monospace', marginInlineEnd: 0, color: cor }}>{c}</Tag>;
const moeda = (r: DocumentoTesouraria) => (r.codigo_moeda && r.codigo_moeda !== 'AOA' && r.valor_total_moeda ? <div style={{ fontSize: 12, opacity: 0.7 }}>{r.valor_total_moeda} {r.codigo_moeda}</div> : null);

function EstadoRecon({ d }: { d: DocumentoTesouraria }) {
  return d.reconciliacao_bancaria ? (
    <Tooltip title={`ID: ${d.reconciliacao_bancaria.codigo}`}><Tag color="success" icon={<IconeFa nome="check-double" />}> Recon.</Tag></Tooltip>
  ) : (
    <Tag style={{ color: '#94a3b8' }} icon={<IconeFa nome="clock" />}> Aberto</Tag>
  );
}

function EstadoContab({ estado }: { estado: DocumentoTesouraria['estado'] }) {
  const cor = estado === 'INTEGRADO' ? 'success' : estado === 'ANULADO' ? 'default' : 'warning';
  return <Tag color={cor}>{estado ?? '—'}</Tag>;
}

/** Operações (Pagamentos / Recebimentos / Todos os Registos) — 14 colunas do legado + AÇÕES (prop `accoes`). */
export function colunasOperacoes(tipo?: TipoDocumento): ColunaApi<DocumentoTesouraria>[] {
  return [
    { title: 'Data', dataIndex: 'data_documento', key: 'data', render: formatarData },
    { title: 'Tipo', dataIndex: 'tipo', key: 'tipo', render: (t: string) => <small>{t}</small> },
    {
      title: 'Referencia', dataIndex: 'referencia', key: 'referencia',
      valorImpressao: (r) => r.referencia || r.numero_documento || '-',
      render: (v: string | null, r) => (
        <>
          <strong>{v || '-'}</strong>
          {r.numero_documento && <div style={{ fontSize: 11, opacity: 0.65 }}>{r.numero_documento}</div>}
          {r.recibo_venda_id ? <Tag color="green" style={{ marginTop: 2, fontSize: 10 }}>Recibo de venda</Tag> : null}
        </>
      ),
    },
    { title: 'Conta Fin.', dataIndex: 'conta_financeira', key: 'conta_fin', render: (c: string) => etiquetaConta(c) },
    { title: 'Contrapartida', key: 'contrapartida', valorImpressao: (r) => linhas(r).map((c) => c.codigo_conta).join('; '), render: (_, r) => pilha(linhas(r).map((c) => etiquetaConta(c.codigo_conta, '#2563eb'))) },
    { title: 'U. Negocio / C. Custo', key: 'un_cc', valorImpressao: (r) => linhas(r).map(textoUnCc).join('; '), render: (_, r) => pilha(linhas(r).map((c) => <small>{textoUnCc(c)}</small>)) },
    { title: 'D/C', key: 'dc', align: 'center', valorImpressao: (r) => linhas(r).map((c) => c.tipo_dc).join('; '), render: (_, r) => pilha(linhas(r).map((c) => <strong>{c.tipo_dc}</strong>), '') },
    { title: 'Entidade (NIF)', key: 'entidade', valorImpressao: (r) => textosEntidade(r).join('; ') || '-', render: (_, r) => pilha(textosEntidade(r).map((t) => <small>{t}</small>)) },
    { title: 'Doc. Origem', key: 'doc_origem', valorImpressao: (r) => linhas(r).filter((c) => c.numero_documento).map(textoDocOrigem).join('; ') || '-', render: (_, r) => pilha(linhas(r).filter((c) => c.numero_documento).map((c) => <small>{textoDocOrigem(c)}</small>)) },
    { title: 'Notas (D/F)', key: 'notas', valorImpressao: (r) => linhas(r).map(textoNotas).join('; '), render: (_, r) => pilha(linhas(r).map((c) => <small>{textoNotas(c)}</small>)) },
    { title: 'Descrição', dataIndex: 'descricao', key: 'descricao', ellipsis: true, width: 260, valorImpressao: (r) => r.descricao ?? '', render: (v: string | null) => <Tooltip title={v}><small>{v || '-'}</small></Tooltip> },
    {
      title: 'Valor', dataIndex: 'valor_total', key: 'valor', align: 'right',
      total: (ls) => totalValor(ls, tipo), totalImpressao: (ls) => totalValor(ls, tipo),
      valorImpressao: (r) => `${formatarKz(r.valor_total)}${r.codigo_moeda && r.codigo_moeda !== 'AOA' && r.valor_total_moeda ? ` (${r.valor_total_moeda} ${r.codigo_moeda})` : ''}`,
      render: (v: string, r) => {
        // legado: uma linha por contrapartida, com o valor da linha a verde (D) ou vermelho (C)
        const ls = linhas(r);
        if (ls.length > 1) return pilha(ls.map((c) => <strong style={{ color: corValorDc(c.tipo_dc) }}>{formatarKz(c.valor)}</strong>));
        return <><strong style={{ color: corValorDc(ls[0]?.tipo_dc) }}>{formatarKz(v)}</strong>{moeda(r)}</>;
      },
    },
    { title: 'Estado Recon.', key: 'estado_recon', align: 'center', valorImpressao: (r) => (r.reconciliacao_bancaria ? 'Recon.' : 'Aberto'), render: (_, r) => <EstadoRecon d={r} /> },
    { title: 'Estado Contab.', dataIndex: 'estado', key: 'estado_contab', render: (e: DocumentoTesouraria['estado']) => <EstadoContab estado={e} /> },
  ];
}

const tipoEtiqueta = (t: TipoDocumento) => <Tag bordered={false} color={t === 'PAGAMENTO' ? 'red' : 'green'}>{t}</Tag>;
const contrapartidasLinhas = (r: DocumentoTesouraria) =>
  pilha(linhas(r).map((c) => <>{etiquetaConta(c.codigo_conta)} [{c.tipo_dc}] {formatarKz(c.valor)}</>));
const textoContrapartidas = (r: DocumentoTesouraria) => linhas(r).map((c) => `${c.codigo_conta} [${c.tipo_dc}] ${formatarKz(c.valor)}`).join('; ');

/** Integração no Diário — colunas de renderAccountingIntegration. */
export function colunasIntegracao(aoAbrir: (d: DocumentoTesouraria) => void): ColunaApi<DocumentoTesouraria>[] {
  return [
    { title: 'Data', dataIndex: 'data_documento', key: 'data', render: formatarData },
    { title: 'Tipo', dataIndex: 'tipo', key: 'tipo', render: tipoEtiqueta },
    { title: 'Referência', dataIndex: 'referencia', key: 'referencia', valorImpressao: (r) => r.referencia || r.numero_documento || '-', render: (v: string | null, r) => <a onClick={(e) => { e.stopPropagation(); aoAbrir(r); }} style={{ fontWeight: 700 }}>{v || r.numero_documento || '-'}</a> },
    { title: 'C. Tesouraria', dataIndex: 'conta_financeira', key: 'conta_fin', render: (c: string) => etiquetaConta(c) },
    { title: 'V. Líquido Total', dataIndex: 'valor_total', key: 'valor', align: 'right', total: true, render: (v: string, r) => <><strong style={{ color: '#2563eb' }}>{formatarKz(v)}</strong>{moeda(r)}</> },
    { title: 'Contrapartidas (Linhas)', key: 'contrapartidas', valorImpressao: textoContrapartidas, render: (_, r) => contrapartidasLinhas(r) },
    { title: 'Entidade / Terceiro', key: 'entidade', valorImpressao: (r) => textosEntidade(r, false).join('; ') || 'Geral', render: (_, r) => pilha(textosEntidade(r, false), 'Geral') },
    { title: 'Doc. Origem', key: 'doc_origem', valorImpressao: (r) => docsOrigem(r).join('; ') || '-', render: (_, r) => pilha(docsOrigem(r)) },
    {
      title: 'Descrição', dataIndex: 'descricao', key: 'descricao', width: 260, valorImpressao: (r) => r.descricao ?? '',
      render: (v: string | null, r) => {
        const url = urlSegura(r.url_documento);
        return <><span style={{ whiteSpace: 'normal', wordBreak: 'break-word', fontSize: 13 }}>{v || '-'}</span>{url && <div><a href={url} target="_blank" rel="noopener noreferrer" onClick={(e) => e.stopPropagation()} style={{ fontSize: 12, color: '#0284c7' }}><IconeFa nome="external-link-alt" /> Ver Doc.</a></div>}</>;
      },
    },
  ];
}

function Compensacao({ d }: { d: DocumentoTesouraria }) {
  const c = d.compensacao;
  if (!c) return <Tag style={{ color: '#94a3b8' }} icon={<IconeFa nome="clock" />}> Pendente</Tag>;
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 3, alignItems: 'flex-start' }}>
      <code style={{ fontSize: 11, background: 'rgba(249,115,22,0.1)', color: '#c2410c', border: '1px solid rgba(249,115,22,0.35)', borderRadius: 4, padding: '2px 6px', wordBreak: 'break-all' }} title="ID de Compensação">{c.codigo}</code>
      <Tag bordered={false} style={{ background: 'rgba(249,115,22,0.15)', color: '#c2410c', fontSize: 10 }}>Compensado</Tag>
    </div>
  );
}

function ReconciliacaoBancaria({ d }: { d: DocumentoTesouraria }) {
  const r = d.reconciliacao_bancaria;
  if (!r) return <Tag style={{ color: '#94a3b8' }} icon={<IconeFa nome="clock" />}> Sem conciliação</Tag>;
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 3, alignItems: 'flex-start' }}>
      <code style={{ fontSize: 11, background: 'rgba(16,185,129,0.1)', color: '#059669', border: '1px solid rgba(16,185,129,0.35)', borderRadius: 4, padding: '2px 6px', wordBreak: 'break-all' }} title="ID de Sessão de conciliação Bancária">{r.codigo}</code>
      {r.data && <span style={{ fontSize: 11, color: '#6b7280' }}><IconeFa nome="calendar-check" style={{ color: '#059669', marginRight: 3 }} />{formatarData(r.data)}</span>}
      <Tag bordered={false} style={{ background: 'rgba(16,185,129,0.15)', color: '#059669', fontSize: 10 }}>{r.estado ?? 'CONCILIADO_BANCO'}</Tag>
    </div>
  );
}

/** Histórico / Descontabilização — colunas de renderAccountingHistory (sem AÇÕES: vêm da prop `accoes`). */
export function colunasHistorico(aoAbrir: (d: DocumentoTesouraria) => void): ColunaApi<DocumentoTesouraria>[] {
  return [
    { title: 'Data', dataIndex: 'data_documento', key: 'data', render: formatarData },
    { title: 'Tipo', dataIndex: 'tipo', key: 'tipo', render: tipoEtiqueta },
    { title: 'Referência', dataIndex: 'referencia', key: 'referencia', valorImpressao: (r) => r.referencia || r.numero_documento || '-', render: (v: string | null, r) => <a onClick={(e) => { e.stopPropagation(); aoAbrir(r); }} style={{ fontWeight: 700 }}>{v || r.numero_documento || '-'}</a> },
    { title: 'C. Tesouraria', dataIndex: 'conta_financeira', key: 'conta_fin', render: (c: string) => etiquetaConta(c) },
    { title: 'V. Líquido Total', dataIndex: 'valor_total', key: 'valor', align: 'right', total: true, render: (v: string, r) => <><strong style={{ color: '#4f46e5' }}>{formatarKz(v)}</strong>{moeda(r)}</> },
    { title: 'Contrapartidas (Linhas)', key: 'contrapartidas', valorImpressao: textoContrapartidas, render: (_, r) => contrapartidasLinhas(r) },
    { title: 'Entidade', key: 'entidade', valorImpressao: (r) => textosEntidade(r, false).join('; ') || 'Geral', render: (_, r) => pilha(textosEntidade(r, false), 'Geral') },
    { title: 'Doc. Origem', key: 'doc_origem', valorImpressao: (r) => docsOrigem(r).join('; ') || '-', render: (_, r) => pilha(docsOrigem(r)) },
    { title: 'Compensação', key: 'compensacao', align: 'center', width: 180, valorImpressao: (r) => (r.compensacao ? `${r.compensacao.codigo} (Compensado)` : 'Pendente'), render: (_, r) => <Compensacao d={r} /> },
    { title: 'Reconciliação Bancária', key: 'reconciliacao', align: 'center', width: 180, valorImpressao: (r) => (r.reconciliacao_bancaria ? r.reconciliacao_bancaria.codigo : 'Sem conciliação'), render: (_, r) => <ReconciliacaoBancaria d={r} /> },
    { title: 'Status', dataIndex: 'estado', key: 'status', render: () => <Tag bordered={false} color="success" icon={<IconeFa nome="check" />}> INTEGRADO</Tag>, valorImpressao: () => 'INTEGRADO' },
  ];
}
