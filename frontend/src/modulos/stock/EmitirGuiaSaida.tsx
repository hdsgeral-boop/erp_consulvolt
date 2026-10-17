import { Button, Card, Col, DatePicker, Empty, Input, InputNumber, Row, Select, Space, Table, Tooltip, Typography, message } from 'antd';
import { ArrowLeftOutlined, DeleteOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos';
import { scrollTabela } from '@/componentes/responsivo';
import { SeletorProjecto } from '@/modulos/activos/comum/componentes';
import { useArmazens } from '@/modulos/compras/comum/referencias';
import { SeletorArmazem, SeletorProduto, SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarNumero } from '@/utilitarios/formatacao';
import type { EncomendaVinculada, GuiaSaida } from './comum/tipos';

/** Tipos de saída do legado (gs-type, js/ui_warehouse.js:713-717). */
export type TipoSaida = 'VENDA' | 'BACK_TO_BACK' | 'CONSUMO';
export const TIPOS_SAIDA: { value: TipoSaida; label: string }[] = [
  { value: 'VENDA', label: 'Venda (Entrega a Cliente)' },
  { value: 'BACK_TO_BACK', label: 'Saída de Encomenda Fechada (Cliente)' },
  { value: 'CONSUMO', label: 'Consumo Interno' },
];

export interface LinhaSaida {
  chave: number;
  produto_id?: number;
  quantidade?: number;
  /** Linha da encomenda (NE) de origem: a GR converte-a (actualiza o entregue). */
  item_origem_id?: number;
  /** Máximo por entregar da linha da encomenda. */
  maximo?: number;
}

let sequencia = 0;
const novaLinha = (l: Omit<LinhaSaida, 'chave'> = { quantidade: 1 }): LinhaSaida => ({ chave: ++sequencia, ...l });

/** Linhas da encomenda com quantidade por entregar (loadGSFromSaleDirect: os artigos da encomenda, aqui só o que falta). */
export function linhasDaEncomenda(ne: Pick<EncomendaVinculada, 'linhas'>): LinhaSaida[] {
  return ne.linhas
    .filter((l) => Number(l.por_entregar) > 0)
    .map((l) => novaLinha({ produto_id: l.produto_id, quantidade: Number(l.por_entregar), item_origem_id: l.id, maximo: Number(l.por_entregar) }));
}

/**
 * Validação de «Emitir e Baixar Stock» com as mensagens do legado (saveGuiaSaida, js/ui_warehouse.js:884-898).
 * Devolve a primeira falha ou null. O servidor volta a verificar tudo (stock com bloqueio, linhas da encomenda).
 */
export function validarGuia(v: {
  tipo: TipoSaida;
  armazem?: number;
  cliente?: number;
  encomenda?: number;
  area?: string;
  linhas: LinhaSaida[];
  stock: Record<string, string>;
  movimentaStock: (produto: number) => boolean;
}): string | null {
  if (!v.armazem) return 'Selecione o Armazém de saída.';
  if (v.tipo === 'BACK_TO_BACK' && !v.encomenda) return 'Selecione a encomenda satisfeita a expedir.';
  if (v.tipo !== 'CONSUMO' && !v.cliente && !v.encomenda) return 'Selecione o cliente ou a encomenda de origem.';
  if (v.tipo === 'CONSUMO' && !v.area?.trim()) return 'Indique a área de destino.';
  const validas = v.linhas.filter((l) => l.produto_id && l.quantidade && l.quantidade > 0);
  if (!validas.length) return 'Adicione pelo menos um item.';
  const porProduto = new Map<number, number>();
  for (const l of validas) porProduto.set(l.produto_id!, (porProduto.get(l.produto_id!) ?? 0) + Number(l.quantidade));
  for (const [p, q] of porProduto) {
    if (v.movimentaStock(p) && q > Number(v.stock[String(p)] ?? 0) + 1e-9) return 'Quantidade indisponível no Armazém selecionado.';
  }
  return null;
}

/**
 * «Emitir Guia de Saída (Entrega/Consumo)» do legado (showGuiaSaidaForm, js/ui_warehouse.js:603-760): painel das encomendas
 * vinculadas a compras (back-to-back), armazém, tipo de saída, projecto, cliente / encomendas satisfeitas / área, data e
 * «Itens a Sair» com o stock disponível no armazém.
 * ADR-043: a Venda e a Saída de Encomenda Fechada emitem a guia de remessa (GR) das Vendas — ligada à encomenda, que fica com
 * o entregue actualizado — e não uma guia do armazém mais a «GR LOG» espelho do legado; o Consumo Interno é a guia de consumo.
 * Abrir com ?encomenda=ID (botão «Gerar Guia de Saída para Cliente» das recepções) preenche o formulário com essa encomenda.
 */
export default function EmitirGuiaSaida() {
  const navegar = useNavigate();
  const [params] = useSearchParams();
  const { pode } = useSessao();
  const podeVender = pode('vendas_fat_emitir');
  const armazens = useArmazens();
  const [tipo, setTipo] = useState<TipoSaida>(podeVender ? 'VENDA' : 'CONSUMO');
  const [armazem, setArmazem] = useState<number>();
  const [cliente, setCliente] = useState<number>();
  const [encomenda, setEncomenda] = useState<EncomendaVinculada | null>(null);
  const [projeto, setProjeto] = useState<number>();
  const [area, setArea] = useState('');
  const [data, setData] = useState<Dayjs | null>(dayjs());
  const [linhas, setLinhas] = useState<LinhaSaida[]>(() => [novaLinha()]);
  const aplicada = useRef(false);

  const encomendas = useQuery({
    queryKey: ['logistica', 'guias', 'encomendas-clientes'],
    queryFn: () => obter<EncomendaVinculada[]>('/logistica/guias-saida/encomendas-clientes'),
    enabled: podeVender,
  });
  const stock = useQuery({
    queryKey: ['logistica', 'guias', 'stock-armazem', armazem],
    queryFn: () => obter<Record<string, string>>('/logistica/guias-saida/stock-armazem', { armazem_id: armazem }),
    enabled: !!armazem,
  });
  const ligadas = encomendas.data ?? [];
  const satisfeitas = useMemo(() => ligadas.filter((e) => e.satisfeita), [ligadas]);
  // serviços da encomenda não têm stock; os artigos do catálogo escolhidos aqui são só os de stock (apenasStock)
  const semStock = useMemo(() => new Set(ligadas.flatMap((e) => e.linhas.filter((l) => !l.movimenta_stock).map((l) => l.produto_id))), [ligadas]);

  // armazém de saída: o predefinido (o legado: o primeiro da lista)
  useEffect(() => {
    if (armazem || !armazens.data?.length) return;
    setArmazem((armazens.data.find((a) => a.predefinido) ?? armazens.data[0]).id);
  }, [armazens.data, armazem]);

  const carregarEncomenda = (ne: EncomendaVinculada, novoTipo: TipoSaida) => {
    setTipo(novoTipo);
    setEncomenda(ne);
    setCliente(ne.cliente_id);
    setProjeto(ne.projeto_id ?? undefined);
    const ls = linhasDaEncomenda(ne);
    setLinhas(ls.length ? ls : [novaLinha()]);
  };
  const largarEncomenda = () => {
    setEncomenda(null);
    setCliente(undefined);
    setProjeto(undefined);
    setLinhas([novaLinha()]);
  };

  // ?encomenda=ID — «Gerar Guia de Saída para Cliente (Back-to-Back)» das recepções validadas
  useEffect(() => {
    const id = Number(params.get('encomenda'));
    if (aplicada.current || !id || !encomendas.data) return;
    aplicada.current = true;
    const ne = encomendas.data.find((e) => e.id === id);
    if (ne) carregarEncomenda(ne, ne.satisfeita ? 'BACK_TO_BACK' : 'VENDA');
    else message.warning('A encomenda do cliente já não tem quantidades por entregar.');
  }, [encomendas.data, params]);

  const mudarTipo = (t: TipoSaida) => {
    setTipo(t);
    if (t === 'CONSUMO' && encomenda) largarEncomenda();
    if (t === 'BACK_TO_BACK' && encomenda && !encomenda.satisfeita) largarEncomenda();
  };

  const emitir = useAccao<GuiaSaida>({
    invalidar: [['logistica'], ['vendas']],
    aoSucesso: (g, pedido) => navegar(pedido.url === '/logistica/guias-saida' ? `../${g.id}` : '..'),
    tituloErro: 'Não foi possível emitir a guia',
  });

  const submeter = () => {
    const erro = validarGuia({ tipo, armazem, cliente, encomenda: encomenda?.id, area, linhas, stock: stock.data ?? {}, movimentaStock: (p) => !semStock.has(p) });
    if (erro) {
      message.warning(erro);
      return;
    }
    const itens = linhas.filter((l) => l.produto_id && l.quantidade);
    if (tipo === 'CONSUMO') {
      emitir.mutate({
        url: '/logistica/guias-saida',
        dados: { armazem_id: armazem, data: dataApi(data), area_rececao: area.trim(), linhas: itens.map((l) => ({ produto_id: l.produto_id, quantidade: l.quantidade })) },
      });
      return;
    }
    emitir.mutate({
      url: '/logistica/guias-saida/remessa',
      dados: {
        tipo,
        armazem_id: armazem,
        data: dataApi(data),
        cliente_id: cliente,
        encomenda_id: encomenda?.id,
        projeto_id: !encomenda && tipo === 'VENDA' ? projeto : undefined,
        linhas: itens.map((l) => ({ produto_id: l.produto_id, quantidade: l.quantidade, item_origem_id: l.item_origem_id })),
      },
    });
  };

  const mudarLinha = (chave: number, alt: Partial<LinhaSaida>) => setLinhas((ls) => ls.map((l) => (l.chave === chave ? { ...l, ...alt } : l)));

  return (
    <>
      <CabecalhoPagina titulo="Emitir Guia de Saída (Entrega/Consumo)" accoes={<Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>} />
      <Card style={{ borderTop: '4px solid var(--ant-color-warning, #f59e0b)', marginBottom: 16 }}>
        {podeVender && ligadas.length > 0 && (
          <div data-testid="painel-back-to-back" style={{ marginBottom: 24, padding: 16, background: '#fff7ed', border: '1px solid #ffedd5', borderRadius: 12 }}>
            <Typography.Title level={5} style={{ margin: '0 0 12px', fontSize: '0.85rem', color: '#9a3412', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
              <IconeFa nome="link" /> Encomendas Vinculadas a Compras (Back-to-Back)
            </Typography.Title>
            <div style={{ display: 'flex', gap: 16, overflowX: 'auto', paddingBottom: 8 }}>
              {ligadas.map((e) => (
                <div
                  key={e.id}
                  role="button"
                  tabIndex={0}
                  aria-label={`Expedir agora a encomenda ${e.numero_documento}`}
                  onClick={() => carregarEncomenda(e, 'VENDA')}
                  onKeyDown={(ev) => (ev.key === 'Enter' || ev.key === ' ') && carregarEncomenda(e, 'VENDA')}
                  style={{
                    flex: '0 0 260px', padding: 12, background: 'var(--ant-color-bg-container, #fff)', borderRadius: 8, cursor: 'pointer',
                    border: `1px solid ${encomenda?.id === e.id ? '#f97316' : '#fed7aa'}`,
                  }}
                >
                  <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6 }}>
                    <strong style={{ fontSize: '0.85rem' }}>{e.numero_documento}</strong>
                    <span style={{ fontSize: '0.75rem', color: '#94a3b8' }}>{e.data_emissao ? formatarData(e.data_emissao) : ''}</span>
                  </div>
                  <div style={{ fontSize: '0.8rem', color: '#475569', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{e.cliente?.nome?.trim() || 'Cliente N/D'}</div>
                  <div style={{ marginTop: 10, textAlign: 'right' }}>
                    <span style={{ fontSize: '0.7rem', color: '#ea580c', fontWeight: 600 }}>
                      EXPEDIR AGORA <IconeFa nome="truck-loading" />
                    </span>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        <Row gutter={16} align="bottom">
          <Col xs={24} md={6}>
            <Field rotulo="Armazém de Saída">
              <SeletorArmazem aria-label="Armazém de Saída" value={armazem} onChange={setArmazem} style={{ width: '100%' }} />
            </Field>
          </Col>
          <Col xs={24} md={6}>
            <Field rotulo="Tipo de Saída">
              <Select<TipoSaida>
                aria-label="Tipo de Saída"
                value={tipo}
                onChange={mudarTipo}
                style={{ width: '100%' }}
                // emitir a GR é das Vendas: sem vendas_fat_emitir só o consumo interno
                options={TIPOS_SAIDA.map((t) => ({ ...t, disabled: t.value !== 'CONSUMO' && !podeVender }))}
              />
            </Field>
          </Col>
          {tipo === 'VENDA' && (
            <Col xs={24} md={4}>
              <Field rotulo="Projecto/Obra">
                <SeletorProjecto aria-label="Projecto/Obra" allowClear value={projeto} onChange={setProjeto} disabled={!!encomenda} style={{ width: '100%' }} />
              </Field>
            </Col>
          )}
          <Col xs={24} md={tipo === 'VENDA' ? 5 : 9}>
            {tipo === 'VENDA' && (
              <Field rotulo="Cliente / Entidade">
                {encomenda ? (
                  <Space.Compact style={{ width: '100%' }}>
                    <Input aria-label="Cliente / Entidade" readOnly value={`${encomenda.cliente?.nome?.trim() ?? 'Cliente'} — ${encomenda.numero_documento}`} />
                    <Tooltip title="Desligar a encomenda">
                      <Button aria-label="Desligar a encomenda" icon={<IconeFa nome="times" />} onClick={largarEncomenda} />
                    </Tooltip>
                  </Space.Compact>
                ) : (
                  <SeletorTerceiro aria-label="Cliente / Entidade" papel="CLIENTE" value={cliente} onChange={setCliente} style={{ width: '100%' }} placeholder="-- Selecione o Cliente --" />
                )}
              </Field>
            )}
            {tipo === 'BACK_TO_BACK' && (
              <Field rotulo="Encomendas Satisfeitas">
                <Select<number>
                  aria-label="Encomendas Satisfeitas"
                  showSearch
                  optionFilterProp="label"
                  placeholder="-- Selecione a Encomenda --"
                  value={encomenda?.id}
                  loading={encomendas.isLoading}
                  notFoundContent={<Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Nenhuma encomenda com a compra recebida" />}
                  onChange={(id) => {
                    const ne = satisfeitas.find((e) => e.id === id);
                    if (ne) carregarEncomenda(ne, 'BACK_TO_BACK');
                  }}
                  options={satisfeitas.map((e) => ({ value: e.id, label: `${e.numero_documento} - ${e.cliente?.nome?.trim() || 'Cliente'}` }))}
                  style={{ width: '100%' }}
                />
              </Field>
            )}
            {tipo === 'CONSUMO' && (
              <Field rotulo="Área de Destino">
                <Input aria-label="Área de Destino" placeholder="Destino..." maxLength={255} value={area} onChange={(e) => setArea(e.target.value)} />
              </Field>
            )}
          </Col>
          <Col xs={24} md={3}>
            <Field rotulo="Data">
              <DatePicker aria-label="Data" format="DD/MM/YYYY" value={data} onChange={setData} allowClear={false} style={{ width: '100%' }} />
            </Field>
          </Col>
        </Row>

        <Card
          size="small"
          title="Itens a Sair"
          style={{ marginBottom: 16 }}
          extra={
            <Tooltip title={encomenda ? 'Os artigos vêm da encomenda; outros artigos saem numa guia à parte.' : undefined}>
              <Button size="small" disabled={!!encomenda} onClick={() => setLinhas((ls) => [...ls, novaLinha()])}>
                + Item
              </Button>
            </Tooltip>
          }
        >
          <Table<LinhaSaida>
            rowKey="chave"
            size="small"
            pagination={false}
            scroll={scrollTabela()}
            dataSource={linhas}
            locale={{ emptyText: 'Adicione os itens a sair.' }}
            columns={[
              {
                title: 'Produto',
                key: 'produto',
                render: (_, l) => (
                  <SeletorProduto
                    aria-label="Produto"
                    apenasStock={!l.item_origem_id}
                    disabled={!!l.item_origem_id}
                    value={l.produto_id}
                    onChange={(id: number) => mudarLinha(l.chave, { produto_id: id })}
                    placeholder="-- Selecione --"
                    style={{ width: '100%', minWidth: 220 }}
                  />
                ),
              },
              {
                title: 'Stock Disp.',
                key: 'stock',
                width: 120,
                align: 'center',
                render: (_, l) => (l.produto_id ? (semStock.has(l.produto_id) ? '—' : formatarNumero(stock.data?.[String(l.produto_id)] ?? 0)) : 0),
              },
              {
                title: 'Qtd Saída',
                key: 'quantidade',
                width: 130,
                render: (_, l) => (
                  <InputNumber
                    aria-label="Qtd Saída"
                    min={0.001}
                    max={l.maximo}
                    step={1}
                    value={l.quantidade}
                    onChange={(q) => mudarLinha(l.chave, { quantidade: q ?? undefined })}
                    style={{ width: '100%' }}
                  />
                ),
              },
              {
                title: '',
                key: 'remover',
                width: 50,
                render: (_, l) => (
                  <Button danger size="small" aria-label="Remover item" icon={<DeleteOutlined />} onClick={() => setLinhas((ls) => ls.filter((x) => x.chave !== l.chave))} />
                ),
              },
            ]}
          />
          {encomenda && (
            <Typography.Text type="secondary" style={{ display: 'block', marginTop: 8, fontSize: '0.8rem' }}>
              Guia de remessa da encomenda {encomenda.numero_documento}: a encomenda fica com as quantidades entregues actualizadas.
            </Typography.Text>
          )}
        </Card>

        <div style={{ textAlign: 'right' }}>
          <Space wrap>
            <Button onClick={() => navegar('..')}>Cancelar</Button>
            <Button type="primary" icon={<IconeFa nome="check" />} loading={emitir.isPending} onClick={submeter}>
              Emitir e Baixar Stock
            </Button>
          </Space>
        </div>
      </Card>
    </>
  );
}

function Field({ rotulo, children }: { rotulo: string; children: ReactNode }) {
  return (
    <div style={{ marginBottom: 16 }}>
      <div style={{ marginBottom: 4 }}>
        <Typography.Text strong>{rotulo}</Typography.Text>
      </div>
      {children}
    </div>
  );
}
