import { Button, Card, Flex, Input, Select } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { useState } from 'react';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EstadoTag, opcoesEstado } from '../comum/estados';
import { NomeTerceiro } from '../comum/referencias';
import { SeletorTerceiro } from '../comum/Seletores';
import { TabelaApi } from '@/componentes/TabelaApi';
import type { ContratoCompra } from '../comum/tipos';
import { DetalheContrato } from './DetalheContrato';
import { ModalContrato } from './ModalContrato';

/** Compras › Contratos de compra (ecrã compras_contratos): contratos com fornecedores, encomendas associadas e marcos. */
export default function Contratos() {
  return (
    <Routes>
      <Route index element={<ListaContratos />} />
      <Route path=":id" element={<DetalheContrato />} />
    </Routes>
  );
}

function ListaContratos() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [estado, setEstado] = useState<string>();
  const [fornecedor, setFornecedor] = useState<number>();
  const [novo, setNovo] = useState(false);
  const [pesquisa, setPesquisa] = useState('');

  const colunas: ColumnsType<ContratoCompra> = [
    { title: 'Referência', dataIndex: 'referencia', fixed: 'left', render: (v: string) => <strong>{v}</strong> },
    { title: 'Fornecedor', key: 'fornecedor', render: (_, r) => <NomeTerceiro id={r.fornecedor_id} terceiro={r.fornecedor} /> },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, render: (v) => v || '—' },
    { title: 'Início', dataIndex: 'data_inicio', render: formatarData },
    { title: 'Fim', dataIndex: 'data_fim', render: formatarData },
    { title: 'Valor (Kz)', dataIndex: 'valor_total', align: 'right', render: (v: string) => formatarKz(v) },
    { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Contratos de compra"
        subtitulo="Contratos com fornecedores: consumo pelas encomendas, facturas e marcos de pagamento"
        accoes={
          pode('compras_contratos_gerir') && (
            <Button type="primary" icon={<PlusOutlined />} onClick={() => setNovo(true)}>
              Novo contrato
            </Button>
          )
        }
      />
      <Card>
        <Flex gap={8} wrap style={{ marginBottom: 16 }}>
          <Select placeholder="Estado" allowClear style={{ width: 180 }} value={estado} onChange={setEstado} options={opcoesEstado(['ATIVO', 'EXPIRADO', 'CANCELADO'])} />
          <SeletorTerceiro papel="FORNECEDOR" style={{ width: 320 }} value={fornecedor} onChange={setFornecedor} />
          <Input.Search placeholder="Referência" allowClear style={{ width: 200 }} onSearch={(v) => setPesquisa(v.trim())} />
        </Flex>
        <TabelaApi<ContratoCompra>
          url="/compras/contratos"
          filtros={{ estado, fornecedor_id: fornecedor, pesquisa: pesquisa || undefined }}
          chaveConsulta={['compras', 'contratos']}
          columns={colunas}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ModalContrato aberto={novo} aoFechar={() => setNovo(false)} aoGravar={(c) => navegar(String(c.id))} />
    </>
  );
}
