import { Alert, Button, Card, Col, Divider, Empty, Flex, Input, InputNumber, List, Modal, Result, Row, Select, Space, Statistic, Typography, type GetRef, type InputRef } from 'antd';
import { ClearOutlined, DeleteOutlined, MinusOutlined, PlusOutlined, PrinterOutlined, SearchOutlined, ShoppingCartOutlined } from '@ant-design/icons';
import { useMemo, useRef, useState } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import { useAtalhos } from '../comum/atalhos';
import {
  adicionarProduto,
  alterarPreco,
  alterarQuantidade,
  exigeDesconto,
  formatarCentimos,
  linhasParaApi,
  pagamentosParaApi,
  removerLinha,
  resumirPagamentos,
  totaisCarrinho,
  totalUnidades,
  type ItemCarrinho,
  type Pagamento,
} from '../comum/calculos';
import { useCatalogoPOS, useCategoriasPOS } from '../comum/dados';
import { htmlTalaoVenda, imprimirHtml, lerPreferencias, reimprimir, useCabecalhoTalao } from '../comum/impressao';
import { acrescentarPagamento, meiosActivos, PainelPagamentos } from '../comum/PainelPagamentos';
import { filtrarProdutos, lerCodigo, produtosVendaveis } from '../comum/produtos';
import type { ProdutoPOS, Terminal, VendaEmitida } from '../comum/tipos';

const MAX_GRELHA = 48;

/**
 * Venda rápida: pesquisa (ou leitor de códigos: «3*COD»), grelha de produtos, carrinho com quantidades, desconto global
 * (com pos_desconto), pagamento misto e emissão da factura-recibo (POST /pos/sessoes/{id}/vendas).
 * Atalhos: F2 pesquisa · F4 cliente · F8 desconto · F9 cobrar · Esc limpa a pesquisa.
 */
export function PainelVenda({ terminal, sessaoId }: { terminal: Terminal; sessaoId: number }) {
  const { pode, empresa } = useSessao();
  const cabecalho = useCabecalhoTalao();
  const catalogo = useCatalogoPOS();
  const categorias = useCategoriasPOS();
  const [pesquisa, setPesquisa] = useState('');
  const [categoria, setCategoria] = useState<number | null>(null);
  const [limite, setLimite] = useState(MAX_GRELHA);
  const [carrinho, setCarrinho] = useState<ItemCarrinho[]>([]);
  const [desconto, setDesconto] = useState<number>(0);
  const [cliente, setCliente] = useState<number | undefined>();
  const [cobrar, setCobrar] = useState(false);
  const [pagamentos, setPagamentos] = useState<Pagamento[]>([]);
  const [observacoes, setObservacoes] = useState('');
  const [emitida, setEmitida] = useState<VendaEmitida | null>(null);
  const refPesquisa = useRef<InputRef>(null);
  const refDesconto = useRef<GetRef<typeof InputNumber>>(null);
  const refCliente = useRef<HTMLDivElement>(null);

  const podeDesconto = pode('pos_desconto');
  const meios = meiosActivos(terminal.meios_pagamento);
  const produtos = useMemo(() => produtosVendaveis(catalogo.data ?? []), [catalogo.data]);
  const visiveis = useMemo(() => filtrarProdutos(produtos, pesquisa, categoria), [produtos, pesquisa, categoria]);
  const nomes = useMemo(() => new Map(produtos.map((p) => [p.id, p.nome])), [produtos]);
  const totais = totaisCarrinho(carrinho, desconto);
  const resumo = resumirPagamentos(pagamentos, totais.total);

  const imprimir = (v: VendaEmitida, manual = false) => {
    const p = lerPreferencias(empresa?.id);
    const html = htmlTalaoVenda(v, cabecalho({ terminal: `${terminal.codigo} — ${terminal.nome}` }), p, nomes);
    if (manual) reimprimir(html, p);
    else imprimirHtml(html);
  };

  const vender = useAccao<VendaEmitida>({
    invalidar: [['pos'], ['logistica', 'stock']],
    tituloErro: 'Não foi possível registar a venda',
    aoSucesso: (v) => {
      setEmitida(v);
      setCobrar(false);
      setCarrinho([]);
      setDesconto(0);
      setCliente(undefined);
      setPagamentos([]);
      setObservacoes('');
      if (lerPreferencias(empresa?.id).automatico) imprimir(v);
    },
  });

  const acrescentar = (p: ProdutoPOS, q = 1) => {
    setCarrinho((c) => adicionarProduto(c, p, q));
  };

  const lerPesquisa = () => {
    const r = lerCodigo(produtos, pesquisa);
    if (r) {
      acrescentar(r.produto, r.quantidade);
      setPesquisa('');
    }
  };

  const abrirCobranca = () => {
    if (!carrinho.length || vender.isPending) return;
    const numerario = meios.find((m) => m.tipo === 'NUMERARIO') ?? meios[0];
    setPagamentos(numerario ? acrescentarPagamento([], numerario, totais.total) : []);
    setCobrar(true);
  };

  const emitir = () => {
    if (!resumo.valido || vender.isPending) return;
    vender.mutate({
      url: `/pos/sessoes/${sessaoId}/vendas`,
      dados: {
        cliente_id: cliente,
        percentagem_desconto: desconto || undefined,
        observacoes: observacoes.trim() || undefined,
        linhas: linhasParaApi(carrinho),
        pagamentos: pagamentosParaApi(pagamentos),
      },
    });
  };

  useAtalhos(
    {
      F2: () => refPesquisa.current?.focus(),
      F4: () => refCliente.current?.querySelector('input')?.focus(),
      F8: () => podeDesconto && refDesconto.current?.focus(),
      F9: abrirCobranca,
      Escape: () => setPesquisa(''),
    },
    !cobrar && !emitida,
  );
  useAtalhos({ F9: emitir }, cobrar);

  const editarPreco = (i: ItemCarrinho) => {
    let valor = i.preco / 100;
    Modal.confirm({
      title: `Preço de ${i.nome}`,
      icon: null,
      content: (
        <InputNumber<number> autoFocus min={0} precision={2} decimalSeparator="," style={{ width: '100%' }} defaultValue={valor} suffix="Kz (c/ IVA)" onChange={(v) => (valor = v ?? 0)} />
      ),
      okText: 'Aplicar',
      cancelText: 'Cancelar',
      onOk: () => setCarrinho((c) => alterarPreco(c, i.produto_id, Math.round(valor * 100))),
    });
  };

  return (
    <Row gutter={[16, 16]}>
      <Col xs={24} lg={14} xl={15}>
        <Card size="small">
          <Flex gap={8} wrap style={{ marginBottom: 12 }}>
            <Input
              ref={refPesquisa}
              size="large"
              autoFocus
              allowClear
              prefix={<SearchOutlined />}
              placeholder="Código ou nome (F2) · Enter adiciona · 3*CÓDIGO para quantidade"
              style={{ flex: '1 1 240px', minWidth: 0 }}
              value={pesquisa}
              onChange={(e) => {
                setPesquisa(e.target.value);
                setLimite(MAX_GRELHA);
              }}
              onPressEnter={lerPesquisa}
            />
            {categorias.data && categorias.data.length > 0 && (
              <Select<number>
                size="large"
                allowClear
                placeholder="Categoria"
                style={{ flex: '1 1 160px', maxWidth: 260 }}
                value={categoria ?? undefined}
                onChange={(v) => setCategoria(v ?? null)}
                options={categorias.data.map((c) => ({ value: c.id, label: c.nome }))}
              />
            )}
          </Flex>
          {catalogo.isError && <Alert type="error" showIcon message="Não foi possível carregar o catálogo de produtos." />}
          {!catalogo.isLoading && visiveis.length === 0 ? (
            <Empty description="Nenhum produto encontrado" />
          ) : (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(130px, 1fr))', gap: 8 }}>
              {visiveis.slice(0, limite).map((p) => (
                <Button
                  key={p.id}
                  onClick={() => acrescentar(p)}
                  style={{ height: 88, whiteSpace: 'normal', display: 'flex', flexDirection: 'column', alignItems: 'flex-start', justifyContent: 'space-between', textAlign: 'left', padding: 8 }}
                  title={p.nome}
                >
                  <Typography.Text strong ellipsis={{ tooltip: false }} style={{ width: '100%', fontSize: 13, lineHeight: 1.2 }}>
                    {p.nome}
                  </Typography.Text>
                  <Flex justify="space-between" style={{ width: '100%' }}>
                    <Typography.Text type="secondary" style={{ fontSize: 11 }}>
                      {p.codigo?.trim()}
                    </Typography.Text>
                    <Typography.Text style={{ fontSize: 13 }}>{formatarKz(p.preco_unitario)}</Typography.Text>
                  </Flex>
                </Button>
              ))}
            </div>
          )}
          {visiveis.length > limite && (
            <Flex justify="center" style={{ marginTop: 12 }}>
              <Button onClick={() => setLimite((l) => l + MAX_GRELHA)}>Mostrar mais ({visiveis.length - limite})</Button>
            </Flex>
          )}
        </Card>
      </Col>

      <Col xs={24} lg={10} xl={9}>
        <Card
          size="small"
          title={
            <Space>
              <ShoppingCartOutlined /> Carrinho · {totalUnidades(carrinho)} un.
            </Space>
          }
          extra={
            <Button type="text" icon={<ClearOutlined />} disabled={!carrinho.length} onClick={() => Modal.confirm({ title: 'Limpar o carrinho?', okText: 'Limpar', cancelText: 'Cancelar', onOk: () => { setCarrinho([]); setDesconto(0); } })}>
              Limpar
            </Button>
          }
        >
          <List
            size="small"
            dataSource={carrinho}
            locale={{ emptyText: <Empty description="Toque num produto ou leia um código" image={Empty.PRESENTED_IMAGE_SIMPLE} /> }}
            style={{ maxHeight: 360, overflowY: 'auto' }}
            renderItem={(i) => (
              <List.Item style={{ paddingInline: 0 }}>
                <Flex vertical style={{ width: '100%' }} gap={4}>
                  <Flex justify="space-between" gap={8}>
                    <Typography.Text strong style={{ flex: 1 }}>
                      {i.nome}
                    </Typography.Text>
                    <Typography.Text strong>{formatarCentimos(Math.round(i.quantidade * i.preco))}</Typography.Text>
                  </Flex>
                  <Flex justify="space-between" align="center" gap={8}>
                    <Space.Compact>
                      <Button icon={<MinusOutlined />} aria-label="Menos" onClick={() => setCarrinho((c) => alterarQuantidade(c, i.produto_id, i.quantidade - 1))} />
                      <InputNumber<number>
                        aria-label={`Quantidade ${i.nome}`}
                        min={0}
                        precision={3}
                        controls={false}
                        style={{ width: 70, textAlign: 'center' }}
                        value={i.quantidade}
                        onChange={(v) => v !== null && setCarrinho((c) => alterarQuantidade(c, i.produto_id, v))}
                      />
                      <Button icon={<PlusOutlined />} aria-label="Mais" onClick={() => setCarrinho((c) => alterarQuantidade(c, i.produto_id, i.quantidade + 1))} />
                    </Space.Compact>
                    <Button type="link" size="small" disabled={!podeDesconto} onClick={() => editarPreco(i)} title={podeDesconto ? 'Alterar o preço' : 'Sem permissão para alterar preços'}>
                      × {formatarCentimos(i.preco)}
                      {i.preco !== i.preco_catalogo && ' *'}
                    </Button>
                    <Button type="text" danger icon={<DeleteOutlined />} aria-label="Retirar" onClick={() => setCarrinho((c) => removerLinha(c, i.produto_id))} />
                  </Flex>
                </Flex>
              </List.Item>
            )}
          />
          <Divider style={{ margin: '8px 0' }} />
          <Flex vertical gap={8}>
            <div ref={refCliente}>
              <SeletorTerceiro
                papel="CLIENTE"
                value={cliente}
                onChange={(v) => setCliente(v)}
                placeholder={terminal.cliente_padrao_id ? 'Cliente (F4) — padrão do terminal' : 'Cliente (F4) — Consumidor Final'}
                style={{ width: '100%' }}
              />
            </div>
            {podeDesconto && (
              <InputNumber<number>
                ref={refDesconto}
                aria-label="Desconto global"
                min={0}
                max={100}
                precision={2}
                decimalSeparator=","
                prefix="Desconto (F8)"
                suffix="%"
                style={{ width: '100%' }}
                value={desconto || null}
                onChange={(v) => setDesconto(v ?? 0)}
              />
            )}
            {exigeDesconto(carrinho, desconto) && !podeDesconto && <Alert type="warning" showIcon message="A venda tem preços alterados: precisa da permissão de descontos." />}
          </Flex>
          <Divider style={{ margin: '8px 0' }} />
          <Flex justify="space-between">
            <Typography.Text type="secondary">Subtotal</Typography.Text>
            <Typography.Text>{formatarCentimos(totais.bruto)}</Typography.Text>
          </Flex>
          {totais.desconto !== 0 && (
            <Flex justify="space-between">
              <Typography.Text type="secondary">Desconto</Typography.Text>
              <Typography.Text>-{formatarCentimos(totais.desconto)}</Typography.Text>
            </Flex>
          )}
          <Flex justify="space-between">
            <Typography.Text type="secondary">Base · IVA</Typography.Text>
            <Typography.Text>
              {formatarCentimos(totais.liquido)} · {formatarCentimos(totais.imposto)}
            </Typography.Text>
          </Flex>
          <Flex justify="space-between" align="center" wrap gap={8} style={{ marginTop: 8 }}>
            <Statistic title="Total a pagar" value={formatarCentimos(totais.total)} suffix="Kz" valueStyle={{ fontSize: 30, fontWeight: 600 }} />
            <Button type="primary" size="large" style={{ height: 64, minWidth: 150, fontSize: 18 }} disabled={!carrinho.length} onClick={abrirCobranca}>
              Cobrar (F9)
            </Button>
          </Flex>
        </Card>
      </Col>

      <Modal
        open={cobrar}
        onCancel={() => setCobrar(false)}
        title={`Pagamento · ${formatarCentimos(totais.total)} Kz`}
        width={larguraModal(760)}
        maskClosable={false}
        destroyOnHidden
        footer={[
          <Button key="c" size="large" onClick={() => setCobrar(false)}>
            Voltar
          </Button>,
          <Button key="e" size="large" type="primary" loading={vender.isPending} disabled={!resumo.valido} onClick={emitir}>
            Emitir factura-recibo (F9)
          </Button>,
        ]}
      >
        <PainelPagamentos grande meios={meios} total={totais.total} pagamentos={pagamentos} onChange={setPagamentos} desactivado={vender.isPending} />
        <Input.TextArea style={{ marginTop: 12 }} rows={2} maxLength={2000} placeholder="Observações (opcional)" value={observacoes} onChange={(e) => setObservacoes(e.target.value)} />
        <Typography.Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>
          Os valores são uma estimativa: o servidor recalcula o total, numera e sela a factura-recibo.
        </Typography.Paragraph>
      </Modal>

      <Modal open={!!emitida} onCancel={() => setEmitida(null)} footer={null} width={larguraModal(520)} destroyOnHidden>
        {emitida && (
          <Result
            status="success"
            title={`Factura-recibo ${emitida.numero_documento}`}
            subTitle={`Total ${formatarKz(emitida.total_bruto)} Kz`}
            extra={[
              <Statistic key="t" title="Troco" value={formatarKz(emitida.pos_troco ?? '0')} suffix="Kz" valueStyle={{ fontSize: 40, color: '#3f8600' }} style={{ marginBottom: 16 }} />,
              <Flex key="b" gap={8} justify="center" wrap>
                <Button size="large" icon={<PrinterOutlined />} onClick={() => imprimir(emitida, true)}>
                  Imprimir talão
                </Button>
                <Button
                  size="large"
                  type="primary"
                  autoFocus
                  onClick={() => {
                    setEmitida(null);
                    window.setTimeout(() => refPesquisa.current?.focus(), 50);
                  }}
                >
                  Nova venda
                </Button>
              </Flex>,
            ]}
          />
        )}
      </Modal>
    </Row>
  );
}
