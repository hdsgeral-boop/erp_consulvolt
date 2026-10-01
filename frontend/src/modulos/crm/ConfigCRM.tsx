import { Alert, Button, Card, Col, ColorPicker, Flex, Form, Input, InputNumber, Modal, Popconfirm, Row, Select, Space, Switch, Table, Tabs, Tag, Typography } from 'antd';
import { ArrowDownOutlined, ArrowUpOutlined, DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAccao } from '@/modulos/compras/comum/accoes';
import { CHAVE_CRM, useConfigCRM, useFunis, useModelosEmail } from './comum/dados';
import { marcadoresDesconhecidos, validarFunil, type Etapa, type Funil, type ModeloEmail, type Sequencia } from './comum/tipos';

const TIPOS_ETAPA = [{ value: 'ABERTA', label: 'Aberta' }, { value: 'GANHA', label: 'Ganha' }, { value: 'PERDIDA', label: 'Perdida' }];

/** CRM › Configuração (crm_config): definições (motivos, origens, prazos), funis com etapas, modelos de email e sequências. */
export default function ConfigCRM() {
  const { pode } = useSessao();
  const gerir = pode('crm_configurar');
  return (
    <>
      <CabecalhoPagina titulo="Configuração do CRM" subtitulo="Funis de vendas, motivos de perda, origens, modelos de email e sequências automáticas" />
      {!gerir && <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Só consulta: alterar a configuração exige a permissão de configurar o CRM." />}
      <Tabs
        items={[
          { key: 'funis', label: 'Funis de vendas', children: <Funis gerir={gerir} /> },
          { key: 'definicoes', label: 'Definições', children: <Definicoes gerir={gerir} /> },
          { key: 'modelos', label: 'Modelos de email', children: <Modelos gerir={gerir} /> },
          { key: 'sequencias', label: 'Sequências', children: <Sequencias gerir={gerir} /> },
        ]}
      />
    </>
  );
}

function Definicoes({ gerir }: { gerir: boolean }) {
  const config = useConfigCRM();
  const [form] = Form.useForm();
  const accao = useAccao({ invalidar: [CHAVE_CRM] });
  useEffect(() => {
    if (config.data) form.setFieldsValue(config.data);
  }, [config.data, form]);
  return (
    <Card loading={config.isLoading}>
      <Form form={form} layout="vertical" disabled={!gerir} onFinish={(v) => accao.mutate({ metodo: 'put', url: '/crm/configuracao', dados: v })} style={{ maxWidth: 760 }}>
        <Form.Item name="motivos_perda" label="Motivos de perda" rules={[{ required: true, type: 'array', min: 1, message: 'Indique pelo menos um motivo.' }]}>
          <Select mode="tags" tokenSeparators={[';']} placeholder="Escreva e prima Enter" />
        </Form.Item>
        <Form.Item name="origens" label="Origens de oportunidades e contas">
          <Select mode="tags" tokenSeparators={[';']} placeholder="Escreva e prima Enter" />
        </Form.Item>
        <Space size={24} wrap>
          <Form.Item name="dias_sem_atividade" label="Alerta após dias sem actividade"><InputNumber min={1} max={365} /></Form.Item>
          <Form.Item name="prazo_pagamento_dias" label="Prazo de pagamento por omissão (dias)" tooltip="Usado para calcular atrasos quando a factura não tem vencimento."><InputNumber min={0} max={3650} /></Form.Item>
        </Space>
        {gerir && <Button type="primary" htmlType="submit" loading={accao.isPending}>Gravar</Button>}
      </Form>
    </Card>
  );
}

function Funis({ gerir }: { gerir: boolean }) {
  const funis = useFunis();
  const [edicao, setEdicao] = useState<Funil | 'novo' | null>(null);
  const accao = useAccao({ invalidar: [CHAVE_CRM] });
  return (
    <Card>
      {gerir && (
        <Flex justify="end" style={{ marginBottom: 12 }}>
          <Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('novo')}>Novo funil</Button>
        </Flex>
      )}
      <Table<Funil>
        rowKey="id"
        loading={funis.isLoading}
        dataSource={funis.data}
        pagination={false}
        columns={[
          { title: 'Funil', dataIndex: 'nome', render: (v: string) => <strong>{v}</strong> },
          { title: 'Etapas', dataIndex: 'etapas', render: (es: Etapa[]) => <Space size={4} wrap>{es.map((e) => <Tag key={e.id} color={e.cor ?? undefined}>{e.nome}</Tag>)}</Space> },
          { title: 'Ordem', dataIndex: 'ordem', width: 80 },
          { title: 'Estado', dataIndex: 'ativo', width: 100, render: (a: boolean) => (a ? <Tag color="green">Activo</Tag> : <Tag>Inactivo</Tag>) },
          {
            title: '',
            width: 90,
            render: (_, f) => (
              <Space>
                <Button size="small" type="text" icon={<EditOutlined />} aria-label={gerir ? 'Editar' : 'Ver'} onClick={() => setEdicao(f)} />
                {gerir && (
                  <Popconfirm title={`Eliminar o funil «${f.nome}»?`} description="Só é possível sem oportunidades." okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/crm/funis/${f.id}` })}>
                    <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" />
                  </Popconfirm>
                )}
              </Space>
            ),
          },
        ]}
      />
      <EditorFunil funil={edicao} gerir={gerir} aoFechar={() => setEdicao(null)} />
    </Card>
  );
}

function EditorFunil({ funil, gerir, aoFechar }: { funil: Funil | 'novo' | null; gerir: boolean; aoFechar: () => void }) {
  const config = useConfigCRM();
  const modelos = useModelosEmail();
  const [form] = Form.useForm<{ nome: string; ordem?: number | null; ativo: boolean; etapas: Etapa[] }>();
  const etapas = Form.useWatch('etapas', form) ?? [];
  const accao = useAccao({ invalidar: [CHAVE_CRM], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (!funil) return;
    form.resetFields();
    form.setFieldsValue(
      funil === 'novo'
        ? {
            ativo: true,
            etapas: [
              { id: '', nome: 'Lead', tipo: 'ABERTA', probabilidade: 10, dias_estagnacao: 7, cor: '#64748b', tarefas: [] },
              { id: '', nome: 'Proposta', tipo: 'ABERTA', probabilidade: 50, dias_estagnacao: 14, cor: '#2a78d6', tarefas: [] },
              { id: '', nome: 'Ganha', tipo: 'GANHA', probabilidade: 100, cor: '#16a34a', tarefas: [] },
              { id: '', nome: 'Perdida', tipo: 'PERDIDA', probabilidade: 0, cor: '#dc2626', tarefas: [] },
            ],
          }
        : funil,
    );
  }, [funil, form]);
  const erros = validarFunil(etapas);
  return (
    <Modal open={!!funil} title={funil === 'novo' ? 'Novo funil' : `Funil — ${funil ? funil.nome : ''}`} onCancel={aoFechar} width={1000} okText="Gravar" cancelText="Cancelar" okButtonProps={{ disabled: !gerir || erros.length > 0 }} confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnClose>
      <Form
        form={form}
        layout="vertical"
        disabled={!gerir}
        onFinish={(v) =>
          accao.mutate({
            metodo: funil === 'novo' ? 'post' : 'put',
            url: funil === 'novo' ? '/crm/funis' : `/crm/funis/${(funil as Funil).id}`,
            dados: { ...v, etapas: v.etapas.map((e) => ({ ...e, id: e.id || null, cor: typeof e.cor === 'string' ? e.cor : null })) },
          })
        }
      >
        <Row gutter={16}>
          <Col span={14}><Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item></Col>
          <Col span={5}><Form.Item name="ordem" label="Ordem"><InputNumber style={{ width: '100%' }} /></Form.Item></Col>
          <Col span={5}><Form.Item name="ativo" label="Activo" valuePropName="checked"><Switch /></Form.Item></Col>
        </Row>
        {erros.length > 0 && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={erros.join(' ')} />}
        <Form.List name="etapas">
          {(campos, { add, remove, move }) => (
            <>
              {campos.map((c, i) => (
                <Card key={c.key} size="small" style={{ marginBottom: 8 }} styles={{ body: { padding: 10 } }}>
                  <Flex gap={8} align="start" wrap>
                    <Form.Item name={[c.name, 'id']} hidden><Input /></Form.Item>
                    <Form.Item name={[c.name, 'cor']} style={{ marginBottom: 0 }} getValueFromEvent={(cor) => cor?.toHexString?.() ?? cor}>
                      <ColorPicker size="small" />
                    </Form.Item>
                    <Form.Item name={[c.name, 'nome']} style={{ flex: 1, minWidth: 160, marginBottom: 0 }} rules={[{ required: true, message: 'Nome.' }]}><Input placeholder="Nome da etapa" maxLength={100} /></Form.Item>
                    <Form.Item name={[c.name, 'tipo']} style={{ width: 120, marginBottom: 0 }}><Select options={TIPOS_ETAPA} /></Form.Item>
                    <Form.Item name={[c.name, 'probabilidade']} style={{ width: 110, marginBottom: 0 }}><InputNumber min={0} max={100} addonAfter="%" placeholder="Prob." /></Form.Item>
                    <Form.Item name={[c.name, 'dias_estagnacao']} style={{ width: 150, marginBottom: 0 }} tooltip="Alerta de estagnação"><InputNumber min={0} addonAfter="dias" placeholder="Estagnação" /></Form.Item>
                    <Space>
                      <Button size="small" icon={<ArrowUpOutlined />} disabled={i === 0} onClick={() => move(i, i - 1)} aria-label="Subir" />
                      <Button size="small" icon={<ArrowDownOutlined />} disabled={i === campos.length - 1} onClick={() => move(i, i + 1)} aria-label="Descer" />
                      <Button size="small" danger icon={<DeleteOutlined />} onClick={() => remove(c.name)} aria-label="Remover etapa" />
                    </Space>
                  </Flex>
                  <Form.List name={[c.name, 'tarefas']}>
                    {(tarefas, { add: addT, remove: remT }) => (
                      <div style={{ marginTop: 8, paddingLeft: 32 }}>
                        {tarefas.map((t) => (
                          <Flex key={t.key} gap={8} style={{ marginBottom: 4 }}>
                            <Form.Item name={[t.name, 'tipo']} style={{ width: 150, marginBottom: 0 }}><Select placeholder="Tipo" options={Object.entries(config.data?.tipos_atividade ?? {}).map(([value, label]) => ({ value, label }))} /></Form.Item>
                            <Form.Item name={[t.name, 'titulo']} style={{ flex: 1, marginBottom: 0 }}><Input placeholder="Tarefa automática ao entrar na etapa" maxLength={255} /></Form.Item>
                            <Form.Item name={[t.name, 'dias']} style={{ width: 120, marginBottom: 0 }}><InputNumber min={0} addonAfter="dias" /></Form.Item>
                            <Form.Item name={[t.name, 'modelo_email_crm_id']} style={{ width: 180, marginBottom: 0 }}><Select allowClear placeholder="Modelo (email)" options={(modelos.data ?? []).map((m) => ({ value: m.id, label: m.nome }))} /></Form.Item>
                            <Button size="small" type="text" danger icon={<DeleteOutlined />} onClick={() => remT(t.name)} aria-label="Remover tarefa" />
                          </Flex>
                        ))}
                        <Button size="small" type="link" icon={<PlusOutlined />} onClick={() => addT({ tipo: 'TAREFA', dias: 1 })}>Tarefa automática</Button>
                      </div>
                    )}
                  </Form.List>
                </Card>
              ))}
              <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ id: '', nome: '', tipo: 'ABERTA', probabilidade: 50, tarefas: [] }, Math.max(0, campos.length - 2))}>Etapa</Button>
            </>
          )}
        </Form.List>
      </Form>
    </Modal>
  );
}

function Modelos({ gerir }: { gerir: boolean }) {
  const modelos = useModelosEmail();
  const config = useConfigCRM();
  const [edicao, setEdicao] = useState<ModeloEmail | 'novo' | null>(null);
  const [form] = Form.useForm<{ nome: string; assunto: string; corpo?: string }>();
  const assunto = Form.useWatch('assunto', form) ?? '';
  const corpo = Form.useWatch('corpo', form) ?? '';
  const accao = useAccao({ invalidar: [CHAVE_CRM], aoSucesso: () => setEdicao(null) });
  const desconhecidos = marcadoresDesconhecidos(`${assunto} ${corpo}`, config.data?.marcadores ?? []);
  useEffect(() => {
    if (!edicao) return;
    form.resetFields();
    if (edicao !== 'novo') form.setFieldsValue({ ...edicao, corpo: edicao.corpo ?? '' });
  }, [edicao, form]);
  return (
    <Card>
      {gerir && <Flex justify="end" style={{ marginBottom: 12 }}><Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('novo')}>Novo modelo</Button></Flex>}
      <Table<ModeloEmail>
        rowKey="id"
        loading={modelos.isLoading}
        dataSource={modelos.data}
        pagination={false}
        columns={[
          { title: 'Modelo', dataIndex: 'nome', render: (v: string) => <strong>{v}</strong> },
          { title: 'Assunto', dataIndex: 'assunto' },
          {
            title: '',
            width: 90,
            render: (_, m) => (
              <Space>
                <Button size="small" type="text" icon={<EditOutlined />} aria-label={gerir ? 'Editar' : 'Ver'} onClick={() => setEdicao(m)} />
                {gerir && (
                  <Popconfirm title={`Eliminar o modelo «${m.nome}»?`} okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/crm/modelos-email/${m.id}` })}>
                    <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" />
                  </Popconfirm>
                )}
              </Space>
            ),
          },
        ]}
      />
      <Modal open={!!edicao} title={edicao === 'novo' ? 'Novo modelo de email' : 'Modelo de email'} onCancel={() => setEdicao(null)} width={720} okText="Gravar" cancelText="Cancelar" okButtonProps={{ disabled: !gerir }} confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnClose>
        <Form form={form} layout="vertical" disabled={!gerir} onFinish={(v) => accao.mutate({ metodo: edicao === 'novo' ? 'post' : 'put', url: edicao === 'novo' ? '/crm/modelos-email' : `/crm/modelos-email/${(edicao as ModeloEmail).id}`, dados: v })}>
          <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item>
          <Form.Item name="assunto" label="Assunto" rules={[{ required: true, message: 'Indique o assunto.' }]}><Input maxLength={100} /></Form.Item>
          <Form.Item name="corpo" label="Mensagem"><Input.TextArea rows={10} maxLength={20000} /></Form.Item>
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
            Marcadores: {(config.data?.marcadores ?? []).map((m) => <Typography.Text key={m} code>{`{{${m}}}`}</Typography.Text>)}
          </Typography.Text>
          {desconhecidos.length > 0 && <Alert style={{ marginTop: 8 }} type="warning" showIcon message={`Marcadores desconhecidos (ficam em branco): ${desconhecidos.join(', ')}`} />}
        </Form>
      </Modal>
    </Card>
  );
}

function Sequencias({ gerir }: { gerir: boolean }) {
  const funis = useFunis();
  const modelos = useModelosEmail();
  const lista = useQuery({ queryKey: ['crm', 'sequencias'], queryFn: () => obter<Sequencia[]>('/crm/sequencias') });
  const [edicao, setEdicao] = useState<Sequencia | 'nova' | null>(null);
  const [form] = Form.useForm<Omit<Sequencia, 'id'>>();
  const funilId = Form.useWatch('funil_vendas_crm_id', form);
  const accao = useAccao({ invalidar: [CHAVE_CRM], aoSucesso: () => setEdicao(null) });
  const nomeFunil = (id: number) => funis.data?.find((f) => f.id === id)?.nome ?? `#${id}`;
  const nomeEtapa = (f: number, e: string) => funis.data?.find((x) => x.id === f)?.etapas.find((x) => x.id === e)?.nome ?? e;
  useEffect(() => {
    if (!edicao) return;
    form.resetFields();
    form.setFieldsValue(edicao === 'nova' ? { ativo: true, passos: [{ dias: 0 }] } : edicao);
  }, [edicao, form]);
  return (
    <Card>
      <Typography.Paragraph type="secondary">Quando uma oportunidade entra na etapa, são agendados os emails da sequência (até 5 passos) como actividades.</Typography.Paragraph>
      {gerir && <Flex justify="end" style={{ marginBottom: 12 }}><Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('nova')}>Nova sequência</Button></Flex>}
      <Table<Sequencia>
        rowKey="id"
        loading={lista.isLoading}
        dataSource={lista.data}
        pagination={false}
        columns={[
          { title: 'Sequência', dataIndex: 'nome', render: (v: string) => <strong>{v}</strong> },
          { title: 'Funil / etapa', key: 'f', render: (_, s) => `${nomeFunil(s.funil_vendas_crm_id)} › ${nomeEtapa(s.funil_vendas_crm_id, s.etapa_codigo)}` },
          { title: 'Passos', dataIndex: 'passos', render: (p: Sequencia['passos']) => p.map((x) => `D+${x.dias ?? 0}`).join(', ') },
          { title: 'Estado', dataIndex: 'ativo', render: (a: boolean) => (a ? <Tag color="green">Activa</Tag> : <Tag>Inactiva</Tag>) },
          {
            title: '',
            width: 90,
            render: (_, s) => (
              <Space>
                <Button size="small" type="text" icon={<EditOutlined />} aria-label={gerir ? 'Editar' : 'Ver'} onClick={() => setEdicao(s)} />
                {gerir && (
                  <Popconfirm title={`Eliminar a sequência «${s.nome}»?`} description="As actividades já agendadas mantêm-se." okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/crm/sequencias/${s.id}` })}>
                    <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" />
                  </Popconfirm>
                )}
              </Space>
            ),
          },
        ]}
      />
      <Modal open={!!edicao} title={edicao === 'nova' ? 'Nova sequência' : 'Sequência'} onCancel={() => setEdicao(null)} width={680} okText="Gravar" cancelText="Cancelar" okButtonProps={{ disabled: !gerir }} confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnClose>
        <Form form={form} layout="vertical" disabled={!gerir} onFinish={(v) => accao.mutate({ metodo: edicao === 'nova' ? 'post' : 'put', url: edicao === 'nova' ? '/crm/sequencias' : `/crm/sequencias/${(edicao as Sequencia).id}`, dados: v })}>
          <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item>
          <Row gutter={16}>
            <Col span={10}><Form.Item name="funil_vendas_crm_id" label="Funil" rules={[{ required: true }]}><Select options={(funis.data ?? []).map((f) => ({ value: f.id, label: f.nome }))} onChange={() => form.setFieldValue('etapa_codigo', undefined)} /></Form.Item></Col>
            <Col span={10}><Form.Item name="etapa_codigo" label="Ao entrar na etapa" rules={[{ required: true }]}><Select options={(funis.data?.find((f) => f.id === funilId)?.etapas ?? []).map((e) => ({ value: e.id, label: e.nome }))} /></Form.Item></Col>
            <Col span={4}><Form.Item name="ativo" label="Activa" valuePropName="checked"><Switch /></Form.Item></Col>
          </Row>
          <Form.List name="passos">
            {(campos, { add, remove }) => (
              <>
                {campos.map((c, i) => (
                  <Flex key={c.key} gap={8} align="start">
                    <Typography.Text style={{ width: 60, paddingTop: 5 }}>Passo {i + 1}</Typography.Text>
                    <Form.Item name={[c.name, 'dias']} style={{ width: 140, marginBottom: 8 }}><InputNumber min={0} max={3650} addonBefore="D+" style={{ width: '100%' }} /></Form.Item>
                    <Form.Item name={[c.name, 'modelo_email_crm_id']} style={{ flex: 1, marginBottom: 8 }} rules={[{ required: true, message: 'Escolha o modelo.' }]}><Select placeholder="Modelo de email" options={(modelos.data ?? []).map((m) => ({ value: m.id, label: m.nome }))} /></Form.Item>
                    <Button type="text" danger icon={<DeleteOutlined />} disabled={campos.length === 1} onClick={() => remove(c.name)} aria-label="Remover passo" />
                  </Flex>
                ))}
                {campos.length < 5 && <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ dias: 3 })}>Passo</Button>}
              </>
            )}
          </Form.List>
        </Form>
      </Modal>
    </Card>
  );
}
