import { Alert, Button, Card, Checkbox, Col, DatePicker, Flex, Form, Input, InputNumber, Modal, Row, Space, Table, Tabs, Tag, Typography, message, theme } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState, type CSSProperties } from 'react';
import { enviar, obter } from '@/api/cliente';
import { BotaoImprimir, pares, tabelaHtml } from '@/componentes/impressao';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { CartaoKpi } from '@/componentes/vistas';
import { deCentimos, paraCentimos } from '@/utilitarios/decimal';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { ValorKz } from '../contab/comum/Componentes';
import { DENOMINACOES, type ConferenciaCaixa } from './api';
import { SeletorContaFinanceira } from './comum';
import { accoesConferencia, lerDenominacoes, totalContado } from './regras';
import { larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';

/** Auto de conferência de caixa impresso: contagem por denominação, totais, diferença, justificação e assinaturas. */
export function pedidoConferencia(c: ConferenciaCaixa) {
  const q = lerDenominacoes(c.denominacoes);
  const linhas = DENOMINACOES.filter((d) => (q[d.chave] ?? 0) > 0).map((d) => ({ rotulo: d.rotulo, quantidade: q[d.chave] ?? 0, valor: ((q[d.chave] ?? 0) * d.valor).toFixed(2) }));
  return {
    titulo: 'Auto de conferência de caixa',
    periodo: formatarData(c.data_conferencia),
    filtros: [`Conta ${c.codigo_conta}${c.nome_conta ? ` — ${c.nome_conta}` : ''}`, `Estado: ${c.estado === 'FINALIZADO' ? 'Finalizado' : 'Rascunho'}`],
    orientacao: 'retrato' as const,
    conteudo:
      pares(
        [
          ['Total contado', `${formatarKz(c.total_fisico)} Kz`],
          ['Saldo do sistema', `${formatarKz(c.total_sistema)} Kz`],
          ['Diferença', `${formatarKz(c.diferenca)} Kz`],
          ['Saldo externo', c.saldo_externo ? `${formatarKz(c.saldo_externo)} Kz` : '—'],
          ['Operador', c.nome_operador ?? '—'],
          ['Regularização', c.referencia_lancamento ?? '—'],
        ],
        3,
      ) +
      tabelaHtml({
        legenda: 'Contagem por denominação',
        colunas: [
          { titulo: 'Denominação', valor: (l: (typeof linhas)[number]) => l.rotulo },
          { titulo: 'Quantidade', valor: (l) => l.quantidade, formato: 'inteiro', somar: true },
          { titulo: 'Valor (Kz)', valor: (l) => l.valor, formato: 'moeda', somar: true },
        ],
        linhas,
        totais: 'Total contado',
        vazio: 'Sem contagem registada.',
      }) +
      (c.justificacao ? `<p style="font-size:8.5pt"><strong>Justificação:</strong> ${c.justificacao.replace(/[&<>"']/g, (x) => `&#${x.charCodeAt(0)};`)}</p>` : '') +
      `<div class="imp-sem-quebra" style="display:flex;justify-content:space-around;gap:10mm;margin-top:16mm"><div style="flex:0 1 38%;text-align:center;border-top:0.3mm solid #1f1f1f;padding-top:1mm;font-size:8pt">O operador${c.nome_operador ? ` (${c.nome_operador.replace(/[&<>"']/g, '')})` : ''}</div><div style="flex:0 1 38%;text-align:center;border-top:0.3mm solid #1f1f1f;padding-top:1mm;font-size:8pt">O gerente${c.nome_gerente ? ` (${c.nome_gerente.replace(/[&<>"']/g, '')}, ${formatarDataHora(c.assinado_gerente_em)})` : ''}</div></div>`,
  };
}

interface ValoresConferencia {
  codigo_conta: string;
  data_conferencia: Dayjs;
  denominacoes: Record<string, number | null>;
  saldo_externo?: number;
  justificacao?: string;
}

/** Diferença físico − sistema com as cores e o texto do legado (ui_tesouraria.js:7084-7086). */
export function leituraDiferenca(dif: number | string | null | undefined): { cor: string; texto: string } {
  const c = paraCentimos(dif ?? 0);
  if (!c) return { cor: '#10b981', texto: 'Sem desvio' };
  return c > 0 ? { cor: '#f59e0b', texto: 'Sobra' } : { cor: '#ef4444', texto: 'Quebra' };
}

const cartaoNumerado = (cor: string): CSSProperties => ({ borderTop: `3px solid ${cor}`, marginBottom: 12 });

/**
 * Tesouraria › Conferência de caixa (ecrã teso_gestao_conferencia), como no legado (ui_tesouraria.js:7150-7356): sem título
 * próprio (o agregador «Tesouraria - Gestão» já o dá), sub-separadores «Nova Conferência» / «Histórico (n)» e o formulário
 * na página em cartões numerados — 1. Identificação, 2. Contagem Física e 3. Comparação lado a lado, 4. Justificação do
 * desvio — com «Limpar» e «Guardar Rascunho». Finalizar (com regularização), assinar e reabrir ficam no histórico.
 */
export default function Conferencia() {
  const { pode, utilizador } = useSessao();
  const cliente = useQueryClient();
  const { token } = theme.useToken();
  const lista = useQuery({ queryKey: ['teso', 'conferencias'], queryFn: () => obter<ConferenciaCaixa[]>('/tesouraria/conferencias') });
  const [separador, setSeparador] = useState<'nova' | 'historico'>(pode('teso_conf_registar') ? 'nova' : 'historico');
  const [edicao, setEdicao] = useState<ConferenciaCaixa | null>(null);
  const [finalizar, setFinalizar] = useState<ConferenciaCaixa | null>(null);
  const [regularizar, setRegularizar] = useState(false);
  const [reabrir, setReabrir] = useState<ConferenciaCaixa | null>(null);
  const [form] = Form.useForm<ValoresConferencia>();
  const [formMotivo] = Form.useForm<{ motivo: string }>();
  const quantidades = Form.useWatch('denominacoes', form) ?? {};
  const conta = Form.useWatch('codigo_conta', form);
  const dataConf = Form.useWatch('data_conferencia', form) as Dayjs | undefined;
  const externo = Form.useWatch('saldo_externo', form);
  const nome = utilizador?.nome_utilizador;
  const pequeno = useEcraPequeno();
  // saldo da conta no Diário à data (pré-visualização; o servidor recalcula ao gravar)
  const dia = dataApi(dataConf);
  const saldo = useQuery({
    queryKey: ['teso', 'conferencia', 'saldo', conta, dia],
    queryFn: () => obter<{ saldo_final: string }>('/tesouraria/extrato-conta', { codigo_conta: conta, data_inicio: dia, data_fim: dia }),
    enabled: !!conta && !!dia && separador === 'nova',
    retry: false,
  });
  const total = totalContado(quantidades);
  const sistema = saldo.data?.saldo_final ?? edicao?.total_sistema ?? null;
  const diferenca = sistema === null ? null : deCentimos(paraCentimos(total) - paraCentimos(sistema));
  const leitura = leituraDiferenca(diferenca);

  const limpar = () => {
    form.resetFields();
    form.setFieldsValue({ data_conferencia: dayjs(), denominacoes: {} });
    setEdicao(null);
  };
  const mutacao = useMutation({
    mutationFn: ({ metodo, url, corpo }: { metodo: 'post' | 'put'; url: string; corpo?: unknown }) => enviar<ConferenciaCaixa>(metodo, url, corpo),
    onSuccess: ({ mensagem }, { url }) => {
      message.success(mensagem);
      setFinalizar(null);
      setReabrir(null);
      formMotivo.resetFields();
      if (url === '/tesouraria/conferencias' || /\/conferencias\/\d+$/.test(url)) {
        limpar();
        setSeparador('historico');
      }
      void cliente.invalidateQueries({ queryKey: ['teso', 'conferencias'] });
      void cliente.invalidateQueries({ queryKey: ['contab'] });
    },
    onError: (e) => notificarErro(e),
  });

  const abrir = (c: ConferenciaCaixa) => {
    form.resetFields();
    form.setFieldsValue({
      codigo_conta: c.codigo_conta,
      data_conferencia: dayjs(c.data_conferencia),
      denominacoes: lerDenominacoes(c.denominacoes),
      saldo_externo: c.saldo_externo ? Number(c.saldo_externo) : undefined,
      justificacao: c.justificacao ?? undefined,
    });
    setEdicao(c);
    setSeparador('nova');
  };

  const gravar = (v: ValoresConferencia) => {
    const corpo = {
      codigo_conta: v.codigo_conta,
      data_conferencia: dataApi(v.data_conferencia),
      denominacoes: Object.fromEntries(DENOMINACOES.map((d) => [d.chave, Math.max(0, Math.trunc(v.denominacoes?.[d.chave] ?? 0))])),
      saldo_externo: v.saldo_externo ?? undefined,
      justificacao: v.justificacao || undefined,
    };
    mutacao.mutate(edicao ? { metodo: 'put', url: `/tesouraria/conferencias/${edicao.id}`, corpo } : { metodo: 'post', url: '/tesouraria/conferencias', corpo });
  };

  const pedidoLista = () => ({
    titulo: 'Conferências de caixa',
    conteudo: tabelaHtml({
      colunas: [
        { titulo: 'Data', valor: (c: ConferenciaCaixa) => c.data_conferencia, formato: 'data' },
        { titulo: 'Conta', valor: (c) => `${c.codigo_conta}${c.nome_conta ? ` — ${c.nome_conta}` : ''}` },
        { titulo: 'Operador', valor: (c) => c.nome_operador ?? '' },
        { titulo: 'Total físico (Kz)', valor: (c) => c.total_fisico, formato: 'moeda' },
        { titulo: 'Saldo sistema (Kz)', valor: (c) => c.total_sistema, formato: 'moeda' },
        { titulo: 'Diferença (Kz)', valor: (c) => c.diferenca, formato: 'moeda', somar: true },
        { titulo: 'Estado', valor: (c) => (c.estado === 'FINALIZADO' ? 'Finalizado' : 'Rascunho') },
        { titulo: 'Gerente', valor: (c) => (c.nome_gerente ? `${c.nome_gerente} · ${formatarDataHora(c.assinado_gerente_em)}` : '') },
      ],
      linhas: lista.data ?? [],
      totais: true,
    }),
  });

  // contagem: Notas e Moedas com o tipo, a quantidade e o valor (ui_tesouraria.js:7267-7271)
  const linhasContagem = [
    { chave: 'g-N', grupo: 'Notas' }, ...DENOMINACOES.filter((d) => d.chave.startsWith('N')).map((d) => ({ chave: d.chave, d })),
    { chave: 'g-M', grupo: 'Moedas' }, ...DENOMINACOES.filter((d) => d.chave.startsWith('M')).map((d) => ({ chave: d.chave, d })),
  ] as { chave: string; grupo?: string; d?: (typeof DENOMINACOES)[number] }[];
  const juntar = (l: { grupo?: string }) => (l.grupo ? { colSpan: 0 } : {});

  const formulario = (
    <Form form={form} layout="vertical" onFinish={gravar} initialValues={{ data_conferencia: dayjs(), denominacoes: {} }}>
      <Card size="small" style={cartaoNumerado('#7c3aed')} title={<><IconeFa nome="id-card" /> 1. Identificação{edicao ? ` — rascunho de ${formatarData(edicao.data_conferencia)}` : ''}</>}>
        <Row gutter={12}>
          <Col xs={24} sm={14}>
            <Form.Item name="codigo_conta" label="Conta de caixa" rules={[{ required: true }]} style={{ marginBottom: 0 }}>
              <SeletorContaFinanceira prefixos={['45']} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={10}>
            <Form.Item name="data_conferencia" label="Data" rules={[{ required: true }]} style={{ marginBottom: 0 }}>
              <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
        </Row>
      </Card>
      <Row gutter={12}>
        <Col xs={24} lg={14}>
          <Card size="small" style={cartaoNumerado('#0891b2')} title={<><IconeFa nome="money-bill-wave" /> 2. Contagem Física</>}>
            <Table<(typeof linhasContagem)[number]>
              rowKey="chave" size="small" pagination={false} dataSource={linhasContagem} data-testid="tabela-contagem"
              onRow={(l) => ({ style: l.grupo ? { background: token.colorFillAlter, fontWeight: 700 } : undefined })}
              columns={[
                { title: 'Denominação', key: 'den', onCell: (l) => (l.grupo ? { colSpan: 4 } : {}), render: (_, l) => l.grupo ?? l.d!.rotulo },
                { title: 'Tipo', key: 'tipo', onCell: juntar, render: (_, l) => (l.d ? (l.d.chave.startsWith('N') ? <Tag color="blue">Nota</Tag> : <Tag color="gold">Moeda</Tag>) : null) },
                {
                  title: 'Quantidade', key: 'q', align: 'right', onCell: juntar,
                  render: (_, l) => (l.d ? (
                    <Form.Item name={['denominacoes', l.d.chave]} noStyle>
                      <InputNumber min={0} precision={0} size="small" style={{ width: 96 }} aria-label={`Quantidade ${l.d.rotulo}`} />
                    </Form.Item>
                  ) : null),
                },
                { title: 'Valor', key: 'v', align: 'right', onCell: juntar, render: (_, l) => (l.d ? formatarKz(Math.max(0, Math.trunc(quantidades[l.d.chave] ?? 0)) * l.d.valor) : null) },
              ]}
              summary={() => (
                <Table.Summary>
                  <Table.Summary.Row className="erp-linha-total">
                    <Table.Summary.Cell index={0} colSpan={3}>TOTAL FÍSICO CONFERIDO</Table.Summary.Cell>
                    <Table.Summary.Cell index={3} align="right">{formatarKz(total)}</Table.Summary.Cell>
                  </Table.Summary.Row>
                </Table.Summary>
              )}
            />
          </Card>
        </Col>
        <Col xs={24} lg={10}>
          <Card size="small" style={cartaoNumerado('#7c3aed')} title={<><IconeFa nome="balance-scale" /> 3. Comparação</>}>
            <Space direction="vertical" style={{ width: '100%' }} size={10}>
              <CartaoKpi rotulo="Total Físico Conferido" valor={formatarKz(total)} unidade="Kz" icone="hand-holding-usd" cor="#7c3aed" corValor="#7c3aed" />
              <CartaoKpi rotulo="Saldo em Sistema (Cont.)" valor={sistema === null ? '—' : formatarKz(sistema)} unidade="Kz" icone="database" cor="#0891b2" corValor="#0891b2"
                secundaria={!conta ? 'Escolha a conta de caixa' : saldo.isFetching ? 'A calcular…' : 'Saldo no Diário à data (o servidor recalcula ao gravar)'} />
              <CartaoKpi rotulo="Saldo Sistema Externo (manual)" valor={externo === undefined || externo === null ? '—' : formatarKz(externo)} unidade="Kz" icone="building" cor="#0d9488"
                secundaria={<Form.Item name="saldo_externo" noStyle><InputNumber precision={2} size="small" style={{ width: '100%', borderColor: '#0d9488' }} placeholder="Opcional" aria-label="Saldo Sistema Externo" /></Form.Item>} />
              <CartaoKpi rotulo="Diferença (Físico vs Sistema)" valor={diferenca === null ? '—' : formatarKz(diferenca)} unidade="Kz" icone="arrows-alt-v" cor={leitura.cor} corValor={leitura.cor}
                secundaria={diferenca === null ? undefined : leitura.texto.toUpperCase()} />
            </Space>
          </Card>
        </Col>
      </Row>
      <div hidden={diferenca === null || !paraCentimos(diferenca)}>
        <Card size="small" style={cartaoNumerado('#ef4444')} title={<><IconeFa nome="exclamation-triangle" /> 4. Justificação do Desvio e Regularização</>}>
          <Form.Item name="justificacao" label="Justificação de diferenças" style={{ marginBottom: 0 }}>
            <Input.TextArea rows={2} maxLength={4000} />
          </Form.Item>
        </Card>
      </div>
      <Flex wrap gap={8} justify="flex-end">
        <Button icon={<IconeFa nome="eraser" />} onClick={limpar}>Limpar</Button>
        <Button htmlType="submit" loading={mutacao.isPending} icon={<IconeFa nome="save" />} style={{ color: '#92400e', background: 'rgba(245,158,11,0.12)', borderColor: 'rgba(245,158,11,0.5)' }}>Guardar Rascunho</Button>
      </Flex>
      <Typography.Paragraph type="secondary" style={{ marginTop: 8 }}>O saldo do sistema e a diferença são recalculados pelo servidor na data da conferência. Finalize o rascunho no Histórico.</Typography.Paragraph>
    </Form>
  );

  const historico = (
    <Card>
      <Flex justify="flex-end" style={{ marginBottom: 8 }}>
        <BotaoImprimir obterPedido={pedidoLista} />
      </Flex>
      <Table<ConferenciaCaixa>
        rowKey="id"
        loading={lista.isLoading}
        dataSource={lista.data}
        size={pequeno ? 'small' : 'middle'}
        pagination={{ pageSize: 25 }}
        scroll={scrollTabela()}
        onRow={(c) => ({ style: c.estado === 'RASCUNHO' ? { background: 'rgba(245,158,11,0.07)' } : undefined })}
        columns={[
          // colunas do legado (ui_tesouraria.js:7214)
          { title: 'Data/Hora', dataIndex: 'data_conferencia', render: formatarDataHora },
          { title: 'Conta', dataIndex: 'codigo_conta', render: (v: string, c) => <><strong>{v}</strong>{c.nome_conta && <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>{c.nome_conta}</Typography.Text>}</> },
          { title: 'Operador', dataIndex: 'nome_operador', responsive: ['lg'] },
          { title: 'Total Físico', dataIndex: 'total_fisico', align: 'right', responsive: ['md'], render: (v: string) => <ValorKz valor={v} /> },
          { title: 'Saldo Sistema', dataIndex: 'total_sistema', align: 'right', responsive: ['md'], render: (v: string) => <ValorKz valor={v} /> },
          {
            title: 'Diferença', dataIndex: 'diferenca', align: 'right',
            render: (v: string, c) => (c.estado !== 'FINALIZADO' && !paraCentimos(v) ? '—'
              : <strong style={{ color: leituraDiferenca(v).cor, whiteSpace: 'nowrap' }}>{formatarKz(v)}</strong>),
          },
          {
            title: 'Estado', dataIndex: 'estado',
            render: (v: string) => (v === 'RASCUNHO' ? <Tag color="gold" icon={<IconeFa nome="pencil-alt" />}> RASCUNHO</Tag> : <Tag color="success" icon={<IconeFa nome="check" />}> FINALIZADO</Tag>),
          },
          {
            title: '',
            render: (_, c) => {
              const a = accoesConferencia(c, pode, nome);
              return (
                <Space wrap>
                  <BotaoImprimir tamanho="small" texto="Auto" obterPedido={() => pedidoConferencia(c)} />
                  {a.podeEditar && <Button size="small" onClick={() => abrir(c)}>Editar</Button>}
                  {a.podeFinalizar && <Button size="small" type="primary" onClick={() => { setRegularizar(false); setFinalizar(c); }}>Finalizar</Button>}
                  {a.podeAssinar && (
                    <Button size="small" onClick={() => Modal.confirm({ title: 'Assinar a conferência como gerente?', okText: 'Assinar', cancelText: 'Cancelar', onOk: () => mutacao.mutateAsync({ metodo: 'post', url: `/tesouraria/conferencias/${c.id}/assinar` }) })}>
                      Assinar
                    </Button>
                  )}
                  {a.podeReabrir && <Button size="small" danger onClick={() => setReabrir(c)}>Reabrir</Button>}
                  {c.nome_gerente && <Tag icon={<IconeFa nome="signature" />} title={formatarDataHora(c.assinado_gerente_em)}> {c.nome_gerente}</Tag>}
                </Space>
              );
            },
          },
        ]}
      />
    </Card>
  );

  return (
    <>
      <Tabs
        activeKey={separador}
        onChange={(k) => setSeparador(k as 'nova' | 'historico')}
        items={[
          ...(pode('teso_conf_registar') ? [{ key: 'nova', label: <><IconeFa nome="plus-circle" /> {edicao ? 'Editar Conferência' : 'Nova Conferência'}</>, children: formulario }] : []),
          { key: 'historico', label: <><IconeFa nome="history" /> Histórico <Tag color="#7c3aed" style={{ marginLeft: 4 }}>{lista.data?.length ?? 0}</Tag></>, children: historico },
        ]}
      />

      <Modal
        width={larguraModal(520)}
        title="Finalizar conferência"
        open={!!finalizar}
        onCancel={() => setFinalizar(null)}
        okText="Finalizar"
        confirmLoading={mutacao.isPending}
        onOk={() => finalizar && mutacao.mutate({ metodo: 'post', url: `/tesouraria/conferencias/${finalizar.id}/finalizar`, corpo: { regularizar } })}
      >
        {finalizar && (
          <>
            <Typography.Paragraph>Diferença registada: <strong>{formatarKz(finalizar.diferenca, true)}</strong></Typography.Paragraph>
            <Checkbox checked={regularizar} onChange={(e) => setRegularizar(e.target.checked)}>
              Lançar a regularização da diferença (sobras/quebras de caixa)
            </Checkbox>
            <Alert style={{ marginTop: 12 }} type="info" showIcon message="As contas de sobras e quebras definem-se em Integração no razão › Contas de tesouraria." />
          </>
        )}
      </Modal>

      <Modal width={larguraModal(520)} title="Reabrir conferência" open={!!reabrir} onCancel={() => setReabrir(null)} okText="Reabrir" okButtonProps={{ danger: true }} confirmLoading={mutacao.isPending} onOk={() => formMotivo.submit()}>
        <Form form={formMotivo} layout="vertical" onFinish={(v) => reabrir && mutacao.mutate({ metodo: 'post', url: `/tesouraria/conferencias/${reabrir.id}/reabrir`, corpo: v })}>
          <Typography.Paragraph type="secondary">A regularização lançada (se houver) é estornada e a assinatura do gerente é retirada.</Typography.Paragraph>
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true, min: 5, message: 'Indique o motivo (pelo menos 5 caracteres).' }]}>
            <Input.TextArea rows={3} maxLength={500} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}
