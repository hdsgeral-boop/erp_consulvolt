import { Button, Card, DatePicker, Input, Select, Tag, Tooltip } from 'antd';
import { CheckCircleTwoTone, PlusOutlined } from '@ant-design/icons';

import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { BarraFiltros, useEcraPequeno } from '@/componentes/responsivo';
import { somar } from '@/utilitarios/decimal';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { CORES_ESTADO, TIPOS_DOCUMENTO, type DocumentoVenda } from './api';
import { ESTADOS_AGT, INFO_ESTADO_AGT, estadoAgtDoDocumento } from './agt/estadoAgt';

export function ListaDocumentos() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [tipo, setTipo] = useState<string>();
  const [estado, setEstado] = useState<string>();
  const [contabilizado, setContabilizado] = useState<string>();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [pesquisa, setPesquisa] = useState('');
  // filtro «Estado AGT» no endereço (?estado_fe=…): os contadores da Facturação electrónica abrem a lista já filtrada (A-04)
  const [procura, setProcura] = useSearchParams();
  const estadoFe = procura.get('estado_fe') && INFO_ESTADO_AGT[procura.get('estado_fe') as string] ? (procura.get('estado_fe') as string) : undefined;
  const setEstadoFe = (v?: string) => setProcura((p) => { const n = new URLSearchParams(p); if (v) n.set('estado_fe', v); else n.delete('estado_fe'); return n; }, { replace: true });
  const pequeno = useEcraPequeno();

  const colunas: ColunaApi<DocumentoVenda>[] = [
    { title: 'Documento', dataIndex: 'numero_documento', fixed: 'left', render: (v: string) => <strong>{v}</strong> },
    { title: 'Tipo', dataIndex: 'tipo_documento', responsive: ['sm'], render: (t: string) => <Tooltip title={TIPOS_DOCUMENTO[t]}><Tag>{t}</Tag></Tooltip> },
    { title: 'Data', dataIndex: 'data_emissao', render: formatarData },
    { title: 'Cliente', render: (_, r) => r.cliente?.nome ?? `#${r.cliente_id}` },
    { title: 'Total (Kz)', dataIndex: 'total_bruto', align: 'right', render: (v: string) => formatarKz(v), totalImpressao: (ls) => formatarKz(somar(ls.map((l) => l.total_bruto))) },
    {
      title: 'Pendente (Kz)',
      dataIndex: 'valor_pendente',
      align: 'right',
      responsive: ['md'],
      render: (v: string | null) => (v && Number(v) > 0 ? formatarKz(v) : '—'),
      totalImpressao: (ls) => formatarKz(somar(ls.map((l) => l.valor_pendente))),
    },
    { title: 'Estado', dataIndex: 'estado', responsive: ['md'], render: (e: string | null) => (e ? <Tag color={CORES_ESTADO[e]}>{e}</Tag> : '—') },
    { title: 'Contab.', dataIndex: 'contabilizado', align: 'center', responsive: ['lg'], render: (c: boolean) => (c ? <CheckCircleTwoTone twoToneColor="#52c41a" /> : null), valorImpressao: (r) => (r.contabilizado ? 'Sim' : 'Não') },
    {
      title: 'AGT',
      responsive: ['lg'],
      render: (_, r) => {
        const e = estadoAgtDoDocumento(r.faturacao_eletronica);
        return e ? <Tag color={INFO_ESTADO_AGT[e].cor}>{INFO_ESTADO_AGT[e].rotulo}</Tag> : '—';
      },
      valorImpressao: (r) => { const e = estadoAgtDoDocumento(r.faturacao_eletronica); return e ? INFO_ESTADO_AGT[e].rotulo : ''; },
    },
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
        <BarraFiltros>
          <Input.Search placeholder="N.º do documento" allowClear style={{ width: 220, maxWidth: '100%' }} onSearch={setPesquisa} />
          <Select placeholder="Tipo" allowClear style={{ width: 200, maxWidth: '100%' }} value={tipo} onChange={setTipo} options={Object.entries(TIPOS_DOCUMENTO).map(([v, l]) => ({ value: v, label: `${v} — ${l}` }))} />
          <Select placeholder="Estado" allowClear style={{ width: 150 }} value={estado} onChange={setEstado} options={Object.keys(CORES_ESTADO).map((e) => ({ value: e, label: e }))} />
          <Select placeholder="Contabilização" allowClear style={{ width: 170 }} value={contabilizado} onChange={setContabilizado} options={[{ value: '1', label: 'Contabilizados' }, { value: '0', label: 'Por contabilizar' }]} />
          <Select placeholder="Estado AGT" allowClear style={{ width: 190 }} value={estadoFe} onChange={setEstadoFe} options={ESTADOS_AGT.map((e) => ({ value: e.chave, label: e.rotulo }))} />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
        </BarraFiltros>
        <TabelaApi<DocumentoVenda>
          url="/vendas/documentos"
          chaveConsulta={['vendas', 'documentos']}
          filtros={{ tipo_documento: tipo, estado, contabilizado, pesquisa, estado_fe: estadoFe, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          impressao={{
            titulo: 'Lista de documentos de venda',
            periodo: periodo?.[0] && periodo?.[1] ? `${periodo[0].format('DD/MM/YYYY')} a ${periodo[1].format('DD/MM/YYYY')}` : undefined,
            filtros: [
              tipo && `Tipo: ${TIPOS_DOCUMENTO[tipo] ?? tipo}`,
              estado && `Estado: ${estado}`,
              contabilizado && (contabilizado === '1' ? 'Contabilizados' : 'Por contabilizar'),
              pesquisa && `Pesquisa: ${pesquisa}`,
              estadoFe && `Estado AGT: ${INFO_ESTADO_AGT[estadoFe].rotulo}`,
            ],
          }}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
    </>
  );
}
