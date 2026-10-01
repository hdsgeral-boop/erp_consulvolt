import { Button, Card, Flex, Input, Select, Typography } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { useState } from 'react';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { EtiquetaProjectos } from './comum/componentes';
import type { Projecto } from './comum/tipos';
import { DetalheProjecto } from './DetalheProjecto';
import { ModalProjecto } from './ModalProjecto';

/** Projectos › Carteira (ecrã projectos_carteira): lista dos projectos e detalhe com os separadores de planeamento, equipa, orçamento e execução. */
export default function Carteira() {
  return (
    <Routes>
      <Route index element={<ListaProjectos />} />
      <Route path=":id/*" element={<DetalheProjecto />} />
    </Routes>
  );
}

function ListaProjectos() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [pesquisa, setPesquisa] = useState('');
  const [estado, setEstado] = useState<string>();
  const [tipo, setTipo] = useState<string>();
  const [novo, setNovo] = useState(false);

  const colunas: ColumnsType<Projecto> = [
    { title: 'Código', dataIndex: 'codigo', render: (v) => <Typography.Text strong>{v ?? '—'}</Typography.Text> },
    { title: 'Nome', dataIndex: 'nome', ellipsis: true, width: 360 },
    { title: 'Tipo', dataIndex: 'tipo', render: (v) => <EtiquetaProjectos valor={v} /> },
    { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaProjectos valor={v} /> },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Carteira de projectos"
        subtitulo="Projectos internos e obras: planeamento, equipa, orçamento, execução e facturação"
        accoes={pode('proj_gerir') && <Button type="primary" icon={<PlusOutlined />} onClick={() => setNovo(true)}>Novo projecto</Button>}
      />
      <Card>
        <Flex gap={8} wrap style={{ marginBottom: 16 }}>
          <Input.Search placeholder="Código ou nome" allowClear onSearch={setPesquisa} style={{ width: 260 }} />
          <Select placeholder="Estado" allowClear value={estado} onChange={setEstado} style={{ width: 170 }}
            options={[{ value: 'PREPARACAO', label: 'Em preparação' }, { value: 'ACTIVO', label: 'Activo / em curso' }, { value: 'ENCERRADO', label: 'Encerrado' }, { value: 'CANCELADO', label: 'Cancelado' }]} />
          <Select placeholder="Tipo" allowClear value={tipo} onChange={setTipo} style={{ width: 150 }}
            options={[{ value: 'INTERNO', label: 'Interno' }, { value: 'EXTERNO', label: 'Externo (obra)' }]} />
        </Flex>
        <TabelaApi<Projecto>
          url="/projetos"
          chaveConsulta={['projectos', 'lista']}
          filtros={{ pesquisa, estado, tipo }}
          columns={colunas}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ModalProjecto aberto={novo} aoFechar={() => setNovo(false)} aoGravar={(p) => navegar(String(p.id))} />
    </>
  );
}
