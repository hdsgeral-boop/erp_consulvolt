import { Alert, Button, Card, Col, ColorPicker, Empty, Flex, Form, Input, InputNumber, Modal, Popconfirm, Row, Select, Space, Switch, Table, Tabs, Tag, Typography } from 'antd';
import { ArrowDownOutlined, ArrowUpOutlined, DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAccao } from '@/componentes/Accoes';
import { CHAVE_CRM, useConfigCRM, useFunis, useModelosEmail } from './comum/dados';
import { marcadoresDesconhecidos, validarFunil, type Etapa, type Funil, type ModeloEmail, type Sequencia } from './comum/tipos';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';

import { TabelaComModos } from '@/componentes/vistas';
import { useSeparadorUrl } from '@/componentes/navegacao/useSeparadorUrl';
const TIPOS_ETAPA = [{ value: 'ABERTA', label: 'Em aberto' }, { value: 'GANHA', label: 'Ganha' }, { value: 'PERDIDA', label: 'Perdida' }];

/** Subtítulo e separadores do antigo (renderCrmConfig, crm_ui_gestao.js:394-395), pela mesma ordem. */
export const SUBTITULO_CONFIG_CRM = 'Pipelines e etapas (probabilidade, dias de estagnação e actividades automáticas), modelos de email, sequências automáticas, motivos de perda e origens das leads.';
export const SEPARADORES_CONFIG_CRM = [
  { key: 'pipelines', label: 'Pipelines e etapas' },
  { key: 'modelos', label: 'Modelos de email' },
  { key: 'sequencias', label: 'Sequências' },
  { key: 'listas', label: 'Motivos e origens' },
] as const;

/** Coluna «Tipo» das etapas (crm_ui_gestao.js:410). */
export const rotuloTipoEtapa = (t: string) => (t === 'ABERTA' ? 'Em aberto' : t === 'GANHA' ? 'Ganha' : 'Perdida');

/** Coluna «Actividades automáticas»: «Telefonar: Primeiro contacto com o cliente (+1d)», uma por linha (crm_ui_gestao.js:410). */
export const textosActividadesEtapa = (e: Pick<Etapa, 'tarefas'>, tipos: Record<string, string>) =>
  (e.tarefas ?? []).map((t) => `${tipos[t.tipo ?? ''] ?? t.tipo ?? ''}: ${t.titulo ?? ''} (+${t.dias ?? 0}d)`);

/** Coluna «Passos» das sequências: «+3d: Seguimento», um por linha (crm_ui_gestao.js:427). */
export const textosPassosSequencia = (passos: Sequencia['passos'], modelos: Pick<ModeloEmail, 'id' | 'nome'>[]) =>
  passos.map((p) => `+${p.dias ?? 0}d: ${modelos.find((m) => m.id === p.modelo_email_crm_id)?.nome ?? '?'}`);

/** Lista «uma por linha» (textareas do antigo, crm_ui_gestao.js:433-434) ↔ lista da API. */
export const linhasParaLista = (v: unknown): string[] => (Array.isArray(v) ? v : String(v ?? '').split('\n')).map((x) => String(x).trim()).filter(Boolean);
const propsTextoPorLinha = {
  getValueProps: (v: unknown) => ({ value: Array.isArray(v) ? v.join('\n') : (v as string | undefined) }),
  normalize: (t: string) => t.split('\n'),
};

/** CRM › Configuração (crm_config): pipelines e etapas, modelos de email, sequências e motivos/origens (renderCrmConfig, crm_ui_gestao.js:390-441). */
export default function ConfigCRM() {
  const { pode } = useSessao();
  const gerir = pode('crm_configurar');
  const filhos = { pipelines: <Funis gerir={gerir} />, modelos: <Modelos gerir={gerir} />, sequencias: <Sequencias gerir={gerir} />, listas: <Definicoes gerir={gerir} /> };
  const [sep, setSep] = useSeparadorUrl(SEPARADORES_CONFIG_CRM.map((x) => x.key));
  return (
    <>
      <CabecalhoPagina titulo="Configuração do CRM" subtitulo={SUBTITULO_CONFIG_CRM} />
      {!gerir && <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Só consulta: alterar a configuração exige a permissão de configurar o CRM." />}
      <Tabs activeKey={sep} onChange={setSep} items={SEPARADORES_CONFIG_CRM.map((x) => ({ key: x.key, label: x.label, children: filhos[x.key] }))} />
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
    // separador «Motivos e origens» (crm_ui_gestao.js:432-437): duas listas «uma por linha», alerta e prazo de pagamento
    <Card loading={config.isLoading}>
      <Form
        form={form}
        layout="vertical"
        disabled={!gerir}
        onFinish={(v) => accao.mutate({ metodo: 'put', url: '/crm/configuracao', dados: { ...v, motivos_perda: linhasParaLista(v.motivos_perda), origens: linhasParaLista(v.origens) } })}
        style={{ maxWidth: 900 }}
      >
        <Row gutter={16}>
          <Col xs={24} md={12}>
            <Form.Item name="motivos_perda" label="Motivos de perda (um por linha)" {...propsTextoPorLinha} rules={[{ validator: (_, v) => (linhasParaLista(v).length ? Promise.resolve() : Promise.reject(new Error('Indique pelo menos um motivo.'))) }]}>
              <Input.TextArea rows={9} />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="origens" label="Origens das leads (uma por linha)" {...propsTextoPorLinha}>
              <Input.TextArea rows={9} />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="dias_sem_atividade" label="Alerta: dias sem actividade registada"><InputNumber min={1} max={365} style={{ width: '100%' }} /></Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="prazo_pagamento_dias" label="Prazo de pagamento por defeito (dias) para calcular facturas em atraso" extra="Usa o vencimento da factura quando existe."><InputNumber min={0} max={3650} style={{ width: '100%' }} /></Form.Item>
          </Col>
        </Row>
        {gerir && <Button type="primary" htmlType="submit" loading={accao.isPending}>Gravar</Button>}
      </Form>
    </Card>
  );
}

function Funis({ gerir }: { gerir: boolean }) {
  const funis = useFunis();
  const config = useConfigCRM();
  const [edicao, setEdicao] = useState<Funil | 'novo' | null>(null);
  const accao = useAccao({ invalidar: [CHAVE_CRM] });
  const tipos = config.data?.tipos_atividade ?? {};
  // separador «Pipelines e etapas» (crm_ui_gestao.js:406-410): «Novo pipeline» e um cartão por pipeline com a tabela das etapas
  return (
    <>
      {gerir && <Button type="primary" icon={<PlusOutlined />} style={{ marginBottom: 10 }} onClick={() => setEdicao('novo')}>Novo pipeline</Button>}
      {funis.isLoading && <Card loading />}
      {!funis.isLoading && !funis.data?.length && <Empty description="Sem pipelines." />}
      {funis.data?.map((f) => (
        <Card key={f.id} size="small" style={{ marginBottom: 10 }}>
          <Flex justify="space-between" align="center" gap={8} wrap>
            <Typography.Title level={5} style={{ margin: 0 }}>
              {f.nome} {!f.ativo && <Tag>inactivo</Tag>}
            </Typography.Title>
            <Space wrap>
              <Button size="small" icon={<EditOutlined />} onClick={() => setEdicao(f)}>{gerir ? 'Editar' : 'Ver'}</Button>
              {gerir && (
                <Popconfirm title="Eliminar pipeline" description="Eliminar este pipeline? Só é possível sem oportunidades." okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/crm/funis/${f.id}` })}>
                  <Button size="small" danger icon={<DeleteOutlined />} aria-label="Eliminar" />
                </Popconfirm>
              )}
            </Space>
          </Flex>
          <Table<Etapa>
            style={{ marginTop: 8 }}
            size="small"
            scroll={scrollTabela()}
            rowKey={(e) => e.id || e.nome}
            dataSource={f.etapas}
            pagination={false}
            columns={[
              { title: 'Etapa', dataIndex: 'nome', render: (v: string, e) => <><span style={{ display: 'inline-block', width: 10, height: 10, borderRadius: 2, background: e.cor ?? '#64748b', marginRight: 5 }} /><strong>{v}</strong></> },
              { title: 'Tipo', dataIndex: 'tipo', render: rotuloTipoEtapa },
              { title: 'Probabilidade', dataIndex: 'probabilidade', align: 'right', render: (p: number | null) => `${p ?? 0}%` },
              { title: 'Estagnação', dataIndex: 'dias_estagnacao', align: 'right', render: (d: number | null) => (d ? `${d} dias` : '—') },
              {
                title: 'Actividades automáticas',
                key: 'tarefas',
                render: (_, e) => {
                  const linhas = textosActividadesEtapa(e, tipos);
                  return linhas.length ? <span style={{ fontSize: '0.78rem' }}>{linhas.map((l, i) => <div key={i}>{l}</div>)}</span> : '—';
                },
              },
            ]}
          />
        </Card>
      ))}
      <EditorFunil funil={edicao} gerir={gerir} aoFechar={() => setEdicao(null)} />
    </>
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
    <Modal open={!!funil} title={funil === 'novo' ? 'Novo pipeline' : `Editar pipeline — ${funil ? funil.nome : ''}`} onCancel={aoFechar} width={larguraModal(1000)} okText="Gravar" cancelText="Cancelar" okButtonProps={{ disabled: !gerir || erros.length > 0 }} confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
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
          <Col xs={24} sm={14}><Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item></Col>
          <Col xs={24} sm={5}><Form.Item name="ordem" label="Ordem"><InputNumber style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} sm={5}><Form.Item name="ativo" label="Activo" valuePropName="checked"><Switch /></Form.Item></Col>
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
                    <Form.Item name={[c.name, 'probabilidade']} style={{ width: 110, marginBottom: 0 }}><InputNumber min={0} max={100} suffix="%" placeholder="Prob." /></Form.Item>
                    <Form.Item name={[c.name, 'dias_estagnacao']} style={{ width: 150, marginBottom: 0 }} tooltip="Alerta de estagnação"><InputNumber min={0} suffix="dias" placeholder="Estagnação" /></Form.Item>
                    <Space wrap>
                      <Button size="small" icon={<ArrowUpOutlined />} disabled={i === 0} onClick={() => move(i, i - 1)} aria-label="Subir" />
                      <Button size="small" icon={<ArrowDownOutlined />} disabled={i === campos.length - 1} onClick={() => move(i, i + 1)} aria-label="Descer" />
                      <Button size="small" danger icon={<DeleteOutlined />} onClick={() => remove(c.name)} aria-label="Remover etapa" />
                    </Space>
                  </Flex>
                  <Form.List name={[c.name, 'tarefas']}>
                    {(tarefas, { add: addT, remove: remT }) => (
                      <div style={{ marginTop: 8, paddingLeft: 32 }}>
                        <div style={{ fontSize: '0.76rem', fontWeight: 600, color: '#475569', marginBottom: 4 }}>Actividades automáticas ao entrar nesta etapa</div>
                        {tarefas.map((t) => (
                          <Flex key={t.key} gap={8} wrap style={{ marginBottom: 4 }}>
                            <Form.Item name={[t.name, 'tipo']} style={{ width: 150, marginBottom: 0 }}><Select placeholder="Tipo" options={Object.entries(config.data?.tipos_atividade ?? {}).map(([value, label]) => ({ value, label }))} /></Form.Item>
                            <Form.Item name={[t.name, 'titulo']} style={{ flex: 1, marginBottom: 0 }}><Input placeholder="Título" maxLength={255} /></Form.Item>
                            <Form.Item name={[t.name, 'dias']} style={{ width: 120, marginBottom: 0 }}><InputNumber min={0} suffix="dias" /></Form.Item>
                            <Form.Item name={[t.name, 'modelo_email_crm_id']} style={{ width: 180, marginBottom: 0 }}><Select allowClear placeholder="— modelo de email —" options={(modelos.data ?? []).map((m) => ({ value: m.id, label: m.nome }))} /></Form.Item>
                            <Button size="small" type="text" danger icon={<DeleteOutlined />} onClick={() => remT(t.name)} aria-label="Remover tarefa" />
                          </Flex>
                        ))}
                        <Button size="small" type="link" icon={<PlusOutlined />} onClick={() => addT({ tipo: 'TAREFA', dias: 1 })}>Actividade</Button>
                      </div>
                    )}
                  </Form.List>
                </Card>
              ))}
              <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ id: '', nome: '', tipo: 'ABERTA', probabilidade: 50, tarefas: [] }, Math.max(0, campos.length - 2))}>Etapa em aberto</Button>
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
    // separador «Modelos de email» (crm_ui_gestao.js:414-418)
    <Card>
      <Alert
        type="info"
        style={{ marginBottom: 10 }}
        message={<>Marcadores: {(config.data?.marcadores ?? []).map((m) => <Typography.Text key={m} code>{`{{${m}}}`}</Typography.Text>)} — substituídos pelos dados da conta, contacto e oportunidade.</>}
      />
      {gerir && <Button type="primary" icon={<PlusOutlined />} style={{ marginBottom: 10 }} onClick={() => setEdicao('novo')}>Novo modelo</Button>}
      <TabelaComModos<ModeloEmail> idVista="modelos" scroll={scrollTabela()}
        rowKey="id"
        loading={modelos.isLoading}
        dataSource={modelos.data}
        pagination={false}
        locale={{ emptyText: 'Sem modelos.' }}
        columns={[
          { title: 'Modelo', dataIndex: 'nome', render: (v: string) => <strong>{v}</strong> },
          { title: 'Assunto', dataIndex: 'assunto' },
          { title: 'Mensagem', dataIndex: 'corpo', render: (c: string | null) => <span style={{ fontSize: '0.76rem', color: '#475569' }}>{String(c ?? '').slice(0, 160)}{String(c ?? '').length > 160 ? '…' : ''}</span> },
          {
            title: '',
            width: 90,
            render: (_, m) => (
              <Space wrap>
                <Button size="small" type="text" icon={<EditOutlined />} aria-label={gerir ? 'Editar' : 'Ver'} onClick={() => setEdicao(m)} />
                {gerir && (
                  <Popconfirm title="Eliminar modelo" description="Eliminar este modelo de email?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/crm/modelos-email/${m.id}` })}>
                    <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" />
                  </Popconfirm>
                )}
              </Space>
            ),
          },
        ]}
      />
      <Modal open={!!edicao} title={edicao === 'novo' ? 'Novo modelo de email' : `Editar modelo — ${(edicao as ModeloEmail | null)?.nome ?? ''}`} onCancel={() => setEdicao(null)} width={larguraModal(720)} okText="Gravar" cancelText="Cancelar" okButtonProps={{ disabled: !gerir }} confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
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
    // separador «Sequências» (crm_ui_gestao.js:422-427)
    <Card>
      <Alert type="info" style={{ marginBottom: 10 }} message="Quando uma oportunidade entra na etapa, os passos são agendados como emails (dias após a entrada). Cada email abre-se com o modelo preenchido a partir da agenda ou da oportunidade." />
      {gerir && <Button type="primary" icon={<PlusOutlined />} style={{ marginBottom: 10 }} onClick={() => setEdicao('nova')}>Nova sequência</Button>}
      <TabelaComModos<Sequencia> idVista="sequencias" scroll={scrollTabela()}
        rowKey="id"
        loading={lista.isLoading}
        dataSource={lista.data}
        pagination={false}
        locale={{ emptyText: 'Sem sequências.' }}
        columns={[
          { title: 'Sequência', dataIndex: 'nome', render: (v: string) => <strong>{v}</strong> },
          { title: 'Inicia em', key: 'f', render: (_, s) => `${nomeFunil(s.funil_vendas_crm_id)} › ${nomeEtapa(s.funil_vendas_crm_id, s.etapa_codigo)}` },
          { title: 'Passos', dataIndex: 'passos', render: (p: Sequencia['passos']) => <span style={{ fontSize: '0.78rem' }}>{textosPassosSequencia(p, modelos.data ?? []).map((l, i) => <div key={i}>{l}</div>)}</span> },
          { title: 'Estado', dataIndex: 'ativo', render: (a: boolean) => (a ? <Tag color="green">activa</Tag> : <Tag>inactiva</Tag>) },
          {
            title: '',
            width: 90,
            render: (_, s) => (
              <Space wrap>
                <Button size="small" type="text" icon={<EditOutlined />} aria-label={gerir ? 'Editar' : 'Ver'} onClick={() => setEdicao(s)} />
                {gerir && (
                  <Popconfirm title="Eliminar sequência" description="Eliminar esta sequência? As actividades já agendadas mantêm-se." okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/crm/sequencias/${s.id}` })}>
                    <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" />
                  </Popconfirm>
                )}
              </Space>
            ),
          },
        ]}
      />
      <Modal open={!!edicao} title={edicao === 'nova' ? 'Nova sequência de emails' : `Editar sequência — ${(edicao as Sequencia | null)?.nome ?? ''}`} onCancel={() => setEdicao(null)} width={larguraModal(680)} okText="Gravar" cancelText="Cancelar" okButtonProps={{ disabled: !gerir }} confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
        <Form form={form} layout="vertical" disabled={!gerir} onFinish={(v) => accao.mutate({ metodo: edicao === 'nova' ? 'post' : 'put', url: edicao === 'nova' ? '/crm/sequencias' : `/crm/sequencias/${(edicao as Sequencia).id}`, dados: v })}>
          <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item>
          <Row gutter={16}>
            <Col xs={24} sm={10}><Form.Item name="funil_vendas_crm_id" label="Pipeline" rules={[{ required: true }]}><Select options={(funis.data ?? []).map((f) => ({ value: f.id, label: f.nome }))} onChange={() => form.setFieldValue('etapa_codigo', undefined)} /></Form.Item></Col>
            <Col xs={24} sm={10}><Form.Item name="etapa_codigo" label="Inicia quando a oportunidade entra em" rules={[{ required: true }]}><Select options={(funis.data?.find((f) => f.id === funilId)?.etapas ?? []).map((e) => ({ value: e.id, label: e.nome }))} /></Form.Item></Col>
            <Col xs={24} sm={4}><Form.Item name="ativo" label="Activa" valuePropName="checked"><Switch /></Form.Item></Col>
          </Row>
          <Form.List name="passos">
            {(campos, { add, remove }) => (
              <>
                {campos.map((c, i) => (
                  <Flex key={c.key} gap={8} align="start" wrap>
                    <Typography.Text style={{ width: 60, paddingTop: 5 }}>Passo {i + 1}</Typography.Text>
                    <Form.Item name={[c.name, 'dias']} style={{ width: 140, marginBottom: 8 }}><InputNumber min={0} max={3650} prefix="D+" style={{ width: '100%' }} /></Form.Item>
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
