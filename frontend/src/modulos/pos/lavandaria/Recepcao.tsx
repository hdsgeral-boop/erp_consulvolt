import { Alert, Button, Card, Checkbox, Col, DatePicker, Divider, Flex, Input, InputNumber, Radio, Result, Row, Select, Space, Statistic, Switch, Table, Typography } from 'antd';
import { DeleteOutlined, PlusOutlined, PrinterOutlined, TagsOutlined } from '@ant-design/icons';
import { obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
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
import { precoPecaServico, type DetalheOrdem, type Peca, type PedidoLav } from './tipos';

interface LinhaRecepcao {
  chave: string;
  peca_id?: number;
  produto_id?: number;
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

/** Recepção de peças: nova ordem de serviço na sessão aberta do terminal de lavandaria, com adiantamento opcional. */
export function Recepcao({ terminal, aoRegistar }: { terminal: Terminal | undefined; aoRegistar?: (pedido: PedidoLav) => void }) {
  const { pode, empresa } = useSessao();
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
  const servicos = useServicosLav();
  const definicoes = useDefinicoesLav();
  const [cliente, setCliente] = useState<number>();
  const [modo, setModo] = useState<'RECEPCAO' | 'ENTREGA'>('ENTREGA');
  const [urgente, setUrgente] = useState(false);
  const [observacoes, setObservacoes] = useState('');
  const [linhas, setLinhas] = useState<LinhaRecepcao[]>([{ chave: novaChave(), quantidade: 1, preco: 0, preco_tabela: 0 }]);
  const [recolha, setRecolha] = useState<Domicilio>({ ativa: false });
  const [entrega, setEntrega] = useState<Domicilio>({ ativa: false });
  const [adiantamento, setAdiantamento] = useState(0);
  const [pagamentos, setPagamentos] = useState<Pagamento[]>([]);
  const [registada, setRegistada] = useState<PedidoLav | null>(null);
  const sessaoId = terminal?.sessao_aberta?.id;
  const def = definicoes.data;
  const estados = def?.estados_entrada ?? [];
  const mapaPecas = new Map((pecas.data ?? []).map((p) => [p.id, p]));

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

  const alterar = (chave: string, a: Partial<LinhaRecepcao>) =>
    setLinhas((ls) =>
      ls.map((l) => {
        if (l.chave !== chave) return l;
        const n = { ...l, ...a };
        if ('peca_id' in a || 'produto_id' in a) {
          const peca: Peca | undefined = n.peca_id ? mapaPecas.get(n.peca_id) : undefined;
          const tabela = precoPecaServico(peca, n.produto_id);
          n.preco = tabela;
          n.preco_tabela = tabela;
          if ('peca_id' in a && peca) {
            n.cor = n.cor || peca.cor || undefined;
            n.tecido = n.tecido || peca.tecido || undefined;
          }
        }
        return n;
      }),
    );

  const taxaRecolha = recolha.ativa ? recolha.taxa ?? Number(def?.valor_taxa_recolha ?? 0) : 0;
  const taxaEntrega = entrega.ativa ? entrega.taxa ?? Number(def?.valor_taxa_entrega ?? 0) : 0;
  const total = estimarOrdem(linhas, urgente, Number(def?.percentagem_urgencia ?? 0), [taxaRecolha, taxaEntrega]);
  const resumo = resumirPagamentos(pagamentos, adiantamento);
  const linhasValidas = linhas.length > 0 && linhas.every((l) => l.peca_id && l.produto_id && l.quantidade > 0 && l.estado_entrada);
  const pronto = !!sessaoId && !!cliente && linhasValidas && (adiantamento === 0 || resumo.valido);

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
    setUrgente(false);
    setObservacoes('');
    setLinhas([{ chave: novaChave(), quantidade: 1, preco: 0, preco_tabela: 0 }]);
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
      <Row gutter={[16, 16]}>
        <Col xs={24} xl={16}>
          <Card size="small" title="Cliente e condições">
            <Flex gap={12} wrap align="center">
              <SeletorTerceiro papel="CLIENTE" value={cliente} onChange={setCliente} style={{ width: 340, maxWidth: '100%' }} />
              <Radio.Group value={modo} onChange={(e) => setModo(e.target.value)} optionType="button">
                <Radio value="ENTREGA">Facturar na entrega</Radio>
                <Radio value="RECEPCAO">Facturar já</Radio>
              </Radio.Group>
              <Space wrap>
                <Switch checked={urgente} onChange={setUrgente} /> Urgente {def && `(+${def.percentagem_urgencia}%)`}
              </Space>
            </Flex>
          </Card>

          <Card size="small" title="Peças" style={{ marginTop: 12 }}>
            <Table<LinhaRecepcao>
              size="small"
              rowKey="chave"
              pagination={false}
              dataSource={linhas}
              scroll={scrollTabela()}
              columns={[
                {
                  title: 'Peça',
                  render: (_, l) => (
                    <Select<number> showSearch optionFilterProp="label" style={{ width: 190 }} placeholder="Peça" value={l.peca_id} onChange={(v) => alterar(l.chave, { peca_id: v })} options={(pecas.data ?? []).map((p) => ({ value: p.id, label: p.nome }))} />
                  ),
                },
                {
                  title: 'Serviço',
                  render: (_, l) => (
                    <Select<number>
                      showSearch
                      optionFilterProp="label"
                      style={{ width: 190 }}
                      placeholder="Serviço"
                      value={l.produto_id}
                      onChange={(v) => alterar(l.chave, { produto_id: v })}
                      options={(servicos.data ?? []).map((s) => ({ value: s.id, label: `${s.nome} (${GRUPOS_LAV[s.lavandaria_grupo] ?? s.lavandaria_grupo})${s.lavandaria_requer_orcamento ? ' · orçamento' : ''}` }))}
                    />
                  ),
                },
                { title: 'Qtd.', render: (_, l) => <InputNumber<number> min={0.001} precision={3} style={{ width: 80 }} value={l.quantidade} onChange={(v) => alterar(l.chave, { quantidade: v ?? 0 })} /> },
                { title: 'N.º peças', render: (_, l) => <InputNumber<number> min={1} precision={0} style={{ width: 70 }} value={l.numero_pecas ?? null} onChange={(v) => alterar(l.chave, { numero_pecas: v })} /> },
                { title: 'Preço (c/ IVA)', render: (_, l) => <InputNumber<number> min={0} precision={2} decimalSeparator="," style={{ width: 120 }} value={l.preco} onChange={(v) => alterar(l.chave, { preco: v ?? 0 })} /> },
                {
                  title: 'Estado à entrada',
                  render: (_, l) => (
                    <Select style={{ width: 190 }} placeholder="Obrigatório" status={l.estado_entrada ? undefined : 'warning'} value={l.estado_entrada} onChange={(v) => alterar(l.chave, { estado_entrada: v })} options={estados.map((e) => ({ value: e, label: e }))} />
                  ),
                },
                { title: 'Cor', render: (_, l) => <Input style={{ width: 100 }} maxLength={100} value={l.cor} onChange={(e) => alterar(l.chave, { cor: e.target.value })} /> },
                { title: 'Tecido', render: (_, l) => <Input style={{ width: 100 }} maxLength={100} value={l.tecido} onChange={(e) => alterar(l.chave, { tecido: e.target.value })} /> },
                { title: 'Notas', render: (_, l) => <Input style={{ width: 160 }} maxLength={1000} value={l.notas_entrada} onChange={(e) => alterar(l.chave, { notas_entrada: e.target.value })} /> },
                { title: 'Valor declarado', render: (_, l) => <InputNumber<number> min={0} precision={2} style={{ width: 110 }} value={l.valor_declarado ?? null} onChange={(v) => alterar(l.chave, { valor_declarado: v })} /> },
                { title: 'Subtotal', align: 'right', render: (_, l) => formatarCentimos(Math.round(l.quantidade * l.preco * 100)) },
                { title: '', render: (_, l) => <Button type="text" danger icon={<DeleteOutlined />} aria-label="Retirar peça" disabled={linhas.length === 1} onClick={() => setLinhas((ls) => ls.filter((x) => x.chave !== l.chave))} /> },
              ]}
            />
            <Button style={{ marginTop: 8 }} icon={<PlusOutlined />} onClick={() => setLinhas((ls) => [...ls, { chave: novaChave(), quantidade: 1, preco: 0, preco_tabela: 0 }])}>
              Acrescentar peça
            </Button>
          </Card>

          <Card size="small" title="Recolha e entrega ao domicílio" style={{ marginTop: 12 }}>
            {(
              [
                ['Recolha', recolha, setRecolha, def?.valor_taxa_recolha],
                ['Entrega', entrega, setEntrega, def?.valor_taxa_entrega],
              ] as const
            ).map(([rotulo, d, set, taxa]) => (
              <Flex key={rotulo} gap={8} wrap align="center" style={{ marginBottom: 8 }}>
                <Checkbox checked={d.ativa} onChange={(e) => set({ ...d, ativa: e.target.checked })} style={{ width: 90 }}>
                  {rotulo}
                </Checkbox>
                <Input disabled={!d.ativa} placeholder="Morada" style={{ width: 280, maxWidth: '100%' }} maxLength={500} value={d.morada} onChange={(e) => set({ ...d, morada: e.target.value })} />
                <DatePicker disabled={!d.ativa} format="DD/MM/YYYY" value={d.data ?? null} onChange={(v) => set({ ...d, data: v })} />
                <InputNumber<number> disabled={!d.ativa} min={0} precision={2} placeholder={`Taxa (${taxa ?? '0'})`} suffix="Kz" style={{ width: 170, maxWidth: '100%' }} value={d.taxa ?? null} onChange={(v) => set({ ...d, taxa: v })} />
              </Flex>
            ))}
            <Input.TextArea rows={2} maxLength={2000} placeholder="Observações da ordem" value={observacoes} onChange={(e) => setObservacoes(e.target.value)} />
          </Card>
        </Col>

        <Col xs={24} xl={8}>
          <Card size="small" title="Valor e adiantamento">
            <Statistic title="Total estimado" value={formatarCentimos(total)} suffix="Kz" />
            <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>
              Estimativa: o servidor calcula o preço, as taxas e o IVA. {def && `Consumidor Final: adiantamento mínimo de ${def.percentagem_adiantamento}%.`}
            </Typography.Paragraph>
            <Divider style={{ margin: '8px 0' }} />
            <Flex gap={8} wrap align="center" style={{ marginBottom: 8 }}>
              <Typography.Text>Adiantamento:</Typography.Text>
              <InputNumber<number> min={0} precision={2} decimalSeparator="," suffix="Kz" value={adiantamento / 100} onChange={(v) => setAdiantamento(Math.round((v ?? 0) * 100))} />
              <Button size="small" onClick={() => setAdiantamento(total)}>
                Total
              </Button>
            </Flex>
            {adiantamento > 0 && terminal && <PainelPagamentos meios={meiosActivos(terminal.meios_pagamento)} total={adiantamento} pagamentos={pagamentos} onChange={setPagamentos} />}
            <Button type="primary" size="large" block style={{ marginTop: 12 }} disabled={!pronto} loading={registar.isPending} onClick={enviar}>
              Registar ordem
            </Button>
            {!linhasValidas && <Typography.Text type="secondary">Cada peça precisa da peça, do serviço, da quantidade e do estado à entrada.</Typography.Text>}
          </Card>
        </Col>
      </Row>
    </>
  );
}
