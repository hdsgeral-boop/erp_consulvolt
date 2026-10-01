import { Button, Card, DatePicker, Flex, Input, Select, Tag, Tooltip } from 'antd';
import { MinusCircleOutlined, PlusCircleOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { EtiquetaEstado, ValorKz } from '../../contab/comum/Componentes';
import { ROTULO_TIPO, type DocumentoTesouraria, type EstadoDocumento, type TipoDocumento } from '../api';
import { SeletorContaFinanceira, TabelaDocumentos } from '../comum';

/** Colunas comuns às listagens de documentos de tesouraria (gestão, integração e histórico). */
export function colunasDocumentos(): ColumnsType<DocumentoTesouraria> {
  return [
    { title: 'Documento', dataIndex: 'numero_documento', render: (v: string | null, r) => <strong>{v ?? `#${r.id}`}</strong> },
    { title: 'Tipo', dataIndex: 'tipo', render: (t: TipoDocumento) => <Tag color={t === 'PAGAMENTO' ? 'volcano' : 'green'}>{ROTULO_TIPO[t] ?? t}</Tag> },
    { title: 'Data', dataIndex: 'data_documento', render: formatarData },
    { title: 'Conta', dataIndex: 'conta_financeira' },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 300, render: (v: string | null) => <Tooltip title={v}>{v ?? '—'}</Tooltip> },
    { title: 'Referência', dataIndex: 'referencia', render: (v: string | null) => v ?? '—' },
    { title: 'Valor (Kz)', dataIndex: 'valor_total', align: 'right', render: (v: string, r) => <><ValorKz valor={v} />{r.codigo_moeda && r.codigo_moeda !== 'AOA' && r.valor_total_moeda ? <div style={{ fontSize: 12, opacity: 0.7 }}>{r.valor_total_moeda} {r.codigo_moeda}</div> : null}</> },
    { title: 'Estado', dataIndex: 'estado', render: (e: string | null) => <EtiquetaEstado estado={e} /> },
    { title: 'Lançamento', dataIndex: 'numero_lan_contabilizacao', render: (v: string | null) => v ?? '—' },
  ];
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
        <Flex gap={8} wrap style={{ marginBottom: 16 }}>
          <Input.Search placeholder="N.º, descrição ou referência" allowClear style={{ width: 260 }} onSearch={setPesquisa} />
          <Select placeholder="Tipo" allowClear style={{ width: 150 }} value={tipo} onChange={setTipo} options={[{ value: 'PAGAMENTO', label: 'Pagamentos' }, { value: 'RECEBIMENTO', label: 'Recebimentos' }]} />
          <Select placeholder="Estado" allowClear style={{ width: 150 }} value={estado} onChange={setEstado} options={[{ value: 'PENDENTE', label: 'Por integrar' }, { value: 'INTEGRADO', label: 'Integrados' }, { value: 'ANULADO', label: 'Anulados' }]} />
          <SeletorContaFinanceira value={conta} onChange={setConta} allowClear />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
        </Flex>
        <TabelaDocumentos
          filtros={{ tipo, estado, conta_financeira: conta, pesquisa, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunasDocumentos()}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
    </>
  );
}
