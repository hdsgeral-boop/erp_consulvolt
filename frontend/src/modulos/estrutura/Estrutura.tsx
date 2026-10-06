import { Alert, Button, Card, Checkbox, Col, ColorPicker, Descriptions, Empty, Flex, Form, Input, InputNumber, List, Modal, Popconfirm, Row, Select, Skeleton, Space, Switch, Table, Tabs, Tag, Tree, Typography } from 'antd';
import { ApartmentOutlined, BlockOutlined, ClusterOutlined, DeleteOutlined, EditOutlined, PlusOutlined, TableOutlined, TeamOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useNavigate } from 'react-router-dom';
import { ModalVariasUnidades, useEstruturaBase } from './comum/ModaisCriacao';
import { tabelaHtml } from '@/componentes/impressao';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAccao } from '@/componentes/Accoes';
import { useColaboradores, useCargos } from '@/modulos/rh/comum/consultas';
import { SeletorColaborador } from '@/modulos/rh/comum/componentes';
import { useTabelaAux, useUnidadesNegocio } from '@/modulos/contab/comum/dados';
import { TIPOS_UNIDADE, aplanar, construirArvore, descendentes, type Estrutura as DadosEstrutura, type NoUnidade, type Posto, type Unidade } from './comum/arvore';

interface NoArvore {
  key: number;
  title: ReactNode;
  children: NoArvore[];
}

const CHAVES = [['rh', 'estrutura'], ['rh', 'colaboradores'], ['rh', 'cargos'], ['gestao']];

export function useEstrutura() {
  return useQuery({ queryKey: ['rh', 'estrutura'], queryFn: () => obter<DadosEstrutura>('/rh/estrutura') });
}

/** Estrutura orgânica › Unidades, cargos e afectação (est_estrutura). */
export default function Estrutura() {
  const estrutura = useEstrutura();
  const cargos = useCargos();
  const navegar = useNavigate();
  return (
    <>
      <CabecalhoPagina
        titulo="Estrutura orgânica"
        subtitulo="Unidades da empresa (órgãos sociais, direcções, departamentos, secções), responsáveis, cargos com vagas e colaboradores afectos. Cada unidade pode ligar-se a um centro de custo e unidade de negócio."
        accoes={
          <>
            <Button icon={<ClusterOutlined />} onClick={() => navegar('../est_organigrama')}>
              Organigrama
            </Button>
            <Button icon={<TableOutlined />} onClick={() => navegar('../est_mapa')}>
              Mapa de pessoal
            </Button>
          </>
        }
        impressaoDesactivada={!estrutura.data?.unidades.length}
        impressao={() => {
          const nos = aplanar(construirArvore(estrutura.data?.unidades ?? []));
          const linhas = nos.flatMap((n) => [
            { n, posto: null as Posto | null },
            ...n.unidade.postos.map((p) => ({ n, posto: p as Posto | null })),
          ]);
          return {
            titulo: 'Estrutura orgânica',
            subtitulo: 'Unidades orgânicas e postos de trabalho',
            conteudo: tabelaHtml({
              linhas,
              colunas: [
                { titulo: 'Unidade / posto', valor: (l) => (l.posto ? `${'\u00a0\u00a0'.repeat(l.n.nivel + 2)}${l.posto.chefia ? '★ ' : ''}${l.posto.titulo || cargos.nome(l.posto.cargo_funcao_id)}` : `${'\u00a0\u00a0'.repeat(l.n.nivel)}${l.n.unidade.codigo ? `${l.n.unidade.codigo} — ` : ''}${l.n.unidade.nome}`) },
                { titulo: 'Tipo', valor: (l) => (l.posto ? 'Posto' : l.n.unidade.tipo ? TIPOS_UNIDADE[l.n.unidade.tipo] ?? l.n.unidade.tipo : '') },
                { titulo: 'Vagas', valor: (l) => (l.posto ? l.posto.vagas ?? 0 : l.n.vagasTotal), formato: 'inteiro' },
                { titulo: 'Ocupados', valor: (l) => (l.posto ? l.posto.ocupados : l.n.ocupadosTotal), formato: 'inteiro' },
                { titulo: 'Pessoas', valor: (l) => (l.posto ? '' : l.n.membrosTotal), formato: 'inteiro' },
                { titulo: 'Estado', valor: (l) => (l.posto ? '' : l.n.unidade.ativo ? 'Activa' : 'Inactiva') },
              ],
            }),
          };
        }}
      />
      <Tabs
        items={[
          { key: 'unidades', label: 'Unidades e postos', children: <Unidades /> },
          { key: 'cargos', label: 'Cargos e funções', children: <Cargos /> },
        ]}
      />
    </>
  );
}

function Unidades() {
  const { pode } = useSessao();
  const estrutura = useEstrutura();
  const colaboradores = useColaboradores();
  const cargos = useCargos();
  const [seleccionada, setSeleccionada] = useState<number | null>(null);
  const [edicao, setEdicao] = useState<Unidade | 'nova' | null>(null);
  const [posto, setPosto] = useState<Posto | 'novo' | null>(null);
  const [afectar, setAfectar] = useState(false);
  const [inactivas, setInactivas] = useState(false);
  const editar = pode('est_editar');
  const eliminar = pode('est_eliminar');
  const accao = useAccao({ invalidar: CHAVES });
  const [varias, setVarias] = useState(false);
  const base = useEstruturaBase();

  const arvore = useMemo(() => construirArvore(estrutura.data?.unidades ?? [], !inactivas), [estrutura.data, inactivas]);
  const nos = useMemo(() => aplanar(arvore), [arvore]);
  const actual = nos.find((n) => n.unidade.id === seleccionada) ?? null;
  const membros = colaboradores.lista.filter((c) => c.unidade_organica_id === seleccionada);
  const paraTree = (ns: NoUnidade[]): NoArvore[] =>
    ns.map((n) => ({
      key: n.unidade.id,
      title: (
        <Space size={4}>
          <span style={{ display: 'inline-block', width: 8, height: 8, borderRadius: 2, background: n.unidade.cor ?? '#d9d9d9' }} />
          <span style={{ opacity: n.unidade.ativo ? 1 : 0.5 }}>{n.unidade.nome}</span>
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>({n.membrosTotal})</Typography.Text>
        </Space>
      ),
      children: paraTree(n.filhos),
    }));

  if (estrutura.isLoading) return <Skeleton active />;

  return (
    <Row gutter={[16, 16]}>
      <Col xs={24} lg={9}>
        <Card
          size="small"
          title={<Space wrap><ApartmentOutlined />Unidades</Space>}
          extra={
            editar && (
              <Space size={4} wrap>
                <Button size="small" icon={<BlockOutlined />} onClick={() => setVarias(true)} title="Criar várias unidades de uma só vez">
                  Várias
                </Button>
                <Button size="small" type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('nova')}>
                  Unidade
                </Button>
              </Space>
            )
          }
        >
          <Flex justify="space-between" style={{ marginBottom: 8 }}>
            <Checkbox checked={inactivas} onChange={(e) => setInactivas(e.target.checked)}>Mostrar inactivas</Checkbox>
            {!!estrutura.data?.sem_unidade && <Tag color="orange">{estrutura.data.sem_unidade} sem unidade</Tag>}
          </Flex>
          {arvore.length ? (
            <Tree
              blockNode
              defaultExpandAll
              selectedKeys={seleccionada ? [seleccionada] : []}
              onSelect={(k) => setSeleccionada(k.length ? Number(k[0]) : null)}
              treeData={paraTree(arvore)}
            />
          ) : (
            <Empty
              image={<ApartmentOutlined style={{ fontSize: 40, color: '#94a3b8' }} />}
              description={
                <>
                  <Typography.Text strong>Ainda não há estrutura orgânica</Typography.Text>
                  <br />
                  <Typography.Text type="secondary">
                    Crie as unidades uma a uma ou comece com uma estrutura base (Conselho de Administração, Direcção-Geral, Direcções e Departamentos) e ajuste-a.
                  </Typography.Text>
                </>
              }
            >
              {editar && (
                <Space wrap style={{ justifyContent: 'center' }}>
                  <Button icon={<EditOutlined />} loading={base.aCriar} onClick={() => base.criar(estrutura.data?.unidades.length ?? 0)}>
                    Criar estrutura base
                  </Button>
                  <Button icon={<BlockOutlined />} onClick={() => setVarias(true)}>
                    Criar várias unidades
                  </Button>
                  <Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('nova')}>
                    Criar a primeira unidade
                  </Button>
                </Space>
              )}
            </Empty>
          )}
        </Card>
      </Col>
      <Col xs={24} lg={15}>
        {!actual ? (
          <Card><Empty description="Escolha uma unidade na árvore." /></Card>
        ) : (
          <Space direction="vertical" size={12} style={{ width: '100%' }}>
            <Card
              size="small"
              title={<Space wrap>{actual.unidade.nome}{actual.unidade.tipo && <Tag>{TIPOS_UNIDADE[actual.unidade.tipo] ?? actual.unidade.tipo}</Tag>}{!actual.unidade.ativo && <Tag>inactiva</Tag>}</Space>}
              extra={
                <Space wrap>
                  {editar && <Button size="small" icon={<EditOutlined />} onClick={() => setEdicao(actual.unidade)}>Editar</Button>}
                  {eliminar && (
                    <Popconfirm title={`Eliminar «${actual.unidade.nome}»?`} description="Só é possível sem subunidades, postos nem colaboradores." okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/rh/estrutura/unidades/${actual.unidade.id}` }).then(() => setSeleccionada(null))}>
                      <Button size="small" danger icon={<DeleteOutlined />} aria-label="Eliminar unidade" />
                    </Popconfirm>
                  )}
                </Space>
              }
            >
              <Descriptions size="small" column={{ xs: 1, sm: 2 }}>
                <Descriptions.Item label="Código">{actual.unidade.codigo ?? '—'}</Descriptions.Item>
                <Descriptions.Item label="Responsável">{colaboradores.nome(actual.unidade.colaborador_responsavel_id)}</Descriptions.Item>
                <Descriptions.Item label="Membros">{actual.unidade.membros} (ramo: {actual.membrosTotal})</Descriptions.Item>
                <Descriptions.Item label="Vagas (ramo)">{actual.ocupadosTotal}/{actual.vagasTotal} ocupadas</Descriptions.Item>
                {actual.unidade.missao && <Descriptions.Item label="Missão" span={2}>{actual.unidade.missao}</Descriptions.Item>}
                {actual.unidade.atribuicoes && <Descriptions.Item label="Atribuições" span={2}><span style={{ whiteSpace: 'pre-wrap' }}>{actual.unidade.atribuicoes}</span></Descriptions.Item>}
              </Descriptions>
            </Card>
            <Card size="small" title="Postos de trabalho" extra={editar && <Button size="small" icon={<PlusOutlined />} onClick={() => setPosto('novo')}>Posto</Button>}>
              <Table<Posto>
                scroll={scrollTabela()}
                size="small"
                rowKey="id"
                pagination={false}
                dataSource={actual.unidade.postos}
                locale={{ emptyText: 'Sem postos definidos.' }}
                columns={[
                  { title: 'Posto', key: 'p', render: (_, p) => <>{p.titulo || cargos.nome(p.cargo_funcao_id)}{p.chefia ? <Tag color="purple" style={{ marginLeft: 6 }}>chefia</Tag> : null}</> },
                  { title: 'Cargo', dataIndex: 'cargo_funcao_id', render: (c: number | null) => cargos.nome(c) },
                  { title: 'Vagas', dataIndex: 'vagas', align: 'right' },
                  { title: 'Ocupados', dataIndex: 'ocupados', align: 'right', render: (n: number, p) => <Typography.Text type={n > (p.vagas ?? 0) ? 'danger' : undefined}>{n}</Typography.Text> },
                  { title: 'Livres', dataIndex: 'livres', align: 'right', render: (n: number) => (n ? <Tag color="orange">{n}</Tag> : 0) },
                  {
                    title: '',
                    width: 80,
                    render: (_, p) => (
                      <Space wrap>
                        {editar && <Button size="small" type="text" icon={<EditOutlined />} aria-label="Editar posto" onClick={() => setPosto(p)} />}
                        {eliminar && (
                          <Popconfirm title="Eliminar o posto?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/rh/estrutura/postos/${p.id}` })}>
                            <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar posto" />
                          </Popconfirm>
                        )}
                      </Space>
                    ),
                  },
                ]}
              />
            </Card>
            <Card size="small" title={`Colaboradores (${membros.length})`} extra={editar && <Button size="small" icon={<TeamOutlined />} onClick={() => setAfectar(true)}>Afectar</Button>}>
              <List
                size="small"
                dataSource={membros}
                locale={{ emptyText: 'Sem colaboradores afectos.' }}
                renderItem={(c) => {
                  const p = actual.unidade.postos.find((x) => x.id === c.posto_trabalho_id);
                  return (
                    <List.Item>
                      <List.Item.Meta
                        title={<Space wrap>{c.nome_completo}{c.estado !== 'ACTIVO' && <Tag>{c.estado}</Tag>}{c.id === actual.unidade.colaborador_responsavel_id && <Tag color="gold">responsável</Tag>}</Space>}
                        description={`${p ? p.titulo || cargos.nome(p.cargo_funcao_id) : 'Sem posto'} · chefia: ${colaboradores.nome(c.colaborador_gestor_id)}`}
                      />
                    </List.Item>
                  );
                }}
              />
            </Card>
          </Space>
        )}
      </Col>
      <FormUnidade unidade={edicao} arvore={arvore} paiInicial={seleccionada} aoFechar={() => setEdicao(null)} />
      {actual && <FormPosto posto={posto} unidade={actual.unidade} aoFechar={() => setPosto(null)} />}
      {actual && <ModalAfectar aberto={afectar} unidade={actual.unidade} aoFechar={() => setAfectar(false)} />}
      {varias && <ModalVariasUnidades aberto={varias} unidades={estrutura.data?.unidades ?? []} paiInicial={seleccionada} aoFechar={() => setVarias(false)} />}
    </Row>
  );
}

function FormUnidade({ unidade, arvore, paiInicial, aoFechar }: { unidade: Unidade | 'nova' | null; arvore: NoUnidade[]; paiInicial: number | null; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const un = useUnidadesNegocio(!!unidade);
  const cc = useTabelaAux('centros-custo', !!unidade);
  const accao = useAccao({ invalidar: CHAVES, aoSucesso: () => aoFechar() });
  const nova = unidade === 'nova';
  const proibidos = unidade && unidade !== 'nova' ? descendentes(arvore, unidade.id) : new Set<number>();
  useEffect(() => {
    if (!unidade) return;
    form.resetFields();
    form.setFieldsValue(nova ? { unidade_organica_pai_id: paiInicial, tipo: 'DEPARTAMENTO', ativo: true, apoio: false } : { ...unidade, apoio: !!unidade.apoio });
  }, [unidade, nova, paiInicial, form]);
  return (
    <Modal open={!!unidade} title={nova ? 'Nova unidade orgânica' : 'Editar unidade orgânica'} onCancel={aoFechar} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} width={larguraModal(760)} destroyOnHidden>
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) => accao.mutate({ metodo: nova ? 'post' : 'put', url: nova ? '/rh/estrutura/unidades' : `/rh/estrutura/unidades/${(unidade as Unidade).id}`, dados: { ...v, cor: typeof v.cor === 'string' ? v.cor : null } })}
      >
        <Row gutter={[16, 0]}>
          <Col xs={24} sm={14}><Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item></Col>
          <Col xs={12} sm={6}><Form.Item name="codigo" label="Código"><Input maxLength={50} /></Form.Item></Col>
          <Col xs={12} sm={4}><Form.Item name="cor" label="Cor" getValueFromEvent={(c) => c?.toHexString?.() ?? c}><ColorPicker /></Form.Item></Col>
          <Col xs={24} sm={8}><Form.Item name="tipo" label="Tipo"><Select options={Object.entries(TIPOS_UNIDADE).map(([value, label]) => ({ value, label }))} /></Form.Item></Col>
          <Col xs={24} sm={16}>
            <Form.Item name="unidade_organica_pai_id" label="Unidade superior">
              <Select allowClear showSearch optionFilterProp="label" placeholder="(topo da estrutura)" options={aplanar(arvore).filter((n) => !proibidos.has(n.unidade.id)).map((n) => ({ value: n.unidade.id, label: `${'— '.repeat(n.nivel)}${n.unidade.nome}` }))} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}><Form.Item name="colaborador_responsavel_id" label="Responsável"><SeletorColaborador apenasActivos style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} sm={6}><Form.Item name="ordem" label="Ordem"><InputNumber style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} sm={3}><Form.Item name="ativo" label="Activa" valuePropName="checked"><Switch /></Form.Item></Col>
          <Col xs={12} sm={3}><Form.Item name="apoio" label="Apoio" valuePropName="checked" tooltip="Unidade de apoio (staff), desenhada ao lado no organigrama."><Switch /></Form.Item></Col>
          <Col xs={24} sm={12}><Form.Item name="centro_custo_id" label="Centro de custo"><Select allowClear showSearch optionFilterProp="label" options={(cc.data ?? []).map((c) => ({ value: c.id, label: `${c.codigo} — ${c.descricao ?? ''}` }))} /></Form.Item></Col>
          <Col xs={24} sm={12}><Form.Item name="unidade_negocio_id" label="Unidade de negócio"><Select allowClear showSearch optionFilterProp="label" options={(un.data ?? []).map((u) => ({ value: u.id, label: u.nome }))} /></Form.Item></Col>
          <Col xs={24}><Form.Item name="missao" label="Missão"><Input.TextArea rows={2} maxLength={5000} /></Form.Item></Col>
          <Col xs={24}><Form.Item name="atribuicoes" label="Atribuições"><Input.TextArea rows={4} maxLength={5000} /></Form.Item></Col>
        </Row>
      </Form>
    </Modal>
  );
}

function FormPosto({ posto, unidade, aoFechar }: { posto: Posto | 'novo' | null; unidade: Unidade; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const cargos = useCargos();
  const accao = useAccao({ invalidar: CHAVES, aoSucesso: () => aoFechar() });
  const novo = posto === 'novo';
  useEffect(() => {
    if (!posto) return;
    form.resetFields();
    form.setFieldsValue(novo ? { vagas: 1, chefia: false } : { ...posto, chefia: !!posto.chefia });
  }, [posto, novo, form]);
  return (
    <Modal open={!!posto} title={novo ? `Novo posto — ${unidade.nome}` : 'Editar posto'} onCancel={aoFechar} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} width={larguraModal(560)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ metodo: novo ? 'post' : 'put', url: novo ? '/rh/estrutura/postos' : `/rh/estrutura/postos/${(posto as Posto).id}`, dados: { ...v, unidade_organica_id: unidade.id } })}>
        <Form.Item name="cargo_funcao_id" label="Cargo / função"><Select allowClear showSearch optionFilterProp="label" options={cargos.lista.map((c) => ({ value: c.id, label: c.nome }))} /></Form.Item>
        <Form.Item name="titulo" label="Título do posto (se diferente do cargo)"><Input maxLength={255} /></Form.Item>
        <Space size={16} wrap>
          <Form.Item name="vagas" label="Vagas"><InputNumber min={0} max={9999} /></Form.Item>
          <Form.Item name="ordem" label="Ordem"><InputNumber /></Form.Item>
          <Form.Item name="chefia" label="Posto de chefia" valuePropName="checked"><Switch /></Form.Item>
        </Space>
        <Form.Item name="posto_superior_id" label="Reporta ao posto">
          <Select allowClear options={unidade.postos.filter((p) => posto === 'novo' || p.id !== posto?.id).map((p) => ({ value: p.id, label: p.titulo || cargos.nome(p.cargo_funcao_id) }))} />
        </Form.Item>
        <Form.Item name="responsabilidades" label="Responsabilidades"><Input.TextArea rows={3} maxLength={5000} /></Form.Item>
      </Form>
    </Modal>
  );
}

function ModalAfectar({ aberto, unidade, aoFechar }: { aberto: boolean; unidade: Unidade; aoFechar: () => void }) {
  const [form] = Form.useForm<{ colaboradores: number[]; posto_trabalho_id?: number | null; alterar_gestor: boolean; colaborador_gestor_id?: number | null }>();
  const cargos = useCargos();
  const alterarGestor = Form.useWatch('alterar_gestor', form);
  const accao = useAccao<{ avisos: string[] }>({
    invalidar: CHAVES,
    aoSucesso: (d) => {
      if (d.avisos?.length) Modal.warning({ title: 'Avisos', content: <ul style={{ paddingLeft: 18 }}>{d.avisos.map((a) => <li key={a}>{a}</li>)}</ul> });
      aoFechar();
    },
  });
  useEffect(() => {
    if (aberto) form.resetFields();
  }, [aberto, form]);
  return (
    <Modal open={aberto} title={`Afectar colaboradores a «${unidade.nome}»`} onCancel={aoFechar} okText="Afectar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} width={larguraModal(620)} destroyOnHidden>
      <Form
        form={form}
        layout="vertical"
        initialValues={{ alterar_gestor: false }}
        onFinish={(v) =>
          accao.mutate({
            url: '/rh/estrutura/afectacao',
            dados: { colaboradores: v.colaboradores, unidade_organica_id: unidade.id, posto_trabalho_id: v.posto_trabalho_id ?? null, ...(v.alterar_gestor ? { colaborador_gestor_id: v.colaborador_gestor_id ?? null } : {}) },
          })
        }
      >
        <Form.Item name="colaboradores" label="Colaboradores" rules={[{ required: true, type: 'array', min: 1, message: 'Escolha pelo menos um.' }]}>
          <SeletorColaborador mode="multiple" apenasActivos style={{ width: '100%' }} />
        </Form.Item>
        <Form.Item name="posto_trabalho_id" label="Posto de trabalho">
          <Select allowClear placeholder="Sem posto" options={unidade.postos.map((p) => ({ value: p.id, label: `${p.titulo || cargos.nome(p.cargo_funcao_id)} (${p.ocupados}/${p.vagas ?? 0})` }))} />
        </Form.Item>
        <Form.Item name="alterar_gestor" valuePropName="checked"><Checkbox>Definir também a chefia directa</Checkbox></Form.Item>
        {alterarGestor && <Form.Item name="colaborador_gestor_id" label="Chefia directa"><SeletorColaborador apenasActivos style={{ width: '100%' }} /></Form.Item>}
        <Alert type="info" showIcon message="Sem chefia indicada, a chefia directa é deduzida do responsável da unidade e dos postos de chefia." />
      </Form>
    </Modal>
  );
}

function Cargos() {
  const { pode } = useSessao();
  const cargos = useCargos();
  const [edicao, setEdicao] = useState<{ id: number; nome: string; descricao?: string | null } | 'novo' | null>(null);
  const [form] = Form.useForm<{ nome: string; descricao?: string }>();
  const accao = useAccao({ invalidar: CHAVES, aoSucesso: () => setEdicao(null) });
  const editar = pode('rh_funcoes_gerir', 'est_editar');
  const eliminar = pode('rh_funcao_del', 'est_eliminar');
  useEffect(() => {
    if (!edicao) return;
    form.resetFields();
    if (edicao !== 'novo') form.setFieldsValue({ nome: edicao.nome, descricao: edicao.descricao ?? '' });
  }, [edicao, form]);
  return (
    <Card>
      {editar && <Flex justify="end" style={{ marginBottom: 12 }}><Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('novo')}>Novo cargo</Button></Flex>}
      <Table
        scroll={scrollTabela()}
        rowKey="id"
        size="small"
        loading={cargos.isLoading}
        dataSource={cargos.lista as { id: number; nome: string; descricao?: string | null }[]}
        pagination={{ pageSize: 50, hideOnSinglePage: true }}
        columns={[
          { title: 'Cargo / função', dataIndex: 'nome', render: (v: string) => <strong>{v}</strong> },
          { title: 'Descrição', dataIndex: 'descricao', ellipsis: true },
          {
            title: '',
            width: 90,
            render: (_, c) => (
              <Space wrap>
                {editar && <Button size="small" type="text" icon={<EditOutlined />} aria-label="Editar" onClick={() => setEdicao(c)} />}
                {eliminar && (
                  <Popconfirm title={`Eliminar «${c.nome}»?`} okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/rh/cargos/${c.id}` })}>
                    <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" />
                  </Popconfirm>
                )}
              </Space>
            ),
          },
        ]}
      />
      <Modal open={!!edicao} title={edicao === 'novo' ? 'Novo cargo' : 'Editar cargo'} onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} width={larguraModal(560)} destroyOnHidden>
        <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ metodo: edicao === 'novo' ? 'post' : 'put', url: edicao === 'novo' ? '/rh/cargos' : `/rh/cargos/${(edicao as { id: number }).id}`, dados: v })}>
          <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item>
          <Form.Item name="descricao" label="Descrição"><Input.TextArea rows={4} maxLength={5000} /></Form.Item>
        </Form>
      </Modal>
    </Card>
  );
}
