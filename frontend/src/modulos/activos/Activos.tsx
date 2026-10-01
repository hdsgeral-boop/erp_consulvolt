import { Button, Card, Checkbox, Flex, Input, Popconfirm, Select, Space, Table, Tabs, Typography } from 'antd';
import { DeleteOutlined, EditOutlined, ImportOutlined, PlusOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeletorAux } from '@/modulos/contab/comum/Seletores';
import { BotaoCsv, ValorKz } from '@/modulos/contab/comum/Componentes';
import { somarColunas } from '@/utilitarios/decimal';
import { useAccao } from '@/componentes/Accoes';
import { formatarData } from '@/utilitarios/formatacao';
import { EtiquetaActivos, SeletorActivo, SeletorCategoria, SeletorProjecto } from './comum/componentes';
import type { Activo, Afectacao, ResumoCategoria, Transferencia } from './comum/tipos';
import { DetalheActivo } from './DetalheActivo';
import { ModalActivo } from './ModalActivo';
import { ModalAfectacao, ModalEdicaoMassa, ModalImportarActivos } from './ModaisActivos';

/** Activos › Cadastro e gestão (ecrã activos): cadastro, ficha com histórico, importação, edição em massa, transferências e afectações. */
export default function Activos() {
  return (
    <Routes>
      <Route index element={<Painel />} />
      <Route path=":id" element={<DetalheActivo />} />
    </Routes>
  );
}

function Painel() {
  return (
    <>
      <CabecalhoPagina titulo="Activos imobilizados" subtitulo="Cadastro, transferências de centro de custo e afectações a projectos" />
      <Tabs
        destroyInactiveTabPane
        items={[
          { key: 'cadastro', label: 'Cadastro', children: <Cadastro /> },
          { key: 'transferencias', label: 'Transferências', children: <Transferencias /> },
          { key: 'afectacoes', label: 'Afectações a projectos', children: <Afectacoes /> },
          { key: 'categorias', label: 'Resumo por categoria', children: <ResumoCategorias /> },
        ]}
      />
    </>
  );
}

function Cadastro() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [texto, setTexto] = useState('');
  const [estado, setEstado] = useState<string>();
  const [categoria, setCategoria] = useState<number>();
  const [centro, setCentro] = useState<number>();
  const [semLancamento, setSemLancamento] = useState(false);
  const [seleccao, setSeleccao] = useState<number[]>([]);
  const [novo, setNovo] = useState(false);
  const [importar, setImportar] = useState(false);
  const [massa, setMassa] = useState(false);
  const eliminar = useAccao<{ eliminados: number }>({ invalidar: [['activos']], aoSucesso: () => setSeleccao([]) });

  const colunas: ColumnsType<Activo> = [
    { title: 'Código', dataIndex: 'codigo', fixed: 'left', render: (v, r) => <Typography.Link strong onClick={() => navegar(String(r.id))}>{v ?? '—'}</Typography.Link> },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 320 },
    { title: 'Categoria', key: 'cat', render: (_, r) => r.categoria_ativo?.nome ?? '—' },
    { title: 'Centro de custo', key: 'cc', render: (_, r) => r.centro_custo?.codigo ?? '—' },
    { title: 'Aquisição', dataIndex: 'data_aquisicao', render: formatarData },
    { title: 'Valor (Kz)', dataIndex: 'valor_aquisicao', align: 'right', render: (v) => <ValorKz valor={v} /> },
    { title: 'Amort. acumulada', dataIndex: 'amortizacao_acumulada', align: 'right', render: (v) => <ValorKz valor={v} /> },
    { title: 'Valor líquido', dataIndex: 'valor_liquido', align: 'right', render: (v) => <ValorKz valor={v} forte /> },
    { title: 'Taxa', dataIndex: 'taxa', align: 'right', render: (v) => (v ? `${Number(v).toLocaleString('pt-PT')}%` : '—') },
    { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaActivos valor={v} /> },
  ];

  return (
    <Card>
      <Flex gap={8} wrap justify="space-between" style={{ marginBottom: 16 }}>
        <Flex gap={8} wrap>
          <Input.Search placeholder="Código ou descrição" allowClear onSearch={setTexto} style={{ width: 240 }} />
          <Select placeholder="Estado" allowClear value={estado} onChange={setEstado} style={{ width: 140 }}
            options={[{ value: 'ACTIVO', label: 'Activo' }, { value: 'INACTIVO', label: 'Inactivo' }, { value: 'ABATIDO', label: 'Abatido' }]} />
          <SeletorCategoria allowClear value={categoria} onChange={setCategoria} style={{ width: 220 }} />
          <SeletorAux tabela="centros-custo" placeholder="Centro de custo" value={centro} onChange={setCentro} style={{ width: 200 }} />
          <Checkbox checked={semLancamento} onChange={(e) => setSemLancamento(e.target.checked)} style={{ alignSelf: 'center' }}>
            Sem lançamento de compra
          </Checkbox>
        </Flex>
        <Space wrap>
          {pode('activos_gerir') && seleccao.length > 0 && (
            <Button icon={<EditOutlined />} onClick={() => setMassa(true)}>Editar {seleccao.length} seleccionado(s)</Button>
          )}
          {pode('activos_eliminar') && seleccao.length > 0 && (
            <Popconfirm title={`Eliminar ${seleccao.length} activo(s)?`} description="Os activos com amortizações, abates ou lançamentos não podem ser eliminados." okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }}
              onConfirm={() => eliminar.mutate({ url: '/ativos/bens/eliminar', dados: { ids: seleccao } })}>
              <Button danger icon={<DeleteOutlined />} loading={eliminar.isPending}>Eliminar</Button>
            </Popconfirm>
          )}
          {pode('activos_gerir') && <Button icon={<ImportOutlined />} onClick={() => setImportar(true)}>Importar</Button>}
          {pode('activos_gerir') && <Button type="primary" icon={<PlusOutlined />} onClick={() => setNovo(true)}>Novo activo</Button>}
        </Space>
      </Flex>
      <TabelaApi<Activo>
        url="/ativos/bens"
        chaveConsulta={['activos', 'bens']}
        filtros={{ texto, estado, categoria_ativo_id: categoria, centro_custo_id: centro, sem_lancamento: semLancamento ? 1 : undefined }}
        porPagina={50}
        columns={colunas}
        rowSelection={pode('activos_gerir', 'activos_eliminar') ? { selectedRowKeys: seleccao, onChange: (k) => setSeleccao(k as number[]), preserveSelectedRowKeys: true } : undefined}
        rowKey="id"
        onRow={(r) => ({ onDoubleClick: () => navegar(String(r.id)) })}
      />
      <ModalActivo aberto={novo} aoFechar={() => setNovo(false)} aoGravar={(a) => navegar(String(a.id))} />
      <ModalImportarActivos aberto={importar} aoFechar={() => setImportar(false)} />
      <ModalEdicaoMassa aberto={massa} ids={seleccao} aoFechar={() => setMassa(false)} />
    </Card>
  );
}

function Transferencias() {
  const [activo, setActivo] = useState<number>();
  const [projecto, setProjecto] = useState<number>();
  const cc = (c?: { codigo: string; descricao: string | null } | null) => (c ? `${c.codigo} — ${c.descricao ?? ''}` : '—');
  return (
    <Card>
      <Flex gap={8} wrap style={{ marginBottom: 16 }}>
        <SeletorActivo allowClear value={activo} onChange={setActivo} style={{ width: 320 }} />
        <SeletorProjecto allowClear value={projecto} onChange={setProjecto} style={{ width: 260 }} />
      </Flex>
      <TabelaApi<Transferencia>
        url="/ativos/transferencias"
        chaveConsulta={['activos', 'transferencias']}
        filtros={{ ativo_imobilizado_id: activo, projeto_id: projecto }}
        columns={[
          { title: 'Data', dataIndex: 'data', render: formatarData },
          { title: 'Activo', key: 'a', render: (_, r) => (r.ativo_imobilizado ? `${r.ativo_imobilizado.codigo} — ${r.ativo_imobilizado.descricao}` : r.ativo_imobilizado_id) },
          { title: 'Origem', key: 'o', render: (_, r) => cc(r.centro_custo_origem) },
          { title: 'Destino', key: 'd', render: (_, r) => cc(r.centro_custo_destino) },
        ]}
      />
    </Card>
  );
}

function Afectacoes() {
  const { pode } = useSessao();
  const [projecto, setProjecto] = useState<number>();
  const [editar, setEditar] = useState<Afectacao | null | undefined>(undefined);
  const q = useQuery({ queryKey: ['activos', 'afetacoes', projecto], queryFn: () => obter<Afectacao[]>('/ativos/afetacoes', { projeto_id: projecto }) });
  const eliminar = useAccao({ invalidar: [['activos']] });
  return (
    <Card>
      <Flex gap={8} wrap justify="space-between" style={{ marginBottom: 16 }}>
        <SeletorProjecto allowClear value={projecto} onChange={setProjecto} style={{ width: 300 }} />
        {pode('activos_gerir') && <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditar(null)}>Nova afectação</Button>}
      </Flex>
      <Table<Afectacao>
        rowKey="id"
        size="middle"
        loading={q.isFetching}
        dataSource={q.data}
        columns={[
          { title: 'Activo', key: 'a', render: (_, r) => (r.ativo_imobilizado ? `${r.ativo_imobilizado.codigo} — ${r.ativo_imobilizado.descricao}` : r.ativo_imobilizado_id) },
          { title: 'Projecto', key: 'p', render: (_, r) => (r.projeto ? `${r.projeto.codigo ?? ''} — ${r.projeto.nome}` : r.projeto_id) },
          { title: 'Início', dataIndex: 'data_inicio', render: formatarData },
          { title: 'Fim', dataIndex: 'data_fim', render: formatarData },
          {
            title: '', key: 'acc', align: 'right',
            render: (_, r) => pode('activos_gerir') && (
              <Space>
                <Button size="small" icon={<EditOutlined />} onClick={() => setEditar(r)} />
                <Popconfirm title="Eliminar a afectação?" okText="Eliminar" cancelText="Cancelar" onConfirm={() => eliminar.mutate({ metodo: 'delete', url: `/ativos/afetacoes/${r.id}` })}>
                  <Button size="small" danger icon={<DeleteOutlined />} />
                </Popconfirm>
              </Space>
            ),
          },
        ]}
      />
      <ModalAfectacao aberto={editar !== undefined} afectacao={editar} aoFechar={() => setEditar(undefined)} />
    </Card>
  );
}

function ResumoCategorias() {
  const q = useQuery({ queryKey: ['activos', 'resumo-categorias'], queryFn: () => obter<ResumoCategoria[]>('/ativos/mapas/categorias') });
  const totais = somarColunas(q.data ?? [], ['bruto', 'acumulada', 'liquido']);
  return (
    <Card extra={<BotaoCsv nome="imobilizado-por-categoria" linhas={q.data} colunas={[
      { titulo: 'Categoria', valor: (l) => l.categoria }, { titulo: 'Activos', valor: (l) => l.ativos },
      { titulo: 'Valor bruto', valor: (l) => l.bruto, numerico: true }, { titulo: 'Amortização acumulada', valor: (l) => l.acumulada, numerico: true },
      { titulo: 'Valor líquido', valor: (l) => l.liquido, numerico: true },
    ]} />}>
      <Table<ResumoCategoria>
        rowKey="categoria_ativo_id"
        size="middle"
        loading={q.isFetching}
        dataSource={q.data}
        pagination={false}
        columns={[
          { title: 'Categoria', dataIndex: 'categoria' },
          { title: 'Activos', dataIndex: 'ativos', align: 'right' },
          { title: 'Valor bruto (Kz)', dataIndex: 'bruto', align: 'right', render: (v) => <ValorKz valor={v} /> },
          { title: 'Amortização acumulada', dataIndex: 'acumulada', align: 'right', render: (v) => <ValorKz valor={v} /> },
          { title: 'Valor líquido', dataIndex: 'liquido', align: 'right', render: (v) => <ValorKz valor={v} forte /> },
        ]}
        summary={() => (
          <Table.Summary.Row>
            <Table.Summary.Cell index={0} colSpan={2}><Typography.Text strong>Total</Typography.Text></Table.Summary.Cell>
            <Table.Summary.Cell index={2} align="right"><ValorKz valor={totais.bruto} forte /></Table.Summary.Cell>
            <Table.Summary.Cell index={3} align="right"><ValorKz valor={totais.acumulada} forte /></Table.Summary.Cell>
            <Table.Summary.Cell index={4} align="right"><ValorKz valor={totais.liquido} forte /></Table.Summary.Cell>
          </Table.Summary.Row>
        )}
      />
    </Card>
  );
}
