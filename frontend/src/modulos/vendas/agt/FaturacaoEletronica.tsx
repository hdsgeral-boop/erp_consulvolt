import { Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Flex, Form, Input, InputNumber, Modal, Row, Select, Skeleton, Space, Statistic, Switch, Table, Tag, Typography } from 'antd';
import { CloudUploadOutlined, DeleteOutlined, EditOutlined, PlusOutlined, SettingOutlined, SyncOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter, obterPagina } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import type { DocumentoVenda } from '../api';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { COLUNAS_DESCRICOES, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { EstadoTag } from '@/modulos/compras/comum/estados';
import { ModalContas } from '@/modulos/compras/comum/ModalContas';

import { TabelaComModos } from '@/componentes/vistas';
interface ConfiguracaoFE {
  ativo: boolean;
  data_inicio: string | null;
  estabelecimentos: { numero: string; nome: string | null }[];
  pais_padrao: string | null;
  isencao_padrao: string | null;
  servico: { auto: boolean; exigir_series_agt: boolean };
  isencoes: Record<string, string>;
}

interface SerieFE {
  id: number;
  codigo: string;
  tipo: string;
  ano: number;
  origem: string | null;
  origem_nome: string | null;
  estado: string;
  contingencia: boolean | null;
  proximo_numero: number | null;
  ultima_data: string | null;
  agt_ultimo_numero: number | null;
  estabelecimento?: string | null;
}

const RESUMO: { chave: string; rotulo: string; cor?: string }[] = [
  { chave: 'POR_ENVIAR', rotulo: 'Por enviar', cor: '#d48806' },
  { chave: 'ENVIADO', rotulo: 'Enviados (a aguardar)' },
  { chave: 'VALIDO', rotulo: 'Válidos', cor: '#389e0d' },
  { chave: 'INVALIDO', rotulo: 'Inválidos', cor: '#cf1322' },
  { chave: 'REJEITADO', rotulo: 'Rejeitados', cor: '#cf1322' },
  { chave: 'ERRO', rotulo: 'Erro de envio', cor: '#cf1322' },
  { chave: 'COM_ERROS_LOCAIS', rotulo: 'Com erros locais', cor: '#cf1322' },
];

const TIPOS_SERIE = ['FT', 'FR', 'NC', 'OR', 'PF', 'NE', 'RE'];

/** Vendas › Facturação › Facturação electrónica AGT: estado do envio, configuração do regime, séries e contas de vendas. */
export function FaturacaoEletronica() {
  const { pode } = useSessao();
  const config = pode('vendas_fe_config');
  const [contas, setContas] = useState(false);
  return (
    <>
      <CabecalhoPagina
        titulo="Facturação electrónica (AGT)"
        subtitulo="Envio dos documentos à AGT, regime, estabelecimentos e séries"
        accoes={pode('vendas_faturacao_view', 'vendas_config') && <Button icon={<SettingOutlined />} onClick={() => setContas(true)}>Contas de vendas</Button>}
      />
      {/* 4.7.1: uma página com as secções pela ordem do legado (facturacao_agt_ui.js:render) */}
      <Flex vertical gap={16}>
        {config && <Configuracao />}
        <EstadoEnvio />
        <DadosARever />
        <Series />
        <DocumentosComErros />
      </Flex>
      <ModalContas url="/vendas/configuracao/contas" titulo="Contas de vendas" chaveConsulta={['vendas', 'contas']} aberto={contas} aoFechar={() => setContas(false)} podeEditar={pode('vendas_config')} />
    </>
  );
}

function EstadoEnvio() {
  const { pode } = useSessao();
  const navegar = useNavigate();
  const resumo = useQuery({ queryKey: ['vendas', 'fe', 'resumo'], queryFn: () => obter<Record<string, number>>('/vendas/faturacao-eletronica/resumo') });
  const ligacao = useQuery({ queryKey: ['vendas', 'fe', 'ligacao'], queryFn: () => obter<Record<string, unknown>>('/vendas/faturacao-eletronica/ligacao'), enabled: pode('vendas_fe_config'), retry: false });
  const [resultado, setResultado] = useState<Record<string, unknown> | null>(null);
  const accao = useAccao<Record<string, unknown>>({ invalidar: [['vendas']], aoSucesso: (d) => setResultado(d) });

  return (
    <>
      <Card
        style={{ marginBottom: 16 }}
        extra={
          pode('vendas_fat_emitir') && (
            <Space wrap>
              <Button type="primary" icon={<CloudUploadOutlined />} loading={accao.isPending && accao.variables?.url.endsWith('enviar')} onClick={() => accao.mutate({ url: '/vendas/faturacao-eletronica/enviar' })}>
                Enviar pendentes
              </Button>
              <Button icon={<SyncOutlined />} loading={accao.isPending && accao.variables?.url.endsWith('consultar')} onClick={() => accao.mutate({ url: '/vendas/faturacao-eletronica/consultar' })}>
                Actualizar estados
              </Button>
            </Space>
          )
        }
        title={<><IconeFa nome="paper-plane" style={{ marginRight: 6 }} />Ligação à AGT (serviço intermédio)</>}
      >
        <Typography.Paragraph type="secondary">
          O serviço intermédio guarda as credenciais e as chaves, assina e envia os documentos à AGT. Nada é enviado enquanto não carregar em «Enviar pendentes» ou o envio automático não estiver ligado.
        </Typography.Paragraph>
        {resumo.isLoading ? (
          <Skeleton active />
        ) : (
          <Row gutter={[16, 16]}>
            {RESUMO.map((r) => (
              <Col key={r.chave} xs={12} md={6} lg={3}>
                {/* atalho para a lista de documentos filtrada pelo estado AGT (A-04) */}
                <div
                  role="link"
                  tabIndex={0}
                  title={`Ver os documentos: ${r.rotulo.toLowerCase()}`}
                  style={{ cursor: 'pointer' }}
                  onClick={() => navegar(`/m/vendas/vendas_faturacao?estado_fe=${r.chave}`)}
                  onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && navegar(`/m/vendas/vendas_faturacao?estado_fe=${r.chave}`)}
                >
                  <Statistic title={r.rotulo} value={resumo.data?.[r.chave] ?? 0} valueStyle={(resumo.data?.[r.chave] ?? 0) > 0 && r.cor ? { color: r.cor } : undefined} />
                </div>
              </Col>
            ))}
          </Row>
        )}
        {resultado && (
          <Alert
            style={{ marginTop: 16 }}
            type="info"
            closable
            onClose={() => setResultado(null)}
            message="Resultado"
            description={
              <Descriptions size="small" column={COLUNAS_DESCRICOES}>
                {Object.entries(resultado).filter(([, v]) => typeof v !== 'object').map(([k, v]) => (
                  <Descriptions.Item key={k} label={k.replace(/_/g, ' ')}>{String(v)}</Descriptions.Item>
                ))}
              </Descriptions>
            }
          />
        )}
      </Card>
      {pode('vendas_fe_config') && (
        <Card size="small" title="Estado da ligação">
          {ligacao.isLoading ? (
            <Skeleton active />
          ) : ligacao.error ? (
            <Alert type="error" showIcon message="Não foi possível obter o estado da ligação." />
          ) : (
            <Descriptions size="small" column={{ xs: 1, md: 2 }} bordered>
              {Object.entries(ligacao.data ?? {}).map(([k, v]) => (
                <Descriptions.Item key={k} label={k.replace(/_/g, ' ')}>
                  {typeof v === 'boolean' ? (v ? <Tag color="green">Sim</Tag> : <Tag color="red">Não</Tag>) : typeof v === 'object' ? JSON.stringify(v) : String(v ?? '—')}
                </Descriptions.Item>
              ))}
            </Descriptions>
          )}
        </Card>
      )}
    </>
  );
}

interface ValoresConfig {
  ativo: boolean;
  data_inicio?: Dayjs | null;
  pais_padrao?: string | null;
  isencao_padrao?: string | null;
  auto?: boolean;
  exigir_series_agt?: boolean;
  estabelecimentos: { numero: string; nome?: string | null }[];
}

function Configuracao() {
  const [form] = Form.useForm<ValoresConfig>();
  const consulta = useQuery({ queryKey: ['vendas', 'fe', 'configuracao'], queryFn: () => obter<ConfiguracaoFE>('/vendas/faturacao-eletronica/configuracao') });
  const gravar = useAccao({ invalidar: [['vendas', 'fe']], tituloErro: 'Não foi possível gravar a configuração' });

  useEffect(() => {
    const c = consulta.data;
    if (c)
      form.setFieldsValue({
        ativo: c.ativo,
        data_inicio: c.data_inicio ? dayjs(c.data_inicio) : null,
        pais_padrao: c.pais_padrao,
        isencao_padrao: c.isencao_padrao,
        auto: c.servico?.auto,
        exigir_series_agt: c.servico?.exigir_series_agt,
        estabelecimentos: c.estabelecimentos?.length ? c.estabelecimentos : [{ numero: '1', nome: 'Sede' }],
      });
  }, [consulta.data, form]);

  if (consulta.isLoading) return <Skeleton active />;
  return (
    <Card title={<><IconeFa nome="stamp" style={{ marginRight: 6 }} />Regime de facturação electrónica{' '}
      {consulta.data?.ativo ? <Tag color="green">Activo{consulta.data.data_inicio ? ` desde ${formatarData(consulta.data.data_inicio)}` : ''}</Tag> : <Tag>Inactivo</Tag>}</>}>
      <Typography.Paragraph type="secondary">
        Facturas (FT), Facturas-Recibo (FR) e Notas de Crédito (NC) passam a ser numeradas por séries e, depois de emitidas, ficam seladas — não se apagam nem se alteram, corrigem-se com Nota de Crédito. Cada documento é validado com as regras publicadas pela AGT e sai impresso com o QR code de consulta.
      </Typography.Paragraph>
      <Form<ValoresConfig>
        form={form}
        layout="vertical"
        onFinish={(v) =>
          gravar.mutate({
            metodo: 'put',
            url: '/vendas/faturacao-eletronica/configuracao',
            dados: {
              ativo: !!v.ativo,
              data_inicio: dataApi(v.data_inicio) ?? null,
              pais_padrao: v.pais_padrao?.toUpperCase() || null,
              isencao_padrao: v.isencao_padrao || null,
              servico: { auto: !!v.auto, exigir_series_agt: !!v.exigir_series_agt },
              estabelecimentos: v.estabelecimentos.map((e) => ({ numero: e.numero, nome: e.nome || null })),
            },
          })
        }
      >
        <Row gutter={16}>
          <Col xs={24} md={6}>
            <Form.Item name="ativo" label="Estado" valuePropName="checked">
              <Switch checkedChildren="Activo" unCheckedChildren="Inactivo" />
            </Form.Item>
          </Col>
          <Col xs={24} md={6}>
            <Form.Item name="data_inicio" label="Data de início">
              <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={12} md={4}>
            <Form.Item name="pais_padrao" label="País por omissão" rules={[{ pattern: /^[A-Za-z]{2}$/, message: 'ISO de 2 letras.' }]}>
              <Input maxLength={2} style={{ textTransform: 'uppercase' }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item name="isencao_padrao" label="Motivo de isenção por omissão (IVA 0%)">
              <Select allowClear showSearch optionFilterProp="label" options={Object.entries(consulta.data?.isencoes ?? {}).map(([k, d]) => ({ value: k, label: `${k} — ${d}` }))} />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="auto" valuePropName="checked" style={{ marginBottom: 8 }}>
              <Checkbox>Envio e consulta automáticos (agendador)</Checkbox>
            </Form.Item>
            <Form.Item name="exigir_series_agt" valuePropName="checked">
              <Checkbox>Exigir séries atribuídas pela AGT</Checkbox>
            </Form.Item>
          </Col>
        </Row>
        <Card size="small" title="Estabelecimentos" style={{ marginBottom: 16 }}>
          <Form.List name="estabelecimentos">
            {(campos, { add, remove }) => (
              <>
                {campos.map(({ key, name }) => (
                  <Row key={key} gutter={8} wrap={false}>
                    <Col flex="0 0 30%">
                      <Form.Item name={[name, 'numero']} rules={[{ required: true, message: 'N.º' }, { max: 20 }]}>
                        <Input placeholder="N.º" />
                      </Form.Item>
                    </Col>
                    <Col flex="1 1 auto" style={{ minWidth: 0 }}>
                      <Form.Item name={[name, 'nome']} rules={[{ max: 200 }]}>
                        <Input placeholder="Nome" />
                      </Form.Item>
                    </Col>
                    <Col flex="0 0 40px">
                      <Button danger type="text" icon={<DeleteOutlined />} disabled={campos.length <= 1} onClick={() => remove(name)} aria-label="Remover" />
                    </Col>
                  </Row>
                ))}
                <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ numero: String(campos.length + 1), nome: '' })}>Acrescentar estabelecimento</Button>
              </>
            )}
          </Form.List>
        </Card>
        <Button type="primary" htmlType="submit" loading={gravar.isPending}>Gravar configuração</Button>
      </Form>
    </Card>
  );
}

function Series() {
  const { pode } = useSessao();
  const podeGerir = pode('vendas_fe_config');
  const [ano, setAno] = useState<number | null>(dayjs().year());
  const [edicao, setEdicao] = useState<SerieFE | 'nova' | null>(null);
  const [form] = Form.useForm<Partial<SerieFE>>();
  const consulta = useQuery({ queryKey: ['vendas', 'fe', 'series', ano], queryFn: () => obter<SerieFE[]>('/vendas/configuracao/series', { ano: ano ?? undefined }) });
  const accao = useAccao({ invalidar: [['vendas', 'fe', 'series']], aoSucesso: () => setEdicao(null) });

  useEffect(() => {
    if (edicao === 'nova') form.setFieldsValue({ tipo: 'FT', ano: dayjs().year(), codigo: '', origem: null, origem_nome: null, estabelecimento: null, contingencia: false, estado: 'ATIVA' });
    else if (edicao) form.setFieldsValue(edicao);
  }, [edicao, form]);

  return (
    <Card
      title={<><IconeFa nome="layer-group" style={{ marginRight: 6 }} />Séries de numeração</>}
      extra={
        <Space wrap>
          <InputNumber placeholder="Ano" min={2000} max={2100} value={ano} onChange={setAno} />
          {podeGerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('nova')}>Nova série</Button>}
          <BotoesExportar
            desactivado={!consulta.data?.length}
            obterPedido={() => ({
              titulo: 'Séries de documentos de venda',
              filtros: [ano ? `Ano: ${ano}` : 'Todos os anos'],
              conteudo: tabelaHtml({
                colunas: [
                  { titulo: 'Tipo', valor: (s: SerieFE) => s.tipo },
                  { titulo: 'Código', valor: (s) => s.codigo },
                  { titulo: 'Ano', valor: (s) => s.ano, alinhamento: 'centro' },
                  { titulo: 'Origem', valor: (s) => [s.origem, s.origem_nome].filter(Boolean).join(' — ') },
                  { titulo: 'Estabelecimento', valor: (s) => s.estabelecimento ?? '' },
                  { titulo: 'Próximo n.º', valor: (s) => s.proximo_numero, formato: 'inteiro' },
                  { titulo: 'Último n.º AGT', valor: (s) => s.agt_ultimo_numero, formato: 'inteiro' },
                  { titulo: 'Última data', valor: (s) => s.ultima_data, formato: 'data' },
                  { titulo: 'Contingência', valor: (s) => (s.contingencia ? 'Sim' : 'Não') },
                  { titulo: 'Estado', valor: (s) => (s.estado === 'ATIVA' ? 'Activa' : s.estado === 'FECHADA' ? 'Fechada' : s.estado) },
                ],
                linhas: consulta.data ?? [],
              }),
            })}
          />
        </Space>
      }
    >
      <TabelaComModos<SerieFE> idVista="series"
        rowKey="id"
        size="small"
        loading={consulta.isFetching}
        dataSource={consulta.data ?? []}
        pagination={false}
        scroll={scrollTabela()}
        columns={[
          { title: 'Tipo', dataIndex: 'tipo', render: (t: string) => <Tag>{t}</Tag> },
          { title: 'Código', dataIndex: 'codigo', render: (v: string) => <strong>{v}</strong> },
          { title: 'Ano', dataIndex: 'ano', responsive: ['sm'] },
          { title: 'Origem', key: 'origem', responsive: ['md'], render: (_, s) => [s.origem, s.origem_nome].filter(Boolean).join(' — ') || '—' },
          { title: 'Próximo n.º', dataIndex: 'proximo_numero', align: 'right', render: (v) => v ?? '—' },
          { title: 'Último n.º AGT', dataIndex: 'agt_ultimo_numero', align: 'right', responsive: ['md'], render: (v) => v ?? '—' },
          { title: 'Última data', dataIndex: 'ultima_data', responsive: ['lg'], render: formatarData },
          { title: 'Contingência', dataIndex: 'contingencia', responsive: ['lg'], render: (c: boolean | null) => (c ? <Tag color="orange">Sim</Tag> : 'Não') },
          { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e === 'ATIVA' ? 'ATIVO' : e === 'FECHADA' ? 'CONCLUIDA' : e} /> },
          {
            title: '',
            key: 'accoes',
            align: 'right',
            render: (_, s) =>
              podeGerir && (
                <Space wrap={false}>
                  <Button size="small" onClick={() => accao.mutate({ url: `/vendas/configuracao/series/${s.id}/solicitar-agt` })}>Pedir à AGT</Button>
                  <Button size="small" icon={<EditOutlined />} onClick={() => setEdicao(s)} aria-label="Editar" />
                  <Button
                    size="small"
                    danger
                    icon={<DeleteOutlined />}
                    aria-label="Eliminar"
                    onClick={() =>
                      Modal.confirm({
                        title: `Eliminar a série ${s.tipo} ${s.codigo}?`,
                        content: 'Só é possível em séries ainda sem documentos.',
                        okText: 'Eliminar',
                        okButtonProps: { danger: true },
                        cancelText: 'Cancelar',
                        onOk: () => accao.mutateAsync({ metodo: 'delete', url: `/vendas/configuracao/series/${s.id}` }),
                      })
                    }
                  />
                </Space>
              ),
          },
        ]}
      />
      <Modal width={larguraModal(560)} title={edicao === 'nova' ? 'Nova série' : 'Editar série'} open={edicao !== null} onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()}>
        <Form
          form={form}
          layout="vertical"
          onFinish={(v) => {
            const dados = { tipo: v.tipo, ano: v.ano, codigo: v.codigo, origem: v.origem || null, origem_nome: v.origem_nome || null, estabelecimento: v.estabelecimento || null, contingencia: !!v.contingencia, estado: v.estado };
            accao.mutate(edicao && edicao !== 'nova' ? { metodo: 'put', url: `/vendas/configuracao/series/${edicao.id}`, dados } : { url: '/vendas/configuracao/series', dados });
          }}
        >
          <Row gutter={16}>
            <Col xs={24} sm={8}>
              <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
                <Select options={TIPOS_SERIE.map((t) => ({ value: t, label: t }))} />
              </Form.Item>
            </Col>
            <Col xs={24} sm={8}>
              <Form.Item name="ano" label="Ano" rules={[{ required: true }]}>
                <InputNumber min={2000} max={2100} style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={24} sm={8}>
              <Form.Item name="estado" label="Estado">
                <Select options={[{ value: 'ATIVA', label: 'Activa' }, { value: 'FECHADA', label: 'Fechada' }]} />
              </Form.Item>
            </Col>
            <Col xs={24} sm={12}>
              <Form.Item name="codigo" label="Código" rules={[{ required: true, message: 'Indique o código.' }, { pattern: /^[A-Za-z0-9]{1,30}$/, message: 'Letras e números, sem espaços nem «/».' }]}>
                <Input />
              </Form.Item>
            </Col>
            <Col xs={24} sm={12}>
              <Form.Item name="estabelecimento" label="Estabelecimento" rules={[{ max: 20 }]}>
                <Input />
              </Form.Item>
            </Col>
            <Col xs={24} sm={8}>
              <Form.Item name="origem" label="Origem" rules={[{ max: 30 }]}>
                <Input placeholder="Ex.: POS1" />
              </Form.Item>
            </Col>
            <Col xs={24} sm={16}>
              <Form.Item name="origem_nome" label="Nome da origem" rules={[{ max: 200 }]}>
                <Input />
              </Form.Item>
            </Col>
            <Col span={24}>
              <Form.Item name="contingencia" valuePropName="checked">
                <Checkbox>Série de contingência</Checkbox>
              </Form.Item>
            </Col>
          </Row>
        </Form>
      </Modal>
    </Card>
  );
}

interface BlocoDiagnostico { total: number; exemplos: string[] }
interface DiagnosticoAgt {
  empresa_nif: string | null;
  sem_codigo: BlocoDiagnostico;
  isentos_sem_motivo: BlocoDiagnostico;
  taxa_fora: BlocoDiagnostico;
  clientes_sem_nif: BlocoDiagnostico;
}

/** 4.7.1 — «Dados a rever antes de activar» (GET /vendas/faturacao-eletronica/diagnostico; só leitura). */
function DadosARever() {
  const diag = useQuery({ queryKey: ['vendas', 'fe', 'diagnostico'], queryFn: () => obter<DiagnosticoAgt>('/vendas/faturacao-eletronica/diagnostico'), retry: false, staleTime: 120_000 });
  const d = diag.data;
  const itens: [string, BlocoDiagnostico | undefined, string][] = [
    ['Produtos sem código', d?.sem_codigo, 'Sem código, o documento usa o identificador interno.'],
    ['Produtos isentos sem motivo', d?.isentos_sem_motivo, 'Indique o motivo no produto ou o motivo por omissão acima.'],
    ['Produtos com taxa fora de 0/5/7/14%', d?.taxa_fora, 'Vão com o código de taxa «OUT».'],
    ['Clientes sem NIF', d?.clientes_sem_nif, 'Serão enviados como consumidor final (999999999).'],
  ];
  return (
    <Card title={<><IconeFa nome="list-check" style={{ marginRight: 6 }} />Dados a rever antes de activar</>} loading={diag.isLoading}>
      <Typography.Paragraph type="secondary">Estes dados entram em cada documento enviado à AGT. Corrija-os nas fichas de produtos, clientes e empresa.</Typography.Paragraph>
      {diag.error && <Alert type="error" showIcon message="Não foi possível verificar os dados." />}
      {d?.empresa_nif && <Alert type="error" showIcon style={{ marginBottom: 12 }} message={d.empresa_nif} />}
      {d && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12 }}>
          {itens.map(([titulo, b, dica]) => (
            <div key={titulo} style={{ border: '1px solid #e2e8f0', borderRadius: 10, padding: 12, background: '#f8fafc' }}>
              <Flex justify="space-between" align="center"><span>{titulo}</span><strong style={{ fontSize: 18, color: b?.total ? '#b45309' : '#047857' }}>{b?.total ?? 0}</strong></Flex>
              {!!b?.total && (
                <>
                  <small style={{ display: 'block', color: '#475569', marginTop: 4 }}>{b.exemplos.join(', ')}{b.total > b.exemplos.length ? ` e mais ${b.total - b.exemplos.length}` : ''}</small>
                  <small style={{ display: 'block', color: '#64748b' }}>{dica}</small>
                </>
              )}
            </div>
          ))}
        </div>
      )}
    </Card>
  );
}

/** 4.7.1 — «Documentos com erros AGT»: documentos do regime com erros locais (seriam rejeitados) e atalhos para os recusados. */
function DocumentosComErros() {
  const navegar = useNavigate();
  const erros = useQuery({
    queryKey: ['vendas', 'fe', 'com-erros'],
    queryFn: () => obterPagina<DocumentoVenda>('/vendas/documentos', { estado_fe: 'COM_ERROS_LOCAIS', por_pagina: 50 }),
    retry: false,
    staleTime: 60_000,
  });
  const lista = erros.data?.itens ?? [];
  const texto = (d: DocumentoVenda) => {
    const e = d.faturacao_eletronica?.erros ?? [];
    return e.map((x) => (typeof x === 'object' && x && 'mensagem' in x ? String((x as { mensagem: unknown }).mensagem) : String(x))).join(' | ') || 'Documento AGT por gerar';
  };
  return (
    <Card title={<><IconeFa nome="exclamation-triangle" style={{ marginRight: 6 }} />Documentos com erros AGT</>} loading={erros.isLoading}>
      <Typography.Paragraph type="secondary">Os documentos abaixo seriam rejeitados pela AGT: corrija os dados de origem e revalide no detalhe do documento.</Typography.Paragraph>
      {erros.error ? (
        <Alert type="error" showIcon message="Não foi possível ler os documentos." />
      ) : lista.length === 0 ? (
        <Typography.Text strong style={{ color: '#047857' }}>Nenhum documento com erros locais.</Typography.Text>
      ) : (
        <Table<DocumentoVenda>
          rowKey="id"
          size="small"
          pagination={false}
          dataSource={lista}
          scroll={scrollTabela()}
          columns={[
            { title: 'Documento', dataIndex: 'numero_documento', render: (v: string) => <span style={{ fontFamily: 'monospace' }}>{v}</span> },
            { title: 'Data', dataIndex: 'data_emissao', render: formatarData },
            { title: 'Total', dataIndex: 'total_bruto', align: 'right', render: (v: string) => formatarKz(v) },
            { title: 'Erros', key: 'erros', render: (_, d) => texto(d) },
            { title: '', key: 'ver', render: (_, d) => <Button size="small" icon={<IconeFa nome="file-code" />} onClick={() => navegar(`/m/vendas/vendas_faturacao/${d.id}`)}>Ver</Button> },
          ]}
        />
      )}
      <Space wrap style={{ marginTop: 12 }}>
        {['INVALIDO', 'REJEITADO', 'ERRO'].map((e) => (
          <Button key={e} size="small" type="link" onClick={() => navegar(`/m/vendas/vendas_faturacao?estado_fe=${e}`)}>
            Ver documentos: {RESUMO.find((r) => r.chave === e)?.rotulo.toLowerCase()}
          </Button>
        ))}
      </Space>
    </Card>
  );
}
