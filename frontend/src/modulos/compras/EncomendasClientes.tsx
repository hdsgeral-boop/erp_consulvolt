import { Alert, Button, Card, Checkbox, Empty, Form, Input, Modal, Segmented, Skeleton, Space, Table, Tag, Typography } from 'antd';
import { ReloadOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState, type Key } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { tabelaHtml } from '@/componentes/impressao';
import { BarraFiltros, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { TabelaComModos, type ColunaLegado } from '@/componentes/vistas';
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

/** Linha da tabela plana do legado (sales-needs-table): um item de encomenda de cliente. */
export interface ItemNecessidade {
  id: number;
  encomenda: EncomendaCliente;
  linha: LinhaEncomendaCliente;
  pendente: boolean;
}

/** Itens das encomendas em tabela plana; por omissão só os pendentes (por comprar), como no legado. */
export function itensNecessidades(encomendas: EncomendaCliente[], soPendentes: boolean): ItemNecessidade[] {
  return encomendas
    .flatMap((e) => e.linhas.map((l) => ({ id: l.id, encomenda: e, linha: l, pendente: l.por_comprar && Number(l.pendente) > 0 })))
    .filter((i) => !soPendentes || i.pendente);
}

/**
 * Compras › Encomendas Clientes (ecrã compras_encomendas_clientes): «Itens de Encomendas de Clientes (Necessidades)» do legado
 * em tabela plana (com «Integrar este Item» e «Gerar Pedido de Compra»), por omissão só os pendentes; a vista em cartões
 * por encomenda fica como alternativa.
 */
export default function EncomendasClientes() {
  const navegar = useNavigate();
  const { pode, utilizador } = useSessao();
  const [selecao, setSelecao] = useState<number[]>([]);
  const [pesquisa, setPesquisa] = useState('');
  const [soPendentes, setSoPendentes] = useState(true);
  const [vista, setVista] = useState<'tabela' | 'cartoes'>('tabela');
  const [gerar, setGerar] = useState<number[] | null>(null);
  const [form] = Form.useForm<{ nome_requerente?: string; descricao?: string }>();
  const podeGerar = pode('compras_gerar_pedidos');

  const consulta = useQuery({ queryKey: ['compras', 'encomendas-clientes'], queryFn: () => obter<EncomendaCliente[]>('/compras/encomendas-clientes') });
  const accao = useAccao<PedidoCompra>({
    invalidar: [['compras']],
    aoSucesso: (p) => {
      setGerar(null);
      setSelecao([]);
      if (p?.id && pode('compras_pedidos_view')) navegar(`/m/compras/compras_pedidos/${p.id}`);
    },
    tituloErro: 'Não foi possível gerar o pedido',
  });

  const encomendas = useMemo(
    () =>
      (consulta.data ?? [])
        .filter((e) => contemTexto(pesquisa, e.numero_documento, e.cliente?.nome, ...e.linhas.map((l) => l.produto)))
        .filter((e) => !soPendentes || e.linhas.some((l) => l.por_comprar && Number(l.pendente) > 0)),
    [consulta.data, pesquisa, soPendentes],
  );
  const itens = useMemo(() => itensNecessidades(encomendas, soPendentes), [encomendas, soPendentes]);
  const selecionaveis = useMemo(() => new Set(linhasSelecionaveis(encomendas)), [encomendas]);
  const alternar = (id: number, marcado: boolean) => setSelecao((s) => (marcado ? [...s, id] : s.filter((x) => x !== id)));
  const abrirGerar = (ids: number[]) => {
    form.setFieldsValue({ nome_requerente: utilizador?.nome_completo || utilizador?.nome_utilizador, descricao: undefined });
    setGerar(ids);
  };

  const colunas: ColunaLegado<ItemNecessidade>[] = [
    { title: 'Data', key: 'data', valorImpressao: (i) => formatarData(i.encomenda.data_emissao), render: (_, i) => formatarData(i.encomenda.data_emissao) },
    { title: 'Encomenda', key: 'encomenda', valorImpressao: (i) => i.encomenda.numero_documento, render: (_, i) => <strong>{i.encomenda.numero_documento}</strong> },
    { title: 'Cliente', key: 'cliente', valorImpressao: (i) => i.encomenda.cliente?.nome?.trim() ?? 'N/D', render: (_, i) => i.encomenda.cliente?.nome?.trim() ?? 'N/D' },
    {
      title: 'Produto', key: 'produto', valorImpressao: (i) => i.linha.produto ?? `#${i.linha.produto_id}`,
      render: (_, i) => <>{i.linha.produto ?? 'Item'}{i.linha.descricao && <Typography.Text type="secondary"> — {i.linha.descricao}</Typography.Text>}</>,
    },
    { title: 'Qtd. Solicitada', key: 'qtd', align: 'center', valorImpressao: (i) => formatarNumero(i.linha.quantidade), render: (_, i) => <strong>{formatarNumero(i.linha.quantidade)}</strong> },
    { title: 'Pendente', key: 'pendente', align: 'center', ocultaPorOmissao: true, render: (_, i) => formatarNumero(i.linha.pendente) },
    { title: 'Stock disponível', key: 'stock', align: 'right', ocultaPorOmissao: true, render: (_, i) => (i.linha.stock_disponivel === null ? '—' : formatarNumero(i.linha.stock_disponivel)) },
    {
      title: 'Status', key: 'estado', valorImpressao: (i) => rotuloEstado(i.encomenda.estado),
      render: (_, i) => (
        <Space wrap size={4}>
          <Tag>{(i.encomenda.estado ?? '—').toUpperCase()}</Tag>
          {i.linha.pedido_compra && (
            <a onClick={(ev) => { ev.stopPropagation(); if (pode('compras_pedidos_view')) navegar(`/m/compras/compras_pedidos/${i.linha.pedido_compra?.id}`); }}>
              {numeroOuId(i.linha.pedido_compra.numero_pedido, i.linha.pedido_compra.id)}
            </a>
          )}
        </Space>
      ),
    },
  ];

  /** Mapa impresso: linhas agrupadas por nota de encomenda (cliente, quantidades, stock e pedido de compra). */
  const pedidoImpressao = () => {
    const linhas = encomendas.flatMap((e) => e.linhas.map((l) => ({ e, l })));
    return {
      titulo: 'Itens de Encomendas de Clientes (Necessidades)',
      filtros: [pesquisa && `Pesquisa: ${pesquisa}`, soPendentes && 'Só pendentes', `${encomendas.length} encomenda(s)`],
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
      {/* título e «Gerar Pedido de Compra» dentro do cartão, por cima da tabela, como o legado (js/ui_compras_v2.js:729-733) */}
      <Card className="compras-cartao-lista">
        <CabecalhoPagina
          titulo="Itens de Encomendas de Clientes (Necessidades)"
          impressao={vista === 'cartoes' ? pedidoImpressao : undefined}
          impressaoDesactivada={!encomendas.length}
          accoes={
            <>
              <Segmented
                value={vista}
                onChange={(v) => setVista(v as 'tabela' | 'cartoes')}
                options={[{ value: 'tabela', label: 'Tabela' }, { value: 'cartoes', label: 'Por encomenda' }]}
              />
              <Button icon={<ReloadOutlined />} onClick={() => void consulta.refetch()} loading={consulta.isFetching}>
                Actualizar
              </Button>
              {podeGerar && (
                <Button type="primary" icon={<IconeFa nome="shopping-basket" />} disabled={selecao.length === 0} onClick={() => abrirGerar(selecao)}>
                  Gerar Pedido de Compra ({selecao.length})
                </Button>
              )}
            </>
          }
        />
        <BarraFiltros
          accoes={
            vista === 'cartoes' &&
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
          <Checkbox checked={soPendentes} onChange={(e) => setSoPendentes(e.target.checked)}>
            Só pendentes
          </Checkbox>
        </BarraFiltros>
        {vista === 'tabela' ? (
          <TabelaComModos<ItemNecessidade>
            rowKey="id"
            modos={false}
            loading={consulta.isLoading}
            dataSource={itens}
            columns={colunas}
            scroll={scrollTabela()}
            impressao={{ titulo: 'Itens de Encomendas de Clientes (Necessidades)', filtros: [pesquisa && `Pesquisa: ${pesquisa}`, soPendentes && 'Só pendentes'] }}
            vazio={{ icone: 'check-circle', titulo: 'Sem necessidades', texto: 'Não há itens de encomendas de clientes por satisfazer.' }}
            seleccao={
              podeGerar
                ? {
                    chaves: selecao,
                    aoMudar: (k: Key[]) => setSelecao(k.map(Number)),
                    bloquearLinha: (i) => (!selecionaveis.has(i.id) ? (i.linha.pedido_compra ? 'Já tem pedido de compra' : 'Sem quantidade pendente') : false),
                    ajuda: 'Marque os itens a integrar num pedido de compra.',
                  }
                : undefined
            }
            accoes={(i) => [
              {
                icone: 'shopping-basket', titulo: 'Integrar este Item', variante: 'primario', cheio: true, oculto: !podeGerar,
                desactivado: !selecionaveis.has(i.id) && (i.linha.pedido_compra ? 'Já tem pedido de compra' : 'Sem quantidade pendente'),
                onClick: () => abrirGerar([i.id]),
              },
            ]}
          />
        ) : consulta.isLoading ? (
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
                dataSource={soPendentes ? e.linhas.filter((l) => l.por_comprar && Number(l.pendente) > 0) : e.linhas}
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
      <Modal width={larguraModal(560)} title="Gerar Pedido de Compra" open={!!gerar} onCancel={() => setGerar(null)} okText="Gerar pedido" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()}>
        <Form form={form} layout="vertical" onFinish={(v) => gerar && accao.mutate({ url: '/compras/encomendas-clientes/pedido', dados: { itens: gerar, nome_requerente: v.nome_requerente || undefined, descricao: v.descricao || undefined } })}>
          <Alert type="info" showIcon style={{ marginBottom: 16 }} message={`${gerar?.length ?? 0} linha(s) escolhida(s). O pedido é criado com as quantidades pendentes e segue para deliberação.`} />
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
