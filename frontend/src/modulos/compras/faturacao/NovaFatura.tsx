import { Button, Card, Col, DatePicker, Divider, Flex, Form, Input, InputNumber, Row, Space, Statistic, Typography } from 'antd';
import { ArrowLeftOutlined, SaveOutlined } from '@ant-design/icons';
import dayjs, { type Dayjs } from 'dayjs';
import { useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { dataApi, formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { comOpcoesOrcamentoPedido, useExcessoOrcamental } from '@/componentes/orcamento';
import { totaisLinhas } from '../comum/calculos';
import { LinhasProdutos, type LinhaProdutoForm } from '../comum/LinhasProdutos';
import { AlocacaoUN, alocacaoParaApi, regraAlocacao, unccDaLinha, useCriterios, type Alocacao } from '@/componentes/reparticao';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
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
  unidade_negocio_id?: number;
  centro_custo_id?: number;
  /** Fase C: repartição das linhas de gasto por várias unidades de negócio (alocacao_un.js) */
  unidades_alocadas?: Alocacao | null;
}

/** Factura directa de fornecedor, sem encomenda (POST /compras/faturas). */
export function NovaFatura() {
  const navegar = useNavigate();
  const [form] = Form.useForm<ValoresFatura>();
  const linhas = Form.useWatch('linhas', form) ?? [];
  const moeda = (Form.useWatch('codigo_moeda', form) ?? 'AOA').toUpperCase();
  const estimativa = totaisLinhas(linhas);
  const criterios = useCriterios();
  const excesso = useExcessoOrcamental();
  const criar: ReturnType<typeof useAccao<FaturaCompra>> = useAccao<FaturaCompra>({
    invalidar: [['compras']],
    aoSucesso: (f) => navegar(`../${f.id}`),
    tituloErro: 'Não foi possível registar a factura',
    // excesso orçamental (A-02): pedir a aprovação ou aprovar no acto e repetir o mesmo pedido
    aoErro: (e, pedido) => excesso.tratar(e, { repetir: (o) => criar.mutate(comOpcoesOrcamentoPedido(pedido, o)) }),
  });

  return (
    <>
      <CabecalhoPagina titulo="Nova Fatura Direta (sem encomenda)" accoes={<Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>} />
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
              unidade_negocio_id: v.unidade_negocio_id, centro_custo_id: v.centro_custo_id,
              unidades_alocadas: alocacaoParaApi(v.unidades_alocadas),
              linhas: v.linhas.map((l) => ({ produto_id: l.produto_id, quantidade: l.quantidade, preco_unitario: l.preco_unitario ?? 0, taxa_imposto: l.taxa_imposto ?? 0, descricao: l.descricao || undefined, ...unccDaLinha(l) })),
            },
          })
        }
      >
        <Card style={{ marginBottom: 16, borderTop: '4px solid var(--erp-primaria, #2563eb)' }}>
          <Row gutter={16}>
            <Col xs={24} md={10}>
              <Form.Item name="fornecedor_id" label="Fornecedor" rules={[{ required: true, message: 'Escolha o fornecedor.' }]}>
                <SeletorTerceiro papel="FORNECEDOR" onEscolher={(t) => t?.codigo_moeda && form.setFieldsValue({ codigo_moeda: t.codigo_moeda })} />
              </Form.Item>
            </Col>
            <Col xs={24} md={6}>
              <Form.Item name="numero_fatura" label="Número da Fatura (Externo)" rules={[{ required: true, message: 'Indique o n.º da factura.' }, { max: 50 }]}>
                <Input placeholder="Ex: FR 2024/123" />
              </Form.Item>
            </Col>
            <Col xs={12} md={4}>
              <Form.Item name="data" label="Data da Fatura" rules={[{ required: true }]}>
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
            <Col xs={12} md={6}>
              <Form.Item name="unidade_negocio_id" label="Unidade de Negócio">
                <SeletorUnidade style={{ width: '100%' }} placeholder="Nenhuma" />
              </Form.Item>
            </Col>
            <Col xs={12} md={6}>
              <Form.Item name="centro_custo_id" label="Centro de Custo">
                <SeletorAux tabela="centros-custo" style={{ width: '100%' }} placeholder="Nenhum" />
              </Form.Item>
            </Col>
            {!!criterios.data?.length && (
              <Col xs={24} md={12}>
                <Form.Item name="unidades_alocadas" rules={[regraAlocacao(criterios.data)]}>
                  <AlocacaoUN valor={estimativa.liquido} titulo="Repartir por várias unidades de negócio" />
                </Form.Item>
              </Col>
            )}
          </Row>
        </Card>
        <Card title="Itens da Fatura" style={{ marginBottom: 16 }}>
          <LinhasProdutos form={form} preco precoObrigatorio iva descricao rotuloPreco={`Preço Unit. s/ IVA (${moeda})`} unidadeLinha />
          <Divider />
          <Flex justify="end" gap={24} wrap>
            <Statistic title={`Subtotal (Líquido) (${moeda})`} value={formatarKz(estimativa.liquido)} />
            <Statistic title="IVA Total" value={formatarKz(estimativa.imposto)} />
            <Statistic title="Total Final (Bruto)" value={formatarKz(estimativa.total)} />
          </Flex>
          <Typography.Text type="secondary">Estimativa; o servidor calcula os valores em Kz e o IVA dedutível.</Typography.Text>
        </Card>
        {/* «Cancelar» e «Gravar Fatura» à direita, como o legado (js/ui_compras_v2.js:1816-1821) */}
        <Flex justify="end">
          <Space wrap>
            <Button onClick={() => navegar('..')}>Cancelar</Button>
            <Button type="primary" htmlType="submit" icon={<SaveOutlined />} loading={criar.isPending}>
              Gravar Fatura
            </Button>
          </Space>
        </Flex>
      </Form>
      {excesso.dialogo}
    </>
  );
}
