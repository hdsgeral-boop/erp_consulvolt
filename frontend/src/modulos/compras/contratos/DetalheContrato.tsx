import { Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Form, Input, InputNumber, Modal, Row, Select, Skeleton, Space, Statistic, Table, Tag } from 'antd';
import { ArrowLeftOutlined, DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { ModalMotivo, useAccao } from '../comum/accoes';
import { EstadoTag } from '../comum/estados';
import { obterLista } from '../comum/lista';
import { NomeTerceiro } from '../comum/referencias';
import { accoesContrato } from '../comum/regras';
import { numeroOuId, type ContratoCompra, type EncomendaCompra, type FaturaCompra, type MarcoContrato } from '../comum/tipos';
import { ConsumoContrato, ModalContrato } from './ModalContrato';

type Encomenda = NonNullable<ContratoCompra['encomendas']>[number];

export function DetalheContrato() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [modal, setModal] = useState<'editar' | 'associar' | 'cancelar' | 'marco' | null>(null);
  const [marco, setMarco] = useState<MarcoContrato | null>(null);
  const [formAssociar] = Form.useForm<{ encomendas: number[]; mover?: boolean }>();
  const [formMarco] = Form.useForm<{ titulo: string; data_prevista?: Dayjs; montante?: number }>();

  const consulta = useQuery({ queryKey: ['compras', 'contrato', id], queryFn: () => obter<ContratoCompra>(`/compras/contratos/${id}`) });
  const c = consulta.data;
  const encomendasFornecedor = useQuery({
    queryKey: ['compras', 'encomendas', 'do-fornecedor', c?.fornecedor_id],
    queryFn: () => obterLista<EncomendaCompra>('/compras/encomendas', { fornecedor_id: c?.fornecedor_id, por_pagina: 200 }),
    enabled: modal === 'associar' && !!c,
  });
  const faturasFornecedor = useQuery({
    queryKey: ['compras', 'faturas', 'do-fornecedor', c?.fornecedor_id],
    queryFn: () => obterLista<FaturaCompra>('/compras/faturas', { fornecedor_id: c?.fornecedor_id, por_pagina: 200 }),
    enabled: !!c && pode('compras_faturacao_view'),
  });
  const accao = useAccao({ invalidar: [['compras']], aoSucesso: () => { setModal(null); setMarco(null); } });

  if (consulta.isLoading) return <Skeleton active />;
  if (!c) return <Alert type="error" message="Contrato não encontrado." />;
  const a = accoesContrato(c, pode);
  const associadas = new Set((c.encomendas ?? []).map((e) => e.id));
  const faturas = (faturasFornecedor.data?.itens ?? []).filter((f) => f.estado !== 'ANULADA');

  const abrirMarco = (m: MarcoContrato | null) => {
    setMarco(m);
    formMarco.setFieldsValue(m ? { titulo: m.titulo, data_prevista: m.data_prevista ? dayjs(m.data_prevista) : undefined, montante: Number(m.montante) } : { titulo: '', data_prevista: undefined, montante: undefined });
    setModal('marco');
  };

  return (
    <>
      <CabecalhoPagina
        titulo={`Contrato ${c.referencia}`}
        subtitulo={<NomeTerceiro id={c.fornecedor_id} />}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {a.editar && <Button icon={<EditOutlined />} onClick={() => setModal('editar')}>Editar</Button>}
            {a.cancelar && <Button danger onClick={() => setModal('cancelar')}>Cancelar contrato</Button>}
          </>
        }
      />
      {c.estado === 'CANCELADO' && <Alert type="error" showIcon style={{ marginBottom: 16 }} message={`Contrato cancelado${c.motivo_cancelamento ? `: ${c.motivo_cancelamento}` : '.'}`} />}
      {c.consumo?.excedido && <Alert type="warning" showIcon style={{ marginBottom: 16 }} message="O valor encomendado excede o valor contratado." />}
      <Card style={{ marginBottom: 16 }}>
        <Descriptions column={{ xs: 1, md: 3 }} size="small">
          <Descriptions.Item label="Estado"><EstadoTag estado={c.estado} /></Descriptions.Item>
          <Descriptions.Item label="Início">{formatarData(c.data_inicio)}</Descriptions.Item>
          <Descriptions.Item label="Fim">{formatarData(c.data_fim)}</Descriptions.Item>
          {c.descricao && <Descriptions.Item label="Objecto" span={3}>{c.descricao}</Descriptions.Item>}
        </Descriptions>
        <Row gutter={16} style={{ marginTop: 16 }}>
          <Col xs={12} md={6}><Statistic title="Contratado (Kz)" value={formatarKz(c.valor_total)} /></Col>
          <Col xs={12} md={6}><Statistic title="Encomendado (Kz)" value={formatarKz(c.consumo?.encomendado)} /></Col>
          <Col xs={12} md={6}><Statistic title="Facturado (Kz)" value={formatarKz(c.consumo?.faturado)} /></Col>
          <Col xs={12} md={6}><Statistic title="Pago (Kz)" value={formatarKz(c.consumo?.pago)} /></Col>
        </Row>
        <ConsumoContrato contrato={c} />
      </Card>

      <Card
        title="Encomendas associadas"
        style={{ marginBottom: 16 }}
        extra={a.associar && <Button size="small" icon={<PlusOutlined />} onClick={() => { formAssociar.setFieldsValue({ encomendas: [], mover: false }); setModal('associar'); }}>Associar encomendas</Button>}
      >
        <Table<Encomenda>
          rowKey="id"
          size="small"
          pagination={false}
          dataSource={c.encomendas ?? []}
          columns={[
            { title: 'Encomenda', render: (_, e) => (pode('compras_encomendas_view') ? <a onClick={() => navegar(`/m/compras/compras_encomendas/${e.id}`)}>{numeroOuId(e.numero_encomenda, e.id)}</a> : numeroOuId(e.numero_encomenda, e.id)) },
            { title: 'Data', dataIndex: 'data', render: formatarData },
            { title: 'Total (Kz)', dataIndex: 'montante_total', align: 'right', render: (v: string | null) => formatarKz(v) },
            { title: 'Estado', dataIndex: 'estado', render: (s: string) => <EstadoTag estado={s} /> },
            {
              title: '',
              key: 'accoes',
              align: 'right',
              render: (_, e) =>
                a.desassociar && (
                  <Button
                    size="small"
                    danger
                    onClick={() =>
                      Modal.confirm({
                        title: `Retirar a encomenda ${numeroOuId(e.numero_encomenda, e.id)} do contrato?`,
                        okText: 'Retirar',
                        cancelText: 'Cancelar',
                        onOk: () => accao.mutateAsync({ metodo: 'delete', url: `/compras/contratos/${c.id}/encomendas/${e.id}` }),
                      })
                    }
                  >
                    Retirar
                  </Button>
                ),
            },
          ]}
        />
      </Card>

      <Card title="Marcos de pagamento" extra={a.marcos && <Button size="small" icon={<PlusOutlined />} onClick={() => abrirMarco(null)}>Novo marco</Button>}>
        <Table<MarcoContrato>
          rowKey="id"
          size="small"
          pagination={false}
          dataSource={c.marcos ?? []}
          columns={[
            { title: 'Marco', dataIndex: 'titulo' },
            { title: 'Data prevista', dataIndex: 'data_prevista', render: formatarData },
            { title: 'Montante (Kz)', dataIndex: 'montante', align: 'right', render: (v: string) => formatarKz(v) },
            {
              title: 'Factura',
              render: (_, m) =>
                a.marcos ? (
                  <Select<number>
                    allowClear
                    size="small"
                    style={{ width: 220 }}
                    placeholder="Ligar factura"
                    value={m.fatura_compra_id ?? undefined}
                    loading={faturasFornecedor.isLoading}
                    onChange={(v) => accao.mutate({ url: `/compras/contratos/${c.id}/marcos/${m.id}/fatura`, dados: { fatura_compra_id: v ?? null } })}
                    options={faturas.map((f) => ({ value: f.id, label: `${f.numero_fatura} — ${formatarKz(f.montante_total)}` }))}
                  />
                ) : m.fatura_compra_id ? (
                  <Tag color="green">Factura #{m.fatura_compra_id}</Tag>
                ) : (
                  '—'
                ),
            },
            {
              title: '',
              key: 'accoes',
              align: 'right',
              render: (_, m) =>
                a.marcos && (
                  <Space>
                    <Button size="small" icon={<EditOutlined />} disabled={!!m.fatura_compra_id} onClick={() => abrirMarco(m)} aria-label="Editar marco" />
                    <Button
                      size="small"
                      danger
                      icon={<DeleteOutlined />}
                      disabled={!!m.fatura_compra_id}
                      aria-label="Eliminar marco"
                      onClick={() =>
                        Modal.confirm({
                          title: `Eliminar o marco «${m.titulo}»?`,
                          okText: 'Eliminar',
                          okButtonProps: { danger: true },
                          cancelText: 'Cancelar',
                          onOk: () => accao.mutateAsync({ metodo: 'delete', url: `/compras/contratos/${c.id}/marcos/${m.id}` }),
                        })
                      }
                    />
                  </Space>
                ),
            },
          ]}
        />
      </Card>

      <ModalContrato contrato={c} aberto={modal === 'editar'} aoFechar={() => setModal(null)} />
      <ModalMotivo
        aberto={modal === 'cancelar'}
        titulo={`Cancelar o contrato ${c.referencia}`}
        textoOk="Cancelar contrato"
        carregando={accao.isPending}
        aoFechar={() => setModal(null)}
        aoConfirmar={(motivo) => accao.mutate({ url: `/compras/contratos/${c.id}/cancelar`, dados: { motivo } })}
      />
      <Modal title="Associar encomendas" open={modal === 'associar'} onCancel={() => setModal(null)} okText="Associar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => formAssociar.submit()}>
        <Form form={formAssociar} layout="vertical" onFinish={(v) => accao.mutate({ url: `/compras/contratos/${c.id}/encomendas`, dados: { encomendas: v.encomendas, mover: !!v.mover } })}>
          <Form.Item name="encomendas" label="Encomendas do fornecedor" rules={[{ required: true, message: 'Escolha pelo menos uma encomenda.' }]}>
            <Select
              mode="multiple"
              optionFilterProp="label"
              loading={encomendasFornecedor.isLoading}
              options={(encomendasFornecedor.data?.itens ?? [])
                .filter((e) => e.estado !== 'ANULADA' && !associadas.has(e.id))
                .map((e) => ({ value: e.id, label: `${numeroOuId(e.numero_encomenda, e.id)} — ${formatarData(e.data)} — ${formatarKz(e.montante_total)} Kz${e.contrato_fornecedor_id ? ` (contrato #${e.contrato_fornecedor_id})` : ''}` }))}
            />
          </Form.Item>
          <Form.Item name="mover" valuePropName="checked">
            <Checkbox>Mover as que já estão noutro contrato</Checkbox>
          </Form.Item>
        </Form>
      </Modal>
      <Modal title={marco ? 'Editar marco' : 'Novo marco'} open={modal === 'marco'} onCancel={() => setModal(null)} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => formMarco.submit()}>
        <Form
          form={formMarco}
          layout="vertical"
          onFinish={(v) =>
            accao.mutate({
              metodo: marco ? 'put' : 'post',
              url: marco ? `/compras/contratos/${c.id}/marcos/${marco.id}` : `/compras/contratos/${c.id}/marcos`,
              dados: { titulo: v.titulo, data_prevista: dataApi(v.data_prevista) ?? null, montante: v.montante },
            })
          }
        >
          <Form.Item name="titulo" label="Título" rules={[{ required: true, message: 'Indique o título.' }, { max: 255 }]}>
            <Input />
          </Form.Item>
          <Row gutter={16}>
            <Col span={12}>
              <Form.Item name="data_prevista" label="Data prevista" tooltip="Dentro da vigência do contrato.">
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col span={12}>
              <Form.Item name="montante" label="Montante (Kz)" rules={[{ required: true, message: 'Indique o montante.' }]}>
                <InputNumber min={0.01} precision={2} style={{ width: '100%' }} />
              </Form.Item>
            </Col>
          </Row>
        </Form>
      </Modal>
    </>
  );
}
