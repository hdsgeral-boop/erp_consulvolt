import { Tag } from 'antd';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { somar } from '@/utilitarios/decimal';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { ContaCRM } from './tipos';

/** «Em atraso» do antigo: valor · dias de atraso (crm_ui_gestao.js:63). */
export function textoEmAtraso(c: ContaCRM): string {
  const f = c.financeiro;
  return f && Number(f.em_atraso) > 0 ? `${formatarKz(f.em_atraso)} · ${f.max_dias_atraso} d` : '';
}

/** «Próxima actividade» do antigo: título · data (crm_ui_gestao.js:64). */
export function textoProximaActividade(c: ContaCRM): string {
  const a = c.proxima_atividade;
  return a ? [a.titulo, a.data_prevista && formatarData(a.data_prevista)].filter(Boolean).join(' · ') : '';
}

const origem = (c: ContaCRM) => c.origem_original ?? c.origem ?? '';
const facturado12m = (c: ContaCRM) => (c.financeiro ? formatarKz(c.financeiro.facturado_12m ?? '0') : '—');

/**
 * Colunas da lista de contas na ordem do antigo (crm_ui_gestao.js:57): Conta, Contacto, Origem, Oportunidades abertas,
 * Valor em aberto, Facturado 12 meses, Em atraso, Próxima actividade (a coluna «Ficha 360º» vem das `accoes`).
 */
export const colunasContas: ColunaApi<ContaCRM>[] = [
  {
    title: 'Conta', dataIndex: 'nome', ocultavel: false,
    valorImpressao: (c) => [c.nome, c.tipo === 'CLIENTE' ? 'cliente' : 'prospect', c.nif && `NIF ${c.nif}`, c.setor].filter(Boolean).join(' · '),
    valorFiltro: (c) => [c.nome, c.nif, c.setor].filter(Boolean).join(' '),
    render: (v: string, c) => (
      <>
        <strong>{v}</strong> {c.tipo === 'CLIENTE' ? <Tag color="green">cliente</Tag> : <Tag>prospect</Tag>}
        <div style={{ fontSize: 12, color: 'rgba(0,0,0,0.55)' }}>{[c.nif && `NIF ${c.nif}`, c.setor].filter(Boolean).join(' · ')}</div>
      </>
    ),
  },
  {
    title: 'Contacto', key: 'ct', responsive: ['lg'],
    valorImpressao: (c) => [c.email, c.telefone].filter(Boolean).join(' · '),
    valorFiltro: (c) => [c.email, c.telefone].filter(Boolean).join(' '),
    render: (_, c) => (
      <span style={{ fontSize: 13 }}>
        {c.email ?? ''}
        {c.telefone && <div>{c.telefone}</div>}
        {!c.email && !c.telefone && '—'}
      </span>
    ),
  },
  { title: 'Origem', key: 'origem', responsive: ['md'], valorImpressao: origem, valorFiltro: origem, render: (_, c) => origem(c) || '—' },
  { title: 'Oportunidades abertas', dataIndex: 'oportunidades_abertas', align: 'right', responsive: ['md'], total: true, formatoTotal: (v) => String(v) },
  { title: 'Valor em aberto', dataIndex: 'valor_aberto', align: 'right', total: true, render: (v: string) => formatarKz(v) },
  {
    title: 'Facturado 12 meses', key: 'f12', align: 'right', responsive: ['md'],
    total: (linhas) => formatarKz(somar(linhas.map((c) => c.financeiro?.facturado_12m ?? '0'))),
    valorImpressao: facturado12m, valorFiltro: facturado12m, render: (_, c) => facturado12m(c),
  },
  {
    title: 'Em atraso', key: 'f', align: 'right', responsive: ['lg'],
    total: (linhas) => formatarKz(somar(linhas.map((c) => c.financeiro?.em_atraso ?? '0'))),
    valorImpressao: (c) => textoEmAtraso(c) || '—', valorFiltro: textoEmAtraso,
    render: (_, c) => (textoEmAtraso(c) ? <Tag color="red">{textoEmAtraso(c)}</Tag> : '—'),
  },
  {
    title: 'Próxima actividade', key: 'prox', responsive: ['lg'],
    valorImpressao: (c) => textoProximaActividade(c) || '—', valorFiltro: textoProximaActividade,
    render: (_, c) => <span style={{ fontSize: 12 }}>{textoProximaActividade(c) || '—'}</span>,
  },
];
