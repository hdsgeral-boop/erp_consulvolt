import { Alert, Button, Card, DatePicker, Flex, Form, InputNumber, List, Modal, Select, Skeleton, Space, Switch, Table, Tag, Tooltip, Typography } from 'antd';
import { CheckOutlined, EyeOutlined, SwapOutlined } from '@ant-design/icons';
import { useQuery, type UseQueryResult } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { TabelaApi } from '@/componentes/TabelaApi';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { IconeLegado } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { DetalheSessao, ValorDesvio } from './comum/DetalheSessao';
import { EstadoPOS, rotuloEstadoPOS } from './comum/estados';
import { accoesItemPrestacao, podeAnularLiquidacao } from './comum/regras';
import type { FolhaCaixa, ItemPrestacao, Liquidacao, SessaoPrestacao } from './comum/tipos';
import { destinoLiquidacao } from './contas/formatos';
import { Seccao } from './contas/Seccao';
import { useCoresPos } from './comum/cores';
import { TIPOS_MEIO } from './comum/dados';

/** Tipo do movimento com o rótulo do meio do legado (Numerário, Multicaixa (TPA), Transferência bancária). */
const rotuloTipo = (v: string | null | undefined) => (v && TIPOS_MEIO[v]) || rotuloEstadoPOS(v);

interface Pendentes {
  sessoes: SessaoPrestacao[];
  folhas_caixa_abertas: FolhaCaixa[];
}

/**
 * POS › Prestação de contas (ecrã pos_prestacao) como no sistema anterior (renderPrestacao, pos_prestacao.js:558-609): na
 * mesma página o cabeçalho «Prestação de contas» com a Folha de Caixa aberta (ou o aviso de que não há), as sessões por
 * regularizar (numerário na Folha de Caixa, TPA e transferências na Tesouraria, com comissão) e a tabela «Movimentos de
 * prestação de contas» (com «Anular») — ADR-048.
 */
export default function Prestacao() {
  const consulta = useQuery({ queryKey: ['pos', 'prestacao'], queryFn: () => obter<Pendentes>('/pos/prestacao') });
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao carregar a prestação de contas');
  }, [consulta.error]);
  const folha = consulta.data?.folhas_caixa_abertas?.[0];
  return (
    <>
      <Seccao
        titulo="Prestação de contas"
        icone="hand-holding-usd"
        cor="#059669"
        descricao="Regulariza as contas transitórias: o numerário entra na Folha de Caixa, o fecho do TPA e cada transferência geram um recebimento pendente na Tesouraria (integrado depois pelo processo normal)."
        extra={
          <BotoesExportar
            tamanho="small"
            desactivado={!consulta.data?.sessoes.length}
            obterPedido={() => ({ titulo: 'Prestação de contas · sessões por regularizar', conteudo: documentoPorRegularizar(consulta.data?.sessoes ?? []) })}
          />
        }
      >
        <AvisoFolhaCaixa carregando={consulta.isLoading} folha={folha} />
      </Seccao>
      <PorRegularizar consulta={consulta} />
      <Seccao titulo="Movimentos de prestação de contas" icone="history" cor="#334155" nivel={4}>
        <ListaLiquidacoes />
      </Seccao>
    </>
  );
}

/** Linha da Folha de Caixa aberta (ou o aviso «Não há Folha de Caixa aberta…», pos_prestacao.js:598). */
export function AvisoFolhaCaixa({ carregando, folha }: { carregando: boolean; folha: FolhaCaixa | undefined }) {
  if (carregando) return null;
  return (
    <div style={{ marginTop: '0.5rem', fontSize: '0.8rem' }} role="status">
      {folha ? (
        <>
          <IconeLegado icone="cash-register" style={{ color: '#059669' }} /> Folha de Caixa aberta: conta <strong>{folha.codigo_conta}</strong> · {folha.operador ?? ''} · desde{' '}
          {formatarData(folha.data_abertura)}
        </>
      ) : (
        <>
          <IconeLegado icone="exclamation-triangle" style={{ color: '#b45309' }} /> Não há Folha de Caixa aberta: abra uma sessão na Folha de Caixa para receber o numerário.
        </>
      )}
    </div>
  );
}

function PorRegularizar({ consulta }: { consulta: UseQueryResult<Pendentes> }) {
  const cores = useCoresPos();
  const { pode, utilizador } = useSessao();
  const [detalhe, setDetalhe] = useState<number | null>(null);
  const [registar, setRegistar] = useState<{ sessao: SessaoPrestacao; item: ItemPrestacao } | null>(null);

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
      {consulta.isLoading ? (
        <Skeleton active />
      ) : !consulta.data?.sessoes.length ? (
        <div style={{ background: cores.fundo, border: `1px solid ${cores.borda}`, borderRadius: 4, padding: '1rem', marginBottom: 16, color: cores.textoSec, textAlign: 'center' }}>
          Sem sessões por regularizar.
        </div>
      ) : (
        <Flex vertical gap={16} style={{ marginBottom: 16 }}>
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

/**
 * «Movimentos de prestação de contas» (pos_prestacao.js:601-607): os movimentos não anulados, do mais recente para o mais
 * antigo — Data, Sessão, Tipo, Destino, Bruto, Comissão, Líquido, Registado por — com filtros por coluna e «Anular».
 */
function ListaLiquidacoes() {
  const { pode } = useSessao();
  const [anular, setAnular] = useState<Liquidacao | null>(null);
  const accao = useAccao({ invalidar: [['pos'], ['tesouraria']], aoSucesso: () => setAnular(null) });

  return (
    <>
      <TabelaApi<Liquidacao>
        idVista="prestacao-movimentos"
        modos={false}
        url="/pos/liquidacoes"
        chaveConsulta={['pos', 'liquidacoes']}
        filtros={{ estado: 'REGISTADO' }}
        size="small"
        impressao={{ titulo: 'Movimentos de prestação de contas' }}
        columns={[
          { title: 'Data', dataIndex: 'data', render: (v) => formatarData(v) },
          { title: 'Sessão', dataIndex: 'numero_z' },
          {
            title: 'Tipo',
            dataIndex: 'natureza_registo',
            render: (v, l) => (
              <>
                {rotuloTipo(v)}
                {l.numero_documento && (
                  <div>
                    <small>{l.numero_documento}</small>
                  </div>
                )}
              </>
            ),
            valorFiltro: (l) => `${rotuloTipo(l.natureza_registo)} ${l.numero_documento ?? ''}`,
            valorImpressao: (l) => `${rotuloTipo(l.natureza_registo)}${l.numero_documento ? ` · ${l.numero_documento}` : ''}`,
          },
          { title: 'Destino', key: 'destino', render: (_, l) => destinoLiquidacao(l), valorFiltro: (l) => destinoLiquidacao(l), valorImpressao: (l) => destinoLiquidacao(l) },
          { title: 'Bruto', dataIndex: 'montante_bruto', align: 'right', render: (v) => formatarKz(v), total: true },
          { title: 'Comissão', dataIndex: 'comissao', align: 'right', render: (v) => formatarKz(v ?? 0), total: true },
          { title: 'Líquido', dataIndex: 'montante_liquido', align: 'right', render: (v) => <b>{formatarKz(v)}</b>, total: true },
          {
            title: 'Registado por',
            dataIndex: 'criado_por',
            render: (v, l) => (
              <>
                {v ?? ''}
                <div>
                  <small>{formatarDataHora(l.criado_em)}</small>
                </div>
              </>
            ),
            valorImpressao: (l) => `${l.criado_por ?? ''} ${formatarDataHora(l.criado_em)}`.trim(),
          },
        ]}
        accoes={(l) => [{ icone: 'undo', titulo: 'Anular', texto: 'Anular', variante: 'perigo', oculto: !podeAnularLiquidacao(pode, l), onClick: () => setAnular(l) }]}
        vazio={{ icone: 'history', titulo: 'Sem movimentos.' }}
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
