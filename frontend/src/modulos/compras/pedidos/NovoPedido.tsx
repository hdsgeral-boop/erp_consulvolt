import { Button, Card, Col, DatePicker, Divider, Flex, Form, Input, Row, Space, Statistic, Typography } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import dayjs, { type Dayjs } from 'dayjs';
import { useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { totaisLinhas } from '../comum/calculos';
import { LinhasProdutos, type LinhaProdutoForm } from '../comum/LinhasProdutos';
import type { PedidoCompra } from '../comum/tipos';

interface ValoresPedido {
  nome_requerente: string;
  data: Dayjs;
  data_entrega?: Dayjs;
  descricao?: string;
  observacoes?: string;
  linhas: LinhaProdutoForm[];
}

/** Novo pedido interno (POST /compras/pedidos). Ao gravar, o servidor inicia a deliberação pelos escalões de valor. */
export function NovoPedido() {
  const navegar = useNavigate();
  const { utilizador } = useSessao();
  const [form] = Form.useForm<ValoresPedido>();
  const linhas = Form.useWatch('linhas', form) ?? [];
  const estimativa = totaisLinhas(linhas);
  const criar = useAccao<PedidoCompra>({ invalidar: [['compras']], aoSucesso: (p) => navegar(`../${p.id}`), tituloErro: 'Não foi possível criar o pedido' });

  return (
    <>
      <CabecalhoPagina titulo="Novo pedido interno" accoes={<Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>} />
      <Form<ValoresPedido>
        form={form}
        layout="vertical"
        initialValues={{ nome_requerente: utilizador?.nome_completo || utilizador?.nome_utilizador, data: dayjs(), linhas: [{ quantidade: 1 }] }}
        onFinish={(v) =>
          criar.mutate({
            url: '/compras/pedidos',
            dados: {
              nome_requerente: v.nome_requerente,
              data: dataApi(v.data),
              data_entrega: dataApi(v.data_entrega),
              descricao: v.descricao || undefined,
              observacoes: v.observacoes || undefined,
              linhas: v.linhas.map((l) => ({ produto_id: l.produto_id, quantidade: l.quantidade, preco_unitario: l.preco_unitario ?? undefined, descricao: l.descricao || undefined })),
            },
          })
        }
      >
        <Card title="Pedido" style={{ marginBottom: 16 }}>
          <Row gutter={16}>
            <Col xs={24} md={10}>
              <Form.Item name="nome_requerente" label="Requerente" rules={[{ required: true, message: 'Indique o requerente.' }, { max: 255 }]}>
                <Input />
              </Form.Item>
            </Col>
            <Col xs={12} md={7}>
              <Form.Item name="data" label="Data">
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={12} md={7}>
              <Form.Item name="data_entrega" label="Entrega pretendida">
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col span={24}>
              <Form.Item name="descricao" label="Descrição / justificação" rules={[{ max: 2000 }]}>
                <Input.TextArea rows={2} />
              </Form.Item>
            </Col>
          </Row>
        </Card>
        <Card title="Artigos" style={{ marginBottom: 16 }}>
          <LinhasProdutos form={form} preco descricao rotuloPreco="Preço estimado" />
          <Divider />
          <Flex justify="end">
            <Statistic title="Valor estimado (sem IVA)" value={formatarKz(estimativa.liquido)} />
          </Flex>
          <Typography.Text type="secondary">
            O valor estimado define os níveis de aprovação. Linhas sem preço contam como zero (o servidor assinala-as).
          </Typography.Text>
        </Card>
        <Card style={{ marginBottom: 16 }}>
          <Form.Item name="observacoes" label="Observações" rules={[{ max: 4000 }]}>
            <Input.TextArea rows={2} />
          </Form.Item>
        </Card>
        <Space>
          <Button type="primary" htmlType="submit" loading={criar.isPending}>
            Criar e enviar para aprovação
          </Button>
          <Button onClick={() => navegar('..')}>Cancelar</Button>
        </Space>
      </Form>
    </>
  );
}
