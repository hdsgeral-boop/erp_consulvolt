import { Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Form, Input, InputNumber, Modal, Popconfirm, Result, Row, Space, Spin, Statistic, Table, Typography } from 'antd';
import { ArrowLeftOutlined, CheckSquareOutlined, DeleteOutlined, EditOutlined, StopOutlined, UndoOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { useAccao } from '@/modulos/compras/comum/accoes';
import { dataApi, formatarData, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaAD } from './comum/componentes';
import { accoesItem } from './comum/regras';
import type { ItemAD, LancamentoAD, MapaItem, Quota } from './comum/tipos';
import { ModalItem } from './ModalItem';

/** Registo de acréscimo/diferimento: ficha, plano de quotas com o estado de cada lançamento, regularização e término. */
export function DetalheItem() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [editar, setEditar] = useState(false);
  const [regularizar, setRegularizar] = useState(false);
  const [terminar, setTerminar] = useState(false);
  const q = useQuery({ queryKey: ['acrescimos', 'item', id], queryFn: () => obter<MapaItem>(`/acrescimos/itens/${id}`) });
  const accao = useAccao({ invalidar: [['acrescimos']] });
  const eliminar = useAccao({ invalidar: [['acrescimos']], aoSucesso: () => navegar('..') });

  if (q.isLoading) return <Spin style={{ display: 'block', margin: 48 }} />;
  if (q.error || !q.data) return <Result status="404" title="Registo não encontrado" extra={<Button onClick={() => navegar('..')}>Voltar</Button>} />;
  const { item: it, quotas, lancamentos } = q.data;
  const contabilizados = lancamentos.filter((l) => l.estado === 'CONTABILIZADO');
  const acc = accoesItem(it, pode, contabilizados.length > 0);

  return (
    <>
      <CabecalhoPagina
        titulo={<Space wrap><Button type="text" icon={<ArrowLeftOutlined />} onClick={() => navegar('..')} />#{it.id} — {it.descricao}<EtiquetaAD valor={it.tipo} /><EtiquetaAD valor={it.estado} /></Space>}
        accoes={
          <>
            {acc.editar && <Button icon={<EditOutlined />} onClick={() => setEditar(true)}>{acc.edicaoParcial ? 'Notas e data limite' : 'Editar'}</Button>}
            {acc.regularizar && <Button icon={<CheckSquareOutlined />} onClick={() => setRegularizar(true)}>Regularizar</Button>}
            {acc.terminar && <Button icon={<StopOutlined />} onClick={() => setTerminar(true)}>Terminar</Button>}
            {acc.desfazer && (
              <Popconfirm title="Desfazer o pedido de regularização/término?" description="Só é possível enquanto não estiver contabilizado." okText="Desfazer" cancelText="Cancelar"
                onConfirm={() => accao.mutate({ url: `/acrescimos/itens/${it.id}/desfazer-pedido` })}>
                <Button icon={<UndoOutlined />}>Desfazer pedido</Button>
              </Popconfirm>
            )}
            {acc.eliminar && (
              <Popconfirm title="Eliminar este registo?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => eliminar.mutate({ metodo: 'delete', url: `/acrescimos/itens/${it.id}` })}>
                <Button danger icon={<DeleteOutlined />} />
              </Popconfirm>
            )}
          </>
        }
      />
      <Row gutter={16} style={{ marginBottom: 16 }}>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Valor (Kz)" value={formatarKz(it.valor)} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Reconhecido (Kz)" value={formatarKz(q.data.reconhecido)} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Por reconhecer (Kz)" value={formatarKz((Number(it.valor) - Number(q.data.reconhecido)).toFixed(2))} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Saldo na conta 37 (Kz)" value={formatarKz(q.data.saldo_balanco)} /></Card></Col>
      </Row>
      <Card size="small" style={{ marginBottom: 16 }}>
        <Descriptions size="small" column={{ xs: 1, md: 3 }}>
          <Descriptions.Item label="Natureza"><EtiquetaAD valor={it.natureza} /></Descriptions.Item>
          <Descriptions.Item label="Conta de resultados">{it.conta_resultado}</Descriptions.Item>
          <Descriptions.Item label="Conta de balanço">{it.conta_balanco}</Descriptions.Item>
          <Descriptions.Item label="Período">{formatarData(it.data_inicio)} → {formatarData(it.data_fim)} ({it.reparticao === 'DIAS' ? 'por dias' : 'por meses'})</Descriptions.Item>
          {it.tipo === 'DIFERIMENTO' ? (
            <Descriptions.Item label="Documento">{formatarData(it.data_documento)}{it.documento_em_balanco ? ' (já na conta 37)' : ''}</Descriptions.Item>
          ) : (
            <Descriptions.Item label="Data limite do documento">{formatarData(it.data_limite)}</Descriptions.Item>
          )}
          <Descriptions.Item label="Origem">{it.origem?.doc ? `${it.origem.fonte ?? ''} ${it.origem.doc}` : 'Manual'}</Descriptions.Item>
          {it.regularizacao && (
            <Descriptions.Item label="Regularização" span={3}>
              {it.regularizacao.anulacao ? 'Anulação' : `${it.regularizacao.fonte ?? ''} ${it.regularizacao.doc ?? ''} — ${formatarKz(it.regularizacao.valor ?? null, true)}`} em {formatarData(it.regularizacao.data ?? null)}
              {it.regularizacao.motivo ? ` · ${it.regularizacao.motivo}` : ''}
            </Descriptions.Item>
          )}
          {it.termino && <Descriptions.Item label="Término" span={3}>{formatarData(it.termino.data)}{it.termino.motivo ? ` · ${it.termino.motivo}` : ''}</Descriptions.Item>}
          {it.notas && <Descriptions.Item label="Notas" span={3}>{it.notas}</Descriptions.Item>}
        </Descriptions>
      </Card>
      <Row gutter={16}>
        <Col xs={24} lg={10}>
          <Card size="small" title="Plano de reconhecimento">
            <Table<Quota>
              size="small"
              rowKey="periodo"
              pagination={false}
              dataSource={quotas}
              columns={[
                { title: 'Período', dataIndex: 'periodo' },
                { title: 'Quota (Kz)', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} /> },
                { title: 'Lançamento', key: 'l', render: (_, x) => (x.lancamento ? <Space size={4}><EtiquetaAD valor={x.lancamento.estado} />{x.lancamento.numero_lan}</Space> : <Typography.Text type="secondary">Por contabilizar</Typography.Text>) },
              ]}
            />
          </Card>
        </Col>
        <Col xs={24} lg={14}>
          <Card size="small" title="Lançamentos">
            <Table<LancamentoAD>
              size="small"
              rowKey="id"
              pagination={false}
              dataSource={lancamentos}
              scroll={{ x: 'max-content' }}
              columns={[
                { title: 'Período', dataIndex: 'periodo' },
                { title: 'Tipo', dataIndex: 'tipo', render: (v) => <EtiquetaAD valor={v} /> },
                { title: 'Documento', dataIndex: 'numero_documento' },
                { title: 'N.º lanç.', dataIndex: 'numero_lan' },
                { title: 'Data', dataIndex: 'data_documento', render: formatarData },
                { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} /> },
                { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaAD valor={v} /> },
                { title: 'Por', key: 'por', render: (_, l) => (l.anulado_por ? `anulado por ${l.anulado_por} ${formatarDataHora(l.anulado_em)}` : `${l.por ?? ''} ${formatarDataHora(l.em)}`) },
              ]}
            />
          </Card>
        </Col>
      </Row>
      <ModalItem aberto={editar} item={it} parcial={acc.edicaoParcial} aoFechar={() => setEditar(false)} />
      <ModalRegularizar item={regularizar ? it : null} aoFechar={() => setRegularizar(false)} />
      <ModalTerminar item={terminar ? it : null} aoFechar={() => setTerminar(false)} />
    </>
  );
}

/** Associa o documento real a um acréscimo (ou anula-o); a regularização entra na proposta do mês. */
export function ModalRegularizar({ item, documento, aoFechar }: { item: Pick<ItemAD, 'id' | 'descricao' | 'valor'> | null; documento?: { fonte: string; id: number | string; doc: string; data: string; valor: string }; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const anulacao = Form.useWatch('anulacao', form);
  const accao = useAccao({ invalidar: [['acrescimos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (!item) return;
    form.resetFields();
    form.setFieldsValue(documento ? { data: dayjs(documento.data), doc: documento.doc, valor: Number(documento.valor), anulacao: false } : { data: dayjs(), anulacao: false });
  }, [item, documento, form]);
  return (
    <Modal title={`Regularizar «${item?.descricao ?? ''}»`} open={!!item} onCancel={aoFechar} onOk={() => form.submit()} okText="Registar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnClose>
      <Typography.Paragraph type="secondary">Acrescido: {formatarKz(item?.valor, true)}. A diferença entre o documento real e o acrescido é tratada no lançamento de regularização.</Typography.Paragraph>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({
        url: `/acrescimos/itens/${item?.id}/regularizar`,
        dados: { data: dataApi(v.data), anulacao: !!v.anulacao, valor: v.anulacao ? null : v.valor, doc: v.anulacao ? null : v.doc, motivo: v.motivo || null,
          fonte: documento?.fonte ?? 'MANUAL', id: documento?.id ?? null },
      })}>
        <Form.Item name="anulacao" valuePropName="checked"><Checkbox disabled={!!documento}>Anular o acréscimo (não haverá documento real)</Checkbox></Form.Item>
        <Space wrap>
          <Form.Item name="data" label="Data" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" /></Form.Item>
          {!anulacao && <Form.Item name="doc" label="N.º do documento" rules={[{ required: true, message: 'Indique o documento.' }]}><Input maxLength={60} style={{ width: 200 }} /></Form.Item>}
          {!anulacao && <Form.Item name="valor" label="Valor sem IVA (Kz)" rules={[{ required: true, message: 'Indique o valor.' }]}><InputNumber min={0.01} precision={2} style={{ width: 180 }} /></Form.Item>}
        </Space>
        <Form.Item name="motivo" label="Motivo / observação"><Input maxLength={100} /></Form.Item>
      </Form>
      {documento && <Alert type="info" showIcon message={`Documento recolhido de ${documento.fonte}: ${documento.doc}`} />}
    </Modal>
  );
}

function ModalTerminar({ item, aoFechar }: { item: ItemAD | null; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const accao = useAccao({ invalidar: [['acrescimos']], aoSucesso: () => aoFechar() });
  useEffect(() => { if (item) form.setFieldsValue({ data: dayjs(), motivo: '' }); }, [item, form]);
  return (
    <Modal title={`Terminar antecipadamente «${item?.descricao ?? ''}»`} open={!!item} onCancel={aoFechar} onOk={() => form.submit()} okText="Terminar" cancelText="Cancelar" okButtonProps={{ danger: true }} confirmLoading={accao.isPending} destroyOnClose>
      <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="O saldo por reconhecer é contabilizado de uma vez na proposta do mês da data indicada." />
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/acrescimos/itens/${item?.id}/terminar`, dados: { data: dataApi(v.data), motivo: v.motivo || null } })}>
        <Form.Item name="data" label="Data do término" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" /></Form.Item>
        <Form.Item name="motivo" label="Motivo"><Input.TextArea rows={2} maxLength={500} /></Form.Item>
      </Form>
    </Modal>
  );
}
