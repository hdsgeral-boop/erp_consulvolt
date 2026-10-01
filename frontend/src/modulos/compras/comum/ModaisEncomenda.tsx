import { Alert, Col, DatePicker, Form, Input, InputNumber, Modal, Row, Table, Typography } from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect } from 'react';
import { dataApi, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { useAccao } from './accoes';
import { numero, pendente } from './calculos';
import { NomeProduto, useMapaProdutos } from './referencias';
import { numeroOuId, type EncomendaCompra, type FaturaCompra, type ItemCompra, type RececaoCompra } from './tipos';

interface LinhaQtd {
  item_encomenda_id: number;
  quantidade?: number;
  taxa_imposto?: number;
}

/** Linhas a enviar: só as que têm quantidade positiva. */
export function linhasComQuantidade<T extends { quantidade?: number | null }>(linhas: T[]): T[] {
  return linhas.filter((l) => numero(l.quantidade) > 0);
}

/** Registo de uma recepção parcial ou total de uma encomenda (POST /compras/encomendas/{id}/rececoes). */
export function ModalRececao({ encomenda, aberto, aoFechar, aoRegistar }: { encomenda: EncomendaCompra; aberto: boolean; aoFechar: () => void; aoRegistar?: (r: RececaoCompra) => void }) {
  const [form] = Form.useForm<{ numero_entrega: string; data: Dayjs; linhas: LinhaQtd[] }>();
  const linhasEnc = encomenda.linhas ?? [];
  const accao = useAccao<RececaoCompra>({
    invalidar: [['compras']],
    aoSucesso: (r) => {
      aoFechar();
      aoRegistar?.(r);
    },
    tituloErro: 'Não foi possível registar a recepção',
  });

  useEffect(() => {
    if (aberto)
      form.setFieldsValue({
        numero_entrega: '',
        data: dayjs(),
        linhas: linhasEnc.map((l) => ({ item_encomenda_id: l.id, quantidade: pendente(l.quantidade, l.quantidade_recebida) })),
      });
  }, [aberto, form, encomenda]);

  return (
    <Modal
      title={`Registar recepção — encomenda ${numeroOuId(encomenda.numero_encomenda, encomenda.id)}`}
      open={aberto}
      onCancel={aoFechar}
      okText="Registar recepção"
      cancelText="Cancelar"
      width={820}
      confirmLoading={accao.isPending}
      onOk={() => form.submit()}
      destroyOnClose
    >
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) => {
          const linhas = linhasComQuantidade(v.linhas);
          if (!linhas.length) {
            Modal.warning({ title: 'Indique pelo menos uma quantidade recebida.' });
            return;
          }
          accao.mutate({ url: `/compras/encomendas/${encomenda.id}/rececoes`, dados: { numero_entrega: v.numero_entrega, data: dataApi(v.data), linhas } });
        }}
      >
        <Row gutter={16}>
          <Col xs={24} md={14}>
            <Form.Item name="numero_entrega" label="N.º da guia do fornecedor" rules={[{ required: true, message: 'Indique o n.º da guia de remessa do fornecedor.' }, { max: 50 }]}>
              <Input />
            </Form.Item>
          </Col>
          <Col xs={24} md={10}>
            <Form.Item name="data" label="Data da recepção" rules={[{ required: true }]}>
              <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
        </Row>
        <Form.List name="linhas">
          {(campos) => (
            <Table
              rowKey="key"
              size="small"
              pagination={false}
              dataSource={campos}
              columns={[
                { title: 'Artigo', render: (_, c) => <NomeProduto id={linhasEnc[c.name]?.produto_id} produto={linhasEnc[c.name]?.produto} descricao={linhasEnc[c.name]?.descricao} /> },
                { title: 'Encomendado', align: 'right', render: (_, c) => formatarNumero(linhasEnc[c.name]?.quantidade) },
                { title: 'Já recebido', align: 'right', render: (_, c) => formatarNumero(linhasEnc[c.name]?.quantidade_recebida ?? 0) },
                {
                  title: 'Recebido agora',
                  render: (_, c) => (
                    <Form.Item name={[c.name, 'quantidade']} style={{ margin: 0 }}>
                      <InputNumber min={0} style={{ width: 120 }} />
                    </Form.Item>
                  ),
                },
              ]}
            />
          )}
        </Form.List>
        <Typography.Text type="secondary">A recepção fica por validar no armazém; só a validação dá entrada em stock e contabiliza.</Typography.Text>
      </Form>
    </Modal>
  );
}

/** Registo da factura do fornecedor a partir da encomenda (POST /compras/encomendas/{id}/faturas). */
export function ModalFaturaEncomenda({ encomenda, aberto, aoFechar, aoRegistar }: { encomenda: EncomendaCompra; aberto: boolean; aoFechar: () => void; aoRegistar?: (f: FaturaCompra) => void }) {
  const [form] = Form.useForm<{ numero_fatura: string; data: Dayjs; data_vencimento?: Dayjs; taxa_cambio?: number; linhas: LinhaQtd[] }>();
  const mapa = useMapaProdutos();
  const linhasEnc: ItemCompra[] = encomenda.linhas ?? [];
  const estrangeira = !!encomenda.codigo_moeda && encomenda.codigo_moeda !== 'AOA';
  const accao = useAccao<FaturaCompra>({
    invalidar: [['compras']],
    aoSucesso: (f) => {
      aoFechar();
      aoRegistar?.(f);
    },
    tituloErro: 'Não foi possível registar a factura',
  });

  useEffect(() => {
    if (aberto)
      form.setFieldsValue({
        numero_fatura: '',
        data: dayjs(),
        linhas: linhasEnc.map((l) => ({
          item_encomenda_id: l.id,
          quantidade: pendente(l.quantidade, l.quantidade_faturada),
          taxa_imposto: l.taxa_imposto != null ? Number(l.taxa_imposto) : mapa.get(l.produto_id)?.taxa_imposto != null ? Number(mapa.get(l.produto_id)?.taxa_imposto) : 0,
        })),
      });
  }, [aberto, form, encomenda, mapa]);

  return (
    <Modal
      title={`Registar factura — encomenda ${numeroOuId(encomenda.numero_encomenda, encomenda.id)}`}
      open={aberto}
      onCancel={aoFechar}
      okText="Registar factura"
      cancelText="Cancelar"
      width={900}
      confirmLoading={accao.isPending}
      onOk={() => form.submit()}
      destroyOnClose
    >
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) => {
          const linhas = linhasComQuantidade(v.linhas);
          if (!linhas.length) {
            Modal.warning({ title: 'Indique pelo menos uma quantidade a facturar.' });
            return;
          }
          accao.mutate({
            url: `/compras/encomendas/${encomenda.id}/faturas`,
            dados: { numero_fatura: v.numero_fatura, data: dataApi(v.data), data_vencimento: dataApi(v.data_vencimento), taxa_cambio: estrangeira ? v.taxa_cambio : undefined, linhas },
          });
        }}
      >
        <Row gutter={16}>
          <Col xs={24} md={8}>
            <Form.Item name="numero_fatura" label="N.º da factura do fornecedor" rules={[{ required: true, message: 'Indique o n.º da factura.' }, { max: 50 }]}>
              <Input />
            </Form.Item>
          </Col>
          <Col xs={12} md={5}>
            <Form.Item name="data" label="Data" rules={[{ required: true }]}>
              <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={12} md={5}>
            <Form.Item
              name="data_vencimento"
              label="Vencimento"
              dependencies={['data']}
              rules={[({ getFieldValue }) => ({ validator: async (_, v?: Dayjs) => (!v || !getFieldValue('data') || !v.isBefore(getFieldValue('data'), 'day') ? undefined : Promise.reject(new Error('Anterior à data.'))) })]}
            >
              <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          {estrangeira && (
            <Col xs={24} md={6}>
              <Form.Item name="taxa_cambio" label={`Câmbio ${encomenda.codigo_moeda}`} tooltip="Vazio: taxa registada para a data da factura.">
                <InputNumber min={0.000001} style={{ width: '100%' }} />
              </Form.Item>
            </Col>
          )}
        </Row>
        {estrangeira && <Alert type="info" showIcon style={{ marginBottom: 12 }} message={`Encomenda em ${encomenda.codigo_moeda}: o servidor calcula as diferenças de câmbio face à recepção.`} />}
        <Form.List name="linhas">
          {(campos) => (
            <Table
              rowKey="key"
              size="small"
              pagination={false}
              dataSource={campos}
              columns={[
                { title: 'Artigo', render: (_, c) => <NomeProduto id={linhasEnc[c.name]?.produto_id} produto={linhasEnc[c.name]?.produto} descricao={linhasEnc[c.name]?.descricao} /> },
                { title: 'Encomendado', align: 'right', render: (_, c) => formatarNumero(linhasEnc[c.name]?.quantidade) },
                { title: 'Recebido', align: 'right', render: (_, c) => formatarNumero(linhasEnc[c.name]?.quantidade_recebida ?? 0) },
                { title: 'Facturado', align: 'right', render: (_, c) => formatarNumero(linhasEnc[c.name]?.quantidade_faturada ?? 0) },
                { title: 'Preço (Kz)', align: 'right', render: (_, c) => formatarKz(linhasEnc[c.name]?.preco_unitario) },
                {
                  title: 'A facturar',
                  render: (_, c) => (
                    <Form.Item name={[c.name, 'quantidade']} style={{ margin: 0 }}>
                      <InputNumber min={0} style={{ width: 110 }} />
                    </Form.Item>
                  ),
                },
                {
                  title: 'IVA %',
                  render: (_, c) => (
                    <Form.Item name={[c.name, 'taxa_imposto']} style={{ margin: 0 }}>
                      <InputNumber min={0} max={100} style={{ width: 80 }} />
                    </Form.Item>
                  ),
                },
              ]}
            />
          )}
        </Form.List>
      </Form>
    </Modal>
  );
}
