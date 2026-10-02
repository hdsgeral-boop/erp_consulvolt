import { Alert, Button, Card, Checkbox, Empty, Form, Input, Modal, Skeleton, Space, Table, Tag, Typography } from 'antd';
import { ReloadOutlined, ShoppingCartOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { tabelaHtml } from '@/componentes/impressao';
import { BarraFiltros, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarNumero } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { EstadoTag, rotuloEstado } from './comum/estados';
import { contemTexto } from './comum/lista';
import { numeroOuId, type EncomendaCliente, type LinhaEncomendaCliente, type PedidoCompra } from './comum/tipos';

/** Linhas que ainda podem entrar num pedido de compra (sem pedido activo e com quantidade pendente). */
export function linhasSelecionaveis(encomendas: EncomendaCliente[]): number[] {
  return encomendas.flatMap((e) => e.linhas.filter((l) => l.por_comprar && Number(l.pendente) > 0).map((l) => l.id));
}

/**
 * Compras › Encomendas de clientes (ecrã compras_encomendas_clientes): notas de encomenda (NE) de clientes
 * com quantidades por satisfazer; gera pedidos de compra a partir das linhas escolhidas.
 */
export default function EncomendasClientes() {
  const navegar = useNavigate();
  const { pode, utilizador } = useSessao();
  const [selecao, setSelecao] = useState<number[]>([]);
  const [pesquisa, setPesquisa] = useState('');
  const [gerar, setGerar] = useState(false);
  const [form] = Form.useForm<{ nome_requerente?: string; descricao?: string }>();
  const podeGerar = pode('compras_gerar_pedidos');

  const consulta = useQuery({ queryKey: ['compras', 'encomendas-clientes'], queryFn: () => obter<EncomendaCliente[]>('/compras/encomendas-clientes') });
  const accao = useAccao<PedidoCompra>({
    invalidar: [['compras']],
    aoSucesso: (p) => {
      setGerar(false);
      setSelecao([]);
      if (p?.id && pode('compras_pedidos_view')) navegar(`/m/compras/compras_pedidos/${p.id}`);
    },
    tituloErro: 'Não foi possível gerar o pedido',
  });

  const encomendas = useMemo(
    () => (consulta.data ?? []).filter((e) => contemTexto(pesquisa, e.numero_documento, e.cliente?.nome, ...e.linhas.map((l) => l.produto))),
    [consulta.data, pesquisa],
  );
  const selecionaveis = useMemo(() => new Set(linhasSelecionaveis(encomendas)), [encomendas]);
  const alternar = (id: number, marcado: boolean) => setSelecao((s) => (marcado ? [...s, id] : s.filter((x) => x !== id)));

  /** Mapa impresso: linhas agrupadas por nota de encomenda (cliente, quantidades, stock e pedido de compra). */
  const pedidoImpressao = () => {
    const linhas = encomendas.flatMap((e) => e.linhas.map((l) => ({ e, l })));
    return {
      titulo: 'Encomendas de clientes por satisfazer',
      filtros: [pesquisa && `Pesquisa: ${pesquisa}`, `${encomendas.length} encomenda(s)`],
      conteudo: tabelaHtml({
        colunas: [
          { titulo: 'Produto', valor: (x: (typeof linhas)[number]) => `${x.l.produto ?? `#${x.l.produto_id}`}${x.l.descricao ? ` — ${x.l.descricao}` : ''}`, quebrar: true },
          { titulo: 'Encomendado', valor: (x) => x.l.quantidade, formato: 'numero' },
          { titulo: 'Pendente', valor: (x) => x.l.pendente, formato: 'numero' },
          { titulo: 'Stock disponível', valor: (x) => x.l.stock_disponivel, formato: 'numero' },
          {
            titulo: 'Pedido de compra',
            valor: (x) =>
              x.l.pedido_compra
                ? `${numeroOuId(x.l.pedido_compra.numero_pedido, x.l.pedido_compra.id)} (${rotuloEstado(x.l.pedido_compra.estado)})`
                : x.l.por_comprar && Number(x.l.pendente) > 0
                  ? 'Por comprar'
                  : '',
          },
        ],
        linhas,
        agrupar: {
          chave: (x) => String(x.e.id),
          titulo: (_, ls) => {
            const e = ls[0].e;
            return `${e.numero_documento} · ${formatarData(e.data_emissao)} · ${e.cliente?.nome?.trim() ?? ''}${e.estado ? ` · ${rotuloEstado(e.estado)}` : ''}`;
          },
        },
      }),
    };
  };

  return (
    <>
      <CabecalhoPagina
        titulo="Encomendas de clientes"
        subtitulo="Notas de encomenda com quantidades por satisfazer e respectivos pedidos de compra"
        impressao={pedidoImpressao}
        impressaoDesactivada={!encomendas.length}
        accoes={
          <>
            <Button icon={<ReloadOutlined />} onClick={() => void consulta.refetch()} loading={consulta.isFetching}>
              Actualizar
            </Button>
            {podeGerar && (
              <Button
                type="primary"
                icon={<ShoppingCartOutlined />}
                disabled={selecao.length === 0}
                onClick={() => {
                  form.setFieldsValue({ nome_requerente: utilizador?.nome_completo || utilizador?.nome_utilizador, descricao: undefined });
                  setGerar(true);
                }}
              >
                Gerar pedido de compra ({selecao.length})
              </Button>
            )}
          </>
        }
      />
      <Card>
        <BarraFiltros
          accoes={
            podeGerar &&
            selecionaveis.size > 0 && (
              <Space wrap>
                <Button size="small" onClick={() => setSelecao([...selecionaveis])}>Marcar todas por comprar</Button>
                <Button size="small" onClick={() => setSelecao([])}>Limpar</Button>
              </Space>
            )
          }
        >
          <Input.Search placeholder="N.º, cliente ou produto" allowClear style={{ width: 320, maxWidth: '100%' }} onSearch={setPesquisa} />
        </BarraFiltros>
        {consulta.isLoading ? (
          <Skeleton active />
        ) : encomendas.length === 0 ? (
          <Empty description="Sem encomendas de clientes por satisfazer." />
        ) : (
          encomendas.map((e) => (
            <Card
              key={e.id}
              size="small"
              style={{ marginBottom: 12 }}
              title={
                <Space wrap>
                  <strong>{e.numero_documento}</strong>
                  <span>{formatarData(e.data_emissao)}</span>
                  <span>{e.cliente?.nome?.trim()}</span>
                  {e.estado && <EstadoTag estado={e.estado} />}
                </Space>
              }
            >
              <Table<LinhaEncomendaCliente>
                rowKey="id"
                size="small"
                pagination={false}
                dataSource={e.linhas}
                scroll={scrollTabela()}
                columns={[
                  {
                    title: '',
                    key: 'sel',
                    width: 40,
                    render: (_, l) => (podeGerar ? <Checkbox disabled={!selecionaveis.has(l.id)} checked={selecao.includes(l.id)} onChange={(ev) => alternar(l.id, ev.target.checked)} /> : null),
                  },
                  { title: 'Produto', render: (_, l) => <>{l.produto ?? `#${l.produto_id}`}{l.descricao && <Typography.Text type="secondary"> — {l.descricao}</Typography.Text>}</> },
                  { title: 'Encomendado', dataIndex: 'quantidade', align: 'right', render: formatarNumero },
                  { title: 'Pendente', dataIndex: 'pendente', align: 'right', render: formatarNumero },
                  { title: 'Stock disponível', dataIndex: 'stock_disponivel', align: 'right', responsive: ['md'], render: (v: string | null) => (v === null ? '—' : formatarNumero(v)) },
                  {
                    title: 'Pedido de compra',
                    render: (_, l) =>
                      l.pedido_compra ? (
                        <Space wrap>
                          <a onClick={() => pode('compras_pedidos_view') && navegar(`/m/compras/compras_pedidos/${l.pedido_compra?.id}`)}>{numeroOuId(l.pedido_compra.numero_pedido, l.pedido_compra.id)}</a>
                          <EstadoTag estado={l.pedido_compra.estado} />
                        </Space>
                      ) : l.por_comprar && Number(l.pendente) > 0 ? (
                        <Tag color="orange">Por comprar</Tag>
                      ) : (
                        '—'
                      ),
                  },
                ]}
              />
            </Card>
          ))
        )}
      </Card>
      <Modal width={larguraModal(560)} title="Gerar pedido de compra" open={gerar} onCancel={() => setGerar(false)} okText="Gerar pedido" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()}>
        <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: '/compras/encomendas-clientes/pedido', dados: { itens: selecao, nome_requerente: v.nome_requerente || undefined, descricao: v.descricao || undefined } })}>
          <Alert type="info" showIcon style={{ marginBottom: 16 }} message={`${selecao.length} linha(s) escolhida(s). O pedido é criado com as quantidades pendentes e segue para deliberação.`} />
          <Form.Item name="nome_requerente" label="Requerente" rules={[{ max: 255 }]}>
            <Input />
          </Form.Item>
          <Form.Item name="descricao" label="Descrição" rules={[{ max: 2000 }]}>
            <Input.TextArea rows={2} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}
