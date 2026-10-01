import { Alert, Button, Card, Checkbox, DatePicker, Descriptions, Empty, Form, Input, Modal, Popconfirm, Select, Space, Table, Tabs, Tag, Typography, message } from 'antd';
import { DeleteOutlined, EditOutlined, PlayCircleOutlined, PlusOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { BotaoCsv, EtiquetaEstado, ValorKz } from './comum/Componentes';

interface Execucao {
  id: number;
  data_execucao: string | null;
  executado_em: string | null;
  data_fim: string | null;
  moeda: string | null;
  estado: string | null;
  utilizador: string | null;
  linhas: number | null;
  totais?: Record<string, unknown>;
}

interface Grupo {
  id: number;
  nome: string;
  holding: { id: number; nome: string; nif: string | null } | null;
  moeda_apresentacao: string;
  conta_reserva_cambial: string;
  eliminacao_ativa: boolean;
  prefixos_excluidos_eliminacao: string;
  conta_diferenca_eliminacao: string;
  membros: { empresa_id: number; nome: string; nif: string | null; percentagem: string; metodo: string }[];
  ultima_execucao_id: number | null;
  execucoes: Execucao[];
}

interface MapaConsolidacao {
  moeda: string;
  data_fim: string;
  colunas: { chave: string; nome: string }[];
  linhas: { tipo: 'cabecalho' | 'conta' | 'subtotal'; codigo: string; descricao: string; valores?: Record<string, string> }[];
  total: Record<string, string>;
  resultado: Record<string, string>;
  lancamentos: number;
  aviso: string | null;
}

interface ValoresGrupo {
  nome: string;
  nif: string;
  moeda_apresentacao?: string;
  conta_reserva_cambial?: string;
  eliminacao_ativa: boolean;
  prefixos_excluidos_eliminacao?: string;
  conta_diferenca_eliminacao?: string;
  membros: number[];
}

/** Contabilidade › Consolidação de empresas (ecrã consolidacao, ADR-057): grupos/holdings, execução e Mapa de Consolidação. */
export default function Consolidacao() {
  const { pode, empresas } = useSessao();
  const cliente = useQueryClient();
  const grupos = useQuery({ queryKey: ['contab', 'consolidacao', 'grupos'], queryFn: () => obter<Grupo[]>('/consolidacao/grupos') });
  const [grupoId, setGrupoId] = useState<number | null>(null);
  const [edicao, setEdicao] = useState<Grupo | 'novo' | null>(null);
  const [form] = Form.useForm<ValoresGrupo>();
  const grupo = grupos.data?.find((g) => g.id === grupoId) ?? grupos.data?.[0];

  const invalidar = () => void cliente.invalidateQueries({ queryKey: ['contab', 'consolidacao'] });
  const gravar = useMutation({
    mutationFn: (v: ValoresGrupo) => (edicao && edicao !== 'novo' ? enviar<Grupo>('put', `/consolidacao/grupos/${edicao.id}`, v) : enviar<Grupo>('post', '/consolidacao/grupos', v)),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      setEdicao(null);
      setGrupoId(dados.id);
      invalidar();
    },
    onError: (e) => notificarErro(e, 'Não foi possível gravar o grupo'),
  });
  const eliminar = useMutation({
    mutationFn: (id: number) => enviar('delete', `/consolidacao/grupos/${id}`),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      setGrupoId(null);
      invalidar();
    },
    onError: (e) => notificarErro(e, 'Não foi possível eliminar'),
  });

  const abrirEdicao = (g: Grupo | 'novo') => {
    form.resetFields();
    if (g === 'novo') form.setFieldsValue({ moeda_apresentacao: 'AOA', eliminacao_ativa: true, membros: [] });
    else
      form.setFieldsValue({
        nome: g.nome,
        nif: g.holding?.nif ?? '',
        moeda_apresentacao: g.moeda_apresentacao,
        conta_reserva_cambial: g.conta_reserva_cambial,
        eliminacao_ativa: g.eliminacao_ativa,
        prefixos_excluidos_eliminacao: g.prefixos_excluidos_eliminacao,
        conta_diferenca_eliminacao: g.conta_diferenca_eliminacao,
        membros: g.membros.map((m) => m.empresa_id),
      });
    setEdicao(g);
  };

  return (
    <>
      <CabecalhoPagina
        titulo="Consolidação de empresas"
        subtitulo="Holdings, eliminações intragrupo por NIF e conversão cambial"
        accoes={
          <Space wrap>
            {(grupos.data?.length ?? 0) > 0 && (
              <Select style={{ minWidth: 240 }} value={grupo?.id} onChange={setGrupoId} options={(grupos.data ?? []).map((g) => ({ value: g.id, label: g.nome }))} aria-label="Grupo" />
            )}
            {pode('consol_gerir') && grupo && <Button icon={<EditOutlined />} onClick={() => abrirEdicao(grupo)}>Editar</Button>}
            {pode('consol_eliminar') && grupo && (
              <Popconfirm title={`Eliminar a holding ${grupo.nome}?`} description="As empresas do grupo não são afectadas." okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => eliminar.mutateAsync(grupo.id)}>
                <Button danger icon={<DeleteOutlined />}>Eliminar</Button>
              </Popconfirm>
            )}
            {pode('consol_gerir') && <Button type="primary" icon={<PlusOutlined />} onClick={() => abrirEdicao('novo')}>Novo grupo</Button>}
          </Space>
        }
      />
      {grupos.isLoading ? (
        <Card loading />
      ) : !grupo ? (
        <Empty description="Não há grupos de consolidação acessíveis." />
      ) : (
        <PainelGrupo key={grupo.id} grupo={grupo} />
      )}

      <Modal
        title={edicao === 'novo' ? 'Novo grupo de consolidação' : 'Editar grupo de consolidação'}
        open={edicao !== null}
        onCancel={() => setEdicao(null)}
        okText="Gravar"
        confirmLoading={gravar.isPending}
        onOk={() => form.submit()}
        width={640}
      >
        <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)}>
          <Form.Item name="nome" label="Nome da holding" rules={[{ required: true }]}>
            <Input maxLength={255} />
          </Form.Item>
          <Form.Item name="nif" label="NIF da holding" rules={[{ required: true }]}>
            <Input maxLength={30} />
          </Form.Item>
          <Form.Item name="membros" label="Empresas a consolidar (100%, método integral)" rules={[{ required: true, message: 'Escolha pelo menos uma empresa.' }]}>
            <Select mode="multiple" optionFilterProp="label" options={empresas.filter((e) => !e.e_consolidacao).map((e) => ({ value: e.id, label: e.nome }))} />
          </Form.Item>
          <Space wrap>
            <Form.Item name="moeda_apresentacao" label="Moeda de apresentação">
              <Input maxLength={3} style={{ width: 100 }} />
            </Form.Item>
            <Form.Item name="conta_reserva_cambial" label="Conta de reserva cambial">
              <Input maxLength={20} placeholder="599" style={{ width: 160 }} />
            </Form.Item>
            <Form.Item name="conta_diferenca_eliminacao" label="Conta de diferenças de eliminação">
              <Input maxLength={20} placeholder="598" style={{ width: 160 }} />
            </Form.Item>
          </Space>
          <Form.Item name="eliminacao_ativa" valuePropName="checked">
            <Checkbox>Eliminações intragrupo activas</Checkbox>
          </Form.Item>
          <Form.Item name="prefixos_excluidos_eliminacao" label="Prefixos excluídos das eliminações">
            <Input maxLength={10} />
          </Form.Item>
          <Typography.Text type="secondary">É exigido acesso à holding e a todas as empresas do grupo.</Typography.Text>
        </Form>
      </Modal>
    </>
  );
}

function PainelGrupo({ grupo }: { grupo: Grupo }) {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [execucaoAberta, setExecucaoAberta] = useState<number | null>(null);
  const [dataFim, setDataFim] = useState<Dayjs>(dayjs().endOf('month'));
  const [moeda, setMoeda] = useState(grupo.moeda_apresentacao);
  const executar = useMutation({
    mutationFn: () => enviar<{ execucao_id: number; equilibrado: boolean }>('post', `/consolidacao/grupos/${grupo.id}/executar`, { data_fim: dataApi(dataFim), moeda }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      if (!dados.equilibrado) message.warning('A consolidação não ficou equilibrada (débitos ≠ créditos): veja os totais da execução.');
      void cliente.invalidateQueries({ queryKey: ['contab', 'consolidacao'] });
    },
    onError: (e) => notificarErro(e, 'Não foi possível executar a consolidação'),
  });

  return (
    <Tabs
      items={[
        {
          key: 'grupo',
          label: 'Grupo e execuções',
          children: (
            <>
              <Card style={{ marginBottom: 16 }}>
                <Descriptions size="small" column={{ xs: 1, md: 3 }}>
                  <Descriptions.Item label="Holding">{grupo.holding ? `${grupo.holding.nome} (NIF ${grupo.holding.nif ?? '—'})` : '—'}</Descriptions.Item>
                  <Descriptions.Item label="Moeda">{grupo.moeda_apresentacao}</Descriptions.Item>
                  <Descriptions.Item label="Eliminações">{grupo.eliminacao_ativa ? <Tag color="green">Activas</Tag> : <Tag>Inactivas</Tag>}</Descriptions.Item>
                  <Descriptions.Item label="Reserva cambial">{grupo.conta_reserva_cambial}</Descriptions.Item>
                  <Descriptions.Item label="Diferenças de eliminação">{grupo.conta_diferenca_eliminacao}</Descriptions.Item>
                  <Descriptions.Item label="Prefixos excluídos">{grupo.prefixos_excluidos_eliminacao || '—'}</Descriptions.Item>
                </Descriptions>
                <Table
                  rowKey="empresa_id"
                  size="small"
                  pagination={false}
                  style={{ marginTop: 12 }}
                  dataSource={grupo.membros}
                  columns={[
                    { title: 'Empresa', dataIndex: 'nome' },
                    { title: 'NIF', dataIndex: 'nif' },
                    { title: '%', dataIndex: 'percentagem', align: 'right' },
                    { title: 'Método', dataIndex: 'metodo' },
                  ]}
                />
              </Card>
              <Card
                title="Execuções"
                extra={
                  pode('consol_executar') && (
                    <Space wrap>
                      <DatePicker value={dataFim} onChange={(v) => v && setDataFim(v)} format="DD/MM/YYYY" allowClear={false} />
                      <Input value={moeda} onChange={(e) => setMoeda(e.target.value.toUpperCase())} maxLength={3} style={{ width: 80 }} aria-label="Moeda" />
                      <Button
                        type="primary"
                        icon={<PlayCircleOutlined />}
                        loading={executar.isPending}
                        onClick={() =>
                          Modal.confirm({
                            title: `Consolidar até ${dataFim.format('DD/MM/YYYY')} em ${moeda}?`,
                            content: 'As linhas geradas pela execução anterior na holding são substituídas; as manuais são preservadas.',
                            okText: 'Executar',
                            cancelText: 'Cancelar',
                            onOk: () => executar.mutateAsync(),
                          })
                        }
                      >
                        Executar consolidação
                      </Button>
                    </Space>
                  )
                }
              >
                <Table<Execucao>
                  rowKey="id"
                  size="small"
                  pagination={false}
                  dataSource={grupo.execucoes}
                  locale={{ emptyText: 'O grupo ainda não foi consolidado.' }}
                  onRow={(r) => ({ onClick: () => setExecucaoAberta(r.id), style: { cursor: 'pointer' } })}
                  columns={[
                    { title: 'N.º', dataIndex: 'id', render: (v: number) => (v === grupo.ultima_execucao_id ? <Space>{v}<Tag color="blue">Última</Tag></Space> : v) },
                    { title: 'Executada em', dataIndex: 'executado_em', render: formatarDataHora },
                    { title: 'Até', dataIndex: 'data_fim', render: formatarData },
                    { title: 'Moeda', dataIndex: 'moeda' },
                    { title: 'Linhas', dataIndex: 'linhas', align: 'right' },
                    { title: 'Utilizador', dataIndex: 'utilizador' },
                    { title: 'Estado', dataIndex: 'estado', render: (v: string) => <EtiquetaEstado estado={v} /> },
                  ]}
                />
              </Card>
              {execucaoAberta && <DetalheExecucao id={execucaoAberta} aoFechar={() => setExecucaoAberta(null)} />}
            </>
          ),
        },
        { key: 'mapa', label: 'Mapa de Consolidação', children: grupo.ultima_execucao_id ? <PainelMapa grupo={grupo} /> : <Empty description="Execute a consolidação para obter o mapa." /> },
      ]}
    />
  );
}

function DetalheExecucao({ id, aoFechar }: { id: number; aoFechar: () => void }) {
  const consulta = useQuery({ queryKey: ['contab', 'consolidacao', 'execucao', id], queryFn: () => obter<Execucao>(`/consolidacao/execucoes/${id}`) });
  const t = (consulta.data?.totais ?? {}) as Record<string, unknown> & { eliminacoes?: Record<string, unknown>; empresas?: Record<string, unknown>[] };
  const el = t.eliminacoes ?? {};
  return (
    <Modal open title={`Execução ${id}`} onCancel={aoFechar} footer={null} width={860}>
      {consulta.isLoading ? (
        <Card loading />
      ) : (
        <>
          <Descriptions bordered size="small" column={{ xs: 1, md: 2 }}>
            <Descriptions.Item label="Linhas">{String(t.linhas ?? '—')}</Descriptions.Item>
            <Descriptions.Item label="Linhas substituídas">{String(t.linhas_apagadas ?? '—')}</Descriptions.Item>
            <Descriptions.Item label="Débitos">{formatarKz(t.debitos as string)}</Descriptions.Item>
            <Descriptions.Item label="Créditos">{formatarKz(t.creditos as string)}</Descriptions.Item>
            <Descriptions.Item label="Reserva cambial">{formatarKz(t.reserva as string)}</Descriptions.Item>
            <Descriptions.Item label="Ajustes de conversão">{String(t.ajustes_conversao ?? '—')}</Descriptions.Item>
            <Descriptions.Item label="Contas criadas">{String(t.contas_criadas ?? '—')}</Descriptions.Item>
            <Descriptions.Item label="Terceiros criados">{String(t.terceiros_criados ?? '—')}</Descriptions.Item>
            <Descriptions.Item label="Eliminações (linhas)">{String(el.linhas ?? '—')}</Descriptions.Item>
            <Descriptions.Item label="Diferença de eliminação">{formatarKz(el.diferenca as string)}</Descriptions.Item>
          </Descriptions>
          {Array.isArray(el.divergencias) && el.divergencias.length > 0 && (
            <Alert style={{ marginTop: 12 }} type="warning" showIcon message={`${el.divergencias.length} divergência(s) nas eliminações intragrupo.`} description={<pre style={{ whiteSpace: 'pre-wrap', margin: 0 }}>{JSON.stringify(el.divergencias, null, 1)}</pre>} />
          )}
          {Array.isArray(t.empresas) && t.empresas.length > 0 && (
            <Table
              rowKey={(_, i) => String(i)}
              size="small"
              style={{ marginTop: 12 }}
              pagination={false}
              dataSource={t.empresas}
              columns={Object.keys(t.empresas[0]).map((k) => ({ title: k.replace(/_/g, ' '), dataIndex: k, render: (v: unknown) => String(v ?? '—') }))}
            />
          )}
        </>
      )}
    </Modal>
  );
}

function PainelMapa({ grupo }: { grupo: Grupo }) {
  const [filtros, setFiltros] = useState<Record<string, unknown>>({ nivel: '2', modo: 'saldo', ocultar_zeros: 1 });
  const [form] = Form.useForm();
  const mapa = useQuery({ queryKey: ['contab', 'consolidacao', 'mapa', grupo.id, filtros], queryFn: () => obter<MapaConsolidacao>(`/consolidacao/grupos/${grupo.id}/mapa`, filtros) });
  const d = mapa.data;
  type Linha = MapaConsolidacao['linhas'][number];
  const estilo = (l: Linha) => (l.tipo === 'cabecalho' ? { fontWeight: 600, background: '#fafafa' } : l.tipo === 'subtotal' ? { fontWeight: 600 } : {});

  return (
    <Card>
      <Form
        form={form}
        layout="inline"
        initialValues={{ nivel: '2', modo: 'saldo', ocultar_zeros: true }}
        onFinish={(v) => setFiltros({ ...v, data_inicio: dataApi(v.data_inicio), data_fim: dataApi(v.data_fim), ocultar_zeros: v.ocultar_zeros ? 1 : undefined })}
        style={{ marginBottom: 16, rowGap: 8 }}
      >
        <Form.Item name="nivel" label="Nível">
          <Select style={{ width: 130 }} options={[{ value: 'classe', label: 'Classe' }, { value: '2', label: '2 dígitos' }, { value: 'conta', label: 'Conta' }]} />
        </Form.Item>
        <Form.Item name="modo" label="Valores">
          <Select style={{ width: 140 }} options={[{ value: 'saldo', label: 'Saldo' }, { value: 'periodo', label: 'Período' }]} />
        </Form.Item>
        <Form.Item name="data_inicio" label="De">
          <DatePicker format="DD/MM/YYYY" />
        </Form.Item>
        <Form.Item name="data_fim" label="Até">
          <DatePicker format="DD/MM/YYYY" />
        </Form.Item>
        <Form.Item name="contas" label="Contas">
          <Input style={{ width: 140 }} maxLength={200} allowClear />
        </Form.Item>
        <Form.Item name="ocultar_zeros" valuePropName="checked">
          <Checkbox>Ocultar zeros</Checkbox>
        </Form.Item>
        <Button type="primary" htmlType="submit" loading={mapa.isFetching}>Actualizar</Button>
      </Form>
      {d && (
        <>
          {d.aviso && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={d.aviso} />}
          <Space style={{ marginBottom: 12 }}>
            <Typography.Text type="secondary">Moeda {d.moeda} · até {formatarData(d.data_fim)} · {d.lancamentos} lançamento(s)</Typography.Text>
            <BotaoCsv<Linha>
              nome={`mapa_consolidacao_${grupo.id}`}
              linhas={d.linhas.filter((l) => l.tipo !== 'cabecalho')}
              colunas={[{ titulo: 'Código', valor: (l) => l.codigo }, { titulo: 'Descrição', valor: (l) => l.descricao }, ...d.colunas.map((c) => ({ titulo: c.nome, valor: (l: Linha) => l.valores?.[c.chave], numerico: true }))]}
            />
          </Space>
          <Table<Linha>
            rowKey={(l, i) => `${l.tipo}|${l.codigo}|${i}`}
            size="small"
            pagination={false}
            dataSource={d.linhas}
            scroll={{ x: 'max-content' }}
            onRow={(l) => ({ style: estilo(l) })}
            columns={[
              { title: 'Código', dataIndex: 'codigo', fixed: 'left' },
              { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 240 },
              ...d.colunas.map((c) => ({
                title: c.nome,
                key: c.chave,
                align: 'right' as const,
                render: (_: unknown, l: Linha) => (l.valores ? <ValorKz valor={l.valores[c.chave]} forte={c.chave === 'TOTAL'} discretoSeZero /> : null),
              })),
            ]}
            summary={() => (
              <>
                <Table.Summary.Row>
                  <Table.Summary.Cell index={0} colSpan={2}><strong>Total</strong></Table.Summary.Cell>
                  {d.colunas.map((c, i) => <Table.Summary.Cell key={c.chave} index={i + 2} align="right"><ValorKz valor={d.total[c.chave]} forte /></Table.Summary.Cell>)}
                </Table.Summary.Row>
                <Table.Summary.Row>
                  <Table.Summary.Cell index={0} colSpan={2}><strong>Resultado (classes 6 e 7)</strong></Table.Summary.Cell>
                  {d.colunas.map((c, i) => <Table.Summary.Cell key={c.chave} index={i + 2} align="right"><ValorKz valor={d.resultado[c.chave]} forte /></Table.Summary.Cell>)}
                </Table.Summary.Row>
              </>
            )}
          />
        </>
      )}
    </Card>
  );
}
