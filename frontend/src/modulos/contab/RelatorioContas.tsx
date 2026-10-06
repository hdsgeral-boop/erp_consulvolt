import { Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Form, Input, InputNumber, Modal, Row, Select, Skeleton, Space, Table, Tabs, Tag, Typography, message } from 'antd';
import { BookOutlined, CheckOutlined, FileTextOutlined, SaveOutlined, SettingOutlined, SyncOutlined, TableOutlined, UnlockOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { esc, tabelaHtml } from '@/componentes/impressao';
import { scrollTabela, useEcra } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { BotaoCsv, ValorKz } from './comum/Componentes';
import { accoesRelatorioContas } from './comum/regras';
import { SeparadorGestao } from './relatorioContas/SeparadorGestao';
import { SECCOES, tabelaSeccao, textoActual, textoParaHtml, type ConfigGestao, type TextoGravado } from './relatorioContas/textosGestao';

type Indicadores = Record<string, string | number | null>;

interface DadosRC {
  ano: number;
  ano_anterior: number;
  encerrado: boolean;
  moeda: string;
  gerado_em: string;
  empresa: { nome: string; nif: string | null };
  n: { indicadores: Indicadores };
  n1: { indicadores: Indicadores };
  composicao: Record<string, { conta: string; descricao: string | null; n: string; n1: string }[]>;
  alertas: { tipo: 'info' | 'aviso' | 'erro'; texto: string }[];
  colaboradores: number;
  anexos: Record<string, { conta: string; descricao: string | null; debito?: string; credito?: string; saldo_devedor?: string; saldo_credor?: string; [k: string]: unknown }[]>;
}

interface RelatorioRC {
  registo: { id: number | null; ano: number; estado: string; configuracao: Record<string, unknown>; textos: Record<string, unknown>; notas_incluir: Record<string, boolean>; atualizado_por: string | null; atualizado_em: string | null };
  dados: DadosRC;
}

const MONETARIOS: [string, string][] = [
  ['vendas_prestacoes', 'Vendas e prestações de serviços'],
  ['margem_bruta', 'Margem bruta'],
  ['pessoal', 'Custos com o pessoal'],
  ['gastos_op', 'Gastos operacionais'],
  ['ebitda', 'EBITDA'],
  ['amortizacoes', 'Amortizações'],
  ['ebit', 'EBIT (resultado operacional)'],
  ['rai', 'Resultado antes de impostos'],
  ['imposto', 'Imposto sobre o rendimento'],
  ['rl', 'Resultado líquido'],
  ['activo', 'Activo total'],
  ['capital_proprio', 'Capital próprio'],
  ['passivo', 'Passivo total'],
  ['disponibilidades', 'Disponibilidades'],
];

const RACIOS: [string, string, 'pct' | 'x'][] = [
  ['roe', 'Rendibilidade do capital próprio (ROE)', 'pct'],
  ['roa', 'Rendibilidade do activo (ROA)', 'pct'],
  ['ros', 'Rendibilidade das vendas (ROS)', 'pct'],
  ['margem_operacional', 'Margem operacional', 'pct'],
  ['autonomia_financeira', 'Autonomia financeira', 'pct'],
  ['solvabilidade', 'Solvabilidade', 'x'],
  ['endividamento', 'Endividamento', 'pct'],
  ['liquidez_geral', 'Liquidez geral', 'x'],
  ['liquidez_reduzida', 'Liquidez reduzida', 'x'],
  ['liquidez_imediata', 'Liquidez imediata', 'x'],
];

function racio(v: string | number | null | undefined, tipo: 'pct' | 'x'): string {
  if (v === null || v === undefined || v === '') return '—';
  const n = Number(v);
  if (!Number.isFinite(n)) return '—';
  return tipo === 'pct' ? `${(n * 100).toLocaleString('pt-PT', { maximumFractionDigits: 2 })} %` : n.toLocaleString('pt-PT', { maximumFractionDigits: 2 });
}

const CORES_ALERTA = { info: 'info', aviso: 'warning', erro: 'error' } as const;

const NOMES_ANEXOS: Record<string, string> = {
  razao_dezembro: 'Balancete razão (Dezembro)',
  razao_apuramento: 'Balancete razão (apuramento)',
  geral_dezembro: 'Balancete geral (Dezembro)',
  geral_apuramento: 'Balancete geral (apuramento)',
  amortizacoes: 'Mapa de amortizações',
};

/**
 * Contabilidade › Relatório e Contas (ecrã relatorio_contas; legado: js/relatorio_contas.js:983-1106).
 * Topo como o legado: exercício, «Actualizar números», «Gravar», «Concluir»/«Reabrir» e «Imprimir / PDF»; alertas;
 * cartões (activo, capital próprio, vendas, EBITDA, resultado líquido); separadores Relatório de Gestão (M-10),
 * Demonstrações Financeiras, Notas às Contas e Configuração e Anexos.
 */
export default function RelatorioContas() {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [ano, setAno] = useState(dayjs().year() - (dayjs().month() < 3 ? 1 : 0));
  const [form] = Form.useForm<Record<string, unknown>>();
  const [notas, setNotas] = useState<Record<string, boolean>>({});
  const [textos, setTextos] = useState<Record<string, TextoGravado>>({});
  const [sujo, setSujo] = useState(false);
  const [aba, setAba] = useState('gestao');
  const configForm = Form.useWatch([], form) as ConfigGestao | undefined;

  const consulta = useQuery({ queryKey: ['contab', 'relatorio-contas', ano], queryFn: () => obter<RelatorioRC>(`/contabilidade/relatorio-contas/${ano}`) });
  const reg = consulta.data?.registo;
  const d = consulta.data?.dados;
  const regras = accoesRelatorioContas(reg?.estado, !!d?.encerrado, pode);
  const config: ConfigGestao = { ...(reg?.configuracao ?? {}), ...(configForm ?? {}) } as ConfigGestao;

  useEffect(() => {
    if (!reg) return;
    const cfg = { ...reg.configuracao };
    if (typeof cfg.data === 'string' && cfg.data) cfg.data = dayjs(cfg.data as string);
    form.setFieldsValue(cfg as never);
    setNotas(Array.isArray(reg.notas_incluir) ? {} : reg.notas_incluir ?? {});
    setTextos(Array.isArray(reg.textos) ? {} : ((reg.textos ?? {}) as Record<string, TextoGravado>));
    setSujo(false);
  }, [reg, form]);

  const invalidar = () => void cliente.invalidateQueries({ queryKey: ['contab', 'relatorio-contas', ano] });
  const gravar = useMutation({
    mutationFn: (corpo: Record<string, unknown>) => enviar('put', `/contabilidade/relatorio-contas/${ano}`, corpo),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      setSujo(false);
      invalidar();
    },
    onError: (e) => notificarErro(e, 'Não foi possível gravar'),
  });
  const concluir = useMutation({
    mutationFn: (forcar: boolean) => enviar('post', `/contabilidade/relatorio-contas/${ano}/concluir`, { forcar }),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      invalidar();
    },
    onError: (e) => {
      if (e instanceof ErroApi && e.codigo === 'RELATORIO_COM_ERROS') {
        Modal.confirm({
          title: 'O relatório tem erros',
          content: 'Há alertas de erro (ver acima). Pretende concluir mesmo assim?',
          okText: 'Concluir mesmo assim',
          cancelText: 'Cancelar',
          onOk: () => concluir.mutateAsync(true),
        });
      } else notificarErro(e, 'Não foi possível concluir');
    },
  });
  const reabrir = useMutation({
    mutationFn: () => enviar('post', `/contabilidade/relatorio-contas/${ano}/reabrir`),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      invalidar();
    },
    onError: (e) => notificarErro(e, 'Não foi possível reabrir'),
  });

  /** «Gravar» do legado: configuração, textos editados e notas a incluir, numa só operação. */
  const gravarTudo = async () => {
    const cfg = { ...(await form.validateFields()) };
    if (cfg.data && dayjs.isDayjs(cfg.data)) cfg.data = (cfg.data as Dayjs).format('YYYY-MM-DD');
    // só se guardam os textos editados; os automáticos regeneram-se sempre a partir dos números
    const editados = Object.fromEntries(Object.entries(textos).filter(([, t]) => t && t.auto === false));
    gravar.mutate({ configuracao: cfg, textos: editados, notas_incluir: notas });
  };
  const mudarTexto = (id: string, valor: TextoGravado | null) => {
    setTextos((t) => {
      const n = { ...t };
      if (valor) n[id] = valor;
      else delete n[id];
      return n;
    });
    setSujo(true);
  };
  const mudarAno = (novo: number) => {
    if (sujo) {
      Modal.confirm({ title: 'Tem alterações por gravar', content: 'Mudar de exercício sem gravar?', okText: 'Mudar sem gravar', cancelText: 'Cancelar', onOk: () => setAno(novo) });
    } else setAno(novo);
  };

  const anos = Array.from({ length: 6 }, (_, i) => dayjs().year() - i);
  const { telemovel } = useEcra();
  const kpis: [string, string][] = [
    ['Total do activo', 'activo'],
    ['Capital próprio', 'capital_proprio'],
    ['Vendas e prestações', 'vendas_prestacoes'],
    ['EBITDA', 'ebitda'],
    ['Resultado líquido', 'rl'],
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Relatório e Contas"
        subtitulo={
          d ? (
            <Space wrap size={6}>
              <span>{`${String(config.nome || d.empresa.nome || '')} · Exercício de ${ano}`}</span>
              <Tag color={regras.concluido ? 'green' : 'gold'}>{regras.concluido ? 'Concluído' : 'Rascunho'}</Tag>
              {d.encerrado ? <Tag>Exercício encerrado</Tag> : <Tag color="red">Provisório — exercício em aberto</Tag>}
              {reg?.atualizado_em && (
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                  gravado por {reg.atualizado_por ?? '—'} em {formatarDataHora(reg.atualizado_em)}
                </Typography.Text>
              )}
            </Space>
          ) : undefined
        }
        impressaoDesactivada={!d}
        impressao={() =>
          d && {
            titulo: `Relatório e Contas · exercício de ${ano}`,
            subtitulo: d.encerrado ? undefined : 'Provisório: exercício aberto',
            filtros: [regras.concluido ? 'Concluído (números fixados)' : 'Rascunho (números recalculados a cada consulta)', `Moeda: ${d.moeda}`],
            conteudo: documentoRelatorioContas(d, notas, textos, config),
          }
        }
        accoes={
          <Space wrap>
            <Select value={ano} onChange={mudarAno} style={{ width: 110 }} options={anos.map((a) => ({ value: a, label: String(a) }))} aria-label="Exercício" />
            {regras.podeEditar && (
              <Button icon={<SyncOutlined />} loading={consulta.isFetching} onClick={() => void consulta.refetch()} title="Recalcular os números e os textos automáticos">
                Actualizar números
              </Button>
            )}
            {regras.podeEditar && (
              <Button icon={<SaveOutlined />} loading={gravar.isPending} onClick={() => void gravarTudo()} type={sujo ? 'primary' : 'default'}>
                Gravar
              </Button>
            )}
            {regras.podeConcluir && (
              <Button
                icon={<CheckOutlined />}
                loading={concluir.isPending}
                onClick={() =>
                  Modal.confirm({
                    title: `Concluir o Relatório e Contas de ${ano}?`,
                    content: 'Os números ficam fixados (fotografia) até o relatório ser reaberto.',
                    okText: 'Concluir',
                    cancelText: 'Cancelar',
                    onOk: () => concluir.mutateAsync(false),
                  })
                }
              >
                Concluir
              </Button>
            )}
            {regras.podeReabrir && (
              <Button
                danger
                icon={<UnlockOutlined />}
                loading={reabrir.isPending}
                onClick={() => Modal.confirm({ title: `Reabrir o Relatório e Contas de ${ano}?`, okText: 'Reabrir', okButtonProps: { danger: true }, cancelText: 'Cancelar', onOk: () => reabrir.mutateAsync() })}
              >
                Reabrir
              </Button>
            )}
          </Space>
        }
      />
      {consulta.isLoading || !d || !reg ? (
        <Skeleton active />
      ) : (
        <>
          {!d.encerrado && !regras.concluido && pode('rc_concluir') && (
            <Alert type="info" showIcon style={{ marginBottom: 8 }} message="A conclusão só fica disponível depois de encerrado o exercício." />
          )}
          {d.alertas.map((a, i) => (
            <Alert key={i} type={CORES_ALERTA[a.tipo] ?? 'info'} showIcon style={{ marginBottom: 8 }} message={a.texto} />
          ))}
          <div className="erp-grelha-auto" style={{ margin: '12px 0 16px' }}>
            {kpis.map(([rotulo, k]) => (
              <Card key={k} size="small">
                <Typography.Text type="secondary" style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase' }}>
                  {rotulo}
                </Typography.Text>
                <div style={{ fontSize: 17, fontWeight: 700 }}>
                  <ValorKz valor={d.n.indicadores[k] as string} forte />
                </div>
              </Card>
            ))}
          </div>
          <Tabs
            activeKey={aba}
            onChange={setAba}
            items={[
              {
                key: 'gestao',
                label: (
                  <span>
                    <FileTextOutlined /> Relatório de Gestão
                  </span>
                ),
                children: <SeparadorGestao dados={d} config={config} textos={textos} editavel={regras.podeEditar} aoMudar={mudarTexto} />,
              },
              {
                key: 'demonstracoes',
                label: (
                  <span>
                    <TableOutlined /> Demonstrações Financeiras
                  </span>
                ),
                children: (
                  <Row gutter={[16, 16]}>
                    <Col xs={24} lg={12}>
                      <Card title="Grandezas (Kz)" size="small">
                        <Table
                          rowKey={(r) => r[0]}
                          size="small"
                          scroll={scrollTabela()}
                          pagination={false}
                          dataSource={MONETARIOS}
                          columns={[
                            { title: 'Indicador', render: (_, r) => r[1] },
                            { title: String(d.ano), align: 'right', render: (_, r) => <ValorKz valor={d.n.indicadores[r[0]] as string} /> },
                            { title: String(d.ano_anterior), align: 'right', render: (_, r) => <ValorKz valor={d.n1.indicadores[r[0]] as string} /> },
                          ]}
                        />
                      </Card>
                    </Col>
                    <Col xs={24} lg={12}>
                      <Card title="Rácios" size="small">
                        <Table
                          rowKey={(r) => r[0]}
                          size="small"
                          scroll={scrollTabela()}
                          pagination={false}
                          dataSource={RACIOS}
                          columns={[
                            { title: 'Rácio', render: (_, r) => r[1] },
                            { title: String(d.ano), align: 'right', render: (_, r) => racio(d.n.indicadores[r[0]], r[2]) },
                            { title: String(d.ano_anterior), align: 'right', render: (_, r) => racio(d.n1.indicadores[r[0]], r[2]) },
                          ]}
                        />
                        <Typography.Paragraph type="secondary" style={{ marginTop: 8 }}>
                          Colaboradores no exercício: {d.colaboradores} · números calculados em {formatarDataHora(d.gerado_em)}
                        </Typography.Paragraph>
                      </Card>
                    </Col>
                  </Row>
                ),
              },
              {
                key: 'notas',
                label: (
                  <span>
                    <BookOutlined /> Notas às Contas
                  </span>
                ),
                children: (
                  <>
                    {Object.entries(d.composicao)
                      .sort(([a], [b]) => Number(a) - Number(b))
                      .map(([nota, contas]) => (
                        <Card
                          key={nota}
                          size="small"
                          style={{ marginBottom: 12 }}
                          title={`Nota ${nota}`}
                          extra={
                            <Checkbox
                              disabled={!regras.podeEditar}
                              checked={notas[nota] !== false}
                              onChange={(e) => {
                                setNotas((n) => ({ ...n, [nota]: e.target.checked }));
                                setSujo(true);
                              }}
                            >
                              Incluir
                            </Checkbox>
                          }
                        >
                          <Table
                            rowKey="conta"
                            size="small"
                            scroll={scrollTabela()}
                            pagination={false}
                            dataSource={contas}
                            columns={[
                              { title: 'Conta', dataIndex: 'conta' },
                              { title: 'Descrição', dataIndex: 'descricao', render: (v: string | null) => v ?? '—' },
                              { title: String(d.ano), dataIndex: 'n', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
                              { title: String(d.ano_anterior), dataIndex: 'n1', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
                            ]}
                          />
                        </Card>
                      ))}
                  </>
                ),
              },
              {
                key: 'configuracao',
                forceRender: true,
                label: (
                  <span>
                    <SettingOutlined /> Configuração e Anexos
                  </span>
                ),
                children: (
                  <>
                    <Form form={form} layout="vertical" disabled={!regras.podeEditar} onValuesChange={() => setSujo(true)}>
                      <Card size="small" title="Identificação da empresa" style={{ marginBottom: 12 }}>
                        <Row gutter={[16, 0]}>
                          {[
                            ['nome', 'Denominação'],
                            ['nif', 'NIF'],
                            ['sede', 'Sede social'],
                            ['forma', 'Forma jurídica'],
                          ].map(([k, rotulo]) => (
                            <Col key={k} xs={24} md={12} xl={6}>
                              <Form.Item name={k} label={rotulo}>
                                <Input maxLength={255} />
                              </Form.Item>
                            </Col>
                          ))}
                          <Col xs={24} md={12} xl={6}>
                            <Form.Item name="capital" label="Capital social (Kz)">
                              <InputNumber min={0} precision={2} style={{ width: '100%' }} />
                            </Form.Item>
                          </Col>
                          <Col xs={24} md={12} xl={9}>
                            <Form.Item name="objecto" label="Objecto social">
                              <Input maxLength={255} />
                            </Form.Item>
                          </Col>
                          <Col xs={24} md={12} xl={9}>
                            <Form.Item name="sector" label="Sector de actividade">
                              <Input maxLength={255} />
                            </Form.Item>
                          </Col>
                        </Row>
                      </Card>
                      <Card size="small" title="Aprovação e assinaturas" style={{ marginBottom: 12 }}>
                        <Row gutter={[16, 0]}>
                          <Col xs={24} md={12} xl={6}>
                            <Form.Item name="local" label="Local">
                              <Input maxLength={255} />
                            </Form.Item>
                          </Col>
                          <Col xs={24} md={12} xl={6}>
                            <Form.Item name="data" label="Data do relatório">
                              <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
                            </Form.Item>
                          </Col>
                          <Col xs={24} md={12} xl={6}>
                            <Form.Item name="director" label="Director Geral / Gerência">
                              <Input maxLength={255} />
                            </Form.Item>
                          </Col>
                          <Col xs={24} md={12} xl={6}>
                            <Form.Item name="contabilista" label="Contabilista Certificado">
                              <Input maxLength={255} />
                            </Form.Item>
                          </Col>
                        </Row>
                      </Card>
                      <Card size="small" title="Imposto e aplicação de resultados" style={{ marginBottom: 12 }}>
                        <Row gutter={[16, 0]}>
                          <Col xs={12} md={8} xl={4}>
                            <Form.Item name="taxa_imposto" label="Taxa de imposto (%)">
                              <InputNumber min={0} max={100} style={{ width: '100%' }} />
                            </Form.Item>
                          </Col>
                          <Col xs={12} md={8} xl={5}>
                            <Form.Item name="prejuizos_fiscais" label="Prejuízos fiscais a deduzir">
                              <InputNumber min={0} precision={2} style={{ width: '100%' }} />
                            </Form.Item>
                          </Col>
                          {[
                            ['pct_reservas', 'Reservas legais (%)'],
                            ['pct_transitados', 'Resultados transitados (%)'],
                            ['pct_dividendos', 'Dividendos (%)'],
                          ].map(([k, rotulo]) => (
                            <Col key={k} xs={12} md={8} xl={5}>
                              <Form.Item name={k} label={rotulo}>
                                <InputNumber min={0} max={100} style={{ width: '100%' }} />
                              </Form.Item>
                            </Col>
                          ))}
                        </Row>
                      </Card>
                      <Card size="small" title="Anexos do relatório impresso" style={{ marginBottom: 12 }}>
                        <Space direction="vertical">
                          {[
                            ['anexo_razao', `Balancete razão (2 dígitos) — abertura a Dezembro e abertura a apuramento (${d.anexos.razao_dezembro?.length ?? 0} contas)`],
                            ['anexo_geral', `Balancete geral (todas as contas) — abertura a Dezembro e abertura a apuramento (${d.anexos.geral_dezembro?.length ?? 0} contas)`],
                            ['anexo_amortizacoes', `Mapa de amortizações (${d.anexos.amortizacoes?.length ?? 0} activos)`],
                            ['graficos', 'Incluir gráficos (no ecrã e no relatório impresso)'],
                          ].map(([k, rotulo]) => (
                            <Form.Item key={k} name={k} valuePropName="checked" noStyle>
                              <Checkbox>{rotulo}</Checkbox>
                            </Form.Item>
                          ))}
                        </Space>
                      </Card>
                    </Form>
                    <Card size="small" title="Consultar anexos">
                      <Tabs
                        tabPosition={telemovel ? 'top' : 'left'}
                        items={Object.entries(d.anexos).map(([k, linhas]) => ({
                          key: k,
                          label: NOMES_ANEXOS[k] ?? k,
                          children: <TabelaAnexo nome={k} linhas={linhas} />,
                        }))}
                      />
                    </Card>
                  </>
                ),
              },
            ]}
          />
          <Descriptions size="small" style={{ marginTop: 16 }}>
            <Descriptions.Item label="Moeda">{d.moeda}</Descriptions.Item>
            <Descriptions.Item label="Estado">{regras.concluido ? <Tag color="green">Concluído (números fixados)</Tag> : <Tag>Rascunho (números recalculados a cada consulta)</Tag>}</Descriptions.Item>
          </Descriptions>
        </>
      )}
    </>
  );
}

function TabelaAnexo({ nome, linhas }: { nome: string; linhas: Record<string, unknown>[] }) {
  if (!linhas?.length) return <Alert type="info" message="Sem linhas." />;
  const chaves = Object.keys(linhas[0]);
  const monetaria = (k: string) => linhas.some((l) => typeof l[k] === 'string' && /^-?\d+\.\d{2}$/.test(l[k] as string));
  return (
    <>
      <div style={{ marginBottom: 8 }}>
        <BotaoCsv<Record<string, unknown>> nome={nome} linhas={linhas} colunas={chaves.map((k) => ({ titulo: k.replace(/_/g, ' '), valor: (l) => l[k] as string, numerico: monetaria(k) }))} />
      </div>
      <Table
        rowKey={(_, i) => String(i)}
        size="small"
        dataSource={linhas}
        pagination={{ pageSize: 50 }}
        scroll={scrollTabela()}
        columns={chaves.map((k) => ({
          title: k.replace(/_/g, ' '),
          dataIndex: k,
          align: monetaria(k) ? ('right' as const) : undefined,
          render: (v: unknown) => (monetaria(k) ? formatarKz(v as string) : typeof v === 'object' && v !== null ? JSON.stringify(v) : String(v ?? '—')),
        }))}
      />
    </>
  );
}

/** Relatório e Contas para impressão: indicadores, rácios, alertas, notas seleccionadas e anexos. */
export function documentoRelatorioContas(d: DadosRC, notas: Record<string, boolean>, textos: Record<string, TextoGravado> = {}, config: ConfigGestao = {}): string {
  // Relatório de Gestão (M-10): títulos, texto em vigor (editado ou automático) e tabelas de indicadores
  let grupoActual: string | undefined;
  const gestao = SECCOES.map((s) => {
    const cab = s.grupo && s.grupo !== grupoActual ? `<h3 style="font-size:10.5pt;text-transform:uppercase;margin:10pt 0 4pt">${esc(s.grupo)}</h3>` : '';
    if (s.grupo) grupoActual = s.grupo;
    const linhas = tabelaSeccao(s.tabela, d, config);
    const tabela = linhas.length
      ? tabelaHtml<[string, string, string]>({
          linhas,
          colunas: [
            { titulo: s.tabela === 'aplicacao' ? 'Aplicação' : 'Indicador', valor: (r) => r[0] },
            { titulo: String(d.ano), valor: (r) => r[1], alinhamento: 'direita' },
            ...(s.tabela === 'aplicacao' ? [] : [{ titulo: String(d.ano_anterior), valor: (r: [string, string, string]) => r[2], alinhamento: 'direita' as const }]),
          ],
        })
      : '';
    return `${cab}<h4 style="font-size:10pt;margin:8pt 0 3pt">${esc(s.titulo)}</h4><div style="font-size:9pt;line-height:1.45">${textoParaHtml(textoActual(s.id, textos[s.id], d, config).texto)}</div>${tabela}`;
  }).join('');
  const anoN = String(d.ano);
  const anoN1 = String(d.ano_anterior);
  const grandezas = tabelaHtml({
    legenda: 'Grandezas (Kz)',
    linhas: MONETARIOS,
    colunas: [
      { titulo: 'Indicador', valor: (r) => r[1] },
      { titulo: anoN, valor: (r) => d.n.indicadores[r[0]] as string, formato: 'moeda' },
      { titulo: anoN1, valor: (r) => d.n1.indicadores[r[0]] as string, formato: 'moeda' },
    ],
  });
  const racios = tabelaHtml({
    legenda: 'Rácios',
    linhas: RACIOS,
    colunas: [
      { titulo: 'Rácio', valor: (r) => r[1] },
      { titulo: anoN, valor: (r) => racio(d.n.indicadores[r[0]], r[2]), alinhamento: 'direita' },
      { titulo: anoN1, valor: (r) => racio(d.n1.indicadores[r[0]], r[2]), alinhamento: 'direita' },
    ],
  });
  const alertas = d.alertas.length
    ? tabelaHtml({
        legenda: `Alertas (${d.alertas.length})`,
        linhas: d.alertas,
        colunas: [
          { titulo: 'Tipo', valor: (a) => (a.tipo === 'erro' ? 'Erro' : a.tipo === 'aviso' ? 'Aviso' : 'Informação') },
          { titulo: 'Alerta', valor: (a) => a.texto, quebrar: true },
        ],
      })
    : '';
  const notasHtml = Object.entries(d.composicao)
    .filter(([nota]) => notas[nota] !== false)
    .sort(([a], [b]) => Number(a) - Number(b))
    .map(([nota, contas]) =>
      tabelaHtml({
        legenda: `Nota ${nota}`,
        linhas: contas,
        totais: true,
        colunas: [
          { titulo: 'Conta', valor: (c) => c.conta },
          { titulo: 'Descrição', valor: (c) => c.descricao ?? '—', quebrar: true },
          { titulo: anoN, valor: (c) => c.n, formato: 'moeda', somar: true },
          { titulo: anoN1, valor: (c) => c.n1, formato: 'moeda', somar: true },
        ],
      }),
    )
    .join('');
  const anexos = Object.entries(d.anexos)
    .filter(([, linhas]) => linhas?.length)
    .map(([k, linhas]) => {
      const chaves = Object.keys(linhas[0]);
      const monetaria = (c: string) => linhas.some((l) => typeof l[c] === 'string' && /^-?\d+\.\d{2}$/.test(l[c] as string));
      return tabelaHtml<Record<string, unknown>>({
        legenda: NOMES_ANEXOS[k] ?? k,
        linhas,
        colunas: chaves.map((c) => ({
          titulo: c.replace(/_/g, ' '),
          valor: (l) => (typeof l[c] === 'object' && l[c] !== null ? JSON.stringify(l[c]) : (l[c] as string | number | null | undefined)),
          formato: monetaria(c) ? ('moeda' as const) : undefined,
        })),
      });
    })
    .join('');
  return `<h2 style="font-size:12pt">Relatório de Gestão</h2>${gestao}<h2 style="font-size:12pt;break-before:page">Demonstrações financeiras e indicadores</h2>${grandezas}${racios}<p style="font-size:8.5pt">Colaboradores no exercício: ${esc(d.colaboradores)}</p>${alertas}${notasHtml}${anexos}`;
}
