import { Alert, Badge, Button, Card, Col, Empty, Row, Select, Space, Statistic, Table, Tabs, Tag } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Route, Routes, useNavigate, useSearchParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { NavActivos } from '../comum/NavActivos';
import { useListaPaginada } from '../comum/paginacao';
import { ModalActivo } from '../ModalActivo';
import { ComparacaoInventarioEcra, FolhaInventarioEcra } from './Folha';
import { PlantaEdificio } from './Planta';
import type { Inventario, ResumoEdificios } from './tipos';

/**
 * Activos › Edifícios e Locais (ecrã activos_edificios — Fase F, Versão Actual; js/activos_edificios.js). Cartões dos
 * edifícios, planta (andares › portas › bens), folhas de inventário físico (contagem, comparação, aplicar, fechar).
 */
export default function Edificios() {
  return (
    <Routes>
      <Route index element={<Painel />} />
      <Route path="planta/:id" element={<PlantaEdificio />} />
      <Route path="inventario/:id" element={<FolhaInventarioEcra />} />
      <Route path="inventario/:id/comparacao" element={<ComparacaoInventarioEcra />} />
    </Routes>
  );
}

function Painel() {
  const [params, setParams] = useSearchParams();
  const separador = params.get('separador') === 'inventarios' ? 'inventarios' : 'edificios';
  return (
    <>
      <NavActivos actual="activos_edificios" />
      <Tabs
        activeKey={separador}
        onChange={(k) => setParams(k === 'inventarios' ? { separador: k } : {})}
        destroyOnHidden
        items={[
          { key: 'edificios', label: <span><IconeFa nome="city" /> Edifícios e Locais</span>, children: <Cartoes /> },
          { key: 'inventarios', label: <span><IconeFa nome="clipboard-check" /> Folhas de Inventário</span>, children: <Folhas /> },
        ]}
      />
    </>
  );
}

/** Cartões por edifício (renderAssetBuildings :99-153). */
function Cartoes() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [novo, setNovo] = useState(false);
  const q = useQuery({ queryKey: ['activos', 'edificios'], queryFn: () => obter<ResumoEdificios>('/ativos/edificios') });
  const d = q.data;
  return (
    <Card
      loading={q.isLoading}
      title={<span><IconeFa nome="city" /> Edifícios e Locais <small style={{ fontWeight: 400 }}>({d?.edificios.length ?? 0})</small></span>}
      extra={pode('activos_gerir') && <Button type="primary" size="small" icon={<IconeFa nome="plus" />} onClick={() => setNovo(true)}>Novo Edifício / Local</Button>}
    >
      <p style={{ color: '#64748b', marginTop: 0 }}>Activos do tipo edifício/local com andares e portas numeradas; os restantes bens ficam localizados numa porta.</p>
      {!!d?.sem_local && (
        <Alert type="info" showIcon style={{ marginBottom: 12 }}
          message={`${d.sem_local} bem(ns) ainda sem porta atribuída — abra a planta de um edifício e arraste-os para a porta respectiva.`} />
      )}
      {d && !d.edificios.length && (
        <Empty description={<>Ainda não há edifícios ou locais registados.<br />Crie um activo do tipo «Edifício / Local» e depois acrescente os andares e as portas.</>} />
      )}
      <Row gutter={[16, 16]}>
        {d?.edificios.map((e) => (
          <Col key={e.id} xs={24} sm={12} xl={8}>
            <Card size="small" style={{ borderLeft: '4px solid #b45309' }}
              title={<div><div style={{ fontSize: 12, color: '#64748b' }}>{e.codigo}</div>{e.descricao || 'Edifício sem descrição'}</div>}
              actions={[
                <Button key="p" type="link" icon={<IconeFa nome="building" />} onClick={() => navegar(`planta/${e.id}`)}>Planta</Button>,
                <Button key="f" type="link" title="Editar a ficha do activo" icon={<IconeFa nome="edit" />} onClick={() => navegar(`/m/activos/activos/${e.id}`)}>Ficha</Button>,
              ]}>
              <Row gutter={8}>
                <Col span={6}><Statistic title="Andares" value={e.andares} /></Col>
                <Col span={6}><Statistic title="Portas" value={e.portas} /></Col>
                <Col span={6}><Statistic title="Bens" value={e.bens} /></Col>
                <Col span={6}><div style={{ fontSize: 12, color: '#64748b' }}>Valor dos bens</div><strong>{formatarKz(e.valor_bens)}</strong></Col>
              </Row>
              {e.estado !== 'ACTIVO' && <Tag style={{ marginTop: 8 }}>{e.estado}</Tag>}
            </Card>
          </Col>
        ))}
      </Row>
      <ModalActivo aberto={novo} tipoInicial="EDIFICIO" aoFechar={() => setNovo(false)} />
    </Card>
  );
}

/** Lista das folhas de inventário (renderAssetInventories :612-661). A folha anula-se com motivo (não se apaga). */
function Folhas() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [estado, setEstado] = useState<string>();
  const [anular, setAnular] = useState<Inventario | null>(null);
  const q = useListaPaginada<Inventario>(['activos', 'inventarios-fisicos'], '/ativos/inventarios-fisicos', { estado });
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: () => setAnular(null) });
  return (
    <Card title={<span>Folhas de Inventário <Badge count={q.paginacao.total} showZero color="#64748b" /></span>}
      extra={<Select allowClear placeholder="Em uso (abertas e fechadas)" value={estado} onChange={setEstado} style={{ width: 220 }}
        options={[{ value: 'ABERTO', label: 'Abertas' }, { value: 'FECHADO', label: 'Fechadas' }, { value: 'ANULADO', label: 'Anuladas' }]} />}>
      <Table<Inventario> rowKey="id" size="middle" loading={q.isFetching} dataSource={q.itens} pagination={q.paginacao} scroll={{ x: 'max-content' }}
        locale={{ emptyText: 'Ainda não há folhas de inventário. Gere uma a partir da planta de um edifício.' }}
        columns={[
          { title: 'Folha', dataIndex: 'numero', render: (v) => <strong>{v}</strong> },
          { title: 'Edifício', key: 'e', render: (_, i) => `${i.edificio?.codigo ?? ''} ${i.edificio?.descricao ?? ''}`.trim() || '—' },
          { title: 'Âmbito', key: 'a', render: (_, i) => <span><Tag>{i.ambito}</Tag>{i.ambito_texto}</span> },
          { title: 'Data', dataIndex: 'data', render: formatarData },
          { title: 'Responsável', dataIndex: 'responsavel', render: (v) => v || '—' },
          { title: 'Esperados', dataIndex: 'previstos', align: 'right' },
          { title: 'Contados', key: 'c', align: 'right', render: (_, i) => <>{i.contados}{i.por_contar ? <small style={{ color: '#64748b' }}> ({i.por_contar} por contar)</small> : null}</> },
          { title: 'Diferenças', dataIndex: 'diferencas', align: 'right', render: (v) => <strong style={{ color: v ? '#dc2626' : undefined }}>{v}</strong> },
          { title: 'Estado', key: 's', render: (_, i) => <Space size={4}><Tag color={i.estado === 'ABERTO' ? 'green' : i.estado === 'ANULADO' ? 'red' : 'default'}>{i.estado}</Tag>
            {i.aplicado_em && <Tag color="blue">aplicado</Tag>}</Space> },
          { title: 'Acções', key: 'acc', align: 'right', render: (_, i) => (
            <Space size={4}>
              <Button size="small" title="Abrir folha" icon={<IconeFa nome="clipboard-check" />} onClick={() => navegar(`inventario/${i.id}`)} />
              <Button size="small" title="Comparar" icon={<IconeFa nome="balance-scale" />} onClick={() => navegar(`inventario/${i.id}/comparacao`)} />
              {pode('activos_inventario_fisico') && i.estado === 'ABERTO' && (
                <Button size="small" danger title="Anular folha" icon={<IconeFa nome="ban" />} onClick={() => setAnular(i)} />
              )}
            </Space>
          ) },
        ]} />
      <ModalMotivo aberto={!!anular} titulo={`Anular a folha ${anular?.numero ?? ''}`} textoOk="Anular" carregando={accao.isPending}
        aviso="A folha e a contagem ficam registadas como anuladas; as localizações dos bens não são alteradas."
        aoConfirmar={(motivo) => anular && accao.mutate({ url: `/ativos/inventarios-fisicos/${anular.id}/anular`, dados: { motivo } })}
        aoFechar={() => setAnular(null)} />
    </Card>
  );
}
