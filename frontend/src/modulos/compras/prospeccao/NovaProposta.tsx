import { Alert, Button, Card, Col, DatePicker, Divider, Flex, Form, Input, InputNumber, Row, Select, Space, Statistic, Table, Tag, Typography, message } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState, type ClipboardEvent } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { enviar, obter, obterPagina } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { colagemParaLinhas, pareceColagemDeGrade, posicaoColagem } from '@/componentes/formularios/colagemGrade';
import type { CampoFormulario } from '@/componentes/formularios/tipos';
import { dataApi, formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { notificarErro } from '@/utilitarios/erros';
import { totaisLinhas } from '../comum/calculos';
import { NomeProduto, useMapaProdutos } from '../comum/referencias';
import { SeletorTerceiro } from '../comum/Seletores';
import { numeroOuId, type PedidoCompra, type PropostaCompra } from '../comum/tipos';
import { scrollTabela } from '@/componentes/responsivo';
import { avaliarLinhas, cambioKz, LIMIAR_AVISO, type AvaliacaoLinha } from './avisoCusto';

interface LinhaProposta {
  pedido_compra_id: number;
  item_pedido_id: number;
  produto_id: number;
  descricao: string | null;
  /** Quantidade pedida (informativa) e saldo pendente (tecto da quantidade proposta — cobertura parcial). */
  quantidade_pedido: string;
  saldo_pendente: string;
  quantidade: number | null;
  preco_unitario?: number;
  taxa_imposto?: number;
}

interface ValoresProposta {
  pedidos_compra_ids: number[];
  fornecedor_id?: number;
  referencia: string;
  data: Dayjs;
  data_entrega?: Dayjs;
  codigo_moeda: string;
  taxa_cambio?: number;
  linhas: LinhaProposta[];
}

/**
 * Linhas da proposta a partir das linhas de UM pedido (o preço do pedido é só estimativa; o IVA vem do catálogo).
 * Cobertura parcial: a quantidade proposta nasce igual ao saldo pendente de cada linha (não à quantidade pedida —
 * pode já haver outra proposta a cobrir parte dela) e as linhas já totalmente cobertas nem aparecem (nada a propor).
 */
export function linhasDoPedido(p: PedidoCompra, taxaDoProduto: (id: number) => number | undefined): LinhaProposta[] {
  const saldoPorItem = new Map((p.saldos ?? []).map((s) => [s.item_pedido_id, s]));
  return (p.linhas ?? [])
    .map((l) => {
      const s = saldoPorItem.get(l.id);
      const saldo = s ? s.saldo_pendente : l.quantidade;
      return {
        pedido_compra_id: p.id, item_pedido_id: l.id, produto_id: l.produto_id, descricao: l.descricao,
        quantidade_pedido: l.quantidade, saldo_pendente: saldo, quantidade: Number(saldo),
        preco_unitario: undefined, taxa_imposto: taxaDoProduto(l.produto_id),
      };
    })
    .filter((l) => Number(l.saldo_pendente) > 0);
}

/** Pedidos que podem receber uma nova proposta: aprovados ou parcialmente adjudicados (ainda com saldo pendente). */
const ESTADOS_DISPONIVEIS = new Set(['APROVADO', 'PARCIALMENTE_ADJUDICADO']);

/**
 * Agrupa as linhas por pedido, pela ordem em que os pedidos foram escolhidos (as linhas a 0 — «não incluir» —
 * ficam de fora; um pedido sem nenhuma linha com quantidade fica de fora também, sem erro). A estrutura do servidor
 * é 1 proposta = 1 pedido, por isso é esta função que decide quantas propostas (POST /compras/propostas) se vão
 * mesmo registar.
 */
export function propostasPorPedido(pedidosEscolhidos: number[], linhas: LinhaProposta[]): { pedido_compra_id: number; linhas: LinhaProposta[] }[] {
  const porPedido = new Map<number, LinhaProposta[]>();
  linhas.filter((l) => (l.quantidade ?? 0) > 0).forEach((l) => porPedido.set(l.pedido_compra_id, [...(porPedido.get(l.pedido_compra_id) ?? []), l]));

  return pedidosEscolhidos.flatMap((id) => {
    const doc = porPedido.get(id);

    return doc ? [{ pedido_compra_id: id, linhas: doc }] : [];
  });
}

/** Registo de uma proposta de fornecedor para um ou mais pedidos (um por pedido: POST /compras/propostas, um por cada). */
export function NovaProposta() {
  const navegar = useNavigate();
  const [parametros] = useSearchParams();
  const [form] = Form.useForm<ValoresProposta>();
  const [aGravar, setAGravar] = useState(false);
  const mapa = useMapaProdutos();
  const pedidosEscolhidos: number[] = Form.useWatch('pedidos_compra_ids', form) ?? [];
  const moeda = Form.useWatch('codigo_moeda', form) ?? 'AOA';
  const linhas = Form.useWatch('linhas', form) ?? [];
  const estimativa = totaisLinhas(linhas.filter((l) => (l.quantidade ?? 0) > 0));
  const taxaCambio = Form.useWatch('taxa_cambio', form);

  const disponiveis = useQuery({
    queryKey: ['compras', 'pedidos', 'disponiveis-proposta'],
    queryFn: () => obterPagina<PedidoCompra>('/compras/pedidos', { disponivel_proposta: 1, por_pagina: 200 }),
  });
  const chavePedidos = [...pedidosEscolhidos].sort((a, b) => a - b);
  const pedidos = useQuery({
    queryKey: ['compras', 'pedidos', 'detalhe', chavePedidos],
    queryFn: () => Promise.all(chavePedidos.map((id) => obter<PedidoCompra>(`/compras/pedidos/${id}`))),
    enabled: chavePedidos.length > 0,
  });

  useEffect(() => {
    const doUrl = Number(parametros.get('pedido'));
    if (doUrl) form.setFieldValue('pedidos_compra_ids', [doUrl]);
  }, [parametros, form]);

  useEffect(() => {
    if (pedidos.data) {
      const taxaDoProduto = (id: number) => (mapa.get(id)?.taxa_imposto != null ? Number(mapa.get(id)?.taxa_imposto) : undefined);
      form.setFieldValue('linhas', pedidos.data.flatMap((p) => linhasDoPedido(p, taxaDoProduto)));
    }
  }, [pedidos.data, mapa, form]);

  // a comparação com o valor da encomenda do cliente só faz sentido com um único pedido (cada um tem a sua própria referência)
  const pedidoUnico = chavePedidos.length === 1 ? pedidos.data?.[0] : undefined;
  const cambio = cambioKz(moeda, taxaCambio);
  const avaliacao = avaliarLinhas(linhas.map((l) => ({ produto_id: l.produto_id, preco_unitario: l.preco_unitario })), pedidoUnico?.referencia_encomendas, cambio);
  const porProduto = new Map(avaliacao.map((a) => [a.produto_id, a]));
  const acima = avaliacao.filter((a) => a.nivel === 'acima');
  const perto = avaliacao.filter((a) => a.nivel === 'aviso');
  const temReferencia = avaliacao.length > 0;
  const naoDisponiveis = (pedidos.data ?? []).filter((p) => !ESTADOS_DISPONIVEIS.has(p.estado));
  const rotuloPedido = (id: number) => numeroOuId(pedidos.data?.find((p) => p.id === id)?.numero_pedido, id);

  // Colagem do Excel: só preço e IVA (as linhas vêm fixas dos artigos dos pedidos — sem botão «Colar do Excel» para
  // acrescentar linhas, porque uma linha nova não teria item_pedido_id/produto_id). Colagem directa numa célula
  // (Ctrl+V), posicional a partir da coluna clicada, preenchendo só as linhas que já existem.
  const camposColaveis: CampoFormulario[] = [
    { nome: 'preco_unitario', rotulo: `Preço unit. (${moeda.toUpperCase()})`, tipo: 'numero', min: 0, precisao: 2 },
    { nome: 'taxa_imposto', rotulo: 'IVA %', tipo: 'numero', min: 0, max: 100 },
  ];
  const aoColarCelula = (e: ClipboardEvent<HTMLDivElement>) => {
    const texto = e.clipboardData?.getData('text') ?? '';
    if (!pareceColagemDeGrade(texto)) return;
    const pos = posicaoColagem(e.target);
    if (!pos) return;
    e.preventDefault();
    const r = colagemParaLinhas(texto, camposColaveis, pos.coluna);
    const actuais = [...((form.getFieldValue('linhas') as LinhaProposta[] | undefined) ?? [])];
    let aplicadas = 0;
    r.linhas.forEach((valores, i) => {
      const idx = pos.linha + i;
      if (idx < actuais.length) {
        actuais[idx] = { ...actuais[idx], ...valores };
        aplicadas += 1;
      }
    });
    if (!aplicadas) return;
    form.setFieldValue('linhas', actuais);
    if (r.naoReconhecidos) void message.warning(`Preços actualizados em ${aplicadas} linha(s). ${r.naoReconhecidos} valor(es) não reconhecido(s) — corrija-os antes de gravar.`);
    else void message.success(`Preços actualizados em ${aplicadas} linha(s).`);
  };

  const submeter = async (v: ValoresProposta) => {
    const documentos = propostasPorPedido(v.pedidos_compra_ids, v.linhas);
    if (!documentos.length) {
      void message.error('Indique a quantidade a propor em pelo menos um artigo.');
      return;
    }
    setAGravar(true);
    const criadas: PropostaCompra[] = [];
    const falhas: string[] = [];
    try {
      for (const { pedido_compra_id, linhas: linhasDoc } of documentos) {
        try {
          const { dados } = await enviar<PropostaCompra>('post', '/compras/propostas', {
            pedido_compra_id, fornecedor_id: v.fornecedor_id, referencia: v.referencia, data: dataApi(v.data), data_entrega: dataApi(v.data_entrega),
            codigo_moeda: v.codigo_moeda?.toUpperCase() || undefined, taxa_cambio: v.codigo_moeda && v.codigo_moeda !== 'AOA' ? v.taxa_cambio : undefined,
            linhas: linhasDoc.map((l) => ({ item_pedido_id: l.item_pedido_id, quantidade: l.quantidade, preco_unitario: l.preco_unitario, taxa_imposto: l.taxa_imposto ?? 0 })),
          });
          criadas.push(dados);
        } catch (e) {
          falhas.push(`${rotuloPedido(pedido_compra_id)}: ${e instanceof Error ? e.message : 'erro desconhecido'}`);
        }
      }
    } finally {
      setAGravar(false);
    }
    if (criadas.length) void message.success(criadas.length === 1 ? `Proposta ${criadas[0].numero_proposta} registada.` : `${criadas.length} proposta(s) registada(s).`);
    if (falhas.length) notificarErro(new Error(`Não foi possível registar a proposta para: ${falhas.join('; ')}`), 'Algumas propostas não foram registadas');
    if (criadas.length && !falhas.length) navegar(criadas.length === 1 ? `../${criadas[0].id}` : '..');
  };

  return (
    <>
      <CabecalhoPagina titulo="Registar proposta de fornecedor" accoes={<Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>} />
      <Form<ValoresProposta>
        form={form}
        layout="vertical"
        initialValues={{ data: dayjs(), codigo_moeda: 'AOA', linhas: [], pedidos_compra_ids: [] }}
        onFinish={submeter}
      >
        <Card title="Proposta" style={{ marginBottom: 16 }}>
          <Row gutter={16}>
            <Col xs={24} md={12}>
              <Form.Item name="pedidos_compra_ids" label="Pedidos aprovados" tooltip="Pode escolher mais do que um: regista-se uma proposta por cada pedido." rules={[{ required: true, type: 'array', min: 1, message: 'Escolha pelo menos um pedido.' }]}>
                <Select
                  mode="multiple"
                  showSearch
                  optionFilterProp="label"
                  loading={disponiveis.isLoading}
                  placeholder="Pedidos aprovados ou parcialmente adjudicados"
                  options={(disponiveis.data?.itens ?? []).map((x) => ({
                    value: x.id,
                    label: `${numeroOuId(x.numero_pedido, x.id)} — ${x.nome_requerente}${x.descricao ? ` — ${x.descricao}` : ''} (${formatarData(x.data)})`,
                  }))}
                />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="fornecedor_id" label="Fornecedor" rules={[{ required: true, message: 'Escolha o fornecedor.' }]}>
                <SeletorTerceiro papel="FORNECEDOR" onEscolher={(t) => t?.codigo_moeda && form.setFieldsValue({ codigo_moeda: t.codigo_moeda })} />
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
          {naoDisponiveis.length > 0 && (
            <Alert
              type="warning" showIcon style={{ marginTop: 8 }}
              message={`Só se registam propostas para pedidos aprovados ou parcialmente adjudicados: ${naoDisponiveis.map((p) => `${numeroOuId(p.numero_pedido, p.id)} está ${p.estado}`).join(', ')}.`}
            />
          )}
        </Card>

        <Card title="Preços por artigo" style={{ marginBottom: 16 }}>
          <Form.List name="linhas" rules={[{ validator: async (_, v) => (v && v.length ? undefined : Promise.reject(new Error('Escolha pelo menos um pedido com artigos por cobrir.'))) }]}>
            {(campos, _op, { errors }) => (
              <>
                <div onPaste={aoColarCelula}>
                  <Table scroll={scrollTabela()}
                    rowKey="key"
                    size="small"
                    pagination={false}
                    loading={pedidos.isFetching}
                    dataSource={campos}
                    locale={{ emptyText: 'Escolha os pedidos para ver os artigos por cobrir.' }}
                    onRow={(c) => ({ 'data-colar-linha': c.name }) as unknown as React.HTMLAttributes<HTMLElement>}
                    columns={[
                      ...(chavePedidos.length > 1
                        ? [{
                            title: 'Pedido', width: 110,
                            render: (_: unknown, c: { name: number }) => {
                              const l = form.getFieldValue(['linhas', c.name]) as LinhaProposta | undefined;
                              return l ? <Tag>{rotuloPedido(l.pedido_compra_id)}</Tag> : null;
                            },
                          }]
                        : []),
                      {
                        title: 'Artigo',
                        render: (_, c) => {
                          const l = form.getFieldValue(['linhas', c.name]) as LinhaProposta | undefined;
                          return l ? <NomeProduto id={l.produto_id} descricao={l.descricao} /> : null;
                        },
                      },
                      {
                        title: 'Pendente',
                        align: 'right',
                        render: (_, c) => {
                          const l = form.getFieldValue(['linhas', c.name]) as LinhaProposta | undefined;
                          if (!l) return null;
                          const parcial = Number(l.saldo_pendente) < Number(l.quantidade_pedido);
                          return (
                            <span title={parcial ? `Pedido: ${formatarNumero(l.quantidade_pedido)} — já coberto o resto` : undefined}>
                              {formatarNumero(l.saldo_pendente)}{parcial && <Tag color="gold" style={{ marginLeft: 4 }}>parcial</Tag>}
                            </span>
                          );
                        },
                      },
                      {
                        title: 'Quantidade a propor',
                        render: (_, c) => {
                          const l = form.getFieldValue(['linhas', c.name]) as LinhaProposta | undefined;
                          return (
                            <Form.Item name={[c.name, 'quantidade']} rules={[{ required: true, message: 'Quantidade' }]} style={{ margin: 0 }} tooltip="0 = não incluir este artigo nesta proposta">
                              <InputNumber aria-label="Quantidade a propor" min={0} max={l ? Number(l.saldo_pendente) : undefined} precision={3} style={{ width: 120 }} />
                            </Form.Item>
                          );
                        },
                      },
                      {
                        title: `Preço unit. (${moeda.toUpperCase()}, s/ IVA)`,
                        render: (_, c) => (
                          <div data-colar-coluna={0}>
                            <Form.Item name={[c.name, 'preco_unitario']} rules={[{ required: true, message: 'Preço' }]} style={{ margin: 0 }}>
                              <InputNumber min={0} precision={2} style={{ width: 160 }} />
                            </Form.Item>
                          </div>
                        ),
                      },
                      ...(temReferencia
                        ? [
                            {
                              title: 'Valor da encomenda (Kz, s/ IVA)',
                              align: 'right' as const,
                              render: (_: unknown, c: { name: number }) => {
                                const l = form.getFieldValue(['linhas', c.name]) as LinhaProposta | undefined;
                                const a = l ? porProduto.get(l.produto_id) : undefined;
                                return a ? <CelulaReferencia a={a} /> : <Typography.Text type="secondary">—</Typography.Text>;
                              },
                            },
                          ]
                        : []),
                      {
                        title: 'IVA %',
                        render: (_, c) => (
                          <div data-colar-coluna={1}>
                            <Form.Item name={[c.name, 'taxa_imposto']} style={{ margin: 0 }}>
                              <InputNumber min={0} max={100} style={{ width: 90 }} />
                            </Form.Item>
                          </div>
                        ),
                      },
                    ]}
                  />
                </div>
                <Form.ErrorList errors={errors} />
                {temReferencia && cambio === null && (
                  <Alert style={{ marginTop: 12 }} type="info" showIcon message="Indique o câmbio para comparar a proposta com o valor da encomenda do cliente." />
                )}
                {acima.length > 0 && (
                  <Alert
                    style={{ marginTop: 12 }}
                    type="error"
                    showIcon
                    message={`Proposta acima do valor da encomenda em ${acima.length} artigo(s)`}
                    description={<ListaAvisos itens={acima} />}
                  />
                )}
                {perto.length > 0 && (
                  <Alert
                    style={{ marginTop: 12 }}
                    type="warning"
                    showIcon
                    message={`Proposta a ${Math.round(LIMIAR_AVISO * 100)} % ou mais do valor da encomenda em ${perto.length} artigo(s)`}
                    description={<ListaAvisos itens={perto} />}
                  />
                )}
              </>
            )}
          </Form.List>
          <Divider />
          <Flex justify="end" gap={24} wrap>
            <Statistic title={`Líquido (${moeda.toUpperCase()})`} value={formatarKz(estimativa.liquido)} />
            <Statistic title="IVA" value={formatarKz(estimativa.imposto)} />
            <Statistic title="Total" value={formatarKz(estimativa.total)} />
          </Flex>
          <Typography.Text type="secondary">
            Estimativa na moeda da proposta; o servidor converte para Kz e grava os totais.
            {chavePedidos.length > 1 && ' Regista-se uma proposta por pedido (as linhas a 0 ficam de fora).'}
          </Typography.Text>
        </Card>
        <Space wrap>
          <Button type="primary" htmlType="submit" loading={aGravar}>
            {chavePedidos.length > 1 ? `Registar ${chavePedidos.length} proposta(s)` : 'Registar proposta'}
          </Button>
          <Button onClick={() => navegar('..')}>Cancelar</Button>
        </Space>
      </Form>
    </>
  );
}

function percentagem(a: AvaliacaoLinha): string {
  return a.razao === null ? '—' : `${Math.round(a.razao * 100)} %`;
}

function CelulaReferencia({ a }: { a: AvaliacaoLinha }) {
  const cor = a.nivel === 'acima' ? 'error' : a.nivel === 'aviso' ? 'warning' : 'success';
  return (
    <Space size={6} title={a.documentos.length ? `Encomenda(s): ${a.documentos.join(', ')}` : undefined}>
      <span>{formatarKz(a.referenciaKz)}</span>
      {a.razao !== null && <Tag color={cor}>{percentagem(a)}</Tag>}
    </Space>
  );
}

function ListaAvisos({ itens }: { itens: AvaliacaoLinha[] }) {
  return (
    <ul style={{ margin: 0, paddingInlineStart: 18 }}>
      {itens.map((a) => (
        <li key={a.produto_id}>
          <NomeProduto id={a.produto_id} /> — custo {formatarKz(a.custoKz ?? 0)} Kz contra {formatarKz(a.referenciaKz)} Kz na encomenda
          {a.documentos.length ? ` ${a.documentos.join(', ')}` : ''} ({percentagem(a)}).
        </li>
      ))}
    </ul>
  );
}
