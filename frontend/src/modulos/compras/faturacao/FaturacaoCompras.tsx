import { Button, Card, Checkbox, DatePicker, Flex, Select } from 'antd';
import { CheckCircleTwoTone, PlusOutlined, SettingOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { EstadoTag, opcoesEstado } from '../comum/estados';
import { ModalContas } from '../comum/ModalContas';
import { NomeTerceiro } from '../comum/referencias';
import { SeletorTerceiro } from '../comum/Seletores';
import { TabelaServidor } from '../comum/Tabelas';
import type { FaturaCompra } from '../comum/tipos';
import { ValorMoeda } from '../comum/Valores';
import { DetalheFatura } from './DetalheFatura';
import { NovaFatura } from './NovaFatura';

/** Compras › Facturas de fornecedores (ecrã compras_faturacao). */
export default function FaturacaoCompras() {
  return (
    <Routes>
      <Route index element={<ListaFaturas />} />
      <Route path="novo" element={<NovaFatura />} />
      <Route path=":id" element={<DetalheFatura />} />
    </Routes>
  );
}

function ListaFaturas() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [estado, setEstado] = useState<string>();
  const [fornecedor, setFornecedor] = useState<number>();
  const [porContabilizar, setPorContabilizar] = useState(false);
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [contas, setContas] = useState(false);

  const colunas: ColumnsType<FaturaCompra> = [
    { title: 'Factura', dataIndex: 'numero_fatura', fixed: 'left', render: (v: string) => <strong>{v}</strong> },
    { title: 'Data', dataIndex: 'data', render: formatarData },
    { title: 'Fornecedor', key: 'fornecedor', render: (_, r) => <NomeTerceiro id={r.fornecedor_id} /> },
    { title: 'Encomenda', dataIndex: 'encomenda_compra_id', render: (v: number | null) => (v ? `#${v}` : 'Directa') },
    { title: 'Total (Kz)', key: 'total', align: 'right', render: (_, r) => <ValorMoeda kz={r.montante_total} moeda={r.codigo_moeda} valorMoeda={r.montante_total_moeda} /> },
    { title: 'Vencimento', dataIndex: 'data_vencimento', render: formatarData },
    { title: 'Estado', dataIndex: 'estado', render: (e: string | null) => <EstadoTag estado={e} /> },
    { title: 'Contab.', dataIndex: 'contabilizado', align: 'center', render: (c: boolean | null) => (c ? <CheckCircleTwoTone twoToneColor="#52c41a" /> : null) },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Facturas de fornecedores"
        subtitulo="Facturas de encomendas e facturas directas; contabilização no diário de fornecedores"
        accoes={
          <>
            {pode('compras_faturacao_view', 'compras_fact_contabilizar') && (
              <Button icon={<SettingOutlined />} onClick={() => setContas(true)}>
                Contas de compras
              </Button>
            )}
            {pode('compras_fact_registar') && (
              <Button type="primary" icon={<PlusOutlined />} onClick={() => navegar('novo')}>
                Factura directa
              </Button>
            )}
          </>
        }
      />
      <Card>
        <Flex gap={8} wrap align="center" style={{ marginBottom: 16 }}>
          <Select placeholder="Estado" allowClear style={{ width: 180 }} value={estado} onChange={setEstado} options={opcoesEstado(['PENDENTE', 'PARCIAL', 'PAGO', 'ANULADA'])} />
          <SeletorTerceiro papel="FORNECEDOR" style={{ width: 320 }} value={fornecedor} onChange={setFornecedor} />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
          <Checkbox checked={porContabilizar} onChange={(e) => setPorContabilizar(e.target.checked)}>
            Só por contabilizar
          </Checkbox>
        </Flex>
        <TabelaServidor<FaturaCompra>
          url="/compras/faturas"
          chaveConsulta={['compras', 'faturas']}
          filtros={{ estado, fornecedor_id: fornecedor, por_contabilizar: porContabilizar ? 1 : undefined, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunas}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ModalContas
        url="/compras/configuracao/contas"
        titulo="Contas de compras"
        chaveConsulta={['compras', 'contas']}
        aberto={contas}
        aoFechar={() => setContas(false)}
        podeEditar={pode('compras_fact_contabilizar')}
      />
    </>
  );
}
