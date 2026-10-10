import { Tag, Tooltip, Typography } from 'antd';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { IconeFa } from '@/componentes/fluxos';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { rotuloTerceiro } from '../comum/terceiro';
import { totaisDC, urlSegura, type LinhaLista } from './ferramentas';

export interface MapasColunas {
  /** id → código do diário */
  codigoDiario: Map<number, string>;
  /** id → descrição do diário */
  nomeDiario: Map<number, string>;
  demo: Map<number, string>;
  fluxo: Map<number, string>;
  unidade: Map<number, string>;
  centro: Map<number, string>;
}

const COR_D = '#2563eb';
const COR_C = '#dc2626';
const vazio = (v: unknown) => (v === null || v === undefined || v === '' ? '' : String(v));
const estrangeira = (l: LinhaLista) => !!l.codigo_moeda && l.codigo_moeda.toUpperCase() !== 'AOA';

/**
 * Colunas da lista de lançamentos, na ordem do sistema anterior (8.1.2; renderLancamentos, js/ui_lancamentos.js:286-438):
 * ID, Nº LANÇ., CD. DIÁRIO, DIÁRIO, DATA FISCAL, DATA DOC., DATA LANÇ., Nº DOC., REF., CD. CONTA, CONTA, TERCEIRO,
 * DESCRIÇÃO, DEM., FLUXO, UN, CC, PROJETO, MOEDA, VALOR NA MOEDA, CÂMBIO, VALOR (KZ), D/C, URL (+ SITUAÇÃO do novo).
 * A caixa de selecção («#» do legado) e a coluna AÇÕES são da TabelaApi (`seleccao` / `accoes`). As menos usadas começam
 * escondidas («Colunas ▾»). Os textos de dados (terceiro, descrição, URL) são sempre escapados pelo React.
 */
export function colunasLancamentos(m: MapasColunas): ColunaApi<LinhaLista>[] {
  return [
    { key: 'id', title: 'ID', dataIndex: 'id', ocultaPorOmissao: true, render: (v: number) => <span style={{ fontSize: '0.75em', color: '#64748b' }}>{v}</span> },
    {
      key: 'numero_lan',
      title: 'Nº Lanç.',
      dataIndex: 'numero_lan',
      ocultavel: false,
      valorImpressao: (r) => r.numero_lan || r.referencia || '',
      render: (v: string, r) => (
        <Typography.Text strong style={{ color: COR_D, whiteSpace: 'nowrap' }}>
          {v || r.referencia || ''}
        </Typography.Text>
      ),
    },
    { key: 'cd_diario', title: 'Cd. Diário', dataIndex: 'diario_id', valorImpressao: (r) => m.codigoDiario.get(r.diario_id) ?? '', render: (v: number) => m.codigoDiario.get(v) ?? '' },
    { key: 'diario', title: 'Diário', dataIndex: 'diario_id', ocultaPorOmissao: true, valorImpressao: (r) => m.nomeDiario.get(r.diario_id) ?? '', render: (v: number) => m.nomeDiario.get(v) ?? '' },
    { key: 'data_fiscal', title: 'Data Fiscal', dataIndex: 'data_documento', render: formatarData, width: 100 },
    { key: 'data_doc', title: 'Data Doc.', dataIndex: 'data_lancamento', ocultaPorOmissao: true, render: (v: string | null) => (v ? formatarData(v.slice(0, 10)) : '') },
    { key: 'data_lanc', title: 'Data Lanç.', dataIndex: 'criado_em', ocultaPorOmissao: true, render: (v: string | null, r) => formatarData((v ?? r.data_documento ?? '').slice(0, 10) || null) },
    { key: 'numero_documento', title: 'Nº Doc.', dataIndex: 'numero_documento', render: vazio },
    { key: 'referencia', title: 'Ref.', dataIndex: 'referencia', render: vazio },
    { key: 'codigo_conta', title: 'Cd. Conta', dataIndex: 'codigo_conta', ocultavel: false },
    { key: 'conta', title: 'Conta', dataIndex: 'conta_descricao', ellipsis: true, width: 200, render: (v: string | null) => <Tooltip title={v}>{v ?? ''}</Tooltip>, valorImpressao: (r) => r.conta_descricao ?? '' },
    {
      key: 'terceiro',
      title: 'Terceiro',
      ellipsis: true,
      width: 180,
      valorImpressao: (r) => (r.terceiro_id ? rotuloTerceiro(r.terceiro, r.terceiro_id) : ''),
      render: (_, r) => (r.terceiro_id ? <Tooltip title={r.terceiro?.nif ? `NIF ${r.terceiro.nif}` : undefined}>{rotuloTerceiro(r.terceiro, r.terceiro_id)}</Tooltip> : ''),
    },
    { key: 'descricao', title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 240, valorImpressao: (r) => r.descricao ?? '', render: (v: string | null) => <Tooltip title={v}>{v ?? ''}</Tooltip> },
    { key: 'demo', title: 'Dem.', dataIndex: 'nota_demonstracao_id', render: (v: number | null) => (v ? m.demo.get(v) ?? v : '') },
    { key: 'fluxo', title: 'Fluxo', dataIndex: 'nota_fluxo_caixa_id', render: (v: number | null) => (v ? m.fluxo.get(v) ?? v : '') },
    { key: 'un', title: 'UN', dataIndex: 'unidade_negocio_id', render: (v: number | null) => (v ? m.unidade.get(v) ?? v : '') },
    { key: 'cc', title: 'CC', dataIndex: 'centro_custo_id', render: (v: number | null) => (v ? m.centro.get(v) ?? v : '') },
    {
      key: 'projeto',
      title: 'Projeto',
      dataIndex: 'codigo_projeto',
      ocultaPorOmissao: true,
      valorImpressao: (r) => r.codigo_projeto ?? '-',
      render: (v: string | null) => (
        <span style={{ fontSize: '0.85em', color: '#475569', whiteSpace: 'nowrap' }}>
          <IconeFa nome="project-diagram" /> {v ?? '-'}
        </span>
      ),
    },
    { key: 'moeda', title: 'Moeda', align: 'center', ocultaPorOmissao: true, valorImpressao: (r) => (estrangeira(r) ? r.codigo_moeda! : 'AOA'), render: (_, r) => <strong>{estrangeira(r) ? r.codigo_moeda : 'AOA'}</strong> },
    {
      key: 'valor_moeda',
      title: 'Valor na Moeda',
      align: 'right',
      ocultaPorOmissao: true,
      valorImpressao: (r) => (estrangeira(r) ? `${formatarKz(r.valor_moeda)} ${r.codigo_moeda}` : ''),
      render: (_, r) => (estrangeira(r) ? <span style={{ whiteSpace: 'nowrap' }}>{`${formatarKz(r.valor_moeda)} ${r.codigo_moeda}`}</span> : ''),
    },
    {
      key: 'cambio',
      title: 'Câmbio',
      align: 'right',
      ocultaPorOmissao: true,
      valorImpressao: (r) => (estrangeira(r) && r.taxa_cambio ? Number(r.taxa_cambio).toLocaleString('pt-PT', { maximumFractionDigits: 6 }) + (r.taxa_cambio_manual ? ' (M)' : '') : ''),
      render: (_, r) =>
        estrangeira(r) && r.taxa_cambio ? (
          <span style={{ whiteSpace: 'nowrap' }}>
            {Number(r.taxa_cambio).toLocaleString('pt-PT', { maximumFractionDigits: 6 })}
            {r.taxa_cambio_manual && (
              <span title="Câmbio manual" style={{ color: '#b45309' }}>
                {' '}
                (M)
              </span>
            )}
          </span>
        ) : (
          ''
        ),
    },
    {
      key: 'valor',
      title: 'Valor (Kz)',
      dataIndex: 'valor',
      align: 'right',
      ocultavel: false,
      valorImpressao: (r) => Number(r.valor),
      render: (v: string, r) => <strong style={{ color: r.tipo_dc === 'D' ? COR_D : COR_C, whiteSpace: 'nowrap' }}>{formatarKz(v)}</strong>,
      // G.3: Σ débito e Σ crédito das linhas filtradas (o valor sem sinal não se soma)
      total: (linhas) => {
        const t = totaisDC(linhas);
        return (
          <span style={{ whiteSpace: 'nowrap' }}>
            <span style={{ color: COR_D }}>D {formatarKz(t.debito)}</span> · <span style={{ color: COR_C }}>C {formatarKz(t.credito)}</span>
          </span>
        );
      },
      totalImpressao: (linhas) => {
        const t = totaisDC(linhas);
        return `D ${formatarKz(t.debito)} · C ${formatarKz(t.credito)}`;
      },
    },
    { key: 'tipo_dc', title: 'D/C', dataIndex: 'tipo_dc', align: 'center', render: (v: 'D' | 'C') => <strong>{v}</strong> },
    {
      key: 'url',
      title: 'URL',
      align: 'center',
      ocultaPorOmissao: true,
      filtravel: false,
      valorImpressao: (r) => urlSegura(r.url_documento) ?? '',
      render: (_, r) => {
        const url = urlSegura(r.url_documento);
        return url ? (
          <a href={url} target="_blank" rel="noopener noreferrer" title="Abrir documento" aria-label="Abrir documento externo" onClick={(e) => e.stopPropagation()}>
            <IconeFa nome="external-link-alt" style={{ color: COR_D }} />
          </a>
        ) : (
          ''
        );
      },
    },
    {
      key: 'situacao',
      title: 'Situação',
      valorImpressao: (r) => (r.estorno_de_id ? 'Estorno' : r.estornado_por_id ? 'Estornado' : r.tipo_origem ?? ''),
      render: (_, r) => (r.estorno_de_id ? <Tag color="purple">Estorno</Tag> : r.estornado_por_id ? <Tag color="red">Estornado</Tag> : r.tipo_origem ? <Tag>{r.tipo_origem}</Tag> : null),
    },
  ];
}
