import { Alert, Badge, Button, Card, Col, Empty, Row, Select, Space, Table, Tag, theme, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
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
import { ModalGerarInventario } from './Modais';
import type { Inventario, Planta, ResumoEdificios } from './tipos';

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

/**
 * O legado não tem separadores aqui (renderAssetBuildings :133-143): o topo dos edifícios tem o botão «Folhas de
 * Inventário (N)» e a lista das folhas tem «← Edifícios» (renderAssetInventories :623-628). `?separador=inventarios`
 * mantém o endereço da lista das folhas.
 */
function Painel() {
  const [params, setParams] = useSearchParams();
  const folhas = params.get('separador') === 'inventarios';
  return (
    <>
      <NavActivos actual="activos_edificios" />
      {folhas
        ? <Folhas aoVoltar={() => setParams({})} />
        : <Cartoes aoAbrirFolhas={() => setParams({ separador: 'inventarios' })} />}
    </>
  );
}

/** «Inventário» do cartão (assetInventarioModal do edifício): carrega a planta e abre a geração da folha. */
function GerarFolhaEdificio({ edificioId, aoFechar }: { edificioId: number; aoFechar: () => void }) {
  const q = useQuery({ queryKey: ['activos', 'edificios', 'planta', edificioId], queryFn: () => obter<Planta>(`/ativos/edificios/${edificioId}/planta`) });
  return <ModalGerarInventario planta={q.data} pedido={q.data ? { ambito: 'EDIFICIO' } : null} aoFechar={aoFechar} />;
}

/** Número pequeno com rótulo por cima, como os `.aed-num` do legado (activos_edificios.js:39-41). */
function Numero({ rotulo, children }: { rotulo: string; children: ReactNode }) {
  const { token } = theme.useToken();
  return (
    <div style={{ fontSize: 12, color: token.colorTextSecondary }}>
      {rotulo}
      <strong style={{ display: 'block', fontSize: 16, color: token.colorText, fontVariantNumeric: 'tabular-nums' }}>{children}</strong>
    </div>
  );
}

/** Cartões por edifício (renderAssetBuildings :99-153). */
function Cartoes({ aoAbrirFolhas }: { aoAbrirFolhas: () => void }) {
  const { token } = theme.useToken();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [novo, setNovo] = useState(false);
  const [inventariar, setInventariar] = useState<number | null>(null);
  const q = useQuery({ queryKey: ['activos', 'edificios'], queryFn: () => obter<ResumoEdificios>('/ativos/edificios') });
  const d = q.data;
  const podeNovo = pode('activos_gerir');
  const podeInventariar = pode('activos_inventario_fisico');
  const botaoNovo = () => podeNovo && <Button type="primary" size="small" icon={<IconeFa nome="plus" />} onClick={() => setNovo(true)}>Novo Edifício / Local</Button>;
  return (
    <Card
      loading={q.isLoading}
      title={<span><IconeFa nome="city" /> Edifícios e Locais <small style={{ fontWeight: 400 }}>({d?.edificios.length ?? 0})</small></span>}
      extra={(
        <Space wrap size={8}>
          <Button size="small" icon={<IconeFa nome="clipboard-check" />} onClick={aoAbrirFolhas}>
            Folhas de Inventário <Badge count={d?.inventarios ?? 0} showZero color="#64748b" size="small" />
          </Button>
          {botaoNovo()}
        </Space>
      )}
    >
      <Typography.Paragraph type="secondary" style={{ marginTop: 0 }}>Activos do tipo edifício/local com andares e portas numeradas; os restantes bens ficam localizados numa porta.</Typography.Paragraph>
      {!!d?.sem_local && (
        <Alert type="info" showIcon style={{ marginBottom: 12 }}
          message={`${d.sem_local} bem(ns) ainda sem porta atribuída (sem localização) — abra a planta de um edifício e arraste-os para a porta respectiva.`} />
      )}
      {d && !d.edificios.length && (
        <Empty image={<IconeFa nome="city" style={{ fontSize: 32, color: '#cbd5e1' }} />}
          description={<>Ainda não há edifícios ou locais registados.<br /><small>Crie um activo do tipo «Edifício / Local» e depois acrescente os andares e as portas.</small></>}>
          {botaoNovo()}
        </Empty>
      )}
      <Row gutter={[16, 16]}>
        {d?.edificios.map((e) => (
          <Col key={e.id} xs={24} sm={12} xl={8}>
            <Card size="small" style={{ borderLeft: '4px solid #b45309' }}
              title={<div><div style={{ fontSize: 12, color: token.colorTextSecondary, fontWeight: 700 }}>{e.codigo}</div>{e.descricao || 'Edifício sem descrição'}</div>}
              actions={[
                <Space key="a" size={6} wrap style={{ padding: '0 12px', width: '100%' }}>
                  <Button type="primary" size="small" icon={<IconeFa nome="building" />} onClick={() => navegar(`planta/${e.id}`)}>Planta</Button>
                  {podeInventariar && <Button size="small" icon={<IconeFa nome="clipboard-list" />} onClick={() => setInventariar(e.id)}>Inventário</Button>}
                  <Button size="small" title="Editar a ficha do activo" aria-label="Editar a ficha do activo" icon={<IconeFa nome="edit" />} onClick={() => navegar(`/m/activos/activos/${e.id}`)} />
                </Space>,
              ]}>
              <Space size={18} wrap>
                <Numero rotulo="Andares">{e.andares}</Numero>
                <Numero rotulo="Portas">{e.portas}</Numero>
                <Numero rotulo="Bens dentro">{e.bens}{e.sem_porta ? <small title="Bens no edifício sem porta" style={{ fontWeight: 400 }}> ({e.sem_porta} s/ porta)</small> : null}</Numero>
                <Numero rotulo="Valor dos bens"><span style={{ fontSize: 13.6 }}>{formatarKz(e.valor_bens)}</span></Numero>
              </Space>
              {e.estado !== 'ACTIVO' && <Tag style={{ marginTop: 8 }}>{e.estado}</Tag>}
            </Card>
          </Col>
        ))}
      </Row>
      <ModalActivo aberto={novo} tipoInicial="EDIFICIO" aoFechar={() => setNovo(false)} />
      {inventariar !== null && <GerarFolhaEdificio edificioId={inventariar} aoFechar={() => setInventariar(null)} />}
    </Card>
  );
}

/** Lista das folhas de inventário (renderAssetInventories :612-661). A folha anula-se com motivo (não se apaga). */
function Folhas({ aoVoltar }: { aoVoltar: () => void }) {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [estado, setEstado] = useState<string>();
  const [anular, setAnular] = useState<Inventario | null>(null);
  const q = useListaPaginada<Inventario>(['activos', 'inventarios-fisicos'], '/ativos/inventarios-fisicos', { estado });
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: () => setAnular(null) });
  return (
    <Card title={<Space wrap><Button size="small" icon={<IconeFa nome="arrow-left" />} onClick={aoVoltar}>Edifícios</Button>
      <span>Folhas de Inventário <small style={{ fontWeight: 400 }}>({q.paginacao.total ?? 0})</small></span></Space>}
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
          { title: 'Contados', key: 'c', align: 'right', render: (_, i) => <>{i.contados}{i.por_contar ? <Typography.Text type="secondary" style={{ fontSize: 12 }}> ({i.por_contar} por contar)</Typography.Text> : null}</> },
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
