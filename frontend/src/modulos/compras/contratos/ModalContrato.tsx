import { Col, DatePicker, Form, Input, InputNumber, Modal, Progress, Row } from 'antd';
import { larguraModal } from '@/componentes/responsivo';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect } from 'react';
import { dataApi } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { SeletorTerceiro } from '../comum/Seletores';
import type { ContratoCompra } from '../comum/tipos';

interface ValoresContrato {
  fornecedor_id?: number;
  referencia: string;
  descricao?: string;
  periodo?: [Dayjs | null, Dayjs | null] | null;
  valor_total?: number;
}

/** Criação ou edição do contrato (POST /compras/contratos; PUT /compras/contratos/{id}). */
export function ModalContrato({ contrato, aberto, aoFechar, aoGravar }: { contrato?: ContratoCompra; aberto: boolean; aoFechar: () => void; aoGravar?: (c: ContratoCompra) => void }) {
  const [form] = Form.useForm<ValoresContrato>();
  const gravar = useAccao<ContratoCompra>({
    invalidar: [['compras', 'contratos'], ['compras', 'contrato']],
    aoSucesso: (c) => {
      aoFechar();
      aoGravar?.(c);
    },
    tituloErro: 'Não foi possível gravar o contrato',
  });

  useEffect(() => {
    if (!aberto) return;
    form.setFieldsValue(
      contrato
        ? {
            fornecedor_id: contrato.fornecedor_id,
            referencia: contrato.referencia,
            descricao: contrato.descricao ?? undefined,
            periodo: [contrato.data_inicio ? dayjs(contrato.data_inicio) : null, contrato.data_fim ? dayjs(contrato.data_fim) : null],
            valor_total: Number(contrato.valor_total),
          }
        : { fornecedor_id: undefined, referencia: '', descricao: undefined, periodo: [dayjs(), null], valor_total: undefined },
    );
  }, [aberto, contrato, form]);

  return (
    <Modal
      title={contrato ? `Editar contrato ${contrato.referencia}` : 'Novo contrato'}
      open={aberto}
      onCancel={aoFechar}
      okText="Gravar"
      cancelText="Cancelar"
      width={larguraModal(680)}
      confirmLoading={gravar.isPending}
      onOk={() => form.submit()}
    >
      <Form<ValoresContrato>
        form={form}
        layout="vertical"
        onFinish={(v) => {
          const dados = {
            fornecedor_id: v.fornecedor_id,
            referencia: v.referencia,
            descricao: v.descricao || null,
            data_inicio: dataApi(v.periodo?.[0]) ?? null,
            data_fim: dataApi(v.periodo?.[1]) ?? null,
            valor_total: v.valor_total,
          };
          gravar.mutate(contrato ? { metodo: 'put', url: `/compras/contratos/${contrato.id}`, dados } : { url: '/compras/contratos', dados });
        }}
      >
        <Row gutter={16}>
          <Col xs={24} md={14}>
            <Form.Item name="fornecedor_id" label="Fornecedor" rules={[{ required: true, message: 'Escolha o fornecedor.' }]} tooltip={contrato ? 'Não muda se o contrato já tiver encomendas.' : undefined}>
              <SeletorTerceiro papel="FORNECEDOR" />
            </Form.Item>
          </Col>
          <Col xs={24} md={10}>
            <Form.Item name="referencia" label="Referência" rules={[{ required: true, message: 'Indique a referência.' }, { max: 50 }]}>
              <Input />
            </Form.Item>
          </Col>
          <Col span={24}>
            <Form.Item name="descricao" label="Objecto / descrição" rules={[{ max: 4000 }]}>
              <Input.TextArea rows={2} />
            </Form.Item>
          </Col>
          <Col xs={24} md={14}>
            <Form.Item name="periodo" label="Vigência (início — fim)">
              <DatePicker.RangePicker format="DD/MM/YYYY" allowEmpty={[true, true]} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={10}>
            <Form.Item name="valor_total" label="Valor contratado (Kz)" rules={[{ required: true, message: 'Indique o valor.' }]}>
              <InputNumber min={0} precision={2} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
        </Row>
      </Form>
    </Modal>
  );
}

/** Barra de consumo do contrato (encomendado face ao contratado). */
export function ConsumoContrato({ contrato }: { contrato: ContratoCompra }) {
  const c = contrato.consumo;
  if (!c) return null;
  return <Progress percent={Math.min(100, c.percentagem)} status={c.excedido ? 'exception' : undefined} format={() => `${c.percentagem}%`} />;
}
