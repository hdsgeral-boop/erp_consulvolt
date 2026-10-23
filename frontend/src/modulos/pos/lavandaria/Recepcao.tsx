import { Alert, Avatar, Button, Card, Checkbox, Col, DatePicker, Divider, Flex, Input, InputNumber, Result, Row, Select, Space, Table, Tag, Typography, theme } from 'antd';
import { CalendarOutlined, DeleteOutlined, PlusOutlined, PrinterOutlined, TagsOutlined } from '@ant-design/icons';
import { obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import type { Dayjs } from 'dayjs';
import dayjs from 'dayjs';
import { useState, type ReactNode } from 'react';
import { useSessao } from '@/sessao/SessaoContexto';
import { scrollTabela } from '@/componentes/responsivo';
import { useAccao } from '@/componentes/Accoes';
import { SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import { deCentimos, formatarCentimos, pagamentosParaApi, resumirPagamentos, type Pagamento } from '../comum/calculos';
import { meiosActivos, novaChave, PainelPagamentos } from '../comum/PainelPagamentos';
import type { Terminal } from '../comum/tipos';
import { imprimirHtml, lerPreferencias, reimprimir, useCabecalhoTalao } from '../comum/impressao';
import { htmlEtiquetasOS, htmlTalaoOS } from './impressoes';
import { GRUPOS_LAV, useDefinicoesLav, usePecas, useServicosLav } from './dados';
import { precoPecaServico, type DetalheOrdem, type PedidoLav } from './tipos';

interface LinhaRecepcao {
  chave: string;
  peca_id: number;
  produto_id: number;
  nome_peca: string;
  nome_servico: string;
  unidade: 'PECA' | 'KG';
  quantidade: number;
  numero_pecas?: number | null;
  /** Preço com IVA em Kz (da tabela peça × serviço, editável). */
  preco: number;
  preco_tabela: number;
  cor?: string;
  tecido?: string;
  estado_entrada?: string;
  notas_entrada?: string;
  valor_declarado?: number | null;
}

interface Domicilio {
  ativa: boolean;
  morada?: string;
  data?: Dayjs | null;
  taxa?: number | null;
}

/** Total estimado da ordem em cêntimos: serviços + urgência (% dos serviços) + taxas de recolha e entrega. */
export function estimarOrdem(linhas: { quantidade: number; preco: number }[], urgente: boolean, pctUrgencia: number, taxas: number[]): number {
  const servicos = linhas.reduce((t, l) => t + Math.round(l.quantidade * l.preco * 100), 0);
  const urgencia = urgente ? Math.round((servicos * pctUrgencia) / 100) : 0;
  return servicos + urgencia + taxas.reduce((t, x) => t + Math.round((x || 0) * 100), 0);
}

/** Etiquetas desta linha (RegrasLavandaria::etiquetas): pela quantidade, ou pelo n.º de peças quando a unidade é Kg. */
const etiquetasLinha = (l: Pick<LinhaRecepcao, 'unidade' | 'quantidade' | 'numero_pecas'>) => Math.max(1, Math.round(l.unidade === 'KG' ? l.numero_pecas ?? 1 : l.quantidade));

/** Secção numerada do ecrã (1 Cliente e condições, 2 Adicionar peça, …), como o legado da frente de caixa da lavandaria. */
function Seccao({ n, titulo, children }: { n: number; titulo: string; children: ReactNode }) {
  const { token } = theme.useToken();
  return (
    <Card
      size="small"
      style={{ marginTop: n === 1 ? 0 : 12 }}
      title={
        <Flex align="center" gap={8}>
          <Avatar size={22} style={{ background: token.colorPrimary, fontSize: 12, fontWeight: 700 }}>{n}</Avatar>
          {titulo}
        </Flex>
      }
    >
      {children}
    </Card>
  );
}

/** Recepção de peças: nova ordem de serviço na sessão aberta do terminal de lavandaria, com adiantamento opcional. */
export function Recepcao({ terminal, aoRegistar }: { terminal: Terminal | undefined; aoRegistar?: (pedido: PedidoLav) => void }) {
  const { pode, empresa } = useSessao();
  const { token } = theme.useToken();
  const cabecalho = useCabecalhoTalao();
  /** Talão da OS e/ou etiquetas da ordem acabada de registar (M-16). */
  const imprimirRegistada = async (id: number, o: 'talao' | 'etiquetas' | 'ambos') => {
    try {
      const d = await obter<DetalheOrdem>(`/pos/lavandaria/ordens/${id}`);
      const p = lerPreferencias(empresa?.id);
      const c = cabecalho({ terminal: terminal ? `${terminal.codigo} — ${terminal.nome}` : d.pedido.codigo_terminal });
      const manual = o !== 'ambos';
      const enviarParaImpressora = (html: string) => (manual ? reimprimir(html, p) : imprimirHtml(html));
      if (o !== 'etiquetas') enviarParaImpressora(htmlTalaoOS(d, definicoes.data, c, p));
      if (o !== 'talao') window.setTimeout(() => enviarParaImpressora(htmlEtiquetasOS(d, c, p)), o === 'ambos' ? 900 : 0);
    } catch (e) {
      notificarErro(e, 'Não foi possível imprimir a ordem de serviço');
    }
  };
  const pecas = usePecas();
  const servicos = useServicosLav(false, terminal?.id);
  const definicoes = useDefinicoesLav();
  const [cliente, setCliente] = useState<number>();
  const [modo, setModo] = useState<'RECEPCAO' | 'ENTREGA'>('ENTREGA');
  const [urgente, setUrgente] = useState(false);
  const [observacoes, setObservacoes] = useState('');
  const [linhas, setLinhas] = useState<LinhaRecepcao[]>([]);
  const [novaPeca, setNovaPeca] = useState<number>();
  const [novoServico, setNovoServico] = useState<number>();
  const [recolha, setRecolha] = useState<Domicilio>({ ativa: false });
  const [entrega, setEntrega] = useState<Domicilio>({ ativa: false });
  const [adiantamento, setAdiantamento] = useState(0);
  const [pagamentos, setPagamentos] = useState<Pagamento[]>([]);
  const [registada, setRegistada] = useState<PedidoLav | null>(null);
  const sessaoId = terminal?.sessao_aberta?.id;
  const def = definicoes.data;
  const estados = def?.estados_entrada ?? [];
  const mapaPecas = new Map((pecas.data ?? []).map((p) => [p.id, p]));
  const mapaServicos = new Map((servicos.data ?? []).map((s) => [s.id, s]));

  const registar = useAccao<{ pedido: PedidoLav }>({
    invalidar: [['pos']],
    tituloErro: 'Não foi possível registar a ordem',
    aoSucesso: (r) => {
      setRegistada(r.pedido);
      aoRegistar?.(r.pedido);
      // como o legado (lavandaria.js:680-681): talão da OS e etiquetas logo a seguir, se o posto imprime automaticamente
      if (lerPreferencias(empresa?.id).automatico) void imprimirRegistada(r.pedido.id, 'ambos');
    },
  });

  const alterar = (chave: string, a: Partial<LinhaRecepcao>) => setLinhas((ls) => ls.map((l) => (l.chave === chave ? { ...l, ...a } : l)));

  const adicionarPeca = () => {
    const peca = novaPeca ? mapaPecas.get(novaPeca) : undefined;
    const servico = novoServico ? mapaServicos.get(novoServico) : undefined;
    if (!peca || !servico) return;
    const tabela = precoPecaServico(peca, servico.id);
    setLinhas((ls) => [...ls, {
      chave: novaChave(), peca_id: peca.id, produto_id: servico.id, nome_peca: peca.nome, nome_servico: servico.nome, unidade: peca.unidade,
      quantidade: 1, numero_pecas: null, preco: tabela, preco_tabela: tabela, cor: peca.cor ?? undefined, tecido: peca.tecido ?? undefined,
    }]);
    setNovaPeca(undefined);
    setNovoServico(undefined);
  };

  const taxaRecolha = recolha.ativa ? recolha.taxa ?? Number(def?.valor_taxa_recolha ?? 0) : 0;
  const taxaEntrega = entrega.ativa ? entrega.taxa ?? Number(def?.valor_taxa_entrega ?? 0) : 0;
  const subtotalServicos = linhas.reduce((t, l) => t + Math.round(l.quantidade * l.preco * 100), 0);
  const total = estimarOrdem(linhas, urgente, Number(def?.percentagem_urgencia ?? 0), [taxaRecolha, taxaEntrega]);
  const resumo = resumirPagamentos(pagamentos, adiantamento);
  const linhasValidas = linhas.length > 0 && linhas.every((l) => l.quantidade > 0 && l.estado_entrada);
  const pronto = !!sessaoId && !!cliente && linhasValidas && (adiantamento === 0 || resumo.valido);
  // pré-visualização do prazo (RegrasLavandaria::registar): o maior prazo das linhas, encurtado se urgente (ServicoOrdensLavandaria)
  const prazoBase = linhas.length ? Math.max(...linhas.map((l) => mapaServicos.get(l.produto_id)?.lavandaria_dias_entrega ?? 2)) : null;
  const dias = prazoBase === null ? null : urgente ? Math.max(1, Math.ceil(prazoBase * Number(def?.fator_prazo_urgencia ?? 1))) : Math.max(1, prazoBase);
  const prontaEm = dias === null ? null : dayjs().add(dias, 'day');
  const etiquetas = linhas.reduce((t, l) => t + etiquetasLinha(l), 0);

  const enviar = () => {
    if (!sessaoId || !pronto) return;
    const domicilio = (d: Domicilio) => (d.ativa ? { ativa: true, morada: d.morada?.trim() || undefined, data: d.data ? d.data.format('YYYY-MM-DD') : undefined, taxa: d.taxa ?? undefined } : undefined);
    registar.mutate({
      url: `/pos/lavandaria/sessoes/${sessaoId}/ordens`,
      dados: {
        cliente_id: cliente,
        modo_faturacao: modo,
        urgente,
        observacoes: observacoes.trim() || undefined,
        itens: linhas.map((l) => ({
          peca_id: l.peca_id,
          produto_id: l.produto_id,
          quantidade: l.quantidade,
          numero_pecas: l.numero_pecas ?? undefined,
          ...(l.preco !== l.preco_tabela ? { preco: l.preco } : {}),
          cor: l.cor?.trim() || undefined,
          tecido: l.tecido?.trim() || undefined,
          estado_entrada: l.estado_entrada,
          notas_entrada: l.notas_entrada?.trim() || undefined,
          valor_declarado: l.valor_declarado ?? undefined,
        })),
        recolha: domicilio(recolha),
        entrega: domicilio(entrega),
        ...(adiantamento > 0 ? { valor: deCentimos(adiantamento), pagamentos: pagamentosParaApi(pagamentos) } : {}),
      },
    });
  };

  const limpar = () => {
    setRegistada(null);
    setCliente(undefined);
    setModo('ENTREGA');
    setUrgente(false);
    setObservacoes('');
    setLinhas([]);
    setNovaPeca(undefined);
    setNovoServico(undefined);
    setRecolha({ ativa: false });
    setEntrega({ ativa: false });
    setAdiantamento(0);
    setPagamentos([]);
  };

  if (registada)
    return (
      <Result
        status="success"
        title={`Ordem ${registada.numero_encomenda} registada`}
        subTitle={`Etiquetas: ${registada.itens.flatMap((i) => i.etiquetas ?? []).join(', ') || '—'}`}
        extra={
          <Space wrap style={{ justifyContent: 'center' }}>
            <Button icon={<PrinterOutlined />} onClick={() => void imprimirRegistada(registada.id, 'talao')}>
              Imprimir talão
            </Button>
            <Button icon={<TagsOutlined />} onClick={() => void imprimirRegistada(registada.id, 'etiquetas')}>
              Imprimir etiquetas
            </Button>
            <Button type="primary" onClick={limpar}>
              Nova recepção
            </Button>
          </Space>
        }
      />
    );

  if (!pode('lav_ordens')) return <Alert type="info" showIcon message="Não tem permissão para registar ordens de serviço." />;

  return (
    <>
      {!sessaoId && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="O terminal escolhido não tem sessão aberta: abra-a na frente de caixa para registar a recepção." />}
      {terminal?.tipo === 'LAVANDARIA' && terminal.lavandaria_servicos_ids?.length === 0 && (
        <Alert type="error" showIcon style={{ marginBottom: 12 }} message="Este terminal ainda não tem serviços configurados. Configure-os em POS › Terminais antes de receber ordens." />
      )}
      <Row gutter={[16, 16]}>
        <Col xs={24} xl={16}>
          <Seccao n={1} titulo="Cliente e condições">
            <Flex gap={12} wrap align="center">
              <SeletorTerceiro papel="CLIENTE" value={cliente} onChange={setCliente} style={{ width: 340, maxWidth: '100%' }} />
              <div>
                <div style={{ fontSize: 12, color: token.colorTextSecondary, marginBottom: 2 }}>Facturação</div>
                <Select value={modo} onChange={setModo} style={{ width: 170 }} options={[{ value: 'ENTREGA', label: 'Na entrega' }, { value: 'RECEPCAO', label: 'Já (na recepção)' }]} />
              </div>
              <Checkbox checked={urgente} onChange={(e) => setUrgente(e.target.checked)}>
                <b>Serviço urgente</b>
                <div style={{ fontSize: 12, color: token.colorTextSecondary }}>+{def?.percentagem_urgencia ?? 0}% e prazo reduzido</div>
              </Checkbox>
            </Flex>
          </Seccao>

          <Seccao n={2} titulo="Adicionar peça">
            <Row gutter={12}>
              <Col xs={24} sm={11}>
                <div style={{ fontSize: 12, color: token.colorTextSecondary, marginBottom: 4 }}>Peça</div>
                <Select<number>
                  showSearch
                  optionFilterProp="label"
                  style={{ width: '100%' }}
                  placeholder="— seleccionar a peça —"
                  value={novaPeca}
                  onChange={setNovaPeca}
                  options={(pecas.data ?? []).map((p) => ({ value: p.id, label: p.nome }))}
                />
              </Col>
              <Col xs={24} sm={11}>
                <div style={{ fontSize: 12, color: token.colorTextSecondary, marginBottom: 4 }}>Serviço</div>
                <Select<number>
                  showSearch
                  optionFilterProp="label"
                  style={{ width: '100%' }}
                  placeholder="— seleccionar o serviço —"
                  value={novoServico}
                  onChange={setNovoServico}
                  options={(servicos.data ?? []).map((s) => ({ value: s.id, label: `${s.nome} (${GRUPOS_LAV[s.lavandaria_grupo] ?? s.lavandaria_grupo})${s.lavandaria_requer_orcamento ? ' · orçamento' : ''}` }))}
                />
              </Col>
            </Row>
            <Button block type="primary" ghost icon={<PlusOutlined />} style={{ marginTop: 12 }} disabled={!novaPeca || !novoServico} onClick={adicionarPeca}>
              Adicionar à ordem
            </Button>
          </Seccao>

          <Seccao n={3} titulo={`Peças da ordem (${linhas.length})`}>
            {linhas.length === 0 ? (
              <div style={{ padding: '1.2rem', textAlign: 'center', color: token.colorTextTertiary, border: `1px dashed ${token.colorBorder}`, borderRadius: 4 }}>
                Ainda não há peças. Seleccione a peça e o serviço acima e carregue em Adicionar.
              </div>
            ) : (
              <Table<LinhaRecepcao>
                size="small"
                rowKey="chave"
                pagination={false}
                dataSource={linhas}
                scroll={scrollTabela()}
                columns={[
                  { title: 'Peça', render: (_, l) => (<><b>{l.nome_peca}</b><div style={{ fontSize: 12, color: token.colorTextSecondary }}>{l.nome_servico}</div></>) },
                  { title: 'Qtd.', render: (_, l) => <InputNumber<number> min={0.001} precision={3} style={{ width: 80 }} value={l.quantidade} onChange={(v) => alterar(l.chave, { quantidade: v ?? 0 })} /> },
                  { title: 'N.º peças', render: (_, l) => <InputNumber<number> min={1} precision={0} style={{ width: 70 }} value={l.numero_pecas ?? null} onChange={(v) => alterar(l.chave, { numero_pecas: v })} /> },
                  { title: 'Preço (c/ IVA)', render: (_, l) => <InputNumber<number> min={0} precision={2} decimalSeparator="," style={{ width: 120 }} value={l.preco} onChange={(v) => alterar(l.chave, { preco: v ?? 0 })} /> },
                  {
                    title: 'Estado à entrada',
                    render: (_, l) => (
                      <Select style={{ width: 170 }} placeholder="Obrigatório" status={l.estado_entrada ? undefined : 'warning'} value={l.estado_entrada} onChange={(v) => alterar(l.chave, { estado_entrada: v })} options={estados.map((e) => ({ value: e, label: e }))} />
                    ),
                  },
                  { title: 'Cor', render: (_, l) => <Input style={{ width: 100 }} maxLength={100} value={l.cor} onChange={(e) => alterar(l.chave, { cor: e.target.value })} /> },
                  { title: 'Tecido', render: (_, l) => <Input style={{ width: 100 }} maxLength={100} value={l.tecido} onChange={(e) => alterar(l.chave, { tecido: e.target.value })} /> },
                  { title: 'Notas', render: (_, l) => <Input style={{ width: 160 }} maxLength={1000} value={l.notas_entrada} onChange={(e) => alterar(l.chave, { notas_entrada: e.target.value })} /> },
                  { title: 'Valor declarado', render: (_, l) => <InputNumber<number> min={0} precision={2} style={{ width: 110 }} value={l.valor_declarado ?? null} onChange={(v) => alterar(l.chave, { valor_declarado: v })} /> },
                  { title: 'Subtotal', align: 'right', render: (_, l) => formatarCentimos(Math.round(l.quantidade * l.preco * 100)) },
                  { title: '', render: (_, l) => <Button type="text" danger icon={<DeleteOutlined />} aria-label="Retirar peça" onClick={() => setLinhas((ls) => ls.filter((x) => x.chave !== l.chave))} /> },
                ]}
              />
            )}
          </Seccao>

          <Seccao n={4} titulo="Recolha e entrega ao domicílio">
            {(
              [
                ['Recolha ao domicílio', recolha, setRecolha, def?.valor_taxa_recolha],
                ['Entrega ao domicílio', entrega, setEntrega, def?.valor_taxa_entrega],
              ] as const
            ).map(([rotulo, d, set, taxa]) => (
              <Flex key={rotulo} gap={8} wrap align="center" style={{ marginBottom: 8 }}>
                <Checkbox checked={d.ativa} onChange={(e) => set({ ...d, ativa: e.target.checked })} style={{ width: 170 }}>
                  {rotulo}
                </Checkbox>
                {d.ativa && (
                  <>
                    <Input placeholder="Morada" style={{ width: 280, maxWidth: '100%' }} maxLength={500} value={d.morada} onChange={(e) => set({ ...d, morada: e.target.value })} />
                    <DatePicker format="DD/MM/YYYY" value={d.data ?? null} onChange={(v) => set({ ...d, data: v })} />
                    <InputNumber<number> min={0} precision={2} placeholder={`Taxa (${taxa ?? '0'})`} suffix="Kz" style={{ width: 170, maxWidth: '100%' }} value={d.taxa ?? null} onChange={(v) => set({ ...d, taxa: v })} />
                  </>
                )}
              </Flex>
            ))}
          </Seccao>

          <Seccao n={5} titulo="Observações">
            <Input.TextArea rows={2} maxLength={2000} placeholder="Instruções gerais da ordem (opcional)" value={observacoes} onChange={(e) => setObservacoes(e.target.value)} />
          </Seccao>
        </Col>

        <Col xs={24} xl={8}>
          <Card size="small" title="Resumo">
            <Flex justify="space-between" style={{ marginBottom: 4 }}>
              <Typography.Text type="secondary">Serviços ({linhas.length} linha{linhas.length === 1 ? '' : 's'})</Typography.Text>
              <Typography.Text>{formatarCentimos(subtotalServicos)} Kz</Typography.Text>
            </Flex>
            <Flex justify="space-between">
              <Typography.Text strong>Total</Typography.Text>
              <Typography.Text strong>{formatarCentimos(total)} Kz</Typography.Text>
            </Flex>
            <Divider style={{ margin: '10px 0' }} />
            <Space direction="vertical" size={4} style={{ width: '100%', fontSize: 13 }}>
              <span><CalendarOutlined /> Pronta a partir de {(prontaEm ?? dayjs()).format('DD/MM/YYYY')}</span>
              <span><TagsOutlined /> {etiquetas} etiqueta{etiquetas === 1 ? '' : 's'}</span>
              <span><PrinterOutlined /> Facturação {modo === 'ENTREGA' ? 'na entrega' : 'na recepção'}</span>
              {def && <Tag color="warning" style={{ whiteSpace: 'normal', marginTop: 4 }}>Consumidor Final: adiantamento mínimo de {def.percentagem_adiantamento}% ({formatarCentimos(Math.round((total * Number(def.percentagem_adiantamento)) / 100))} Kz)</Tag>}
            </Space>
            <Divider style={{ margin: '10px 0' }} />
            <Flex gap={8} wrap align="center" style={{ marginBottom: 8 }}>
              <Typography.Text>Adiantamento:</Typography.Text>
              <InputNumber<number> min={0} precision={2} decimalSeparator="," suffix="Kz" value={adiantamento / 100} onChange={(v) => setAdiantamento(Math.round((v ?? 0) * 100))} />
              <Button size="small" onClick={() => setAdiantamento(total)}>
                Total
              </Button>
            </Flex>
            {adiantamento > 0 && terminal && <PainelPagamentos meios={meiosActivos(terminal.meios_pagamento)} total={adiantamento} pagamentos={pagamentos} onChange={setPagamentos} />}
            <Flex gap={8} style={{ marginTop: 12 }}>
              <Button type="primary" size="large" block disabled={!pronto} loading={registar.isPending} onClick={enviar}>
                Registar ordem
              </Button>
              <Button size="large" onClick={limpar}>
                Limpar
              </Button>
            </Flex>
            {!linhasValidas && <Typography.Text type="secondary">Cada peça precisa do estado à entrada.</Typography.Text>}
          </Card>
        </Col>
      </Row>
    </>
  );
}
