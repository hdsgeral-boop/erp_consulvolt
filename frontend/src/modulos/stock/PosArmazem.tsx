import { Alert, Button, Card, Col, DatePicker, Descriptions, Empty, Flex, Input, InputNumber, Modal, Result, Row, Skeleton, Table, Tabs, Tag, Typography } from 'antd';
import { DeleteOutlined, PlusOutlined, SendOutlined, ShoppingCartOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { pedidoDocumentoComercial } from '@/modulos/vendas/impressao/documentoComercial';
import { dadosGuiaSaida } from './comum/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { EstadoTag } from '@/modulos/compras/comum/estados';
import { contemTexto } from '@/modulos/compras/comum/lista';
import { NomeProduto, NomeTerceiro, useArmazens } from '@/modulos/compras/comum/referencias';
import { SeletorArmazem, SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import { TabelaLocal } from '@/modulos/compras/comum/Tabelas';
import { adicionarAoCarrinho, alterarQuantidade, totalUnidades, type ItemCarrinho } from './comum/carrinho';
import type { EncomendaPicking, GuiaSaida, LinhaStock, ListaRecolha } from './comum/tipos';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';

/**
 * Armazém › POS de armazém (ecrã pos_armazem): venda ao balcão (guia de saída), histórico das vendas ao balcão e
 * picking das notas de encomenda (expedição gera a guia de remessa). O acerto de stock faz-se nos ajustes/inventário (ADR-050).
 */
export default function PosArmazem() {
  const { pode } = useSessao();
  const armazens = useArmazens();
  const [armazem, setArmazem] = useState<number>();
  useEffect(() => {
    if (!armazem && armazens.data?.length) setArmazem((armazens.data.find((a) => a.predefinido) ?? armazens.data[0]).id);
  }, [armazens.data, armazem]);

  const separadores = [
    ...(pode('pos_armazem_vender') ? [{ key: 'balcao', label: 'Balcão', children: armazem ? <Balcao armazem={armazem} /> : <Skeleton active /> }] : []),
    { key: 'vendas', label: 'Vendas ao balcão', children: <VendasBalcao armazem={armazem} /> },
    { key: 'picking', label: 'Picking de encomendas', children: armazem ? <Picking armazem={armazem} /> : <Skeleton active /> },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="POS de armazém"
        subtitulo="Saídas ao balcão e expedição de encomendas"
        accoes={<SeletorArmazem style={{ width: 260, maxWidth: '100%' }} value={armazem} onChange={setArmazem} />}
      />
      <Tabs items={separadores} destroyOnHidden />
    </>
  );
}

function Balcao({ armazem }: { armazem: number }) {
  const [pesquisa, setPesquisa] = useState('');
  const [carrinho, setCarrinho] = useState<ItemCarrinho[]>([]);
  const [cliente, setCliente] = useState<number>();
  const [observacoes, setObservacoes] = useState('');
  const [emitida, setEmitida] = useState<GuiaSaida | null>(null);
  const stock = useQuery({ queryKey: ['logistica', 'pos-armazem', 'stock', armazem], queryFn: () => obter<LinhaStock[]>('/pos/armazem/stock', { armazem_id: armazem, so_com_stock: 1 }) });
  const vender = useAccao<GuiaSaida>({
    invalidar: [['logistica']],
    aoSucesso: (g) => {
      setEmitida(g);
      setCarrinho([]);
      setCliente(undefined);
      setObservacoes('');
    },
    tituloErro: 'Não foi possível concluir a saída',
  });
  useEffect(() => setCarrinho([]), [armazem]);

  const linhas = useMemo(() => (stock.data ?? []).filter((l) => contemTexto(pesquisa, l.codigo, l.nome)), [stock.data, pesquisa]);

  return (
    <Row gutter={16}>
      <Col xs={24} lg={14}>
        <Card title="Stock do armazém" extra={<Input.Search placeholder="Código ou produto" allowClear style={{ width: 240, maxWidth: '100%' }} onSearch={setPesquisa} onChange={(e) => !e.target.value && setPesquisa('')} />}>
          <Table<LinhaStock> scroll={scrollTabela()}
            rowKey="produto_id"
            size="small"
            loading={stock.isFetching}
            dataSource={linhas}
            pagination={{ defaultPageSize: 20 }}
            columns={[
              { title: 'Código', dataIndex: 'codigo', render: (v) => v || '—' },
              { title: 'Produto', dataIndex: 'nome' },
              { title: 'Disponível', dataIndex: 'quantidade', align: 'right', render: formatarNumero },
              {
                title: '',
                key: 'add',
                align: 'right',
                render: (_, l) => (
                  <Button
                    size="small"
                    icon={<PlusOutlined />}
                    disabled={Number(l.quantidade) <= 0}
                    onClick={() => setCarrinho((c) => adicionarAoCarrinho(c, { produto_id: l.produto_id, codigo: l.codigo, nome: l.nome, disponivel: Number(l.quantidade) }))}
                    aria-label="Acrescentar"
                  />
                ),
              },
            ]}
          />
        </Card>
      </Col>
      <Col xs={24} lg={10}>
        <Card title={<><ShoppingCartOutlined /> Saída ({formatarNumero(totalUnidades(carrinho))} un.)</>}>
          {carrinho.length === 0 ? (
            <Empty description="Acrescente produtos do stock." />
          ) : (
            <Table<ItemCarrinho> scroll={scrollTabela()}
              rowKey="produto_id"
              size="small"
              pagination={false}
              dataSource={carrinho}
              columns={[
                { title: 'Produto', render: (_, i) => <>{i.nome}<Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>máx. {formatarNumero(i.disponivel)}</Typography.Text></> },
                { title: 'Qtd.', render: (_, i) => <InputNumber min={0} max={i.disponivel} value={i.quantidade} style={{ width: 90 }} onChange={(v) => setCarrinho((c) => alterarQuantidade(c, i.produto_id, Number(v ?? 0)))} /> },
                { title: '', key: 'x', render: (_, i) => <Button danger type="text" icon={<DeleteOutlined />} onClick={() => setCarrinho((c) => alterarQuantidade(c, i.produto_id, 0))} aria-label="Retirar" /> },
              ]}
            />
          )}
          <div style={{ marginTop: 16 }}>
            <SeletorTerceiro papel="CLIENTE" style={{ width: '100%' }} placeholder="Cliente (opcional — cliente de balcão)" value={cliente} onChange={setCliente} />
            <Input.TextArea rows={2} maxLength={2000} style={{ marginTop: 8 }} placeholder="Observações" value={observacoes} onChange={(e) => setObservacoes(e.target.value)} />
            <Button
              type="primary"
              block
              size="large"
              style={{ marginTop: 12 }}
              disabled={!carrinho.length}
              loading={vender.isPending}
              onClick={() =>
                vender.mutate({
                  url: '/pos/armazem/vendas',
                  dados: { armazem_id: armazem, terceiro_id: cliente, observacoes: observacoes || undefined, linhas: carrinho.map((i) => ({ produto_id: i.produto_id, quantidade: i.quantidade })) },
                })
              }
            >
              Concluir saída
            </Button>
          </div>
        </Card>
      </Col>
      <Modal open={!!emitida} onCancel={() => setEmitida(null)} footer={<Button type="primary" onClick={() => setEmitida(null)}>Fechar</Button>}>
        {emitida && (
          <Result
            status={emitida.aviso_contabilizacao ? 'warning' : 'success'}
            title={`Guia ${emitida.numero_documento} emitida`}
            subTitle={emitida.aviso_contabilizacao ? `Custo das mercadorias por contabilizar: ${emitida.aviso_contabilizacao}` : 'Stock actualizado.'}
          />
        )}
      </Modal>
    </Row>
  );
}

function VendasBalcao({ armazem }: { armazem?: number }) {
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [aberta, setAberta] = useState<number | null>(null);
  const detalhe = useQuery({ queryKey: ['logistica', 'pos-armazem', 'venda', aberta], queryFn: () => obter<GuiaSaida>(`/pos/armazem/vendas/${aberta}`), enabled: aberta !== null });
  return (
    <Card>
      <Flex gap={8} wrap style={{ marginBottom: 16 }}>
        <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
      </Flex>
      <TabelaLocal<GuiaSaida>
        url="/pos/armazem/vendas"
        params={{ armazem_id: armazem, de: dataApi(periodo?.[0]), ate: dataApi(periodo?.[1]) }}
        chaveConsulta={['logistica', 'pos-armazem', 'vendas']}
        onRow={(r) => ({ onClick: () => setAberta(r.id), style: { cursor: 'pointer' } })}
        columns={[
          { title: 'Guia', dataIndex: 'numero_documento', render: (v: string) => <strong>{v}</strong> },
          { title: 'Data', dataIndex: 'data', render: formatarData },
          { title: 'Cliente', key: 'c', render: (_, r) => (r.terceiro_id ? <NomeTerceiro id={r.terceiro_id} terceiro={r.terceiro} /> : r.area_rececao || 'Cliente de balcão') },
          { title: 'Estado', dataIndex: 'estado', responsive: ['sm'], render: (e: string | null) => <EstadoTag estado={e} /> },
          { title: 'Contab.', dataIndex: 'contabilizado', responsive: ['md'], render: (c: boolean | null) => (c ? <Tag color="green">Sim</Tag> : <Tag>Não</Tag>) },
          { title: 'Emitida por', dataIndex: 'criado_por', responsive: ['lg'], render: (v) => v || '—' },
        ]}
      />
      <Modal
        title={detalhe.data ? `Guia ${detalhe.data.numero_documento}` : 'Guia'}
        open={aberta !== null}
        onCancel={() => setAberta(null)}
        footer={detalhe.data ? <BotoesExportar obterPedido={() => (detalhe.data ? pedidoDocumentoComercial(dadosGuiaSaida(detalhe.data, null)) : null)} /> : null}
        width={larguraModal(720)}
      >
        {detalhe.isLoading ? (
          <Skeleton active />
        ) : (
          detalhe.data && (
            <>
              <Descriptions size="small" column={{ xs: 1, sm: 2 }} style={{ marginBottom: 12 }}>
                <Descriptions.Item label="Data">{formatarData(detalhe.data.data)}</Descriptions.Item>
                <Descriptions.Item label="Estado"><EstadoTag estado={detalhe.data.estado} /></Descriptions.Item>
                <Descriptions.Item label="Cliente">{detalhe.data.area_rececao || '—'}</Descriptions.Item>
                {detalhe.data.observacoes && <Descriptions.Item label="Observações">{detalhe.data.observacoes}</Descriptions.Item>}
              </Descriptions>
              <Table scroll={scrollTabela()}
                rowKey="id"
                size="small"
                pagination={false}
                dataSource={detalhe.data.linhas ?? []}
                columns={[
                  { title: 'Produto', dataIndex: 'produto_id', render: (v: number, l) => <NomeProduto id={v} produto={l.produto} /> },
                  { title: 'Qtd.', dataIndex: 'quantidade', align: 'right', render: formatarNumero },
                  { title: 'Custo (Kz)', dataIndex: 'valor_kz', align: 'right', render: (v: string | null) => formatarKz(v) },
                ]}
              />
            </>
          )
        )}
      </Modal>
    </Card>
  );
}

function Picking({ armazem }: { armazem: number }) {
  const { pode } = useSessao();
  const [encomenda, setEncomenda] = useState<number>();
  const [observacoes, setObservacoes] = useState('');
  const fila = useQuery({ queryKey: ['logistica', 'pos-armazem', 'picking'], queryFn: () => obter<EncomendaPicking[]>('/pos/armazem/picking') });
  const podeVerLista = pode('pos_armazem_picking', 'pos_armazem_expedir');
  const lista = useQuery({
    queryKey: ['logistica', 'pos-armazem', 'recolha', encomenda, armazem],
    queryFn: () => obter<ListaRecolha>(`/pos/armazem/picking/${encomenda}`, { armazem_id: armazem }),
    enabled: !!encomenda && podeVerLista,
  });
  const expedir = useAccao({
    invalidar: [['logistica'], ['vendas']],
    aoSucesso: () => {
      setEncomenda(undefined);
      setObservacoes('');
    },
    tituloErro: 'Não foi possível expedir',
  });

  return (
    <Row gutter={16}>
      <Col xs={24} lg={10}>
        <Card title="Fila de encomendas">
          <Table<EncomendaPicking> scroll={scrollTabela()}
            rowKey="id"
            size="small"
            loading={fila.isFetching}
            dataSource={fila.data ?? []}
            pagination={{ defaultPageSize: 15 }}
            rowClassName={(r) => (r.id === encomenda ? 'ant-table-row-selected' : '')}
            onRow={(r) => ({ onClick: () => setEncomenda(r.id), style: { cursor: podeVerLista ? 'pointer' : undefined } })}
            columns={[
              { title: 'Encomenda', dataIndex: 'numero_documento', render: (v: string) => <strong>{v}</strong> },
              { title: 'Data', dataIndex: 'data_emissao', render: formatarData },
              { title: 'Cliente', dataIndex: 'cliente', render: (v) => v?.trim() || '—' },
              { title: 'Linhas', dataIndex: 'linhas_por_expedir', align: 'right' },
              { title: 'Estado', dataIndex: 'estado_picking', render: (e: string) => <EstadoTag estado={e} /> },
            ]}
          />
        </Card>
      </Col>
      <Col xs={24} lg={14}>
        <Card title="Lista de recolha">
          {!podeVerLista ? (
            <Alert type="info" showIcon message="Sem permissão para iniciar o picking." />
          ) : !encomenda ? (
            <Empty description="Escolha uma encomenda da fila." />
          ) : lista.isLoading ? (
            <Skeleton active />
          ) : (
            lista.data && (
              <>
                <Flex justify="space-between" align="center" gap={8} wrap style={{ marginBottom: 8 }}>
                  <Typography.Title level={5} style={{ margin: 0 }}>
                    {lista.data.encomenda.numero_documento} · {formatarKz(lista.data.encomenda.total_bruto)} Kz
                  </Typography.Title>
                  <BotoesExportar
                    tamanho="small"
                    obterPedido={() =>
                      lista.data
                        ? {
                            titulo: `Lista de recolha — ${lista.data.encomenda.numero_documento}`,
                            filtros: ['Picking de encomenda de cliente'],
                            conteudo:
                              tabelaHtml({
                                colunas: [
                                  { titulo: 'Código', valor: (l: (typeof lista.data.linhas)[number]) => l.codigo ?? '' },
                                  { titulo: 'Produto', valor: (l) => l.nome, quebrar: true },
                                  { titulo: 'Por expedir', valor: (l) => l.por_expedir, formato: 'numero' },
                                  { titulo: 'Stock no armazém', valor: (l) => (l.movimenta_stock ? formatarNumero(l.stock_armazem) : 'serviço'), alinhamento: 'direita' },
                                  { titulo: 'Situação', valor: (l) => (l.disponivel ? 'Disponível' : 'Sem stock') },
                                  { titulo: 'Recolhido', valor: () => '', largura: '24mm' },
                                ],
                                linhas: lista.data.linhas,
                              }) +
                              '<div class="imp-sem-quebra" style="display:flex;justify-content:space-around;gap:10mm;margin-top:14mm"><div style="flex:0 1 38%;text-align:center;border-top:0.3mm solid #1f1f1f;padding-top:1mm;font-size:8pt">Recolhido por</div><div style="flex:0 1 38%;text-align:center;border-top:0.3mm solid #1f1f1f;padding-top:1mm;font-size:8pt">Conferido por</div></div>',
                          }
                        : null
                    }
                  />
                </Flex>
                <Table scroll={scrollTabela()}
                  rowKey="item_id"
                  size="small"
                  pagination={false}
                  dataSource={lista.data.linhas}
                  columns={[
                    { title: 'Código', dataIndex: 'codigo', render: (v) => v || '—' },
                    { title: 'Produto', dataIndex: 'nome' },
                    { title: 'Por expedir', dataIndex: 'por_expedir', align: 'right', render: formatarNumero },
                    { title: 'Stock no armazém', dataIndex: 'stock_armazem', align: 'right', responsive: ['sm'], render: (v: string | null, l) => (l.movimenta_stock ? formatarNumero(v) : 'serviço') },
                    { title: '', dataIndex: 'disponivel', render: (d: boolean) => (d ? <Tag color="green">Disponível</Tag> : <Tag color="red">Sem stock</Tag>) },
                  ]}
                />
                {!lista.data.pode_expedir && <Alert style={{ marginTop: 12 }} type="warning" showIcon message="Há artigos sem stock suficiente neste armazém: não é possível expedir." />}
                {pode('pos_armazem_expedir') && (
                  <>
                    <Input.TextArea rows={2} maxLength={2000} style={{ marginTop: 12 }} placeholder="Observações da expedição" value={observacoes} onChange={(e) => setObservacoes(e.target.value)} />
                    <Button
                      type="primary"
                      icon={<SendOutlined />}
                      style={{ marginTop: 12 }}
                      disabled={!lista.data.pode_expedir}
                      loading={expedir.isPending}
                      onClick={() =>
                        Modal.confirm({
                          title: `Confirmar a expedição de ${lista.data?.encomenda.numero_documento}?`,
                          content: 'É emitida a guia de remessa e o stock sai do armazém.',
                          okText: 'Expedir',
                          cancelText: 'Cancelar',
                          onOk: () => expedir.mutateAsync({ url: `/pos/armazem/picking/${encomenda}/expedir`, dados: { armazem_id: armazem, observacoes: observacoes || undefined } }).catch(() => undefined),
                        })
                      }
                    >
                      Confirmar expedição
                    </Button>
                  </>
                )}
              </>
            )
          )}
        </Card>
      </Col>
    </Row>
  );
}

