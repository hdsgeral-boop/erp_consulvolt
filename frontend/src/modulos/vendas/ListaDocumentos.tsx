import { Button, Card, DatePicker, Flex, Input, Select, Tag, Tooltip } from 'antd';
import { CheckCircleTwoTone, PlusOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { CORES_ESTADO, TIPOS_DOCUMENTO, type DocumentoVenda } from './api';

export function ListaDocumentos() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [tipo, setTipo] = useState<string>();
  const [estado, setEstado] = useState<string>();
  const [contabilizado, setContabilizado] = useState<string>();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [pesquisa, setPesquisa] = useState('');

  const colunas: ColumnsType<DocumentoVenda> = [
    { title: 'Documento', dataIndex: 'numero_documento', fixed: 'left', render: (v: string) => <strong>{v}</strong> },
    { title: 'Tipo', dataIndex: 'tipo_documento', render: (t: string) => <Tooltip title={TIPOS_DOCUMENTO[t]}><Tag>{t}</Tag></Tooltip> },
    { title: 'Data', dataIndex: 'data_emissao', render: formatarData },
    { title: 'Cliente', render: (_, r) => r.cliente?.nome ?? `#${r.cliente_id}` },
    { title: 'Total (Kz)', dataIndex: 'total_bruto', align: 'right', render: (v: string) => formatarKz(v) },
    { title: 'Pendente (Kz)', dataIndex: 'valor_pendente', align: 'right', render: (v: string | null) => (v && Number(v) > 0 ? formatarKz(v) : '—') },
    { title: 'Estado', dataIndex: 'estado', render: (e: string | null) => (e ? <Tag color={CORES_ESTADO[e]}>{e}</Tag> : '—') },
    { title: 'Contab.', dataIndex: 'contabilizado', align: 'center', render: (c: boolean) => (c ? <CheckCircleTwoTone twoToneColor="#52c41a" /> : null) },
    { title: 'AGT', render: (_, r) => (r.faturacao_eletronica?.estado ? <Tag>{r.faturacao_eletronica.estado}</Tag> : '—') },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Facturação"
        subtitulo="Documentos comerciais e fiscais"
        accoes={
          pode('vendas_fat_emitir') && (
            <Button type="primary" icon={<PlusOutlined />} onClick={() => navegar('novo')}>
              Novo documento
            </Button>
          )
        }
      />
      <Card>
        <Flex gap={8} wrap style={{ marginBottom: 16 }}>
          <Input.Search placeholder="N.º do documento" allowClear style={{ width: 220 }} onSearch={setPesquisa} />
          <Select placeholder="Tipo" allowClear style={{ width: 200 }} value={tipo} onChange={setTipo} options={Object.entries(TIPOS_DOCUMENTO).map(([v, l]) => ({ value: v, label: `${v} — ${l}` }))} />
          <Select placeholder="Estado" allowClear style={{ width: 150 }} value={estado} onChange={setEstado} options={Object.keys(CORES_ESTADO).map((e) => ({ value: e, label: e }))} />
          <Select placeholder="Contabilização" allowClear style={{ width: 170 }} value={contabilizado} onChange={setContabilizado} options={[{ value: '1', label: 'Contabilizados' }, { value: '0', label: 'Por contabilizar' }]} />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
        </Flex>
        <TabelaApi<DocumentoVenda>
          url="/vendas/documentos"
          chaveConsulta={['vendas', 'documentos']}
          filtros={{ tipo_documento: tipo, estado, contabilizado, pesquisa, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunas}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
    </>
  );
}
