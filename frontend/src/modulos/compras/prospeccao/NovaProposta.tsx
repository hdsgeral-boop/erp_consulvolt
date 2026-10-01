import { Alert, Button, Card, Col, DatePicker, Divider, Flex, Form, Input, InputNumber, Row, Select, Space, Statistic, Table, Typography } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { obter, obterPagina } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { dataApi, formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { totaisLinhas } from '../comum/calculos';
import { NomeProduto, useMapaProdutos } from '../comum/referencias';
import { SeletorTerceiro } from '../comum/Seletores';
import { numeroOuId, type ItemCompra, type PedidoCompra, type PropostaCompra } from '../comum/tipos';

interface LinhaProposta {
  item_pedido_id: number;
  produto_id: number;
  descricao: string | null;
  quantidade: string;
  preco_unitario?: number;
  taxa_imposto?: number;
}

interface ValoresProposta {
  pedido_compra_id?: number;
  fornecedor_id?: number;
  referencia: string;
  data: Dayjs;
  data_entrega?: Dayjs;
  codigo_moeda: string;
  taxa_cambio?: number;
  linhas: LinhaProposta[];
}

/** Linhas da proposta a partir das linhas do pedido (o preço do pedido é só estimativa; o IVA vem do catálogo). */
export function linhasDoPedido(itens: ItemCompra[], taxaDoProduto: (id: number) => number | undefined): LinhaProposta[] {
  return itens.map((l) => ({
    item_pedido_id: l.id,
    produto_id: l.produto_id,
    descricao: l.descricao,
    quantidade: l.quantidade,
    preco_unitario: undefined,
    taxa_imposto: taxaDoProduto(l.produto_id),
  }));
}

/** Registo de uma proposta de fornecedor para um pedido APROVADO (POST /compras/propostas). */
export function NovaProposta() {
  const navegar = useNavigate();
  const [parametros] = useSearchParams();
  const [form] = Form.useForm<ValoresProposta>();
  const mapa = useMapaProdutos();
  const pedidoId = Form.useWatch('pedido_compra_id', form);
  const moeda = Form.useWatch('codigo_moeda', form) ?? 'AOA';
  const linhas = Form.useWatch('linhas', form) ?? [];
  const estimativa = totaisLinhas(linhas);

  const aprovados = useQuery({
    queryKey: ['compras', 'pedidos', 'aprovados'],
    queryFn: () => obterPagina<PedidoCompra>('/compras/pedidos', { estado: 'APROVADO', por_pagina: 200 }),
  });
  const pedido = useQuery({
    queryKey: ['compras', 'pedido', String(pedidoId)],
    queryFn: () => obter<PedidoCompra>(`/compras/pedidos/${pedidoId}`),
    enabled: !!pedidoId,
  });
  const criar = useAccao<PropostaCompra>({ invalidar: [['compras']], aoSucesso: (c) => navegar(`../${c.id}`), tituloErro: 'Não foi possível registar a proposta' });

  useEffect(() => {
    const doUrl = Number(parametros.get('pedido'));
    if (doUrl) form.setFieldValue('pedido_compra_id', doUrl);
  }, [parametros, form]);

  useEffect(() => {
    if (pedido.data?.linhas) {
      form.setFieldValue('linhas', linhasDoPedido(pedido.data.linhas, (id) => (mapa.get(id)?.taxa_imposto != null ? Number(mapa.get(id)?.taxa_imposto) : undefined)));
    }
  }, [pedido.data, mapa, form]);

  const p = pedido.data;
  return (
    <>
      <CabecalhoPagina titulo="Registar proposta de fornecedor" accoes={<Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>} />
      <Form<ValoresProposta>
        form={form}
        layout="vertical"
        initialValues={{ data: dayjs(), codigo_moeda: 'AOA', linhas: [] }}
        onFinish={(v) =>
          criar.mutate({
            url: '/compras/propostas',
            dados: {
              pedido_compra_id: v.pedido_compra_id,
              fornecedor_id: v.fornecedor_id,
              referencia: v.referencia,
              data: dataApi(v.data),
              data_entrega: dataApi(v.data_entrega),
              codigo_moeda: v.codigo_moeda?.toUpperCase() || undefined,
              taxa_cambio: v.codigo_moeda && v.codigo_moeda !== 'AOA' ? v.taxa_cambio : undefined,
              linhas: v.linhas.map((l) => ({ item_pedido_id: l.item_pedido_id, preco_unitario: l.preco_unitario, taxa_imposto: l.taxa_imposto ?? 0 })),
            },
          })
        }
      >
        <Card title="Proposta" style={{ marginBottom: 16 }}>
          <Row gutter={16}>
            <Col xs={24} md={12}>
              <Form.Item name="pedido_compra_id" label="Pedido aprovado" rules={[{ required: true, message: 'Escolha o pedido.' }]}>
                <Select
                  showSearch
                  optionFilterProp="label"
                  loading={aprovados.isLoading}
                  placeholder="Pedidos em estado Aprovado"
                  options={(aprovados.data?.itens ?? []).map((x) => ({
                    value: x.id,
                    label: `${numeroOuId(x.numero_pedido, x.id)} — ${x.nome_requerente}${x.descricao ? ` — ${x.descricao}` : ''} (${formatarData(x.data)})`,
                  }))}
                />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="fornecedor_id" label="Fornecedor" rules={[{ required: true, message: 'Escolha o fornecedor.' }]}>
                <SeletorTerceiro papel="FORNECEDOR" />
              </Form.Item>
            </Col>
            <Col xs={24} md={6}>
              <Form.Item name="referencia" label="Referência da proposta" rules={[{ required: true, message: 'Indique a referência.' }, { max: 50 }]}>
                <Input />
              </Form.Item>
            </Col>
            <Col xs={12} md={5}>
              <Form.Item name="data" label="Data">
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={12} md={5}>
              <Form.Item name="data_entrega" label="Prazo de entrega">
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={12} md={3}>
              <Form.Item name="codigo_moeda" label="Moeda" rules={[{ pattern: /^[A-Za-z]{3}$/, message: 'ISO de 3 letras' }]}>
                <Input maxLength={3} style={{ textTransform: 'uppercase' }} />
              </Form.Item>
            </Col>
            {moeda.toUpperCase() !== 'AOA' && (
              <Col xs={12} md={5}>
                <Form.Item name="taxa_cambio" label="Câmbio (Kz por unidade)" tooltip="Vazio: o servidor usa a taxa de câmbio registada para a data.">
                  <InputNumber min={0.000001} style={{ width: '100%' }} />
                </Form.Item>
              </Col>
            )}
          </Row>
          {p && p.estado !== 'APROVADO' && <Alert type="warning" showIcon message="Só se registam propostas para pedidos aprovados." />}
        </Card>

        <Card title="Preços por artigo" style={{ marginBottom: 16 }}>
          <Form.List name="linhas" rules={[{ validator: async (_, v) => (v && v.length ? undefined : Promise.reject(new Error('Escolha um pedido com artigos.'))) }]}>
            {(campos, _op, { errors }) => (
              <>
                <Table
                  rowKey="key"
                  size="small"
                  pagination={false}
                  loading={pedido.isFetching}
                  dataSource={campos}
                  locale={{ emptyText: 'Escolha o pedido para ver os artigos.' }}
                  columns={[
                    {
                      title: 'Artigo',
                      render: (_, c) => {
                        const l = form.getFieldValue(['linhas', c.name]) as LinhaProposta | undefined;
                        return l ? <NomeProduto id={l.produto_id} descricao={l.descricao} /> : null;
                      },
                    },
                    { title: 'Qtd.', align: 'right', render: (_, c) => formatarNumero((form.getFieldValue(['linhas', c.name]) as LinhaProposta | undefined)?.quantidade) },
                    {
                      title: `Preço unit. (${moeda.toUpperCase()}, s/ IVA)`,
                      render: (_, c) => (
                        <Form.Item name={[c.name, 'preco_unitario']} rules={[{ required: true, message: 'Preço' }]} style={{ margin: 0 }}>
                          <InputNumber min={0} precision={2} style={{ width: 160 }} />
                        </Form.Item>
                      ),
                    },
                    {
                      title: 'IVA %',
                      render: (_, c) => (
                        <Form.Item name={[c.name, 'taxa_imposto']} style={{ margin: 0 }}>
                          <InputNumber min={0} max={100} style={{ width: 90 }} />
                        </Form.Item>
                      ),
                    },
                  ]}
                />
                <Form.ErrorList errors={errors} />
              </>
            )}
          </Form.List>
          <Divider />
          <Flex justify="end" gap={32}>
            <Statistic title={`Líquido (${moeda.toUpperCase()})`} value={formatarKz(estimativa.liquido)} />
            <Statistic title="IVA" value={formatarKz(estimativa.imposto)} />
            <Statistic title="Total" value={formatarKz(estimativa.total)} />
          </Flex>
          <Typography.Text type="secondary">Estimativa na moeda da proposta; o servidor converte para Kz e grava os totais.</Typography.Text>
        </Card>
        <Space>
          <Button type="primary" htmlType="submit" loading={criar.isPending}>
            Registar proposta
          </Button>
          <Button onClick={() => navegar('..')}>Cancelar</Button>
        </Space>
      </Form>
    </>
  );
}
