import { Alert, Button, Checkbox, Descriptions, Drawer, Flex, Form, Input, InputNumber, Modal, Skeleton, Space, Table, Tabs, Tag, Timeline, Tooltip, Typography } from 'antd';
import { CheckOutlined, DollarOutlined, ExclamationCircleOutlined, FileDoneOutlined, PlayCircleOutlined, SendOutlined, StopOutlined, ToolOutlined } from '@ant-design/icons';
import { useEffect, useState } from 'react';
import { enviar } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { BotoesExportar, pares, tabelaHtml, type PedidoImpressao } from '@/componentes/impressao';
import { larguraGaveta, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { SeletorProduto } from '@/modulos/compras/comum/Seletores';
import { deCentimos, pagamentosParaApi, resumirPagamentos, type Pagamento } from '../comum/calculos';
import { EstadoPOS, rotuloEstadoPOS } from '../comum/estados';
import { meiosActivos, PainelPagamentos } from '../comum/PainelPagamentos';
import { accoesOrdem } from '../comum/regras';
import type { Terminal } from '../comum/tipos';
import { useOrdem } from './dados';
import type { DetalheOrdem as Detalhe, ItemOrdem, PagamentoLav, SimulacaoEntrega } from './tipos';

const INVALIDAR = [['pos']];

/** Ordem de serviço: peças, execução, orçamentos, dinheiro (receber, facturar, entregar), recibos, reclamações e histórico. */
export function DetalheOrdem({ id, terminal, aoFechar }: { id: number | null; terminal: Terminal | undefined; aoFechar: () => void }) {
  const { pode } = useSessao();
  const consulta = useOrdem(id);
  const d = consulta.data;
  const sessaoId = terminal?.sessao_aberta?.id ?? null;
  const [receber, setReceber] = useState(false);
  const [entregar, setEntregar] = useState(false);
  const [anular, setAnular] = useState(false);
  const [anularRecibo, setAnularRecibo] = useState<PagamentoLav | null>(null);
  const [reclamar, setReclamar] = useState<ItemOrdem | null>(null);
  const [material, setMaterial] = useState<ItemOrdem | null>(null);
  const accao = useAccao({
    invalidar: INVALIDAR,
    aoSucesso: () => {
      setAnular(false);
      setAnularRecibo(null);
      setReclamar(null);
      setMaterial(null);
    },
  });

  const a = d ? accoesOrdem(pode, { estado: d.pedido.estado, itens: d.pedido.itens, saldo: d.totais.saldo, por_facturar: d.totais.por_facturar }, !!sessaoId) : null;
  const outroTerminal = !!d && !!terminal && d.pedido.terminal_pos_id !== terminal.id;

  const linhas = (accaoLinha: 'INICIAR' | 'PRONTA', linha?: number) => d && accao.mutate({ url: `/pos/lavandaria/ordens/${d.pedido.id}/linhas`, dados: { accao: accaoLinha, linha_id: linha } });
  const orcamento = (decisao: 'APROVAR' | 'RECUSAR', i: ItemOrdem) =>
    d &&
    accao.mutate({
      url: '/pos/lavandaria/orcamentos',
      dados: { decisao, linhas: [{ pedido_id: d.pedido.id, linha_id: i.linha_id, valor: Number(i.valor_orcamento ?? i.valor) }] },
    });

  return (
    <Drawer
      open={!!id}
      onClose={aoFechar}
      width={larguraGaveta(1000)}
      destroyOnHidden
      title={d ? `${d.pedido.numero_encomenda} · ${d.cliente?.nome ?? ''}` : 'Ordem de serviço'}
      extra={
        d &&
        a && (
          <Space wrap>
            <BotoesExportar tamanho="small" obterPedido={() => pedidoOrdem(d)} />
            {a.receber && (
              <Button icon={<DollarOutlined />} onClick={() => setReceber(true)}>
                Receber
              </Button>
            )}
            {a.faturar && (
              <Button
                icon={<FileDoneOutlined />}
                onClick={() =>
                  Modal.confirm({
                    title: 'Emitir a factura das linhas por facturar?',
                    content: `${formatarKz(d.totais.por_facturar)} Kz na sessão ${terminal?.sessao_aberta?.codigo_sessao ?? ''}.`,
                    okText: 'Facturar',
                    cancelText: 'Cancelar',
                    onOk: () => accao.mutateAsync({ url: `/pos/lavandaria/sessoes/${sessaoId}/ordens/${d.pedido.id}/faturar` }).catch(() => undefined),
                  })
                }
              >
                Facturar
              </Button>
            )}
            {a.entregar && (
              <Button type="primary" icon={<SendOutlined />} onClick={() => setEntregar(true)}>
                Entregar
              </Button>
            )}
            {a.anular && (
              <Button danger icon={<StopOutlined />} onClick={() => setAnular(true)}>
                Anular
              </Button>
            )}
          </Space>
        )
      }
    >
      {!d ? (
        <Skeleton active />
      ) : (
        <>
          {outroTerminal && <Alert type="info" showIcon style={{ marginBottom: 12 }} message={`Ordem recebida no terminal ${d.pedido.codigo_terminal}; as operações de caixa correm na sessão do terminal ${terminal?.codigo}.`} />}
          <Descriptions size="small" bordered column={{ xs: 1, md: 3 }}>
            <Descriptions.Item label="Estado">
              <EstadoPOS estado={d.pedido.estado} /> {d.pedido.urgente && <Tag color="red">Urgente</Tag>}
            </Descriptions.Item>
            <Descriptions.Item label="Recebida">{`${formatarDataHora(d.pedido.recebido_em)} · ${d.pedido.recebido_por ?? ''}`}</Descriptions.Item>
            <Descriptions.Item label="Prometida">{formatarDataHora(d.pedido.data_prometida)}</Descriptions.Item>
            <Descriptions.Item label="Cliente">{d.cliente ? `${d.cliente.nome}${d.cliente.telefone ? ` · ${d.cliente.telefone}` : ''}` : '—'}</Descriptions.Item>
            <Descriptions.Item label="Facturação">{d.pedido.modo_faturacao === 'RECEPCAO' ? 'Na recepção' : 'Na entrega'}</Descriptions.Item>
            <Descriptions.Item label="Responsável">{d.indicadores.responsavel ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Total">{formatarKz(d.totais.total)} Kz</Descriptions.Item>
            <Descriptions.Item label="Facturado / pago">{`${formatarKz(d.totais.facturado)} / ${formatarKz(d.totais.pago)}`}</Descriptions.Item>
            <Descriptions.Item label="Saldo">
              <b>{formatarKz(d.totais.saldo)} Kz</b>
            </Descriptions.Item>
            <Descriptions.Item label="Situação">{d.indicadores.situacao ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Por facturar">{formatarKz(d.totais.por_facturar)} Kz</Descriptions.Item>
            {d.pedido.observacoes && <Descriptions.Item label="Observações">{d.pedido.observacoes}</Descriptions.Item>}
          </Descriptions>

          <Tabs
            style={{ marginTop: 12 }}
            items={[
              {
                key: 'pecas',
                label: `Peças (${d.pedido.itens.length})`,
                children: (
                  <>
                    <Space wrap style={{ marginBottom: 8 }}>
                      {a?.iniciar && (
                        <Button size="small" icon={<PlayCircleOutlined />} onClick={() => linhas('INICIAR')}>
                          Iniciar todas
                        </Button>
                      )}
                      {a?.pronta && (
                        <Button size="small" icon={<CheckOutlined />} onClick={() => linhas('PRONTA')}>
                          Todas prontas
                        </Button>
                      )}
                    </Space>
                    <Table<ItemOrdem>
                      size="small"
                      rowKey="linha_id"
                      pagination={false}
                      dataSource={d.pedido.itens}
                      scroll={scrollTabela()}
                      columns={[
                        { title: 'Etiquetas', dataIndex: 'etiquetas', render: (v: string[] | undefined) => v?.join(', ') ?? '—' },
                        { title: 'Peça', render: (_, i) => `${i.nome_peca ?? '—'}${i.cor || i.tecido ? ` (${[i.cor, i.tecido].filter(Boolean).join(', ')})` : ''}` },
                        { title: 'Serviço', dataIndex: 'nome' },
                        { title: 'Qtd.', dataIndex: 'quantidade', align: 'right', render: (v) => formatarNumero(v) },
                        { title: 'Entrada', dataIndex: 'estado_entrada', render: (v, i) => (i.notas_entrada ? <Tooltip title={i.notas_entrada}>{v}</Tooltip> : v ?? '—') },
                        { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => formatarKz(v) },
                        { title: 'Estado', dataIndex: 'estado', render: (v) => <EstadoPOS estado={v} /> },
                        { title: 'Orçamento', dataIndex: 'estado_orcamento', render: (v, i) => (i.requer_orcamento ? <EstadoPOS estado={v} /> : '—') },
                        {
                          title: '',
                          key: 'accoes',
                          render: (_, i) => {
                            const activa = d.pedido.estado !== 'ANULADA' && d.pedido.estado !== 'ENTREGUE';
                            const ordens = pode('lav_ordens') && activa;
                            return (
                              <Space size={2} wrap>
                                {ordens && i.estado === 'RECEBIDA' && i.estado_orcamento !== 'PENDENTE' && (
                                  <Button size="small" onClick={() => linhas('INICIAR', i.linha_id)}>
                                    Iniciar
                                  </Button>
                                )}
                                {ordens && i.estado === 'EM_EXECUCAO' && (
                                  <Button size="small" onClick={() => linhas('PRONTA', i.linha_id)}>
                                    Pronta
                                  </Button>
                                )}
                                {ordens && i.estado_orcamento === 'PENDENTE' && (
                                  <>
                                    <Button size="small" type="primary" onClick={() => orcamento('APROVAR', i)}>
                                      Aprovar
                                    </Button>
                                    <Button size="small" danger onClick={() => orcamento('RECUSAR', i)}>
                                      Recusar
                                    </Button>
                                  </>
                                )}
                                {ordens && i.grupo === 'ALFAIATARIA' && (
                                  <Tooltip title="Consumir material (guia ao custo médio)">
                                    <Button size="small" icon={<ToolOutlined />} onClick={() => setMaterial(i)} />
                                  </Tooltip>
                                )}
                                {pode('lav_ordens') && d.pedido.estado !== 'ANULADA' && (
                                  <Tooltip title="Registar dano ou reclamação">
                                    <Button size="small" icon={<ExclamationCircleOutlined />} onClick={() => setReclamar(i)} />
                                  </Tooltip>
                                )}
                              </Space>
                            );
                          },
                        },
                      ]}
                    />
                  </>
                ),
              },
              {
                key: 'dinheiro',
                label: `Recibos e facturas (${d.pagamentos.length + d.faturas.length})`,
                children: (
                  <>
                    <Typography.Title level={5}>Recibos</Typography.Title>
                    <Table<PagamentoLav>
                      size="small"
                      scroll={scrollTabela()}
                      rowKey="id"
                      pagination={false}
                      dataSource={d.pagamentos}
                      columns={[
                        { title: 'Recibo', dataIndex: 'numero_recibo' },
                        { title: 'Data', dataIndex: 'data', render: (v) => formatarData(v) },
                        { title: 'Natureza', dataIndex: 'natureza_registo', render: (v) => <EstadoPOS estado={v} /> },
                        { title: 'Meios', render: (_, p) => (p.pos_pagamentos ?? []).map((x) => `${x.nome ?? x.tipo}: ${formatarKz(x.valor)}`).join(' · ') },
                        { title: 'Montante', dataIndex: 'montante', align: 'right', render: (v) => formatarKz(v) },
                        { title: 'Estado', dataIndex: 'estado', render: (v) => <EstadoPOS estado={v} /> },
                        {
                          title: '',
                          key: 'a',
                          render: (_, p) =>
                            p.estado === 'REGISTADO' && pode('lav_anular') ? (
                              <Button size="small" danger onClick={() => setAnularRecibo(p)}>
                                Anular
                              </Button>
                            ) : null,
                        },
                      ]}
                    />
                    <Typography.Title level={5} style={{ marginTop: 16 }}>
                      Facturas
                    </Typography.Title>
                    <Table
                      size="small"
                      scroll={scrollTabela()}
                      rowKey="id"
                      pagination={false}
                      dataSource={d.faturas}
                      columns={[
                        { title: 'Documento', dataIndex: 'numero_documento' },
                        { title: 'Data', dataIndex: 'data_emissao', render: (v) => formatarData(v) },
                        { title: 'Total', dataIndex: 'total_bruto', align: 'right', render: (v) => formatarKz(v) },
                        { title: 'Estado', dataIndex: 'estado', render: (v) => <EstadoPOS estado={v} /> },
                      ]}
                    />
                  </>
                ),
              },
              {
                key: 'reclamacoes',
                label: `Reclamações (${d.reclamacoes.length})`,
                children: (
                  <Table
                    size="small"
                    scroll={scrollTabela()}
                    rowKey="id"
                    pagination={false}
                    dataSource={d.reclamacoes}
                    columns={[
                      { title: 'Peça', render: (_, r) => r.nome_item ?? r.descricao_peca ?? '—' },
                      { title: 'Descrição', dataIndex: 'descricao' },
                      { title: 'Declarado', dataIndex: 'valor_declarado', align: 'right', render: (v) => formatarKz(v) },
                      { title: 'Estado', dataIndex: 'estado', render: (v) => <EstadoPOS estado={v} /> },
                    ]}
                  />
                ),
              },
              {
                key: 'historico',
                label: 'Histórico',
                children: <Timeline items={(d.pedido.historico_alteracoes ?? []).map((h) => ({ children: `${formatarDataHora(h.em)} · ${h.por} — ${h.texto}` }))} />,
              },
            ]}
          />

          {sessaoId && <ModalReceber aberto={receber} detalhe={d} sessaoId={sessaoId} terminal={terminal!} aoFechar={() => setReceber(false)} />}
          {sessaoId && <ModalEntregar aberto={entregar} detalhe={d} sessaoId={sessaoId} terminal={terminal!} aoFechar={() => setEntregar(false)} />}
          <ModalMotivo aberto={anular} titulo={`Anular ${d.pedido.numero_encomenda}`} textoOk="Anular ordem" carregando={accao.isPending} aoFechar={() => setAnular(false)} aoConfirmar={(motivo) => accao.mutate({ url: `/pos/lavandaria/ordens/${d.pedido.id}/anular`, dados: { motivo } })} />
          <ModalMotivo
            aberto={!!anularRecibo}
            titulo={`Anular o recibo ${anularRecibo?.numero_recibo ?? ''}`}
            textoOk="Anular recibo"
            aviso="Um recibo só se anula com a sessão em que foi emitido ainda aberta. Uma factura-recibo corrige-se com nota de crédito."
            carregando={accao.isPending}
            aoFechar={() => setAnularRecibo(null)}
            aoConfirmar={(motivo) => anularRecibo && accao.mutate({ url: `/pos/lavandaria/pagamentos/${anularRecibo.id}/anular`, dados: { motivo } })}
          />
          <ModalReclamacao item={reclamar} carregando={accao.isPending} aoFechar={() => setReclamar(null)} aoConfirmar={(dados) => reclamar && accao.mutate({ url: `/pos/lavandaria/ordens/${d.pedido.id}/linhas/${reclamar.linha_id}/reclamacoes`, dados })} />
          <ModalMaterial item={material} carregando={accao.isPending} aoFechar={() => setMaterial(null)} aoConfirmar={(dados) => material && accao.mutate({ url: `/pos/lavandaria/ordens/${d.pedido.id}/linhas/${material.linha_id}/materiais`, dados })} />
        </>
      )}
    </Drawer>
  );
}

function ModalReceber({ aberto, detalhe, sessaoId, terminal, aoFechar }: { aberto: boolean; detalhe: Detalhe; sessaoId: number; terminal: Terminal; aoFechar: () => void }) {
  const saldo = Math.round(Number(detalhe.totais.saldo) * 100);
  const [valor, setValor] = useState(saldo);
  const [pagamentos, setPagamentos] = useState<Pagamento[]>([]);
  const meios = meiosActivos(terminal.meios_pagamento);
  useEffect(() => {
    if (aberto) {
      setValor(saldo);
      setPagamentos([]);
    }
  }, [aberto, saldo]);
  const receber = useAccao({ invalidar: INVALIDAR, aoSucesso: aoFechar, tituloErro: 'Não foi possível registar o recebimento' });
  const resumo = resumirPagamentos(pagamentos, valor);
  return (
    <Modal
      open={aberto}
      title={`Receber · ${detalhe.pedido.numero_encomenda}`}
      width={larguraModal(720)}
      onCancel={aoFechar}
      okText="Registar recebimento"
      cancelText="Cancelar"
      okButtonProps={{ disabled: !resumo.valido || valor <= 0 || valor > saldo }}
      confirmLoading={receber.isPending}
      onOk={() => receber.mutate({ url: `/pos/lavandaria/sessoes/${sessaoId}/ordens/${detalhe.pedido.id}/pagamentos`, dados: { valor: deCentimos(valor), pagamentos: pagamentosParaApi(pagamentos) } })}
      destroyOnHidden
    >
      <Flex gap={8} wrap align="center" style={{ marginBottom: 12 }}>
        <Typography.Text>Valor a receber (saldo {formatarKz(detalhe.totais.saldo)} Kz):</Typography.Text>
        <InputNumber<number> min={0} max={saldo / 100} precision={2} decimalSeparator="," suffix="Kz" value={valor / 100} onChange={(v) => setValor(Math.round((v ?? 0) * 100))} />
      </Flex>
      <PainelPagamentos meios={meios} total={valor} pagamentos={pagamentos} onChange={setPagamentos} />
    </Modal>
  );
}

function ModalEntregar({ aberto, detalhe, sessaoId, terminal, aoFechar }: { aberto: boolean; detalhe: Detalhe; sessaoId: number; terminal: Terminal; aoFechar: () => void }) {
  const prontas = detalhe.pedido.itens.filter((i) => i.estado === 'PRONTA');
  const [linhas, setLinhas] = useState<number[]>([]);
  const [simulacao, setSimulacao] = useState<SimulacaoEntrega | null>(null);
  const [valor, setValor] = useState(0);
  const [pagamentos, setPagamentos] = useState<Pagamento[]>([]);
  const meios = meiosActivos(terminal.meios_pagamento);
  const entregar = useAccao({ invalidar: INVALIDAR, aoSucesso: aoFechar, tituloErro: 'Não foi possível registar a entrega' });

  useEffect(() => {
    if (aberto) {
      setLinhas(prontas.map((i) => i.linha_id));
      setPagamentos([]);
    }
  }, [aberto]);

  useEffect(() => {
    if (!aberto || !linhas.length) {
      setSimulacao(null);
      return;
    }
    let activo = true;
    enviar<SimulacaoEntrega>('post', `/pos/lavandaria/ordens/${detalhe.pedido.id}/entrega/simulacao`, { linhas })
      .then(({ dados }) => {
        if (!activo) return;
        setSimulacao(dados);
        setValor(Math.round(Number(dados.saldo_facturado) * 100));
      })
      .catch((e) => notificarErro(e, 'Não foi possível simular a entrega'));
    return () => {
      activo = false;
    };
  }, [aberto, linhas, detalhe.pedido.id]);

  const resumo = resumirPagamentos(pagamentos, valor);
  const pagamentoValido = valor === 0 || resumo.valido;
  return (
    <Modal
      open={aberto}
      title={`Entregar · ${detalhe.pedido.numero_encomenda}`}
      width={larguraModal(760)}
      onCancel={aoFechar}
      okText="Registar entrega"
      cancelText="Cancelar"
      okButtonProps={{ disabled: !linhas.length || !simulacao || !pagamentoValido }}
      confirmLoading={entregar.isPending}
      onOk={() =>
        entregar.mutate({
          url: `/pos/lavandaria/sessoes/${sessaoId}/ordens/${detalhe.pedido.id}/entregar`,
          dados: { linhas, valor: deCentimos(valor), pagamentos: valor > 0 ? pagamentosParaApi(pagamentos) : [] },
        })
      }
      destroyOnHidden
    >
      <Checkbox.Group style={{ width: '100%' }} value={linhas} onChange={(v) => setLinhas(v as number[])}>
        <Flex vertical gap={4}>
          {prontas.map((i) => (
            <Checkbox key={i.linha_id} value={i.linha_id}>
              {`${i.etiquetas?.join(', ') ?? ''} · ${i.nome_peca ?? ''} · ${i.nome} · ${formatarKz(i.valor)} Kz`}
            </Checkbox>
          ))}
        </Flex>
      </Checkbox.Group>
      {simulacao && (
        <Descriptions size="small" column={{ xs: 1, sm: 2 }} style={{ marginTop: 12 }}>
          <Descriptions.Item label="Por facturar">{formatarKz(simulacao.por_facturar)} Kz</Descriptions.Item>
          <Descriptions.Item label="Saldo a pagar">{formatarKz(simulacao.saldo_facturado)} Kz</Descriptions.Item>
          {simulacao.taxa_armazenagem && Number(simulacao.taxa_armazenagem.valor) > 0 && (
            <Descriptions.Item label="Taxa de armazenagem">{formatarKz(simulacao.taxa_armazenagem.valor)} Kz</Descriptions.Item>
          )}
          {simulacao.consumidor_final && <Descriptions.Item label="Consumidor Final">o saldo paga-se na entrega</Descriptions.Item>}
        </Descriptions>
      )}
      <Flex gap={8} wrap align="center" style={{ margin: '12px 0' }}>
        <Typography.Text>Valor a receber agora:</Typography.Text>
        <InputNumber<number> min={0} precision={2} decimalSeparator="," suffix="Kz" value={valor / 100} onChange={(v) => setValor(Math.round((v ?? 0) * 100))} />
      </Flex>
      {valor > 0 && <PainelPagamentos meios={meios} total={valor} pagamentos={pagamentos} onChange={setPagamentos} />}
    </Modal>
  );
}

function ModalReclamacao({ item, carregando, aoFechar, aoConfirmar }: { item: ItemOrdem | null; carregando: boolean; aoFechar: () => void; aoConfirmar: (d: { descricao: string; valor_declarado?: number }) => void }) {
  const [form] = Form.useForm<{ descricao: string; valor_declarado?: number }>();
  useEffect(() => {
    if (item) form.resetFields();
  }, [item, form]);
  return (
    <Modal open={!!item} title={`Dano ou reclamação · ${item?.nome_peca ?? ''}`} okText="Registar" cancelText="Cancelar" confirmLoading={carregando} onCancel={aoFechar} onOk={() => form.submit()} width={larguraModal(560)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => aoConfirmar({ descricao: v.descricao.trim(), valor_declarado: v.valor_declarado ?? undefined })}>
        <Form.Item name="descricao" label="Descrição" rules={[{ required: true, message: 'Descreva o dano ou a reclamação.' }]}>
          <Input.TextArea rows={3} maxLength={2000} showCount />
        </Form.Item>
        <Form.Item name="valor_declarado" label="Valor declarado pelo cliente">
          <InputNumber<number> min={0} precision={2} decimalSeparator="," suffix="Kz" style={{ width: 220, maxWidth: '100%' }} />
        </Form.Item>
      </Form>
    </Modal>
  );
}

function ModalMaterial({ item, carregando, aoFechar, aoConfirmar }: { item: ItemOrdem | null; carregando: boolean; aoFechar: () => void; aoConfirmar: (d: { produto_id: number; quantidade: number }) => void }) {
  const [form] = Form.useForm<{ produto_id: number; quantidade: number }>();
  useEffect(() => {
    if (item) form.resetFields();
  }, [item, form]);
  return (
    <Modal open={!!item} title={`Consumo de material · ${item?.nome ?? ''}`} okText="Abater ao stock" cancelText="Cancelar" confirmLoading={carregando} onCancel={aoFechar} onOk={() => form.submit()} width={larguraModal(560)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={aoConfirmar}>
        <Form.Item name="produto_id" label="Material" rules={[{ required: true, message: 'Escolha o material.' }]}>
          <SeletorProduto apenasStock />
        </Form.Item>
        <Form.Item name="quantidade" label="Quantidade" rules={[{ required: true, message: 'Indique a quantidade.' }]}>
          <InputNumber<number> min={0.001} precision={3} decimalSeparator="," style={{ width: 200, maxWidth: '100%' }} />
        </Form.Item>
        <Typography.Text type="secondary">Gera uma guia de consumo ao custo médio com o CMV; sem stock suficiente, a operação é recusada.</Typography.Text>
      </Form>
    </Modal>
  );
}


/** Ordem de serviço da lavandaria em A4: dados, peças, recibos, facturas e reclamações. */
export function pedidoOrdem(d: Detalhe): PedidoImpressao {
  const p = d.pedido;
  const dados: [string, string][] = [
    ['Cliente', d.cliente ? `${d.cliente.nome}${d.cliente.telefone ? ` · ${d.cliente.telefone}` : ''}` : '—'],
    ['Estado', `${rotuloEstadoPOS(p.estado)}${p.urgente ? ' · urgente' : ''}`],
    ['Recebida', `${formatarDataHora(p.recebido_em)} · ${p.recebido_por ?? ''}`],
    ['Prometida', formatarDataHora(p.data_prometida)],
    ['Facturação', p.modo_faturacao === 'RECEPCAO' ? 'Na recepção' : 'Na entrega'],
    ['Responsável', d.indicadores.responsavel ?? '—'],
    ['Total', `${formatarKz(d.totais.total)} Kz`],
    ['Facturado / pago', `${formatarKz(d.totais.facturado)} / ${formatarKz(d.totais.pago)}`],
    ['Saldo', `${formatarKz(d.totais.saldo)} Kz`],
    ['Situação', d.indicadores.situacao ?? '—'],
    ['Por facturar', `${formatarKz(d.totais.por_facturar)} Kz`],
  ];
  if (p.observacoes) dados.push(['Observações', p.observacoes]);
  const pecas = tabelaHtml<ItemOrdem>({
    legenda: `Peças (${p.itens.length})`,
    linhas: p.itens,
    totais: true,
    colunas: [
      { titulo: 'Etiquetas', valor: (i) => i.etiquetas?.join(', ') ?? '—', quebrar: true },
      { titulo: 'Peça', valor: (i) => `${i.nome_peca ?? '—'}${i.cor || i.tecido ? ` (${[i.cor, i.tecido].filter(Boolean).join(', ')})` : ''}` },
      { titulo: 'Serviço', valor: (i) => i.nome },
      { titulo: 'Qtd.', valor: (i) => i.quantidade, formato: 'numero' },
      { titulo: 'Entrada', valor: (i) => `${i.estado_entrada ?? '—'}${i.notas_entrada ? ` · ${i.notas_entrada}` : ''}`, quebrar: true },
      { titulo: 'Valor', valor: (i) => i.valor, formato: 'moeda', somar: true },
      { titulo: 'Estado', valor: (i) => rotuloEstadoPOS(i.estado) },
      { titulo: 'Orçamento', valor: (i) => (i.requer_orcamento && i.estado_orcamento ? rotuloEstadoPOS(i.estado_orcamento) : '—') },
    ],
  });
  const recibos = d.pagamentos.length
    ? tabelaHtml<PagamentoLav>({
        legenda: 'Recibos',
        linhas: d.pagamentos,
        colunas: [
          { titulo: 'Recibo', valor: (r) => r.numero_recibo },
          { titulo: 'Data', valor: (r) => r.data, formato: 'data' },
          { titulo: 'Natureza', valor: (r) => rotuloEstadoPOS(r.natureza_registo) },
          { titulo: 'Meios', valor: (r) => (r.pos_pagamentos ?? []).map((x) => `${x.nome ?? x.tipo}: ${formatarKz(x.valor)}`).join(' · '), quebrar: true },
          { titulo: 'Montante', valor: (r) => r.montante, formato: 'moeda' },
          { titulo: 'Estado', valor: (r) => rotuloEstadoPOS(r.estado) },
        ],
      })
    : '';
  const facturas = d.faturas.length
    ? tabelaHtml({
        legenda: 'Facturas',
        linhas: d.faturas,
        colunas: [
          { titulo: 'Documento', valor: (x) => x.numero_documento },
          { titulo: 'Data', valor: (x) => x.data_emissao, formato: 'data' },
          { titulo: 'Total', valor: (x) => x.total_bruto, formato: 'moeda' },
          { titulo: 'Estado', valor: (x) => (x.estado ? rotuloEstadoPOS(x.estado) : '') },
        ],
      })
    : '';
  const reclamacoes = d.reclamacoes.length
    ? tabelaHtml({
        legenda: 'Reclamações',
        linhas: d.reclamacoes,
        colunas: [
          { titulo: 'Peça', valor: (r) => r.nome_item ?? r.descricao_peca ?? '—' },
          { titulo: 'Descrição', valor: (r) => r.descricao, quebrar: true },
          { titulo: 'Declarado', valor: (r) => r.valor_declarado, formato: 'moeda' },
          { titulo: 'Estado', valor: (r) => rotuloEstadoPOS(r.estado) },
        ],
      })
    : '';
  return {
    titulo: `Ordem de serviço ${p.numero_encomenda}`,
    subtitulo: p.codigo_terminal ? `Terminal ${p.codigo_terminal}` : undefined,
    conteudo: `${pares(dados, 2)}${pecas}${recibos}${facturas}${reclamacoes}`,
  };
}
