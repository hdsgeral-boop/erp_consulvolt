import { Button, Card, Input, Select } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { BarraFiltros, useEcraPequeno } from '@/componentes/responsivo';
import { useState } from 'react';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EstadoTag, opcoesEstado, rotuloEstado } from '../comum/estados';
import { NomeTerceiro } from '../comum/referencias';
import { SeletorTerceiro } from '../comum/Seletores';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
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
  const [nomeFornecedor, setNomeFornecedor] = useState<string>();
  const pequeno = useEcraPequeno();

  const colunas: ColunaApi<ContratoCompra>[] = [
    { title: 'Referência', dataIndex: 'referencia', fixed: 'left', render: (v: string) => <strong>{v}</strong> },
    { title: 'Fornecedor', key: 'fornecedor', valorImpressao: (r) => r.fornecedor?.nome?.trim() ?? `#${r.fornecedor_id}`, render: (_, r) => <NomeTerceiro id={r.fornecedor_id} terceiro={r.fornecedor} /> },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, responsive: ['lg'], render: (v) => v || '—' },
    { title: 'Início', dataIndex: 'data_inicio', responsive: ['md'], render: formatarData },
    { title: 'Fim', dataIndex: 'data_fim', responsive: ['md'], render: formatarData },
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
        <BarraFiltros>
          <Select placeholder="Estado" allowClear style={{ width: 180 }} value={estado} onChange={setEstado} options={opcoesEstado(['ATIVO', 'EXPIRADO', 'CANCELADO'])} />
          <SeletorTerceiro papel="FORNECEDOR" style={{ width: 320, maxWidth: '100%' }} value={fornecedor} onChange={(v, o) => { setFornecedor(v); setNomeFornecedor(o && !Array.isArray(o) && o.label ? String(o.label) : undefined); }} />
          <Input.Search placeholder="Referência" allowClear style={{ width: 200, maxWidth: '100%' }} onSearch={(v) => setPesquisa(v.trim())} />
        </BarraFiltros>
        <TabelaApi<ContratoCompra>
          url="/compras/contratos"
          filtros={{ estado, fornecedor_id: fornecedor, pesquisa: pesquisa || undefined }}
          chaveConsulta={['compras', 'contratos']}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          impressao={{
            titulo: 'Lista de contratos de compra',
            filtros: [estado && `Estado: ${rotuloEstado(estado)}`, !!fornecedor && `Fornecedor: ${nomeFornecedor ?? `#${fornecedor}`}`, pesquisa && `Pesquisa: ${pesquisa}`],
          }}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ModalContrato aberto={novo} aoFechar={() => setNovo(false)} aoGravar={(c) => navegar(String(c.id))} />
    </>
  );
}
