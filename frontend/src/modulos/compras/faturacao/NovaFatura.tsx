import { Button, Card, Col, DatePicker, Divider, Flex, Form, Input, InputNumber, Row, Space, Statistic, Typography } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import dayjs, { type Dayjs } from 'dayjs';
import { useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { dataApi, formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '../comum/accoes';
import { totaisLinhas } from '../comum/calculos';
import { LinhasProdutos, type LinhaProdutoForm } from '../comum/LinhasProdutos';
import { SeletorTerceiro } from '../comum/Seletores';
import type { FaturaCompra } from '../comum/tipos';

interface ValoresFatura {
  fornecedor_id?: number;
  numero_fatura: string;
  data: Dayjs;
  data_vencimento?: Dayjs;
  codigo_moeda: string;
  taxa_cambio?: number;
  linhas: LinhaProdutoForm[];
}

/** Factura directa de fornecedor, sem encomenda (POST /compras/faturas). */
export function NovaFatura() {
  const navegar = useNavigate();
  const [form] = Form.useForm<ValoresFatura>();
  const linhas = Form.useWatch('linhas', form) ?? [];
  const moeda = (Form.useWatch('codigo_moeda', form) ?? 'AOA').toUpperCase();
  const estimativa = totaisLinhas(linhas);
  const criar = useAccao<FaturaCompra>({ invalidar: [['compras']], aoSucesso: (f) => navegar(`../${f.id}`), tituloErro: 'Não foi possível registar a factura' });

  return (
    <>
      <CabecalhoPagina titulo="Factura directa de fornecedor" accoes={<Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>} />
      <Form<ValoresFatura>
        form={form}
        layout="vertical"
        initialValues={{ data: dayjs(), codigo_moeda: 'AOA', linhas: [{ quantidade: 1 }] }}
        onFinish={(v) =>
          criar.mutate({
            url: '/compras/faturas',
            dados: {
              fornecedor_id: v.fornecedor_id,
              numero_fatura: v.numero_fatura,
              data: dataApi(v.data),
              data_vencimento: dataApi(v.data_vencimento),
              codigo_moeda: v.codigo_moeda?.toUpperCase() || undefined,
              taxa_cambio: moeda !== 'AOA' ? v.taxa_cambio : undefined,
              linhas: v.linhas.map((l) => ({ produto_id: l.produto_id, quantidade: l.quantidade, preco_unitario: l.preco_unitario ?? 0, taxa_imposto: l.taxa_imposto ?? 0, descricao: l.descricao || undefined })),
            },
          })
        }
      >
        <Card title="Factura" style={{ marginBottom: 16 }}>
          <Row gutter={16}>
            <Col xs={24} md={10}>
              <Form.Item name="fornecedor_id" label="Fornecedor" rules={[{ required: true, message: 'Escolha o fornecedor.' }]}>
                <SeletorTerceiro papel="FORNECEDOR" />
              </Form.Item>
            </Col>
            <Col xs={24} md={6}>
              <Form.Item name="numero_fatura" label="N.º da factura" rules={[{ required: true, message: 'Indique o n.º da factura.' }, { max: 50 }]}>
                <Input />
              </Form.Item>
            </Col>
            <Col xs={12} md={4}>
              <Form.Item name="data" label="Data" rules={[{ required: true }]}>
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={12} md={4}>
              <Form.Item
                name="data_vencimento"
                label="Vencimento"
                dependencies={['data']}
                rules={[({ getFieldValue }) => ({ validator: async (_, val?: Dayjs) => (!val || !val.isBefore(getFieldValue('data'), 'day') ? undefined : Promise.reject(new Error('Anterior à data.'))) })]}
              >
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={12} md={3}>
              <Form.Item name="codigo_moeda" label="Moeda" rules={[{ pattern: /^[A-Za-z]{3}$/, message: 'ISO de 3 letras' }]}>
                <Input maxLength={3} style={{ textTransform: 'uppercase' }} />
              </Form.Item>
            </Col>
            {moeda !== 'AOA' && (
              <Col xs={12} md={5}>
                <Form.Item name="taxa_cambio" label="Câmbio (Kz)" tooltip="Vazio: taxa registada para a data.">
                  <InputNumber min={0.000001} style={{ width: '100%' }} />
                </Form.Item>
              </Col>
            )}
          </Row>
        </Card>
        <Card title="Linhas" style={{ marginBottom: 16 }}>
          <LinhasProdutos form={form} preco precoObrigatorio iva descricao rotuloPreco={`Preço (${moeda})`} />
          <Divider />
          <Flex justify="end" gap={32}>
            <Statistic title={`Líquido (${moeda})`} value={formatarKz(estimativa.liquido)} />
            <Statistic title="IVA" value={formatarKz(estimativa.imposto)} />
            <Statistic title="Total" value={formatarKz(estimativa.total)} />
          </Flex>
          <Typography.Text type="secondary">Estimativa; o servidor calcula os valores em Kz e o IVA dedutível.</Typography.Text>
        </Card>
        <Space>
          <Button type="primary" htmlType="submit" loading={criar.isPending}>
            Registar factura
          </Button>
          <Button onClick={() => navegar('..')}>Cancelar</Button>
        </Space>
      </Form>
    </>
  );
}
