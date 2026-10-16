import { Alert, Col, Form, InputNumber, Modal, Row } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';
import { obter } from '@/api/cliente';
import type { ConfiguracaoRH } from '../api';
import { useAccaoRh, useAvisarErro } from '../comum/consultas';

type Valores = Pick<ConfiguracaoRH, 'subsidio_ferias_pct' | 'subsidio_natal_pct'>;

/**
 * Subsídios de férias e de Natal da minuta do contrato (#8, legado companies.subsidio_ferias_pct/subsidio_natal_pct): em % do
 * salário base, nunca abaixo do mínimo legal de 50 % (art. 238.º, n.º 1, da Lei n.º 12/23). O servidor valida o mesmo.
 */
export function SubsidiosMinuta({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const q = useQuery({ queryKey: ['rh', 'configuracao'], queryFn: () => obter<ConfiguracaoRH>('/rh/configuracao'), enabled: aberto });
  useAvisarErro(q.error);
  const [form] = Form.useForm<Valores>();
  const accao = useAccaoRh(() => aoFechar());
  useEffect(() => {
    if (aberto && q.data) form.setFieldsValue({ subsidio_ferias_pct: q.data.subsidio_ferias_pct ?? 50, subsidio_natal_pct: q.data.subsidio_natal_pct ?? 50 });
  }, [aberto, q.data, form]);
  const regra = [{ type: 'number' as const, min: 50, max: 300, message: 'Entre 50 % (mínimo legal) e 300 %.' }, { required: true, message: 'Indique a percentagem.' }];
  return (
    <Modal title="Subsídios na minuta do contrato" open={aberto} onCancel={aoFechar} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending}
      onOk={() => form.submit()} destroyOnHidden>
      <Alert type="info" showIcon style={{ marginBottom: 16 }} message="Art. 238.º, n.º 1, da Lei n.º 12/23"
        description="A gratificação de férias e o subsídio de Natal são, no mínimo, 50 % do salário base. Se a empresa pagar mais, indique aqui: é o que sai na Cláusula 6.ª dos contratos emitidos a partir de agora." />
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ metodo: 'put', url: '/rh/configuracao', dados: v })}>
        <Row gutter={16}>
          <Col xs={24} sm={12}><Form.Item name="subsidio_ferias_pct" label="Gratificação de férias (% do salário base)" rules={regra}><InputNumber min={50} max={300} style={{ width: '100%' }} suffix="%" /></Form.Item></Col>
          <Col xs={24} sm={12}><Form.Item name="subsidio_natal_pct" label="Subsídio de Natal (% do salário base)" rules={regra}><InputNumber min={50} max={300} style={{ width: '100%' }} suffix="%" /></Form.Item></Col>
        </Row>
      </Form>
    </Modal>
  );
}
