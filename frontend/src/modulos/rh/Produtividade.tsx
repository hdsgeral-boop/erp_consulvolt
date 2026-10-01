import { Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Flex, Form, Input, InputNumber, Modal, Popconfirm, Row, Select, Space, Table, Tabs, Tag, Typography } from 'antd';
import { ArrowLeftOutlined, DeleteOutlined, EditOutlined, LockOutlined, PlusOutlined, UnlockOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { METRICAS_PRODUTIVIDADE, type DetalheProdutividade, type ItemProdutividade, type PeriodoProdutividade, type RegistoProdutividade } from './api';
import { CadastroSimples } from './comum/CadastroSimples';
import { EstadoTag } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useInfotipos } from './comum/consultas';
import { mesPorExtenso, somar } from './comum/regras';

/** RH › Subsídio de produtividade (ecrã rh_produtividade): períodos, registos e itens. */
export default function Produtividade() {
  return (
    <Routes>
      <Route index element={<Inicio />} />
      <Route path=":id" element={<DetalhePeriodo />} />
    </Routes>
  );
}

function Inicio() {
  const { pode } = useSessao();
  const infotipos = useInfotipos();
  return (
    <>
      <CabecalhoPagina titulo="Subsídio de produtividade" subtitulo="Só colaboradores activos com itens de produtividade no contrato; o período fechado é importado no ecrã Calcular" />
      <Tabs items={[
        { key: 'periodos', label: 'Períodos', children: <Periodos /> },
        {
          key: 'itens',
          label: 'Itens',
          children: (
            <CadastroSimples<ItemProdutividade>
              url="/rh/produtividade/itens"
              chave={['rh', 'produtividade', 'itens']}
              nomeItem="item de produtividade"
              podeGerir={pode('rh_prod_config')}
              podeEliminar={pode('rh_prod_config')}
              pesquisa={(r) => `${r.codigo} ${r.descricao}`}
              valoresNovos={{ metrica: 'QUANTIDADE', ativo: true }}
              paraFormulario={(r) => ({ ...r, preco_unitario: Number(r.preco_unitario), minimo: r.minimo !== null ? Number(r.minimo) : null, maximo: r.maximo !== null ? Number(r.maximo) : null })}
              colunas={[
                { title: 'Código', dataIndex: 'codigo', render: (v: string) => <strong>{v}</strong> },
                { title: 'Descrição', dataIndex: 'descricao' },
                { title: 'Métrica', dataIndex: 'metrica' },
                { title: 'Unidade', dataIndex: 'unidade', render: (v: string | null) => v ?? '—' },
                { title: 'Preço unitário', dataIndex: 'preco_unitario', align: 'right', render: (v: string) => formatarKz(v) },
                { title: 'Mín./máx. (total)', render: (_, r) => `${r.minimo !== null ? formatarNumero(r.minimo) : '—'} / ${r.maximo !== null ? formatarNumero(r.maximo) : '—'}` },
                { title: 'Rubrica', dataIndex: 'infotipo_salarial_id', render: (v: number) => infotipos.nome(v) },
                { title: 'Activo', dataIndex: 'ativo', render: (v: boolean) => (v ? <Tag color="green">Sim</Tag> : <Tag>Não</Tag>) },
              ]}
              campos={
                <Row gutter={12}>
                  <Col span={8}><Form.Item name="codigo" label="Código" rules={[{ required: true }, { max: 20 }]}><Input /></Form.Item></Col>
                  <Col span={16}><Form.Item name="descricao" label="Descrição" rules={[{ required: true }, { max: 500 }]}><Input /></Form.Item></Col>
                  <Col span={8}><Form.Item name="metrica" label="Métrica"><Select options={METRICAS_PRODUTIVIDADE.map((m) => ({ value: m, label: m }))} /></Form.Item></Col>
                  <Col span={8}><Form.Item name="unidade" label="Unidade"><Input maxLength={10} /></Form.Item></Col>
                  <Col span={8}><Form.Item name="preco_unitario" label="Preço unitário" rules={[{ required: true }]}><InputNumber min={0.0001} precision={4} decimalSeparator="," style={{ width: '100%' }} /></Form.Item></Col>
                  <Col span={8}><Form.Item name="minimo" label="Mínimo (total)"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
                  <Col span={8}><Form.Item name="maximo" label="Máximo (total)"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
                  <Col span={8}><Form.Item name="ativo" label=" " valuePropName="checked"><Checkbox>Activo</Checkbox></Form.Item></Col>
                  <Col span={24}>
                    <Form.Item name="infotipo_salarial_id" label="Rubrica (vencimento)" rules={[{ required: true }]}>
                      <Select showSearch optionFilterProp="label" options={infotipos.lista.filter((i) => i.tipo === 'VENCIMENTO').map((i) => ({ value: i.id, label: i.nome }))} />
                    </Form.Item>
                  </Col>
                </Row>
              }
              larguraModal={720}
            />
          ),
        },
      ]} />
    </>
  );
}

function Periodos() {
  const { pode } = useSessao();
  const navegar = useNavigate();
  const [novo, setNovo] = useState(false);
  const [form] = Form.useForm<{ mes: Dayjs; janela: [Dayjs, Dayjs]; observacoes?: string }>();
  const q = useQuery({ queryKey: ['rh', 'produtividade', 'periodos'], queryFn: () => obter<PeriodoProdutividade[]>('/rh/produtividade/periodos') });
  useAvisarErro(q.error);
  const accao = useAccaoRh<PeriodoProdutividade>((p) => { setNovo(false); navegar(String(p.id)); });

  return (
    <Card>
      {pode('rh_prod_periodo') && (
        <Flex justify="end" style={{ marginBottom: 12 }}>
          <Button type="primary" icon={<PlusOutlined />} onClick={() => { form.resetFields(); const m = dayjs().startOf('month'); form.setFieldsValue({ mes: m, janela: [m, m.endOf('month')] }); setNovo(true); }}>Abrir período</Button>
        </Flex>
      )}
      <Table<PeriodoProdutividade> rowKey="id" size="middle" loading={q.isFetching} dataSource={q.data ?? []} pagination={{ pageSize: 24 }}
        onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        columns={[
          { title: 'Mês', dataIndex: 'mes', render: (m: string) => <strong>{mesPorExtenso(m)}</strong> },
          { title: 'Janela de medição', render: (_, p) => `${formatarData(p.data_inicio)} a ${formatarData(p.data_fim)}` },
          { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
          { title: 'Total no fecho', dataIndex: 'total_fecho', align: 'right', render: (v: string | null) => (v ? formatarKz(v) : '—') },
          { title: 'Lançado', dataIndex: 'lancado_em', render: (v: string | null) => (v ? formatarDataHora(v) : '—') },
        ]} />
      <Modal title="Abrir período de produtividade" open={novo} onCancel={() => setNovo(false)} okText="Abrir" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnClose>
        <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ metodo: 'post', url: '/rh/produtividade/periodos', dados: { mes: v.mes.format('YYYY-MM'), data_inicio: dataApi(v.janela[0]), data_fim: dataApi(v.janela[1]), observacoes: v.observacoes ?? null } })}>
          <Form.Item name="mes" label="Mês do processamento" rules={[{ required: true }]}><DatePicker picker="month" format="MM/YYYY" style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="janela" label="Janela de medição (até 93 dias)" rules={[{ required: true }]}><DatePicker.RangePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="observacoes" label="Observações"><Input.TextArea rows={2} maxLength={1000} /></Form.Item>
        </Form>
      </Modal>
    </Card>
  );
}

interface ValoresRegisto {
  colaborador_id: number;
  item_produtividade_id: number;
  quantidade: number;
  data?: Dayjs | null;
  observacoes?: string;
}

function DetalhePeriodo() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const q = useQuery({ queryKey: ['rh', 'produtividade', 'periodo', id], queryFn: () => obter<DetalheProdutividade>(`/rh/produtividade/periodos/${id}`) });
  useAvisarErro(q.error);
  const itens = useQuery({ queryKey: ['rh', 'produtividade', 'itens'], queryFn: () => obter<ItemProdutividade[]>('/rh/produtividade/itens') });
  const [edicao, setEdicao] = useState<RegistoProdutividade | 'novo' | null>(null);
  const [reabrir, setReabrir] = useState(false);
  const [form] = Form.useForm<ValoresRegisto>();
  const [formR] = Form.useForm<{ motivo: string }>();
  const accao = useAccaoRh(() => { setEdicao(null); setReabrir(false); });
  const colabForm = Form.useWatch('colaborador_id', form);
  const p = q.data;
  if (!p) return <Card loading={q.isLoading}>{!q.isLoading && <Alert type="error" message="Período não encontrado." />}</Card>;

  const aberto = p.estado !== 'FECHADO';
  const registar = aberto && pode('rh_prod_registar');
  const nomeColab = (cid: number) => p.elegiveis.find((e) => e.colaborador_id === cid)?.nome ?? `#${cid}`;
  const item = (iid: number) => itens.data?.find((i) => i.id === iid);
  const itensDoColab = p.elegiveis.find((e) => e.colaborador_id === colabForm)?.itens ?? {};

  const colunas: ColumnsType<RegistoProdutividade> = [
    { title: 'Colaborador', dataIndex: 'colaborador_id', render: nomeColab },
    { title: 'Item', dataIndex: 'item_produtividade_id', render: (v: number) => (item(v) ? `${item(v)?.codigo} — ${item(v)?.descricao}` : `#${v}`) },
    { title: 'Data', dataIndex: 'data', render: formatarData },
    { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: formatarNumero },
    { title: 'Considerada', dataIndex: 'quantidade_considerada', align: 'right', render: (v: string | null, r) => (v !== null && Number(v) !== Number(r.quantidade) ? <Typography.Text type="warning">{formatarNumero(v)}</Typography.Text> : formatarNumero(v)) },
    { title: 'Preço', dataIndex: 'preco_unitario', align: 'right', render: (v: string) => formatarKz(v) },
    { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => formatarKz(v) },
    { title: 'Origem', dataIndex: 'origem', render: (o: string | null) => (o ? <Tag>{o}</Tag> : '—') },
    {
      title: '',
      key: 'accoes',
      render: (_, r) => registar && (
        <Space size={4}>
          <Button size="small" type="text" icon={<EditOutlined />} aria-label="Editar" onClick={() => { form.setFieldsValue({ colaborador_id: r.colaborador_id, item_produtividade_id: r.item_produtividade_id, quantidade: Number(r.quantidade), data: r.data ? dayjs(r.data) : null, observacoes: r.observacoes ?? undefined }); setEdicao(r); }} />
          <Popconfirm title="Eliminar o registo?" okText="Eliminar" okButtonProps={{ danger: true }} cancelText="Cancelar" onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/rh/produtividade/periodos/${id}/registos/${r.id}` })}>
            <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" />
          </Popconfirm>
        </Space>
      ),
    },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo={`Produtividade — ${mesPorExtenso(p.mes)}`}
        subtitulo={<Space><EstadoTag estado={p.estado} />Janela {formatarData(p.data_inicio)} a {formatarData(p.data_fim)}</Space>}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {registar && <Button icon={<PlusOutlined />} onClick={() => { form.resetFields(); setEdicao('novo'); }}>Registo</Button>}
            {aberto && pode('rh_prod_periodo') && (
              <Button type="primary" icon={<LockOutlined />} onClick={() => Modal.confirm({ title: 'Fechar o período?', content: 'Depois de fechado pode ser importado no processamento salarial do mês.', okText: 'Fechar', cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ metodo: 'post', url: `/rh/produtividade/periodos/${id}/fechar` }) })}>Fechar período</Button>
            )}
            {!aberto && pode('rh_prod_periodo') && <Button icon={<UnlockOutlined />} onClick={() => { formR.resetFields(); setReabrir(true); }}>Reabrir</Button>}
          </>
        }
      />
      <Card style={{ marginBottom: 16 }}>
        <Descriptions size="small" column={{ xs: 1, md: 4 }}>
          <Descriptions.Item label="Elegíveis">{p.elegiveis.length}</Descriptions.Item>
          <Descriptions.Item label="Registos">{p.registos.length}</Descriptions.Item>
          <Descriptions.Item label="Total actual">{formatarKz(somar(p.registos.map((r) => r.valor)), true)}</Descriptions.Item>
          <Descriptions.Item label="Total no fecho">{p.total_fecho ? formatarKz(p.total_fecho, true) : '—'}</Descriptions.Item>
          {p.lancado_em && <Descriptions.Item label="Lançado no processamento">{formatarDataHora(p.lancado_em)}</Descriptions.Item>}
          {p.motivo_reabertura && <Descriptions.Item label="Motivo da reabertura" span={3}>{p.motivo_reabertura}</Descriptions.Item>}
        </Descriptions>
      </Card>
      <Card>
        <Typography.Paragraph type="secondary">O mínimo e o máximo do item aplicam-se ao total do colaborador no período; a quantidade considerada reparte-se pelos registos.</Typography.Paragraph>
        <Table<RegistoProdutividade> rowKey="id" size="small" columns={colunas} dataSource={p.registos} scroll={{ x: 'max-content' }} pagination={{ pageSize: 50 }} />
      </Card>
      <Modal title={edicao === 'novo' ? 'Novo registo' : 'Editar registo'} open={edicao !== null} onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnClose>
        <Form form={form} layout="vertical" onFinish={(v) => {
          const dados = { ...v, data: dataApi(v.data ?? null) ?? null, observacoes: v.observacoes ?? null };
          if (edicao === 'novo') accao.mutate({ metodo: 'post', url: `/rh/produtividade/periodos/${id}/registos`, dados });
          else if (edicao) accao.mutate({ metodo: 'put', url: `/rh/produtividade/periodos/${id}/registos/${edicao.id}`, dados });
        }}>
          <Form.Item name="colaborador_id" label="Colaborador (elegível)" rules={[{ required: true }]}>
            <Select showSearch optionFilterProp="label" onChange={() => form.setFieldValue('item_produtividade_id', undefined)} options={p.elegiveis.map((e) => ({ value: e.colaborador_id, label: e.nome }))} />
          </Form.Item>
          <Form.Item name="item_produtividade_id" label="Item do contrato" rules={[{ required: true }]}>
            <Select options={Object.values(itensDoColab).map((i) => ({ value: i.item_id, label: `${i.codigo} (${formatarKz(i.preco)})` }))} />
          </Form.Item>
          <Row gutter={12}>
            <Col span={12}><Form.Item name="quantidade" label="Quantidade" rules={[{ required: true }]}><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
            <Col span={12}><Form.Item name="data" label="Data"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} disabledDate={(d) => d.isBefore(dayjs(p.data_inicio)) || d.isAfter(dayjs(p.data_fim)) || d.isAfter(dayjs())} /></Form.Item></Col>
          </Row>
          <Form.Item name="observacoes" label="Observações"><Input.TextArea rows={2} maxLength={1000} /></Form.Item>
        </Form>
      </Modal>
      <Modal title="Reabrir período de produtividade" open={reabrir} onCancel={() => setReabrir(false)} okText="Reabrir" okButtonProps={{ danger: true }} cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => formR.submit()} destroyOnClose>
        <Form form={formR} layout="vertical" onFinish={(v) => accao.mutate({ metodo: 'post', url: `/rh/produtividade/periodos/${id}/reabrir`, dados: v })}>
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true, min: 5, message: 'Pelo menos 5 caracteres.' }]}><Input.TextArea rows={3} maxLength={500} /></Form.Item>
          <Typography.Text type="secondary">Exige o processamento salarial do mês aberto.</Typography.Text>
        </Form>
      </Modal>
    </>
  );
}
