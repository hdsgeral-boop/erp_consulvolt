import { Alert, Button, Card, Col, DatePicker, Descriptions, Form, Input, InputNumber, Modal, Popconfirm, Row, Segmented, Skeleton, Space, Statistic, Table, Tag, Typography, message } from 'antd';
import { ArrowLeftOutlined, DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaEstado, ValorKz } from '../contab/comum/Componentes';
import { deCentimos, paraCentimos } from '../contab/comum/decimal';
import { SeletorAux, SeletorConta, SeletorTerceiro, SeletorUnidade } from '../contab/comum/Seletores';
import type { MovimentoCaixa, SessaoCaixa } from './api';
import { SeletorContaFinanceira } from './comum';
import { accoesSessao } from './regras';

/** Tesouraria › Folha de Caixa (ecrã teso_folha_caixa): sessões por conta 45, movimentos, fecho com contagem e contabilização (diário CX). */
export default function FolhaCaixa() {
  return (
    <Routes>
      <Route index element={<ListaSessoes />} />
      <Route path=":id" element={<DetalheSessao />} />
    </Routes>
  );
}

function ListaSessoes() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [conta, setConta] = useState<string>();
  const [abrir, setAbrir] = useState(false);
  const [form] = Form.useForm<{ codigo_conta: string; data: Dayjs; saldo_abertura?: number }>();
  const sessoes = useQuery({ queryKey: ['teso', 'caixa', 'sessoes', conta], queryFn: () => obter<SessaoCaixa[]>('/tesouraria/caixa/sessoes', { codigo_conta: conta }) });
  const abertura = useMutation({
    mutationFn: (v: { codigo_conta: string; data: Dayjs; saldo_abertura?: number }) => enviar<SessaoCaixa>('post', '/tesouraria/caixa/sessoes', { ...v, data: dataApi(v.data) }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      if (dados.aviso) message.warning(dados.aviso, 8);
      void cliente.invalidateQueries({ queryKey: ['teso', 'caixa'] });
      navegar(String(dados.id));
    },
    onError: (e) => notificarErro(e, 'Não foi possível abrir a sessão'),
  });

  return (
    <>
      <CabecalhoPagina
        titulo="Folha de Caixa"
        subtitulo="Sessões de caixa por conta"
        accoes={pode('teso_caixa_operar') && <Button type="primary" icon={<PlusOutlined />} onClick={() => { form.setFieldsValue({ data: dayjs() }); setAbrir(true); }}>Abrir sessão</Button>}
      />
      <Card>
        <Space style={{ marginBottom: 16 }}>
          <SeletorContaFinanceira value={conta} onChange={setConta} allowClear prefixos={['45']} placeholder="Conta de caixa" />
        </Space>
        <Table<SessaoCaixa>
          rowKey="id"
          loading={sessoes.isLoading}
          dataSource={sessoes.data}
          pagination={{ pageSize: 25 }}
          scroll={{ x: 'max-content' }}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
          columns={[
            { title: 'Sessão', dataIndex: 'id', render: (v: number) => <strong>#{v}</strong> },
            { title: 'Conta', dataIndex: 'codigo_conta' },
            { title: 'Abertura', dataIndex: 'data_abertura', render: formatarData },
            { title: 'Fecho', dataIndex: 'data_fecho', render: formatarData },
            { title: 'Operador', dataIndex: 'operador' },
            { title: 'Saldo de abertura', dataIndex: 'saldo_abertura', align: 'right', render: (v: string | null) => <ValorKz valor={v} /> },
            { title: 'Saldo de fecho', dataIndex: 'saldo_fecho', align: 'right', render: (v: string | null) => <ValorKz valor={v} /> },
            { title: 'Contado', dataIndex: 'saldo_fisico', align: 'right', render: (v: string | null) => <ValorKz valor={v} /> },
            { title: 'Estado', dataIndex: 'estado', render: (v: string) => <EtiquetaEstado estado={v} /> },
          ]}
        />
      </Card>
      <Modal title="Abrir sessão de caixa" open={abrir} onCancel={() => setAbrir(false)} okText="Abrir" confirmLoading={abertura.isPending} onOk={() => form.submit()}>
        <Form form={form} layout="vertical" onFinish={(v) => abertura.mutate(v)}>
          <Form.Item name="codigo_conta" label="Conta de caixa" rules={[{ required: true }]}>
            <SeletorContaFinanceira prefixos={['45']} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="data" label="Data" rules={[{ required: true }]}>
            <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="saldo_abertura" label="Saldo de abertura" tooltip="Vazio = saldo de fecho da sessão anterior">
            <InputNumber min={0} precision={2} style={{ width: '100%' }} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}

interface ValoresMovimento {
  tipo: 'REC' | 'PAG';
  data_documento: Dayjs;
  conta_contrapartida: string;
  valor: number;
  descricao: string;
  terceiro_id?: number;
  numero_documento?: string;
  referencia?: string;
  centro_custo_id?: number;
  unidade_negocio_id?: number;
}

function DetalheSessao() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [movimento, setMovimento] = useState(false);
  const [fecho, setFecho] = useState(false);
  const [descontab, setDescontab] = useState(false);
  const [formMov] = Form.useForm<ValoresMovimento>();
  const [formFecho] = Form.useForm<{ saldo_fisico: number; data: Dayjs }>();
  const [formMotivo] = Form.useForm<{ motivo: string }>();
  const contado = Form.useWatch('saldo_fisico', formFecho);
  const consulta = useQuery({ queryKey: ['teso', 'caixa', 'sessao', id], queryFn: () => obter<SessaoCaixa>(`/tesouraria/caixa/sessoes/${id}`) });

  const accao = useMutation({
    mutationFn: ({ metodo = 'post', caminho, dados }: { metodo?: 'post' | 'delete'; caminho: string; dados?: unknown }) => enviar<SessaoCaixa | null>(metodo, `/tesouraria/caixa/sessoes/${id}${caminho}`, dados),
    onSuccess: ({ mensagem }, { caminho, metodo }) => {
      message.success(mensagem);
      setMovimento(false);
      setFecho(false);
      setDescontab(false);
      formMov.resetFields();
      formMotivo.resetFields();
      void cliente.invalidateQueries({ queryKey: ['teso'] });
      void cliente.invalidateQueries({ queryKey: ['contab'] });
      if (metodo === 'delete' && caminho === '') navegar('..');
    },
    onError: (e) => notificarErro(e),
  });

  if (consulta.isLoading) return <Skeleton active />;
  const s = consulta.data;
  if (!s) return <Alert type="error" message="Sessão não encontrada." />;
  const a = accoesSessao(s, pode);
  const diferencaFecho = contado !== undefined && contado !== null && s.saldo_sistema !== undefined ? deCentimos(paraCentimos(contado) - paraCentimos(s.saldo_sistema)) : null;

  return (
    <>
      <CabecalhoPagina
        titulo={`Sessão de caixa #${s.id}`}
        subtitulo={`Conta ${s.codigo_conta} · ${formatarData(s.data_abertura)}`}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {a.podeRegistar && <Button icon={<PlusOutlined />} onClick={() => { formMov.setFieldsValue({ tipo: 'REC', data_documento: dayjs(s.data_abertura) }); setMovimento(true); }}>Registar movimento</Button>}
            {a.podeFechar && <Button type="primary" onClick={() => { formFecho.setFieldsValue({ data: dayjs(), saldo_fisico: Number(s.saldo_sistema ?? 0) }); setFecho(true); }}>Fechar sessão</Button>}
            {a.podeContabilizar && <Button type="primary" loading={accao.isPending} onClick={() => Modal.confirm({ title: 'Contabilizar a sessão?', content: 'São gerados os lançamentos no diário de caixa.', okText: 'Contabilizar', cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ caminho: '/contabilizar' }) })}>Contabilizar</Button>}
            {a.podeDescontabilizar && <Button danger onClick={() => setDescontab(true)}>Descontabilizar</Button>}
            {a.podeEliminar && (
              <Popconfirm title="Eliminar esta sessão (sem movimentos)?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => accao.mutateAsync({ metodo: 'delete', caminho: '' })}>
                <Button danger icon={<DeleteOutlined />}>Eliminar</Button>
              </Popconfirm>
            )}
          </>
        }
      />
      <Card style={{ marginBottom: 16 }}>
        <Space size={40} wrap>
          <Statistic title="Saldo de abertura" value={formatarKz(s.saldo_abertura)} />
          <Statistic title="Saldo do sistema" value={formatarKz(s.saldo_sistema)} />
          {s.saldo_fisico !== null && <Statistic title="Saldo contado" value={formatarKz(s.saldo_fisico)} />}
          {s.diferenca !== null && s.diferenca !== undefined && <Statistic title="Diferença" value={formatarKz(s.diferenca)} valueStyle={{ color: paraCentimos(s.diferenca) === 0 ? undefined : '#cf1322' }} />}
        </Space>
        <Descriptions size="small" column={{ xs: 1, md: 4 }} style={{ marginTop: 16 }}>
          <Descriptions.Item label="Estado"><EtiquetaEstado estado={s.estado} /></Descriptions.Item>
          <Descriptions.Item label="Operador">{s.operador ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Fecho">{formatarData(s.data_fecho)}</Descriptions.Item>
          <Descriptions.Item label="Lançamentos">{s.numeros_lan_contabilizacao ?? '—'}</Descriptions.Item>
        </Descriptions>
      </Card>
      <Card title="Movimentos">
        <Table<MovimentoCaixa>
          rowKey="id"
          size="small"
          dataSource={s.movimentos ?? []}
          pagination={false}
          scroll={{ x: 'max-content' }}
          columns={[
            { title: 'Data', dataIndex: 'data_documento', render: formatarData },
            { title: 'Tipo', dataIndex: 'tipo', render: (v: string) => (v === 'REC' ? <Tag color="green">Entrada</Tag> : <Tag color="volcano">Saída</Tag>) },
            { title: 'Documento', dataIndex: 'numero_documento' },
            { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 300 },
            { title: 'Débito', dataIndex: 'conta_debito' },
            { title: 'Crédito', dataIndex: 'conta_credito' },
            { title: 'Entrada', align: 'right', render: (_, m) => (m.tipo === 'REC' ? <ValorKz valor={m.valor} /> : null) },
            { title: 'Saída', align: 'right', render: (_, m) => (m.tipo === 'PAG' ? <ValorKz valor={m.valor} /> : null) },
            { title: 'Origem', dataIndex: 'tipo_origem', render: (v: string | null) => (v ? <Tag>{v}</Tag> : 'Manual') },
            {
              title: '',
              render: (_, m) =>
                a.podeRegistar && !m.contabilizado ? (
                  <Popconfirm title="Remover este movimento?" okText="Remover" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => accao.mutateAsync({ metodo: 'delete', caminho: `/movimentos/${m.id}` })}>
                    <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Remover" />
                  </Popconfirm>
                ) : null,
            },
          ]}
        />
      </Card>

      <Modal title="Registar movimento de caixa" open={movimento} onCancel={() => setMovimento(false)} okText="Registar" confirmLoading={accao.isPending} onOk={() => formMov.submit()} width={720}>
        <Form form={formMov} layout="vertical" onFinish={(v) => accao.mutate({ caminho: '/movimentos', dados: { ...v, data_documento: dataApi(v.data_documento) } })}>
          <Row gutter={12}>
            <Col span={10}>
              <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
                <Segmented block options={[{ value: 'REC', label: 'Entrada (recebimento)' }, { value: 'PAG', label: 'Saída (pagamento)' }]} />
              </Form.Item>
            </Col>
            <Col span={7}>
              <Form.Item name="data_documento" label="Data" rules={[{ required: true }]}>
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col span={7}>
              <Form.Item name="valor" label="Valor (Kz)" rules={[{ required: true }]}>
                <InputNumber min={0.01} precision={2} style={{ width: '100%' }} />
              </Form.Item>
            </Col>
          </Row>
          <Form.Item name="conta_contrapartida" label="Conta de contrapartida" rules={[{ required: true }]}>
            <SeletorConta style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="descricao" label="Descrição" rules={[{ required: true, min: 3 }]}>
            <Input maxLength={1000} />
          </Form.Item>
          <Row gutter={12}>
            <Col span={12}><Form.Item name="terceiro_id" label="Terceiro"><SeletorTerceiro style={{ width: '100%' }} /></Form.Item></Col>
            <Col span={6}><Form.Item name="numero_documento" label="N.º documento"><Input maxLength={100} /></Form.Item></Col>
            <Col span={6}><Form.Item name="referencia" label="Referência"><Input maxLength={100} /></Form.Item></Col>
            <Col span={12}><Form.Item name="centro_custo_id" label="Centro de custo"><SeletorAux tabela="centros-custo" style={{ width: '100%' }} /></Form.Item></Col>
            <Col span={12}><Form.Item name="unidade_negocio_id" label="Unidade de negócio"><SeletorUnidade style={{ width: '100%' }} /></Form.Item></Col>
          </Row>
        </Form>
      </Modal>

      <Modal title="Fechar sessão (contagem)" open={fecho} onCancel={() => setFecho(false)} okText="Fechar sessão" confirmLoading={accao.isPending} onOk={() => formFecho.submit()}>
        <Form form={formFecho} layout="vertical" onFinish={(v) => accao.mutate({ caminho: '/fechar', dados: { saldo_fisico: v.saldo_fisico, data: dataApi(v.data) } })}>
          <Typography.Paragraph>Saldo do sistema: <strong>{formatarKz(s.saldo_sistema, true)}</strong></Typography.Paragraph>
          <Form.Item name="saldo_fisico" label="Saldo contado (Kz)" rules={[{ required: true }]}>
            <InputNumber min={0} precision={2} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="data" label="Data de fecho" rules={[{ required: true }]}>
            <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
          </Form.Item>
          {diferencaFecho && paraCentimos(diferencaFecho) !== 0 && (
            <Alert type="warning" showIcon message={`Diferença de ${formatarKz(diferencaFecho, true)} (${paraCentimos(diferencaFecho) > 0 ? 'sobra' : 'quebra'} de caixa).`} />
          )}
        </Form>
      </Modal>

      <Modal title="Descontabilizar a sessão (estorno)" open={descontab} onCancel={() => setDescontab(false)} okText="Descontabilizar" okButtonProps={{ danger: true }} confirmLoading={accao.isPending} onOk={() => formMotivo.submit()}>
        <Form form={formMotivo} layout="vertical" onFinish={(v) => accao.mutate({ caminho: '/descontabilizar', dados: v })}>
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true, min: 5, message: 'Indique o motivo (pelo menos 5 caracteres).' }]}>
            <Input.TextArea rows={3} maxLength={500} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}
