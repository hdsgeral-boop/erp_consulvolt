import { Button, Card, DatePicker, Input, Select, Tag, Tooltip } from 'antd';
import { MinusCircleOutlined, PlusCircleOutlined } from '@ant-design/icons';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { BarraFiltros } from '@/componentes/responsivo';
import { somar } from '@/utilitarios/decimal';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaEstado, ValorKz } from '../../contab/comum/Componentes';
import { ROTULO_TIPO, type DocumentoTesouraria, type EstadoDocumento, type TipoDocumento } from '../api';
import { SeletorContaFinanceira, TabelaDocumentos } from '../comum';

/** Colunas comuns às listagens de documentos de tesouraria (gestão, integração e histórico). */
export function colunasDocumentos(): ColunaApi<DocumentoTesouraria>[] {
  return [
    { title: 'Documento', dataIndex: 'numero_documento', render: (v: string | null, r) => <strong>{v ?? `#${r.id}`}</strong> },
    { title: 'Tipo', dataIndex: 'tipo', responsive: ['sm'], render: (t: TipoDocumento) => <Tag color={t === 'PAGAMENTO' ? 'volcano' : 'green'}>{ROTULO_TIPO[t] ?? t}</Tag> },
    { title: 'Data', dataIndex: 'data_documento', render: formatarData },
    { title: 'Conta', dataIndex: 'conta_financeira', responsive: ['md'] },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 300, responsive: ['lg'], valorImpressao: (r) => r.descricao ?? '', render: (v: string | null) => <Tooltip title={v}>{v ?? '—'}</Tooltip> },
    { title: 'Referência', dataIndex: 'referencia', responsive: ['lg'], render: (v: string | null) => v ?? '—' },
    {
      title: 'Valor (Kz)',
      dataIndex: 'valor_total',
      align: 'right',
      valorImpressao: (r) => `${formatarKz(r.valor_total)}${r.codigo_moeda && r.codigo_moeda !== 'AOA' && r.valor_total_moeda ? ` (${r.valor_total_moeda} ${r.codigo_moeda})` : ''}`,
      totalImpressao: (ls) => formatarKz(somar(ls.filter((l) => l.estado !== 'ANULADO').map((l) => (l.tipo === 'PAGAMENTO' ? `-${l.valor_total}` : l.valor_total)))),
      render: (v: string, r) => <><ValorKz valor={v} />{r.codigo_moeda && r.codigo_moeda !== 'AOA' && r.valor_total_moeda ? <div style={{ fontSize: 12, opacity: 0.7 }}>{r.valor_total_moeda} {r.codigo_moeda}</div> : null}</>,
    },
    { title: 'Estado', dataIndex: 'estado', responsive: ['sm'], render: (e: string | null) => <EtiquetaEstado estado={e} /> },
    { title: 'Lançamento', dataIndex: 'numero_lan_contabilizacao', responsive: ['lg'], render: (v: string | null) => v ?? '—' },
  ];
}

/** Texto do período (DD/MM/AAAA a DD/MM/AAAA) para o cabeçalho das listagens impressas. */
export function textoPeriodo(periodo: [Dayjs | null, Dayjs | null] | null): string | undefined {
  if (!periodo?.[0] && !periodo?.[1]) return undefined;
  return `${periodo?.[0]?.format('DD/MM/YYYY') ?? '…'} a ${periodo?.[1]?.format('DD/MM/YYYY') ?? '…'}`;
}

export function ListaDocumentos() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [tipo, setTipo] = useState<TipoDocumento>();
  const [estado, setEstado] = useState<EstadoDocumento>();
  const [conta, setConta] = useState<string>();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [pesquisa, setPesquisa] = useState('');

  return (
    <>
      <CabecalhoPagina
        titulo="Pagamentos e recebimentos"
        subtitulo="Documentos de tesouraria (por integrar, integrados e anulados)"
        accoes={
          pode('teso_doc_emitir') && (
            <>
              <Button icon={<MinusCircleOutlined />} onClick={() => navegar('novo?tipo=PAGAMENTO')}>Novo pagamento</Button>
              <Button type="primary" icon={<PlusCircleOutlined />} onClick={() => navegar('novo?tipo=RECEBIMENTO')}>Novo recebimento</Button>
            </>
          )
        }
      />
      <Card>
        <BarraFiltros>
          <Input.Search placeholder="N.º, descrição ou referência" allowClear style={{ width: 260, maxWidth: '100%' }} onSearch={setPesquisa} />
          <Select placeholder="Tipo" allowClear style={{ width: 150 }} value={tipo} onChange={setTipo} options={[{ value: 'PAGAMENTO', label: 'Pagamentos' }, { value: 'RECEBIMENTO', label: 'Recebimentos' }]} />
          <Select placeholder="Estado" allowClear style={{ width: 150 }} value={estado} onChange={setEstado} options={[{ value: 'PENDENTE', label: 'Por integrar' }, { value: 'INTEGRADO', label: 'Integrados' }, { value: 'ANULADO', label: 'Anulados' }]} />
          <SeletorContaFinanceira value={conta} onChange={setConta} allowClear />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
        </BarraFiltros>
        <TabelaDocumentos
          filtros={{ tipo, estado, conta_financeira: conta, pesquisa, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunasDocumentos()}
          impressao={{
            titulo: 'Lista de pagamentos e recebimentos',
            periodo: textoPeriodo(periodo),
            filtros: [
              tipo && `Tipo: ${ROTULO_TIPO[tipo]}`,
              estado && `Estado: ${{ PENDENTE: 'Por integrar', INTEGRADO: 'Integrados', ANULADO: 'Anulados' }[estado]}`,
              conta && `Conta: ${conta}`,
              pesquisa && `Pesquisa: ${pesquisa}`,
              'Total: recebimentos menos pagamentos (sem anulados)',
            ],
            rotuloTotal: 'Saldo',
          }}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
    </>
  );
}
