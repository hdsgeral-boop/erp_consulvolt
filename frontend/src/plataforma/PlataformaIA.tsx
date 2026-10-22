import { Alert, Button, Card, Col, Form, Input, InputNumber, Row, Select, Space, Statistic, Switch, Table, Tabs, Tag, Typography, message } from 'antd';
import { ApiOutlined, CheckCircleOutlined, RobotOutlined, SaveOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { GraficoBarras } from '@/componentes/graficos/Graficos';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora, formatarNumero } from '@/utilitarios/formatacao';

interface Fornecedor {
  id: number | null;
  fornecedor: 'anthropic' | 'openai' | 'gemini';
  nome: string;
  configurado: boolean;
  chave_final: string | null;
  modelos: string[];
  modelo_padrao: string | null;
  preco_entrada_mtok: number;
  preco_saida_mtok: number;
  ativo: boolean;
  testado_em: string | null;
  ultimo_erro: string | null;
}

interface Atribuicao {
  tenant_id: number;
  cliente: string;
  slug: string;
  estado: string;
  fornecedor: string | null;
  modelo: string | null;
  ativo: boolean;
  quota_tokens_mes: number | null;
  pesquisa_web: boolean;
  accoes_erp: boolean;
  tokens_mes: number;
  custo_mes_usd: number;
}

interface Consumo {
  meses: string[];
  tokens_entrada: number[];
  tokens_saida: number[];
  custo_usd: number[];
  pedidos: number[];
  clientes_mes: { cliente: string; tokens: number; custo_usd: number; pedidos: number; pesquisas: number }[];
}

const CHAVE = ['plataforma', 'ia'];
const AJUDA: Record<string, string> = {
  anthropic: 'Chave em console.anthropic.com › API keys. Modelos actuais: claude-opus-5-5 (o mais capaz), claude-sonnet-5-5, claude-haiku-5-5 (o mais económico).',
  openai: 'Chave em platform.openai.com › API keys. Indique os modelos que quer disponibilizar (Responses API).',
  gemini: 'Chave em aistudio.google.com › Get API key. Indique os modelos Gemini que quer disponibilizar.',
};

/** Cartão de um fornecedor: chave (nunca mostrada — só os últimos 4 caracteres), modelos, preços, activo e teste de ligação. */
function CartaoFornecedor({ f }: { f: Fornecedor }) {
  const qc = useQueryClient();
  const [form] = Form.useForm();
  useEffect(() => {
    form.setFieldsValue({ chave: '', modelos: f.modelos, modelo_padrao: f.modelo_padrao, preco_entrada_mtok: f.preco_entrada_mtok, preco_saida_mtok: f.preco_saida_mtok, ativo: f.ativo });
  }, [f, form]);
  const gravar = useMutation({
    mutationFn: (v: Record<string, unknown>) => enviar('put', `/plataforma/ia/fornecedores/${f.fornecedor}`, v),
    onSuccess: () => { message.success('Fornecedor gravado.'); void qc.invalidateQueries({ queryKey: CHAVE }); },
    onError: (e) => notificarErro(e),
  });
  const testar = useMutation({
    mutationFn: () => enviar('post', `/plataforma/ia/fornecedores/${f.fornecedor}/testar`, {}),
    onSuccess: () => { message.success('Ligação verificada: a chave e o modelo funcionam.'); void qc.invalidateQueries({ queryKey: CHAVE }); },
    onError: (e) => { notificarErro(e); void qc.invalidateQueries({ queryKey: CHAVE }); },
  });
  const modelos = Form.useWatch('modelos', form) as string[] | undefined;

  return (
    <Card
      title={<Space><RobotOutlined />{f.nome}</Space>}
      extra={f.configurado ? <Tag color={f.ativo ? 'green' : 'default'}>{f.ativo ? 'Activo' : 'Inactivo'} · chave {f.chave_final}</Tag> : <Tag>Sem chave</Tag>}
    >
      <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>{AJUDA[f.fornecedor]}</Typography.Paragraph>
      {f.ultimo_erro && <Alert type="error" showIcon style={{ marginBottom: 12 }} message="Último teste falhou" description={f.ultimo_erro} />}
      {f.testado_em && !f.ultimo_erro && <Alert type="success" showIcon style={{ marginBottom: 12 }} icon={<CheckCircleOutlined />} message={`Ligação verificada em ${formatarDataHora(f.testado_em)}`} />}
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(Object.fromEntries(Object.entries(v).filter(([k, x]) => k !== 'chave' || x)))}>
        <Form.Item name="chave" label={f.configurado ? 'Nova chave da API (em branco mantém a actual)' : 'Chave da API'} extra="Guardada cifrada; nunca é mostrada nem enviada ao navegador.">
          <Input.Password autoComplete="off" maxLength={500} placeholder={f.chave_final ?? ''} />
        </Form.Item>
        <Form.Item name="modelos" label="Modelos disponíveis"><Select mode="tags" tokenSeparators={[',', ' ']} placeholder="ex.: claude-opus-5-5" /></Form.Item>
        <Form.Item name="modelo_padrao" label="Modelo por omissão"><Select allowClear options={(modelos ?? []).map((m) => ({ value: m, label: m }))} /></Form.Item>
        <Row gutter={12}>
          <Col span={12}><Form.Item name="preco_entrada_mtok" label="Preço entrada (USD / milhão tokens)"><InputNumber min={0} step={0.1} style={{ width: '100%' }} /></Form.Item></Col>
          <Col span={12}><Form.Item name="preco_saida_mtok" label="Preço saída (USD / milhão tokens)"><InputNumber min={0} step={0.1} style={{ width: '100%' }} /></Form.Item></Col>
        </Row>
        <Form.Item name="ativo" label="Activo" valuePropName="checked"><Switch /></Form.Item>
        <Space>
          <Button type="primary" icon={<SaveOutlined />} htmlType="submit" loading={gravar.isPending}>Gravar</Button>
          <Button icon={<ApiOutlined />} disabled={!f.configurado} loading={testar.isPending} onClick={() => testar.mutate()}>Testar ligação</Button>
        </Space>
      </Form>
    </Card>
  );
}

/** Linha editável da atribuição de um cliente. */
function useAtribuicoes(fornecedores: Fornecedor[]) {
  const qc = useQueryClient();
  const lista = useQuery({ queryKey: [...CHAVE, 'atribuicoes'], queryFn: () => obter<Atribuicao[]>('/plataforma/ia/atribuicoes') });
  const gravar = useMutation({
    mutationFn: (a: Atribuicao) => enviar('put', `/plataforma/ia/atribuicoes/${a.tenant_id}`, {
      fornecedor: a.fornecedor, modelo: a.modelo, ativo: a.ativo, quota_tokens_mes: a.quota_tokens_mes, pesquisa_web: a.pesquisa_web, accoes_erp: a.accoes_erp,
    }),
    onSuccess: () => { message.success('Atribuição gravada.'); void qc.invalidateQueries({ queryKey: [...CHAVE, 'atribuicoes'] }); },
    onError: (e) => notificarErro(e),
  });
  const retirar = useMutation({
    mutationFn: (id: number) => enviar('delete', `/plataforma/ia/atribuicoes/${id}`),
    onSuccess: () => { message.success('IA retirada ao cliente.'); void qc.invalidateQueries({ queryKey: [...CHAVE, 'atribuicoes'] }); },
    onError: (e) => notificarErro(e),
  });
  const modelosDe = (f: string | null) => fornecedores.find((x) => x.fornecedor === f)?.modelos ?? [];
  return { lista, gravar, retirar, modelosDe };
}

function Atribuicoes({ fornecedores }: { fornecedores: Fornecedor[] }) {
  const { lista, gravar, retirar, modelosDe } = useAtribuicoes(fornecedores);
  const [edicao, setEdicao] = useState<Record<number, Atribuicao>>({});
  const linha = (a: Atribuicao) => edicao[a.tenant_id] ?? a;
  const mudar = (a: Atribuicao, campo: Partial<Atribuicao>) => setEdicao((e) => ({ ...e, [a.tenant_id]: { ...linha(a), ...campo } }));
  const disponiveis = fornecedores.filter((f) => f.configurado);

  return (
    <Card>
      {disponiveis.length === 0 && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="Configure primeiro a chave de pelo menos um fornecedor no separador «Fornecedores»." />}
      <Table<Atribuicao>
        rowKey="tenant_id"
        size="small"
        loading={lista.isLoading}
        dataSource={lista.data ?? []}
        scroll={{ x: 'max-content' }}
        pagination={{ pageSize: 20, hideOnSinglePage: true }}
        columns={[
          { title: 'Cliente', render: (_, a) => <><div style={{ fontWeight: 600 }}>{a.cliente}</div><div style={{ fontSize: 12, color: '#64748b' }}>{a.slug}</div></> },
          { title: 'Fornecedor', render: (_, a) => <Select style={{ width: 170 }} placeholder="Sem IA" value={linha(a).fornecedor ?? undefined} options={disponiveis.map((f) => ({ value: f.fornecedor, label: f.nome }))}
            onChange={(v) => mudar(a, { fornecedor: v, modelo: fornecedores.find((f) => f.fornecedor === v)?.modelo_padrao ?? null })} /> },
          { title: 'Modelo', render: (_, a) => <Select style={{ width: 190 }} value={linha(a).modelo ?? undefined} options={modelosDe(linha(a).fornecedor).map((m) => ({ value: m, label: m }))} onChange={(v) => mudar(a, { modelo: v })} /> },
          { title: 'Quota mensal (tokens)', render: (_, a) => <InputNumber style={{ width: 150 }} min={1000} step={100000} placeholder="Sem limite" value={linha(a).quota_tokens_mes ?? undefined}
            onChange={(v) => mudar(a, { quota_tokens_mes: v ?? null })} formatter={(v) => (v ? formatarNumero(Number(v)) : '')} parser={(v) => Number(String(v ?? '').replace(/\D/g, '')) || 0} /> },
          { title: 'Internet', render: (_, a) => <Switch size="small" checked={linha(a).pesquisa_web} onChange={(v) => mudar(a, { pesquisa_web: v })} /> },
          { title: 'Acções no ERP', render: (_, a) => <Switch size="small" checked={linha(a).accoes_erp} onChange={(v) => mudar(a, { accoes_erp: v })} /> },
          { title: 'Activa', render: (_, a) => <Switch size="small" checked={linha(a).ativo} onChange={(v) => mudar(a, { ativo: v })} /> },
          { title: 'Este mês', render: (_, a) => <span>{formatarNumero(a.tokens_mes)} tokens<br /><Typography.Text type="secondary" style={{ fontSize: 12 }}>≈ {a.custo_mes_usd.toFixed(2)} USD</Typography.Text></span> },
          {
            title: '',
            render: (_, a) => (
              <Space>
                <Button size="small" type="primary" disabled={!linha(a).fornecedor} loading={gravar.isPending} onClick={() => gravar.mutate(linha(a))}>Gravar</Button>
                {a.fornecedor && <Button size="small" danger onClick={() => retirar.mutate(a.tenant_id)}>Retirar</Button>}
              </Space>
            ),
          },
        ]}
      />
    </Card>
  );
}

function ConsumoIA() {
  const { data, isLoading } = useQuery({ queryKey: [...CHAVE, 'consumo'], queryFn: () => obter<Consumo>('/plataforma/ia/consumo') });
  const totalMes = data?.clientes_mes.reduce((t, c) => t + c.tokens, 0) ?? 0;
  const custoMes = data?.clientes_mes.reduce((t, c) => t + c.custo_usd, 0) ?? 0;
  return (
    <>
      <Row gutter={[12, 12]}>
        <Col xs={12} md={6}><Card loading={isLoading}><Statistic title="Tokens este mês" value={totalMes} formatter={(v) => formatarNumero(Number(v))} /></Card></Col>
        <Col xs={12} md={6}><Card loading={isLoading}><Statistic title="Custo estimado este mês" value={custoMes} precision={2} suffix="USD" /></Card></Col>
        <Col xs={12} md={6}><Card loading={isLoading}><Statistic title="Pedidos este mês" value={data?.clientes_mes.reduce((t, c) => t + c.pedidos, 0) ?? 0} /></Card></Col>
        <Col xs={12} md={6}><Card loading={isLoading}><Statistic title="Pesquisas na internet" value={data?.clientes_mes.reduce((t, c) => t + c.pesquisas, 0) ?? 0} /></Card></Col>
      </Row>
      <Row gutter={[12, 12]} style={{ marginTop: 12 }}>
        <Col xs={24} xl={14}>
          <Card title="Tokens por mês" loading={isLoading}>
            {data && <GraficoBarras rotulos={data.meses} empilhado series={[{ rotulo: 'Entrada', valores: data.tokens_entrada, cor: '#2563eb' }, { rotulo: 'Saída', valores: data.tokens_saida, cor: '#7c3aed' }]} altura={240} />}
          </Card>
        </Col>
        <Col xs={24} xl={10}>
          <Card title="Custo estimado por mês (USD)" loading={isLoading}>
            {data && <GraficoBarras rotulos={data.meses} series={[{ rotulo: 'USD', valores: data.custo_usd, cor: '#16a34a' }]} altura={240} />}
          </Card>
        </Col>
      </Row>
      <Card title="Consumo por cliente (este mês)" style={{ marginTop: 12 }}>
        <Table rowKey="cliente" size="small" pagination={false} loading={isLoading} dataSource={data?.clientes_mes ?? []}
          columns={[
            { title: 'Cliente', dataIndex: 'cliente' },
            { title: 'Tokens', align: 'right', render: (_, c) => formatarNumero(c.tokens) },
            { title: 'Pedidos', dataIndex: 'pedidos', align: 'right' },
            { title: 'Pesquisas', dataIndex: 'pesquisas', align: 'right' },
            { title: 'Custo (USD)', align: 'right', render: (_, c) => c.custo_usd.toFixed(2) },
          ]} />
      </Card>
    </>
  );
}

/**
 * Plataforma SaaS — inteligência artificial (plataforma_ia): chaves dos fornecedores (Anthropic, OpenAI, Google Gemini),
 * atribuição a cada cliente (fornecedor, modelo, quota mensal de tokens, internet, acções no ERP) e consumo.
 */
export default function PlataformaIA() {
  const fornecedores = useQuery({ queryKey: CHAVE, queryFn: () => obter<Fornecedor[]>('/plataforma/ia/fornecedores') });
  return (
    <>
      <CabecalhoPagina titulo="Inteligência artificial" subtitulo="Fornecedores, atribuição aos clientes, quotas e consumo do assistente IA" />
      <Tabs
        items={[
          { key: 'fornecedores', label: 'Fornecedores', children: (
            <Row gutter={[12, 12]}>
              {(fornecedores.data ?? []).map((f) => <Col key={f.fornecedor} xs={24} xl={8}><CartaoFornecedor f={f} /></Col>)}
            </Row>
          ) },
          { key: 'atribuicoes', label: 'Atribuição aos clientes', children: <Atribuicoes fornecedores={fornecedores.data ?? []} /> },
          { key: 'consumo', label: 'Consumo', children: <ConsumoIA /> },
        ]}
      />
    </>
  );
}
