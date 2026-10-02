import { Alert, Button, DatePicker, Descriptions, Drawer, Flex, Form, Input, InputNumber, Modal, Radio, Skeleton, Space, Table, Timeline, Tooltip, Typography } from 'antd';
import { DeleteOutlined, EditOutlined, LogoutOutlined, PlusOutlined, SaveOutlined, StopOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { BotoesExportar, pares, tabelaHtml, type PedidoImpressao } from '@/componentes/impressao';
import { larguraGaveta, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { SeletorProduto, SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import { formatarCentimos } from '../comum/calculos';
import { useCatalogoPOS } from '../comum/dados';
import { EstadoPOS, rotuloEstadoPOS } from '../comum/estados';
import { accoesEstadia } from '../comum/regras';
import type { Terminal } from '../comum/tipos';
import { precoQuarto, quantidadeMinima, type ConsumoEstadia, type Estadia, type Quarto } from './tipos';

export function useEstadia(id: number | null) {
  return useQuery({ queryKey: ['pos', 'hotelaria', 'estadia', id], queryFn: () => obter<Estadia>(`/pos/hotelaria/estadias/${id}`), enabled: !!id });
}

/** Check-in num quarto livre (POST /pos/hotelaria/sessoes/{sessao}/checkin). */
export function ModalCheckin({ quarto, terminal, aoFechar }: { quarto: Quarto | null; terminal: Terminal | undefined; aoFechar: () => void }) {
  const { pode } = useSessao();
  const [form] = Form.useForm<{ cliente_hospede_id: number; modo: 'DIA' | 'HORA'; quantidade: number; preco_unitario: number; entrada_em?: Dayjs | null; numero_hospedes?: number; observacoes?: string }>();
  const modo = Form.useWatch('modo', form) ?? 'DIA';
  const quantidade = Form.useWatch('quantidade', form) ?? 0;
  const preco = Form.useWatch('preco_unitario', form) ?? 0;
  const checkin = useAccao({ invalidar: [['pos']], aoSucesso: aoFechar, tituloErro: 'Não foi possível registar o check-in' });
  const sessaoId = terminal?.sessao_aberta?.id;
  useEffect(() => {
    if (quarto) form.setFieldsValue({ modo: 'DIA', quantidade: 1, preco_unitario: precoQuarto('DIA', quarto), entrada_em: null, numero_hospedes: 1, observacoes: undefined, cliente_hospede_id: undefined });
  }, [quarto, form]);
  const mudarModo = (m: 'DIA' | 'HORA') => quarto && form.setFieldsValue({ quantidade: quantidadeMinima(m, quarto), preco_unitario: precoQuarto(m, quarto) });

  return (
    <Modal open={!!quarto} title={`Check-in · ${quarto?.nome ?? ''}`} okText="Registar check-in" cancelText="Cancelar" confirmLoading={checkin.isPending} okButtonProps={{ disabled: !sessaoId }} onCancel={aoFechar} onOk={() => form.submit()} width={larguraModal(600)} destroyOnHidden>
      {!sessaoId && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="O terminal de hotelaria não tem sessão aberta: abra-a na frente de caixa." />}
      {quarto && (
        <Form
          form={form}
          layout="vertical"
          onFinish={(v) => {
            const precoAlterado = Math.round(v.preco_unitario * 100) !== Math.round(precoQuarto(v.modo, quarto) * 100);
            checkin.mutate({
              url: `/pos/hotelaria/sessoes/${sessaoId}/checkin`,
              dados: {
                produto_quarto_id: quarto.produto_id,
                cliente_hospede_id: v.cliente_hospede_id,
                modo: v.modo,
                quantidade: v.quantidade,
                ...(precoAlterado ? { preco_unitario: v.preco_unitario } : {}),
                entrada_em: v.entrada_em ? v.entrada_em.format('YYYY-MM-DD HH:mm:ss') : undefined,
                numero_hospedes: v.numero_hospedes,
                observacoes: v.observacoes?.trim() || undefined,
              },
            });
          }}
        >
          <Form.Item name="cliente_hospede_id" label="Hóspede (cliente)" rules={[{ required: true, message: 'Escolha o hóspede.' }]}>
            <SeletorTerceiro papel="CLIENTE" />
          </Form.Item>
          <Form.Item name="modo" label="Modalidade">
            <Radio.Group onChange={(e) => mudarModo(e.target.value)} optionType="button">
              <Radio value="DIA">Diária ({formatarKz(quarto.preco_por_dia)} Kz)</Radio>
              <Radio value="HORA">À hora ({formatarKz(quarto.preco_por_hora)} Kz, mín. {Number(quarto.horas_minimas)} h)</Radio>
            </Radio.Group>
          </Form.Item>
          <Flex gap={12} wrap>
            <Form.Item name="quantidade" label={modo === 'HORA' ? 'Horas' : 'Diárias'} rules={[{ required: true }]}>
              <InputNumber<number> min={quantidadeMinima(modo, quarto)} precision={modo === 'HORA' ? 1 : 0} style={{ width: 120 }} />
            </Form.Item>
            <Form.Item name="preco_unitario" label="Preço (c/ IVA)" extra={pode('pos_desconto') ? undefined : 'Alterar o preço exige a permissão de descontos.'}>
              <InputNumber<number> min={0} precision={2} decimalSeparator="," style={{ width: 160 }} disabled={!pode('pos_desconto')} />
            </Form.Item>
            <Form.Item name="numero_hospedes" label="Hóspedes">
              <InputNumber<number> min={1} precision={0} style={{ width: 90 }} />
            </Form.Item>
            <Form.Item name="entrada_em" label="Entrada" extra="Vazio: agora.">
              <DatePicker showTime format="DD/MM/YYYY HH:mm" />
            </Form.Item>
          </Flex>
          <Form.Item name="observacoes" label="Observações">
            <Input.TextArea rows={2} maxLength={2000} />
          </Form.Item>
          <Typography.Text>
            Alojamento estimado: <b>{formatarCentimos(Math.round(quantidade * preco * 100))} Kz</b>
          </Typography.Text>
        </Form>
      )}
    </Modal>
  );
}

/** Estadia aberta ou fechada: dados, consumos (gravados na estadia; o stock sai no check-out), alteração e anulação. */
export function DetalheEstadia({ id, terminal, aoFechar, aoCheckout }: { id: number | null; terminal: Terminal | undefined; aoFechar: () => void; aoCheckout: (e: Estadia) => void }) {
  const { pode } = useSessao();
  const consulta = useEstadia(id);
  const e = consulta.data;
  const catalogo = useCatalogoPOS();
  const [consumos, setConsumos] = useState<ConsumoEstadia[]>([]);
  const [novoProduto, setNovoProduto] = useState<number>();
  const [alterar, setAlterar] = useState(false);
  const [anular, setAnular] = useState(false);
  const accao = useAccao({
    invalidar: [['pos']],
    aoSucesso: () => {
      setAlterar(false);
      setAnular(false);
    },
  });
  useEffect(() => setConsumos(e?.itens ?? []), [e]);
  const a = e ? accoesEstadia(pode, e, !!terminal?.sessao_aberta) : null;
  const alterados = JSON.stringify(consumos) !== JSON.stringify(e?.itens ?? []);
  const nome = (pid: number) => catalogo.data?.find((p) => p.id === pid)?.nome ?? `Produto #${pid}`;

  const acrescentar = () => {
    const p = catalogo.data?.find((x) => x.id === novoProduto);
    if (!p) return;
    setConsumos((c) => [...c, { produto_id: p.id, descricao: p.nome, quantidade: 1, preco_unitario: Number(p.preco_unitario ?? 0) }]);
    setNovoProduto(undefined);
  };

  return (
    <Drawer
      open={!!id}
      onClose={aoFechar}
      width={larguraGaveta(820)}
      destroyOnHidden
      title={e ? `${e.nome_quarto} · ${e.nome_hospede ?? ''}` : 'Estadia'}
      extra={
        e &&
        a && (
          <Space wrap>
            <BotoesExportar tamanho="small" obterPedido={() => pedidoEstadia(e, consumos, nome)} />
            {a.alterar && (
              <Button icon={<EditOutlined />} onClick={() => setAlterar(true)}>
                Alterar
              </Button>
            )}
            {a.anular.visivel && (
              <Tooltip title={a.anular.bloqueio}>
                <Button danger icon={<StopOutlined />} disabled={!!a.anular.bloqueio} onClick={() => setAnular(true)}>
                  Anular check-in
                </Button>
              </Tooltip>
            )}
            {a.checkout && (
              <Button type="primary" icon={<LogoutOutlined />} disabled={alterados} onClick={() => aoCheckout(e)}>
                Check-out
              </Button>
            )}
          </Space>
        )
      }
    >
      {!e ? (
        <Skeleton active />
      ) : (
        <>
          {e.proposta_atraso && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={`Saída tardia: a estadia passaria a ${e.proposta_atraso.quantidade} (${e.modo === 'HORA' ? 'horas' : 'diárias'}). Decide-se no check-out.`} />}
          <Descriptions size="small" bordered column={{ xs: 1, sm: 2 }}>
            <Descriptions.Item label="Estado">
              <EstadoPOS estado={e.estado} />
            </Descriptions.Item>
            <Descriptions.Item label="Modalidade">{e.modo === 'HORA' ? `À hora · ${Number(e.quantidade)} h` : `Diária · ${Number(e.quantidade)}`}</Descriptions.Item>
            <Descriptions.Item label="Entrada">{formatarDataHora(e.entrada_em)}</Descriptions.Item>
            <Descriptions.Item label="Saída prevista">{formatarDataHora(e.saida_prevista_em)}</Descriptions.Item>
            <Descriptions.Item label="Preço">{formatarKz(e.preco_unitario)} Kz</Descriptions.Item>
            <Descriptions.Item label="Hóspedes">{e.numero_hospedes ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Alojamento">{formatarKz(e.total_alojamento)} Kz</Descriptions.Item>
            <Descriptions.Item label="Consumos">{formatarKz(e.total_consumos)} Kz</Descriptions.Item>
            <Descriptions.Item label="Total em aberto">
              <b>{formatarKz(e.total_em_aberto)} Kz</b>
            </Descriptions.Item>
            {e.numero_venda && <Descriptions.Item label="Factura">{e.numero_venda}</Descriptions.Item>}
            {e.observacoes && (
              <Descriptions.Item label="Observações" span={2}>
                {e.observacoes}
              </Descriptions.Item>
            )}
            {e.motivo_cancelamento && (
              <Descriptions.Item label="Anulação" span={2}>
                {e.motivo_cancelamento}
              </Descriptions.Item>
            )}
          </Descriptions>

          <Typography.Title level={5} style={{ marginTop: 16 }}>
            Consumos
          </Typography.Title>
          <Table<ConsumoEstadia>
            size="small"
            scroll={scrollTabela()}
            pagination={false}
            rowKey={(_, n) => String(n)}
            dataSource={consumos}
            columns={[
              { title: 'Produto', render: (_, c) => c.descricao ?? nome(c.produto_id) },
              {
                title: 'Qtd.',
                render: (_, c, n) =>
                  a?.consumos ? (
                    <InputNumber<number> min={0.001} precision={3} style={{ width: 90 }} value={Number(c.quantidade)} onChange={(v) => setConsumos((cs) => cs.map((x, k) => (k === n ? { ...x, quantidade: v ?? 0 } : x)))} />
                  ) : (
                    Number(c.quantidade)
                  ),
              },
              {
                title: 'Preço',
                render: (_, c, n) =>
                  a?.consumos && pode('pos_desconto') ? (
                    <InputNumber<number> min={0} precision={2} decimalSeparator="," style={{ width: 120 }} value={Number(c.preco_unitario)} onChange={(v) => setConsumos((cs) => cs.map((x, k) => (k === n ? { ...x, preco_unitario: v ?? 0 } : x)))} />
                  ) : (
                    formatarKz(c.preco_unitario)
                  ),
              },
              { title: 'Total', align: 'right', render: (_, c) => formatarCentimos(Math.round(Number(c.quantidade) * Number(c.preco_unitario) * 100)) },
              { title: '', render: (_, _c, n) => a?.consumos && <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Retirar" onClick={() => setConsumos((cs) => cs.filter((_x, k) => k !== n))} /> },
            ]}
          />
          {a?.consumos && (
            <Flex gap={8} style={{ marginTop: 8 }} wrap>
              <SeletorProduto style={{ width: 320, maxWidth: '100%' }} value={novoProduto} onChange={setNovoProduto} />
              <Button icon={<PlusOutlined />} disabled={!novoProduto} onClick={acrescentar}>
                Acrescentar
              </Button>
              <Button
                type="primary"
                icon={<SaveOutlined />}
                disabled={!alterados}
                loading={accao.isPending}
                onClick={() =>
                  accao.mutate({
                    metodo: 'put',
                    url: `/pos/hotelaria/estadias/${e.id}/consumos`,
                    dados: { linhas: consumos.map((c) => ({ produto_id: c.produto_id, quantidade: Number(c.quantidade), preco_unitario: Number(c.preco_unitario) })) },
                  })
                }
              >
                Gravar consumos
              </Button>
            </Flex>
          )}
          {(e.historico_alteracoes ?? []).length > 0 && (
            <>
              <Typography.Title level={5} style={{ marginTop: 16 }}>
                Histórico
              </Typography.Title>
              <Timeline items={(e.historico_alteracoes ?? []).map((h) => ({ children: `${formatarDataHora(h.em)} · ${h.por} — ${h.texto}` }))} />
            </>
          )}
          <ModalAlterar estadia={alterar ? e : null} carregando={accao.isPending} aoFechar={() => setAlterar(false)} aoConfirmar={(dados) => accao.mutate({ metodo: 'put', url: `/pos/hotelaria/estadias/${e.id}`, dados })} />
          <ModalMotivo aberto={anular} titulo={`Anular o check-in do ${e.nome_quarto}`} textoOk="Anular" aviso="Só é possível sem consumos." carregando={accao.isPending} aoFechar={() => setAnular(false)} aoConfirmar={(motivo) => accao.mutate({ url: `/pos/hotelaria/estadias/${e.id}/anular`, dados: { motivo } })} />
        </>
      )}
    </Drawer>
  );
}

function ModalAlterar({ estadia, carregando, aoFechar, aoConfirmar }: { estadia: Estadia | null; carregando: boolean; aoFechar: () => void; aoConfirmar: (d: Record<string, unknown>) => void }) {
  const { pode } = useSessao();
  const [form] = Form.useForm();
  useEffect(() => {
    if (estadia)
      form.setFieldsValue({
        cliente_hospede_id: undefined,
        entrada_em: dayjs(estadia.entrada_em),
        quantidade: Number(estadia.quantidade),
        preco_unitario: Number(estadia.preco_unitario),
        numero_hospedes: estadia.numero_hospedes,
        observacoes: estadia.observacoes,
      });
  }, [estadia, form]);
  return (
    <Modal open={!!estadia} title="Alterar estadia" okText="Gravar" cancelText="Cancelar" confirmLoading={carregando} onCancel={aoFechar} onOk={() => form.submit()} width={larguraModal(600)} destroyOnHidden>
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) =>
          aoConfirmar({
            cliente_hospede_id: v.cliente_hospede_id || undefined,
            entrada_em: v.entrada_em ? (v.entrada_em as Dayjs).format('YYYY-MM-DD HH:mm:ss') : undefined,
            quantidade: v.quantidade,
            ...(Math.round(v.preco_unitario * 100) !== Math.round(Number(estadia?.preco_unitario) * 100) ? { preco_unitario: v.preco_unitario } : {}),
            numero_hospedes: v.numero_hospedes,
            observacoes: v.observacoes?.trim() || null,
          })
        }
      >
        <Form.Item name="cliente_hospede_id" label="Trocar o hóspede" extra={`Actual: ${estadia?.nome_hospede ?? '—'}`}>
          <SeletorTerceiro papel="CLIENTE" />
        </Form.Item>
        <Flex gap={12} wrap>
          <Form.Item name="entrada_em" label="Entrada">
            <DatePicker showTime format="DD/MM/YYYY HH:mm" />
          </Form.Item>
          <Form.Item name="quantidade" label={estadia?.modo === 'HORA' ? 'Horas' : 'Diárias'}>
            <InputNumber<number> min={0.5} precision={estadia?.modo === 'HORA' ? 1 : 0} style={{ width: 110 }} />
          </Form.Item>
          <Form.Item name="preco_unitario" label="Preço">
            <InputNumber<number> min={0} precision={2} decimalSeparator="," disabled={!pode('pos_desconto')} style={{ width: 150 }} />
          </Form.Item>
          <Form.Item name="numero_hospedes" label="Hóspedes">
            <InputNumber<number> min={1} precision={0} style={{ width: 90 }} />
          </Form.Item>
        </Flex>
        <Form.Item name="observacoes" label="Observações">
          <Input.TextArea rows={2} maxLength={2000} />
        </Form.Item>
      </Form>
    </Modal>
  );
}

/** Estadia (quarto, hóspede, valores e consumos) em A4. */
export function pedidoEstadia(e: Estadia, consumos: ConsumoEstadia[], nome: (produtoId: number) => string): PedidoImpressao {
  const dados: [string, string | number][] = [
    ['Estado', rotuloEstadoPOS(e.estado)],
    ['Modalidade', e.modo === 'HORA' ? `À hora · ${Number(e.quantidade)} h` : `Diária · ${Number(e.quantidade)}`],
    ['Entrada', formatarDataHora(e.entrada_em)],
    ['Saída prevista', formatarDataHora(e.saida_prevista_em)],
    ['Preço', `${formatarKz(e.preco_unitario)} Kz`],
    ['Hóspedes', e.numero_hospedes ?? '—'],
    ['Alojamento', `${formatarKz(e.total_alojamento)} Kz`],
    ['Consumos', `${formatarKz(e.total_consumos)} Kz`],
    ['Total em aberto', `${formatarKz(e.total_em_aberto)} Kz`],
  ];
  if (e.numero_venda) dados.push(['Factura', e.numero_venda]);
  if (e.observacoes) dados.push(['Observações', e.observacoes]);
  if (e.motivo_cancelamento) dados.push(['Anulação', e.motivo_cancelamento]);
  const tabela = tabelaHtml<ConsumoEstadia>({
    legenda: 'Consumos',
    linhas: consumos,
    totais: true,
    vazio: 'Sem consumos.',
    colunas: [
      { titulo: 'Produto', valor: (c) => c.descricao ?? nome(c.produto_id) },
      { titulo: 'Qtd.', valor: (c) => Number(c.quantidade), formato: 'numero' },
      { titulo: 'Preço', valor: (c) => c.preco_unitario, formato: 'moeda' },
      { titulo: 'Total', valor: (c) => (Number(c.quantidade) * Number(c.preco_unitario)).toFixed(2), formato: 'moeda', somar: true },
    ],
  });
  return { titulo: `Estadia · ${e.nome_quarto}`, subtitulo: e.nome_hospede ?? undefined, conteudo: `${pares(dados, 2)}${tabela}` };
}
