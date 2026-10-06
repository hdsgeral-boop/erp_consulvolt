import { Alert, Button, Card, Descriptions, Divider, Flex, Input, InputNumber, Modal, Radio, Result, Skeleton, Space, Table, Typography } from 'antd';
import { PrinterOutlined } from '@ant-design/icons';
import { useEffect, useMemo, useState } from 'react';
import { enviar } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import { formatarCentimos, liquidarTroco, pagamentosParaApi, paraCentimos, ratearPagamentos, resumirPagamentos, type Pagamento } from '../comum/calculos';
import { meiosActivos, PainelPagamentos } from '../comum/PainelPagamentos';
import type { Terminal, VendaEmitida } from '../comum/tipos';
import { htmlTalaoVenda, imprimirHtml, lerPreferencias, reimprimir, useCabecalhoTalao } from '../comum/impressao';
import type { SimulacaoCheckout } from './tipos';

interface Props {
  aberto: boolean;
  estadias: { id: number; nome_quarto: string }[];
  terminal: Terminal;
  aoFechar: () => void;
}

/**
 * Check-out de um ou mais quartos: decisão da saída tardia (recalcular ou manter), desconto (pos_desconto), factura por
 * quarto ou única, simulação no servidor e pagamento misto rateado pelas facturas (ServicoCheckoutHotel).
 * M-16: no fim imprime o talão de cada factura-recibo (legado: printPOSThermalReceipt do hotel), automático se o posto o tiver activo.
 */
export function Checkout({ aberto, estadias, terminal, aoFechar }: Props) {
  const { pode, empresa } = useSessao();
  const cabecalho = useCabecalhoTalao();
  const sessaoId = terminal.sessao_aberta?.id;
  const [opcoes, setOpcoes] = useState<Record<number, 'RECALCULAR' | 'MANTER' | undefined>>({});
  const [desconto, setDesconto] = useState(0);
  const [modo, setModo] = useState<'POR_QUARTO' | 'UNICA'>('POR_QUARTO');
  const [cliente, setCliente] = useState<number>();
  const [observacoes, setObservacoes] = useState('');
  const [simulacao, setSimulacao] = useState<SimulacaoCheckout | null>(null);
  const [aSimular, setASimular] = useState(false);
  const [pagamentos, setPagamentos] = useState<Pagamento[]>([]);
  const [concluido, setConcluido] = useState<{ total: string; troco: string; vendas: VendaEmitida[] } | null>(null);
  const meios = meiosActivos(terminal.meios_pagamento);
  const chaveEstadias = estadias.map((e) => e.id).join(',');

  useEffect(() => {
    if (aberto) {
      setOpcoes({});
      setDesconto(0);
      setModo(estadias.length > 1 ? 'UNICA' : 'POR_QUARTO');
      setCliente(undefined);
      setObservacoes('');
      setPagamentos([]);
      setConcluido(null);
    }
  }, [aberto, chaveEstadias]);

  const corpo = useMemo(
    () => ({
      estadias: chaveEstadias
        .split(',')
        .filter(Boolean)
        .map((id) => ({ id: Number(id), opcao_atraso: opcoes[Number(id)] })),
      percentagem_desconto: desconto || undefined,
      modo_faturacao: modo,
      cliente_id: modo === 'UNICA' ? cliente : undefined,
      observacoes: observacoes.trim() || undefined,
    }),
    [chaveEstadias, opcoes, desconto, modo, cliente, observacoes],
  );

  useEffect(() => {
    if (!aberto || !sessaoId || !corpo.estadias.length || (modo === 'UNICA' && !cliente)) {
      setSimulacao(null);
      return;
    }
    let activo = true;
    setASimular(true);
    const t = window.setTimeout(() => {
      enviar<SimulacaoCheckout>('post', `/pos/hotelaria/sessoes/${sessaoId}/checkout/simular`, corpo)
        .then(({ dados }) => activo && setSimulacao(dados))
        .catch((e) => activo && notificarErro(e, 'Não foi possível simular o check-out'))
        .finally(() => activo && setASimular(false));
    }, 300);
    return () => {
      activo = false;
      window.clearTimeout(t);
    };
  }, [aberto, sessaoId, corpo, modo, cliente]);

  const checkout = useAccao<{ total: string; troco: string; vendas: VendaEmitida[] }>({
    invalidar: [['pos']],
    aoSucesso: (r) => {
      setConcluido(r);
      if (lerPreferencias(empresa?.id).automatico) imprimirTaloes(r.vendas, false);
    },
    tituloErro: 'Não foi possível concluir o check-out',
  });

  const total = paraCentimos(simulacao?.total);
  const resumo = resumirPagamentos(pagamentos, total);
  const porDecidir = simulacao?.saidas_por_decidir ?? [];
  const rateio = useMemo(() => {
    if (!simulacao || !resumo.valido) return null;
    const { linhas, troco } = liquidarTroco(pagamentos, total);
    return ratearPagamentos(
      linhas.map((l) => ({ meio_id: l.meio_id, tipo: l.tipo, valor: l.valor, referencia: l.referencia })),
      troco,
      simulacao.facturas.map((f) => paraCentimos(f.total)),
    );
  }, [simulacao, pagamentos, total, resumo.valido]);
  /** Talão de cada factura do check-out (com o quarto em «Mesa/Quarto»). */
  const imprimirTaloes = (vendas: VendaEmitida[], manual: boolean) => {
    const p = lerPreferencias(empresa?.id);
    vendas.forEach((v, i) => {
      const html = htmlTalaoVenda(v, cabecalho({ terminal: `${terminal.codigo} — ${terminal.nome}`, rotuloLocal: 'Quarto(s)' }), p);
      window.setTimeout(() => (manual ? reimprimir(html, p) : imprimirHtml(html)), i * 800);
    });
  };
  const nomeMeio = (id: string) => meios.find((m) => m.id === id)?.nome ?? id;

  return (
    <Modal
      open={aberto}
      onCancel={aoFechar}
      width={larguraModal(900)}
      title={`Check-out · ${estadias.map((e) => e.nome_quarto).join(', ')}`}
      okText={concluido ? 'Fechar' : 'Concluir check-out'}
      cancelText="Cancelar"
      maskClosable={false}
      destroyOnHidden
      okButtonProps={{ disabled: !concluido && (!simulacao || aSimular || !resumo.valido || porDecidir.length > 0) }}
      confirmLoading={checkout.isPending}
      onOk={() => (concluido ? aoFechar() : checkout.mutate({ url: `/pos/hotelaria/sessoes/${sessaoId}/checkout`, dados: { ...corpo, pagamentos: pagamentosParaApi(pagamentos) } }))}
    >
      {concluido ? (
        <Result
          status="success"
          title="Check-out concluído"
          subTitle={`Emitido(s): ${concluido.vendas.map((v) => v.numero_documento).join(', ')} · total ${formatarKz(concluido.total)} Kz · troco ${formatarKz(concluido.troco)} Kz`}
          extra={
            <Button icon={<PrinterOutlined />} onClick={() => imprimirTaloes(concluido.vendas, true)}>
              Imprimir talão
            </Button>
          }
        />
      ) : !sessaoId ? (
        <Alert type="warning" showIcon message="O terminal não tem sessão aberta: abra-a na frente de caixa." />
      ) : (
        <>
          <Flex gap={16} wrap align="center" style={{ marginBottom: 12 }}>
            <Radio.Group value={modo} onChange={(e) => setModo(e.target.value)} optionType="button">
              <Radio value="POR_QUARTO">Uma factura por quarto</Radio>
              <Radio value="UNICA">Factura única</Radio>
            </Radio.Group>
            {modo === 'UNICA' && <SeletorTerceiro papel="CLIENTE" value={cliente} onChange={setCliente} style={{ width: 300, maxWidth: '100%' }} placeholder="Cliente da factura única" />}
            {pode('pos_desconto') && (
              <InputNumber<number> min={0} max={100} precision={2} decimalSeparator="," prefix="Desconto" suffix="%" style={{ width: 200, maxWidth: '100%' }} value={desconto || null} onChange={(v) => setDesconto(v ?? 0)} />
            )}
          </Flex>

          {aSimular && !simulacao ? (
            <Skeleton active />
          ) : !simulacao ? (
            <Alert type="info" showIcon message={modo === 'UNICA' && !cliente ? 'Escolha o cliente da factura única.' : 'A preparar a simulação…'} />
          ) : (
            <>
              {simulacao.facturas.map((f, i) => (
                <Card key={i} size="small" style={{ marginBottom: 8 }} title={`Factura ${i + 1} · ${f.estadias.map((e) => e.nome_quarto).join(', ')}`} extra={<b>{formatarKz(f.total)} Kz</b>}>
                  {f.estadias
                    .filter((e) => e.proposta)
                    .map((e) => (
                      <Alert
                        key={e.id}
                        type="warning"
                        showIcon
                        style={{ marginBottom: 8 }}
                        message={`${e.nome_quarto}: saída tardia (+${e.proposta!.extra}). Cobrar o recálculo ou manter o contratado?`}
                        action={
                          <Radio.Group size="small" value={opcoes[e.id]} onChange={(ev) => setOpcoes((o) => ({ ...o, [e.id]: ev.target.value }))}>
                            <Radio.Button value="RECALCULAR">Recalcular</Radio.Button>
                            <Radio.Button value="MANTER">Manter</Radio.Button>
                          </Radio.Group>
                        }
                      />
                    ))}
                  <Table
                    size="small"
                    scroll={scrollTabela()}
                    pagination={false}
                    rowKey={(_, n) => String(n)}
                    dataSource={f.linhas}
                    columns={[
                      { title: 'Descrição', dataIndex: 'descricao' },
                      { title: 'Qtd.', dataIndex: 'quantidade', align: 'right', render: (v) => Number(v) },
                      { title: 'Preço', dataIndex: 'preco_unitario', align: 'right', render: (v) => formatarKz(v) },
                      { title: 'Total', align: 'right', render: (_, l) => formatarCentimos(Math.round(Number(l.quantidade) * Number(l.preco_unitario) * 100)) },
                    ]}
                  />
                  <Descriptions size="small" column={{ xs: 2, sm: 4 }} style={{ marginTop: 8 }}>
                    <Descriptions.Item label="Bruto">{formatarKz(f.bruto)}</Descriptions.Item>
                    <Descriptions.Item label="Desconto">{formatarKz(f.desconto)}</Descriptions.Item>
                    {Number(f.arredondamento_agt ?? 0) !== 0 && <Descriptions.Item label="Arredondamento AGT">{formatarKz(f.arredondamento_agt)}</Descriptions.Item>}
                    <Descriptions.Item label="Base">{formatarKz(f.total_liquido)}</Descriptions.Item>
                    <Descriptions.Item label="IVA">{formatarKz(f.total_imposto)}</Descriptions.Item>
                  </Descriptions>
                  {rateio && simulacao.facturas.length > 1 && (
                    <Typography.Text type="secondary">Pagamentos desta factura: {rateio[i].map((p) => `${nomeMeio(p.meio_id)} ${formatarCentimos(p.valor)}`).join(' · ') || '—'}</Typography.Text>
                  )}
                </Card>
              ))}
              {porDecidir.length > 0 && <Alert type="error" showIcon style={{ marginBottom: 8 }} message={`Decida a saída tardia de: ${porDecidir.join(', ')}.`} />}
              <Divider style={{ margin: '8px 0' }} />
              <PainelPagamentos meios={meios} total={total} pagamentos={pagamentos} onChange={setPagamentos} />
              <Space direction="vertical" style={{ width: '100%', marginTop: 8 }}>
                <Input.TextArea rows={2} maxLength={2000} placeholder="Observações" value={observacoes} onChange={(e) => setObservacoes(e.target.value)} />
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                  O total segue a regra AGT do documento fiscal e pode diferir um cêntimo do valor contratado. Os pagamentos repartem-se pelas facturas na proporção do total; o troco sai do numerário.
                </Typography.Text>
              </Space>
            </>
          )}
        </>
      )}
    </Modal>
  );
}
