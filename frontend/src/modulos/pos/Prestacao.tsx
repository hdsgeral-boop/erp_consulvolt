import { Alert, Button, Card, DatePicker, Empty, Flex, Form, InputNumber, List, Modal, Select, Skeleton, Space, Switch, Table, Tabs, Tag, Tooltip, Typography } from 'antd';
import { CheckOutlined, EyeOutlined, StopOutlined, SwapOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi } from '@/componentes/TabelaApi';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { BarraFiltros, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { somar } from '@/utilitarios/decimal';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { DetalheSessao, ValorDesvio } from './comum/DetalheSessao';
import { EstadoPOS, opcoesEstadoPOS, rotuloEstadoPOS } from './comum/estados';
import { SeletorTerminal, useFiltroTerminal } from './comum/Filtros';
import { accoesItemPrestacao, podeAnularLiquidacao } from './comum/regras';
import type { FolhaCaixa, ItemPrestacao, Liquidacao, SessaoPrestacao } from './comum/tipos';

interface Pendentes {
  sessoes: SessaoPrestacao[];
  folhas_caixa_abertas: FolhaCaixa[];
}

/**
 * POS › Prestação de contas (ecrã pos_prestacao): saldar as contas transitórias das sessões integradas — numerário na
 * folha de caixa, TPA e transferências na tesouraria (com comissão) — e anular liquidações (ADR-048).
 */
export default function Prestacao() {
  return (
    <>
      <CabecalhoPagina titulo="Prestação de contas POS" subtitulo="Liquidação do numerário, TPA e transferências das sessões integradas" />
      <Tabs
        destroyOnHidden
        items={[
          { key: 'pendentes', label: 'Por regularizar', children: <PorRegularizar /> },
          { key: 'liquidacoes', label: 'Liquidações', children: <ListaLiquidacoes /> },
        ]}
      />
    </>
  );
}

function PorRegularizar() {
  const { pode, utilizador } = useSessao();
  const [terminal, setTerminal] = useState<number>();
  const [detalhe, setDetalhe] = useState<number | null>(null);
  const [registar, setRegistar] = useState<{ sessao: SessaoPrestacao; item: ItemPrestacao } | null>(null);
  const filtroTerminal = useFiltroTerminal(terminal);
  const consulta = useQuery({ queryKey: ['pos', 'prestacao', terminal], queryFn: () => obter<Pendentes>('/pos/prestacao', { terminal_pos_id: terminal }) });
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao carregar a prestação de contas');
  }, [consulta.error]);

  const lote = useAccao<{ registadas: unknown[]; erros: { chave_item: string; numero_documento: string | null; mensagem: string }[] }>({
    invalidar: [['pos']],
    aoSucesso: (r) => {
      if (r.erros.length)
        Modal.warning({
          title: 'Transferências com erro',
          content: (
            <List size="small" dataSource={r.erros} renderItem={(e) => <List.Item>{`${e.numero_documento ?? e.chave_item}: ${e.mensagem}`}</List.Item>} />
          ),
        });
    },
  });

  return (
    <>
      <BarraFiltros
        accoes={
          <BotoesExportar
            tamanho="small"
            desactivado={!consulta.data?.sessoes.length}
            obterPedido={() => ({ titulo: 'Prestação de contas POS · por regularizar', filtros: [filtroTerminal], conteudo: documentoPorRegularizar(consulta.data?.sessoes ?? []) })}
          />
        }
      >
        <SeletorTerminal value={terminal} onChange={setTerminal} />
      </BarraFiltros>
      {consulta.isLoading ? (
        <Skeleton active />
      ) : !consulta.data?.sessoes.length ? (
        <Empty description="Não há sessões por regularizar." />
      ) : (
        <Flex vertical gap={16}>
          {consulta.data.sessoes.map((s) => {
            const transferencias = s.itens.filter((i) => i.natureza === 'TRANSFERENCIA' && accoesItemPrestacao(pode, i, s.operador_id, utilizador?.id).visivel);
            const proprio = !!utilizador?.id && s.operador_id === utilizador.id;
            return (
              <Card
                key={s.id}
                size="small"
                title={
                  <Space wrap>
                    <b>{s.numero_z}</b>
                    <span>
                      {s.codigo_terminal} — {s.nome_terminal}
                    </span>
                    <Typography.Text type="secondary">
                      {s.nome_operador} · {formatarDataHora(s.fechado_em)}
                    </Typography.Text>
                    <EstadoPOS estado={s.estado_liquidacao} />
                  </Space>
                }
                extra={
                  <Space wrap>
                    <Button size="small" icon={<EyeOutlined />} onClick={() => setDetalhe(s.id)}>
                      Sessão
                    </Button>
                    {transferencias.length > 1 && (
                      <Button size="small" icon={<SwapOutlined />} disabled={proprio} loading={lote.isPending} onClick={() => lote.mutate({ url: `/pos/sessoes/${s.id}/prestacao/transferencias` })}>
                        Registar {transferencias.length} transferências
                      </Button>
                    )}
                  </Space>
                }
              >
                <TabelaItens sessao={s} aoRegistar={(item) => setRegistar({ sessao: s, item })} />
              </Card>
            );
          })}
        </Flex>
      )}
      <DetalheSessao id={detalhe} aoFechar={() => setDetalhe(null)} />
      <ModalRegistar alvo={registar} folhas={consulta.data?.folhas_caixa_abertas ?? []} aoFechar={() => setRegistar(null)} />
    </>
  );
}

function TabelaItens({ sessao, aoRegistar }: { sessao: SessaoPrestacao; aoRegistar: (i: ItemPrestacao) => void }) {
  const { pode, utilizador } = useSessao();
  return (
    <Table<ItemPrestacao>
      size="small"
      pagination={false}
      rowKey="chave_item"
      dataSource={sessao.itens}
      scroll={scrollTabela()}
      columns={[
        { title: 'Natureza', dataIndex: 'natureza', render: (n) => <EstadoPOS estado={n} /> },
        { title: 'Meio', dataIndex: 'nome' },
        { title: 'Transitória → liquidação', responsive: ['md'], render: (_, i) => `${i.conta_transitoria ?? '—'} → ${i.conta_liquidacao ?? '—'}` },
        {
          title: 'Detalhe',
          render: (_, i) =>
            i.natureza === 'NUMERARIO' ? (
              <span>
                Sistema {formatarKz(i.numerario_sistema)}
                {i.desvio_aplicado ? (
                  <>
                    {' '}
                    · desvio <ValorDesvio valor={i.desvio_aplicado} />
                  </>
                ) : null}
                {i.movimento === 'PAG' && <Tag color="volcano" style={{ marginLeft: 6 }}>Pagamento</Tag>}
              </span>
            ) : i.natureza === 'TPA' ? (
              <span>
                Talão {formatarKz(i.valor_talao)}
                {i.diferenca && Number(i.diferenca) !== 0 && (
                  <>
                    {' '}
                    · dif. <ValorDesvio valor={i.diferenca} />
                  </>
                )}{' '}
                · comissão {i.comissao_pct ?? 0}% ≈ {formatarKz(i.comissao_sugerida)}
              </span>
            ) : (
              <span>
                {i.numero_documento} · comprovativo {i.referencia ?? '—'}
              </span>
            ),
        },
        { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => <b>{formatarKz(v)}</b> },
        {
          title: 'Estado',
          render: (_, i) =>
            i.bloqueio ? (
              <Tooltip title={i.bloqueio.mensagem}>
                <span>
                  <EstadoPOS estado={i.estado} />
                </span>
              </Tooltip>
            ) : (
              <EstadoPOS estado={i.estado} />
            ),
        },
        {
          title: '',
          key: 'accoes',
          render: (_, i) => {
            const a = accoesItemPrestacao(pode, i, sessao.operador_id, utilizador?.id);
            if (i.bloqueio) return <Typography.Text type="secondary" style={{ fontSize: 12 }}>{i.bloqueio.mensagem}</Typography.Text>;
            if (i.liquidacao) return <Typography.Text type="secondary" style={{ fontSize: 12 }}>{`${i.liquidacao.alvo === 'FOLHA_CAIXA' ? 'Folha de caixa' : 'Tesouraria'} · ${formatarData(i.liquidacao.data)}`}</Typography.Text>;
            return a.visivel ? (
              <Tooltip title={a.bloqueio}>
                <Button size="small" type="primary" icon={<CheckOutlined />} disabled={!!a.bloqueio} onClick={() => aoRegistar(i)}>
                  Registar
                </Button>
              </Tooltip>
            ) : null;
          },
        },
      ]}
    />
  );
}

interface FormRegisto {
  data: Dayjs;
  sessao_caixa_id?: number;
  conta_financeira?: string;
  comissao?: number | null;
  comissao_deduzida?: boolean;
}

function ModalRegistar({ alvo, folhas, aoFechar }: { alvo: { sessao: SessaoPrestacao; item: ItemPrestacao } | null; folhas: FolhaCaixa[]; aoFechar: () => void }) {
  const [form] = Form.useForm<FormRegisto>();
  const accao = useAccao({ invalidar: [['pos'], ['tesouraria']], aoSucesso: aoFechar, tituloErro: 'Não foi possível registar a prestação de contas' });
  const item = alvo?.item;
  useEffect(() => {
    if (!item) return;
    form.setFieldsValue({
      data: dayjs(),
      sessao_caixa_id: folhas.find((f) => f.codigo_conta === item.conta_liquidacao)?.id,
      conta_financeira: item.conta_liquidacao ?? undefined,
      comissao: item.comissao_sugerida ? Number(item.comissao_sugerida) : null,
      comissao_deduzida: item.comissao_deduzida ?? true,
    });
  }, [item, folhas, form]);

  const enviar = (v: FormRegisto) => {
    if (!alvo || !item) return;
    const base = { chave_item: item.chave_item, data: dataApi(v.data) };
    const dados =
      item.natureza === 'NUMERARIO'
        ? { ...base, sessao_caixa_id: v.sessao_caixa_id }
        : item.natureza === 'TPA'
          ? { ...base, conta_financeira: v.conta_financeira, comissao: v.comissao ?? 0, comissao_deduzida: v.comissao_deduzida }
          : { ...base, conta_financeira: v.conta_financeira };
    accao.mutate({ url: `/pos/sessoes/${alvo.sessao.id}/prestacao`, dados });
  };

  return (
    <Modal open={!!alvo} title={item ? `Prestar ${item.nome ?? item.natureza} · ${formatarKz(item.valor)} Kz` : ''} okText="Registar" cancelText="Cancelar" confirmLoading={accao.isPending} onCancel={aoFechar} onOk={() => form.submit()} width={larguraModal(560)} destroyOnHidden>
      {item && (
        <Form form={form} layout="vertical" onFinish={enviar}>
          <Alert
            type="info"
            showIcon
            style={{ marginBottom: 12 }}
            message={
              item.natureza === 'NUMERARIO'
                ? `${item.movimento === 'PAG' ? 'Pagamento' : 'Recebimento'} na folha de caixa aberta (D caixa / C transitória ${item.conta_transitoria}).`
                : item.natureza === 'TPA'
                  ? `Recebimento pendente na tesouraria pelo valor do sistema (C transitória ${item.conta_transitoria}).`
                  : `Recebimento na tesouraria do comprovativo ${item.referencia ?? ''} (${item.numero_documento ?? ''}).`
            }
          />
          <Form.Item name="data" label="Data" rules={[{ required: true, message: 'Indique a data.' }]}>
            <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
          </Form.Item>
          {item.natureza === 'NUMERARIO' ? (
            <Form.Item name="sessao_caixa_id" label="Folha de caixa aberta" extra={folhas.length ? undefined : 'Não há folhas de caixa abertas: abra uma sessão na folha de caixa da Tesouraria.'}>
              <Select allowClear placeholder={`Folha da conta ${item.conta_liquidacao ?? ''}`} options={folhas.map((f) => ({ value: f.id, label: `${f.codigo_conta} · n.º ${f.id} · ${f.operador ?? '—'} · ${formatarData(f.data_abertura)}` }))} />
            </Form.Item>
          ) : (
            <Form.Item name="conta_financeira" label="Conta bancária (43)" extra="Por omissão, a conta de liquidação do meio no terminal.">
              <SeletorConta prefixo="43" />
            </Form.Item>
          )}
          {item.natureza === 'TPA' && (
            <Flex gap={16} wrap>
              <Form.Item name="comissao" label={`Comissão (${item.comissao_pct ?? 0}% sugerido)`}>
                <InputNumber<number> min={0} precision={2} decimalSeparator="," suffix="Kz" style={{ width: 200, maxWidth: '100%' }} />
              </Form.Item>
              <Form.Item name="comissao_deduzida" label="Deduzida no recebimento" valuePropName="checked">
                <Switch />
              </Form.Item>
            </Flex>
          )}
        </Form>
      )}
    </Modal>
  );
}

function ListaLiquidacoes() {
  const { pode } = useSessao();
  const [estado, setEstado] = useState<string | undefined>('REGISTADO');
  const [natureza, setNatureza] = useState<string>();
  const [alvo, setAlvo] = useState<string>();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [anular, setAnular] = useState<Liquidacao | null>(null);
  const accao = useAccao({ invalidar: [['pos'], ['tesouraria']], aoSucesso: () => setAnular(null) });

  return (
    <>
      <BarraFiltros>
        <Select allowClear placeholder="Estado" style={{ width: 160 }} value={estado} onChange={setEstado} options={opcoesEstadoPOS(['REGISTADO', 'ANULADO'])} />
        <Select allowClear placeholder="Natureza" style={{ width: 170 }} value={natureza} onChange={setNatureza} options={opcoesEstadoPOS(['NUMERARIO', 'TPA', 'TRANSFERENCIA'])} />
        <Select
          allowClear
          placeholder="Destino"
          style={{ width: 170 }}
          value={alvo}
          onChange={setAlvo}
          options={[
            { value: 'FOLHA_CAIXA', label: 'Folha de caixa' },
            { value: 'TESOURARIA', label: 'Tesouraria' },
          ]}
        />
        <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
      </BarraFiltros>
      <TabelaApi<Liquidacao>
        url="/pos/liquidacoes"
        chaveConsulta={['pos', 'liquidacoes']}
        filtros={{ estado, natureza_registo: natureza, alvo, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
        impressao={{
          titulo: 'Liquidações POS',
          periodo: periodo?.[0] && periodo[1] ? `${formatarData(dataApi(periodo[0]))} a ${formatarData(dataApi(periodo[1]))}` : undefined,
          filtros: [
            `Estado: ${estado ? rotuloEstadoPOS(estado) : 'Todos'}`,
            natureza && `Natureza: ${rotuloEstadoPOS(natureza)}`,
            alvo && `Destino: ${alvo === 'FOLHA_CAIXA' ? 'Folha de caixa' : 'Tesouraria'}`,
          ],
        }}
        columns={[
          { title: 'Data', dataIndex: 'data', render: (v) => formatarData(v) },
          { title: 'Z', dataIndex: 'numero_z' },
          { title: 'Natureza', dataIndex: 'natureza_registo', render: (v) => <EstadoPOS estado={v} /> },
          { title: 'Destino', dataIndex: 'alvo', render: (v) => (v === 'FOLHA_CAIXA' ? 'Folha de caixa' : 'Tesouraria') },
          { title: 'Contas', responsive: ['lg'], render: (_, l) => `${l.conta_transitoria ?? '—'} → ${l.conta_destino ?? '—'}` },
          { title: 'Referência', render: (_, l) => l.numero_documento ?? l.referencia ?? (l.documento_tesouraria_id ? `Doc. #${l.documento_tesouraria_id}` : l.movimento_caixa_id ? `Mov. #${l.movimento_caixa_id}` : '—') },
          { title: 'Bruto', dataIndex: 'montante_bruto', align: 'right', render: (v) => formatarKz(v), responsive: ['md'], totalImpressao: (ls) => formatarKz(somar(ls.map((l) => l.montante_bruto))) },
          { title: 'Comissão', dataIndex: 'comissao', align: 'right', render: (v) => formatarKz(v), responsive: ['md'], totalImpressao: (ls) => formatarKz(somar(ls.map((l) => l.comissao))) },
          { title: 'Líquido', dataIndex: 'montante_liquido', align: 'right', render: (v) => formatarKz(v), totalImpressao: (ls) => formatarKz(somar(ls.map((l) => l.montante_liquido))) },
          { title: 'Estado', dataIndex: 'estado', render: (v, l) => (l.cancelado_em ? <Tooltip title={`${l.cancelado_por ?? ''} · ${formatarDataHora(l.cancelado_em)}`}><span><EstadoPOS estado={v} /></span></Tooltip> : <EstadoPOS estado={v} />) },
          { title: 'Por', dataIndex: 'criado_por', responsive: ['lg'] },
          {
            title: '',
            key: 'accoes',
            fixed: 'right',
            exportar: false,
            render: (_, l) =>
              podeAnularLiquidacao(pode, l) ? (
                <Button size="small" danger icon={<StopOutlined />} onClick={() => setAnular(l)}>
                  Anular
                </Button>
              ) : null,
          },
        ]}
      />
      <ModalMotivo
        aberto={!!anular}
        titulo="Anular liquidação"
        textoOk="Anular"
        aviso="Numerário: retira o movimento da folha ainda aberta. Documentos de tesouraria pendentes são anulados. Documentos integrados ou folhas contabilizadas impedem a anulação."
        carregando={accao.isPending}
        aoFechar={() => setAnular(null)}
        aoConfirmar={(motivo) => anular && accao.mutate({ url: `/pos/liquidacoes/${anular.id}/anular`, dados: { motivo } })}
      />
    </>
  );
}

/** Texto do detalhe de um item a prestar (igual ao do ecrã, sem cores). */
function detalheItem(i: ItemPrestacao): string {
  if (i.natureza === 'NUMERARIO') return `Sistema ${formatarKz(i.numerario_sistema)}${i.desvio_aplicado ? ` · desvio ${formatarKz(i.desvio_aplicado)}` : ''}${i.movimento === 'PAG' ? ' · pagamento' : ''}`;
  if (i.natureza === 'TPA')
    return `Talão ${formatarKz(i.valor_talao)}${i.diferenca && Number(i.diferenca) !== 0 ? ` · dif. ${formatarKz(i.diferenca)}` : ''} · comissão ${i.comissao_pct ?? 0}% ≈ ${formatarKz(i.comissao_sugerida)}`;
  return `${i.numero_documento ?? ''} · comprovativo ${i.referencia ?? '—'}`;
}

/** Sessões por regularizar (agrupadas por Z) para impressão. */
export function documentoPorRegularizar(sessoes: SessaoPrestacao[]): string {
  const linhas = sessoes.flatMap((s) => s.itens.map((i) => ({ s, i })));
  return tabelaHtml({
    linhas,
    totais: true,
    agrupar: {
      chave: (l) => `${l.s.numero_z ?? l.s.codigo_sessao} · ${l.s.codigo_terminal} — ${l.s.nome_terminal} · ${l.s.nome_operador ?? '—'} · ${formatarDataHora(l.s.fechado_em)}`,
      subtotais: true,
    },
    colunas: [
      { titulo: 'Natureza', valor: (l) => rotuloEstadoPOS(l.i.natureza) },
      { titulo: 'Meio', valor: (l) => l.i.nome },
      { titulo: 'Transitória → liquidação', valor: (l) => `${l.i.conta_transitoria ?? '—'} → ${l.i.conta_liquidacao ?? '—'}` },
      { titulo: 'Detalhe', valor: (l) => detalheItem(l.i), quebrar: true },
      { titulo: 'Valor', valor: (l) => l.i.valor, formato: 'moeda', somar: true },
      { titulo: 'Estado', valor: (l) => (l.i.bloqueio ? `${rotuloEstadoPOS(l.i.estado)} (${l.i.bloqueio.mensagem})` : rotuloEstadoPOS(l.i.estado)), quebrar: true },
    ],
  });
}
