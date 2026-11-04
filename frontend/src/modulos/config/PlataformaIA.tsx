import { useEffect, useState } from 'react';
import { Alert, Button, Card, Col, Form, Input, InputNumber, Popconfirm, Row, Select, Space, Statistic, Switch, Table, Tabs, Tag, Typography, message } from 'antd';
import { ApiOutlined, CheckCircleOutlined, RobotOutlined, SaveOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { GraficoBarras } from '@/componentes/graficos/Graficos';
import type { AtribuicaoIA, ConsumoIA, FornecedorIA } from '@/componentes/assistente/tipos';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora, formatarNumero } from '@/utilitarios/formatacao';
import { corpoFornecedor, estadoDaChave, fornecedoresAtribuiveis, nivelQuota, problemaAtribuicao, QUOTA_MINIMA, totaisConsumo } from './ia/regrasIA';

const CHAVE = ['plataforma', 'ia'];
const AJUDA: Record<string, string> = {
  anthropic: 'Chave em console.anthropic.com › API keys. Escolha os modelos Claude que quer disponibilizar.',
  openai: 'Chave em platform.openai.com › API keys. Indique os modelos que quer disponibilizar.',
  gemini: 'Chave em aistudio.google.com › Get API key. Indique os modelos Gemini que quer disponibilizar.',
};

/**
 * Cartão de um fornecedor. A chave é só de escrita: o servidor guarda-a cifrada e nunca a devolve — o ecrã mostra apenas
 * se está «configurada». Deixar o campo em branco mantém a chave actual; digitar uma nova substitui-a.
 */
function CartaoFornecedor({ f }: { f: FornecedorIA }) {
  const qc = useQueryClient();
  const [form] = Form.useForm();
  const [modelosDetectados, setModelosDetectados] = useState<string[]>([]);
  useEffect(() => {
    form.setFieldsValue({ chave: '', modelos: f.modelos, modelo_padrao: f.modelo_padrao, preco_entrada_mtok: f.preco_entrada_mtok, preco_saida_mtok: f.preco_saida_mtok, ativo: f.ativo });
  }, [f, form]);
  const gravar = useMutation({
    mutationFn: (v: Record<string, unknown>) => enviar('put', `/plataforma/ia/fornecedores/${f.fornecedor}`, corpoFornecedor(v)),
    onSuccess: () => {
      message.success('Fornecedor gravado.');
      form.setFieldValue('chave', '');
      void qc.invalidateQueries({ queryKey: CHAVE });
    },
    onError: (e) => notificarErro(e),
  });
  const testar = useMutation({
    mutationFn: () => enviar('post', `/plataforma/ia/fornecedores/${f.fornecedor}/testar`, { modelo: form.getFieldValue('modelo_padrao') }),
    onSuccess: () => {
      message.success('Ligação verificada: a chave e o modelo funcionam.');
      void qc.invalidateQueries({ queryKey: CHAVE });
    },
    onError: (e) => {
      notificarErro(e);
      void qc.invalidateQueries({ queryKey: CHAVE });
    },
  });
  const modelos = Form.useWatch('modelos', form) as string[] | undefined;
  const chaveDigitada = Form.useWatch('chave', form) as string | undefined;
  const consultarModelos = useMutation({
    mutationFn: (chave?: string) => enviar<string[]>('post', `/plataforma/ia/fornecedores/${f.fornecedor}/modelos`, { chave: chave || undefined }),
    onSuccess: (r) => {
      setModelosDetectados(r.dados);
      const actual = form.getFieldValue('modelo_padrao') as string | undefined;
      form.setFieldsValue({ modelos: r.dados, modelo_padrao: actual && r.dados.includes(actual) ? actual : r.dados[0] });
      message.success(`${r.dados.length} modelos disponíveis encontrados. Clique em Gravar para guardar a configuração.`);
    },
    onError: (e) => notificarErro(e, 'Não foi possível reconhecer a chave'),
  });
  const estado = estadoDaChave(f);

  return (
    <Card title={<Space><RobotOutlined />{f.nome}</Space>} extra={<Tag color={estado.cor}>{estado.texto}</Tag>}>
      <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>{AJUDA[f.fornecedor]}</Typography.Paragraph>
      {f.ultimo_erro && <Alert type="error" showIcon style={{ marginBottom: 12 }} title="Último teste falhou" description={f.ultimo_erro} />}
      {f.testado_em && !f.ultimo_erro && <Alert type="success" showIcon style={{ marginBottom: 12 }} icon={<CheckCircleOutlined />} title={`Ligação verificada em ${formatarDataHora(f.testado_em)}`} />}
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)}>
        <Form.Item
          name="chave"
          label={f.configurado ? 'Nova chave da API (em branco mantém a actual)' : 'Chave da API'}
          extra="Guardada cifrada no servidor. Nunca é mostrada nem devolvida ao navegador."
        >
          <Input.Password autoComplete="new-password" maxLength={500} visibilityToggle={false} aria-label={`Chave da API de ${f.nome}`} />
        </Form.Item>
        <Button size="small" loading={consultarModelos.isPending} disabled={!f.configurado && !chaveDigitada?.trim()} onClick={() => consultarModelos.mutate(chaveDigitada?.trim() || undefined)} style={{ marginBottom: 12 }}>
          Reconhecer chave e carregar modelos
        </Button>
        {modelosDetectados.length > 0 && <Alert type="success" showIcon title={`Chave reconhecida · ${modelosDetectados.length} modelos encontrados`} style={{ marginBottom: 12 }} />}
        <Form.Item name="modelos" label="Modelos disponíveis"><Select mode="tags" tokenSeparators={[',', ' ']} placeholder="Reconheça a chave para carregar os modelos" /></Form.Item>
        <Form.Item name="modelo_padrao" label="Modelo por omissão"><Select allowClear options={(modelos ?? []).map((m) => ({ value: m, label: m }))} /></Form.Item>
        <Row gutter={12}>
          <Col span={12}><Form.Item name="preco_entrada_mtok" label="Preço entrada (USD / milhão tokens)"><InputNumber min={0} step={0.1} style={{ width: '100%' }} /></Form.Item></Col>
          <Col span={12}><Form.Item name="preco_saida_mtok" label="Preço saída (USD / milhão tokens)"><InputNumber min={0} step={0.1} style={{ width: '100%' }} /></Form.Item></Col>
        </Row>
        <Form.Item name="ativo" label="Activo" valuePropName="checked"><Switch /></Form.Item>
        <Space wrap>
          <Button type="primary" icon={<SaveOutlined />} htmlType="submit" loading={gravar.isPending}>Gravar</Button>
          <Button icon={<ApiOutlined />} disabled={!f.configurado} loading={testar.isPending} onClick={() => testar.mutate()}>Testar ligação</Button>
        </Space>
      </Form>
    </Card>
  );
}

function Atribuicoes({ fornecedores }: { fornecedores: FornecedorIA[] }) {
  const qc = useQueryClient();
  const lista = useQuery({ queryKey: [...CHAVE, 'atribuicoes'], queryFn: () => obter<AtribuicaoIA[]>('/plataforma/ia/atribuicoes') });
  const [edicao, setEdicao] = useState<Record<number, AtribuicaoIA>>({});
  const disponiveis = fornecedoresAtribuiveis(fornecedores);
  const linha = (a: AtribuicaoIA) => edicao[a.tenant_id] ?? a;
  const mudar = (a: AtribuicaoIA, campo: Partial<AtribuicaoIA>) => setEdicao((e) => ({ ...e, [a.tenant_id]: { ...linha(a), ...campo } }));
  const refrescar = () => qc.invalidateQueries({ queryKey: [...CHAVE, 'atribuicoes'] });
  const gravar = useMutation({
    mutationFn: (a: AtribuicaoIA) => enviar('put', `/plataforma/ia/atribuicoes/${a.tenant_id}`, {
      fornecedor: a.fornecedor, modelo: a.modelo, ativo: a.ativo, quota_tokens_mes: a.quota_tokens_mes, pesquisa_web: a.pesquisa_web, accoes_erp: a.accoes_erp,
    }),
    onSuccess: (_r, a) => {
      message.success('Atribuição gravada.');
      setEdicao((e) => {
        const { [a.tenant_id]: _fora, ...resto } = e;
        return resto;
      });
      void refrescar();
    },
    onError: (e) => notificarErro(e),
  });
  const retirar = useMutation({
    mutationFn: (id: number) => enviar('delete', `/plataforma/ia/atribuicoes/${id}`),
    onSuccess: (_r, id) => {
      message.success('IA retirada ao cliente.');
      setEdicao((e) => {
        const { [id]: _fora, ...resto } = e;
        return resto;
      });
      void refrescar();
    },
    onError: (e) => notificarErro(e),
  });
  const modelosDe = (f: string | null) => fornecedores.find((x) => x.fornecedor === f)?.modelos ?? [];

  function guardar(a: AtribuicaoIA) {
    const problema = problemaAtribuicao(linha(a), fornecedores);
    if (problema) {
      message.warning(problema);
      return;
    }
    gravar.mutate(linha(a));
  }

  return (
    <Card>
      {disponiveis.length === 0 && <Alert type="warning" showIcon style={{ marginBottom: 12 }} title="Configure e active primeiro a chave de pelo menos um fornecedor no separador «Fornecedores»." />}
      <Table<AtribuicaoIA>
        rowKey="tenant_id"
        size="small"
        loading={lista.isLoading}
        dataSource={lista.data ?? []}
        scroll={{ x: 'max-content' }}
        pagination={{ pageSize: 20, hideOnSinglePage: true }}
        columns={[
          { title: 'Cliente', render: (_, a) => <><div style={{ fontWeight: 600 }}>{a.cliente}</div><Typography.Text type="secondary" style={{ fontSize: 12 }}>{a.slug}</Typography.Text></> },
          {
            title: 'Fornecedor',
            render: (_, a) => (
              <Select
                style={{ width: 170 }}
                placeholder="Sem IA"
                aria-label={`Fornecedor de ${a.cliente}`}
                value={linha(a).fornecedor ?? undefined}
                options={disponiveis.map((f) => ({ value: f.fornecedor, label: f.nome }))}
                onChange={(v) => mudar(a, { fornecedor: v, modelo: fornecedores.find((f) => f.fornecedor === v)?.modelo_padrao ?? null })}
              />
            ),
          },
          {
            title: 'Modelo',
            render: (_, a) => (
              <Select style={{ width: 190 }} aria-label={`Modelo de ${a.cliente}`} value={linha(a).modelo ?? undefined} options={modelosDe(linha(a).fornecedor).map((m) => ({ value: m, label: m }))} onChange={(v) => mudar(a, { modelo: v })} />
            ),
          },
          {
            title: 'Quota mensal (tokens)',
            render: (_, a) => (
              <InputNumber
                style={{ width: 150 }}
                min={QUOTA_MINIMA}
                step={100000}
                placeholder="Sem limite"
                aria-label={`Quota mensal de ${a.cliente}`}
                value={linha(a).quota_tokens_mes ?? undefined}
                onChange={(v) => mudar(a, { quota_tokens_mes: v ?? null })}
                formatter={(v) => (v ? formatarNumero(Number(v)) : '')}
                parser={(v) => Number(String(v ?? '').replace(/\D/g, '')) || 0}
              />
            ),
          },
          { title: 'Internet', render: (_, a) => <Switch size="small" aria-label={`Pesquisa na internet de ${a.cliente}`} checked={linha(a).pesquisa_web} onChange={(v) => mudar(a, { pesquisa_web: v })} /> },
          { title: 'Acções no ERP', render: (_, a) => <Switch size="small" aria-label={`Acções no ERP de ${a.cliente}`} checked={linha(a).accoes_erp} onChange={(v) => mudar(a, { accoes_erp: v })} /> },
          { title: 'Activa', render: (_, a) => <Switch size="small" aria-label={`IA activa de ${a.cliente}`} checked={linha(a).ativo} onChange={(v) => mudar(a, { ativo: v })} /> },
          {
            title: 'Este mês',
            render: (_, a) => {
              const nivel = nivelQuota(a);
              return (
                <span>
                  {formatarNumero(a.tokens_mes)} tokens{' '}
                  {nivel === 'esgotada' && <Tag color="red">Quota esgotada</Tag>}
                  {nivel === 'alerta' && <Tag color="gold">Perto da quota</Tag>}
                  <br />
                  <Typography.Text type="secondary" style={{ fontSize: 12 }}>≈ {a.custo_mes_usd.toFixed(2)} USD</Typography.Text>
                </span>
              );
            },
          },
          {
            title: '',
            render: (_, a) => (
              <Space>
                <Button size="small" type="primary" disabled={!linha(a).fornecedor} loading={gravar.isPending && gravar.variables?.tenant_id === a.tenant_id} onClick={() => guardar(a)}>Gravar</Button>
                {a.fornecedor && (
                  <Popconfirm title="Retirar a IA a este cliente?" okText="Retirar" cancelText="Cancelar" onConfirm={() => retirar.mutate(a.tenant_id)}>
                    <Button size="small" danger>Retirar</Button>
                  </Popconfirm>
                )}
              </Space>
            ),
          },
        ]}
      />
    </Card>
  );
}

function ConsumoPlataforma() {
  const { data, isLoading } = useQuery({ queryKey: [...CHAVE, 'consumo'], queryFn: () => obter<ConsumoIA>('/plataforma/ia/consumo') });
  const t = totaisConsumo(data);
  return (
    <>
      <Row gutter={[12, 12]}>
        <Col xs={12} md={6}><Card loading={isLoading}><Statistic title="Tokens este mês" value={t.tokens} formatter={(v) => formatarNumero(Number(v))} /></Card></Col>
        <Col xs={12} md={6}><Card loading={isLoading}><Statistic title="Custo estimado este mês" value={t.custoUsd} precision={2} suffix="USD" /></Card></Col>
        <Col xs={12} md={6}><Card loading={isLoading}><Statistic title="Pedidos este mês" value={t.pedidos} /></Card></Col>
        <Col xs={12} md={6}><Card loading={isLoading}><Statistic title="Pesquisas na internet" value={t.pesquisas} /></Card></Col>
      </Row>
      <Row gutter={[12, 12]} style={{ marginTop: 12 }}>
        <Col xs={24} xl={14}>
          <Card title="Tokens por mês" loading={isLoading}>
            {data && <GraficoBarras rotulos={data.meses} empilhado series={[{ rotulo: 'Entrada', valores: data.tokens_entrada }, { rotulo: 'Saída', valores: data.tokens_saida }]} altura={240} />}
          </Card>
        </Col>
        <Col xs={24} xl={10}>
          <Card title="Custo estimado por mês (USD)" loading={isLoading}>
            {data && <GraficoBarras rotulos={data.meses} series={[{ rotulo: 'USD', valores: data.custo_usd }]} monetario altura={240} />}
          </Card>
        </Col>
      </Row>
      <Card title="Consumo por cliente (este mês)" style={{ marginTop: 12 }}>
        <Table
          rowKey="cliente"
          size="small"
          pagination={false}
          loading={isLoading}
          dataSource={data?.clientes_mes ?? []}
          scroll={{ x: 'max-content' }}
          columns={[
            { title: 'Cliente', dataIndex: 'cliente' },
            { title: 'Tokens', align: 'right', render: (_, c) => formatarNumero(c.tokens) },
            { title: 'Pedidos', dataIndex: 'pedidos', align: 'right' },
            { title: 'Pesquisas', dataIndex: 'pesquisas', align: 'right' },
            { title: 'Custo (USD)', align: 'right', render: (_, c) => c.custo_usd.toFixed(2) },
          ]}
        />
      </Card>
    </>
  );
}

/**
 * Plataforma SaaS › Inteligência artificial (plataforma_ia): chaves dos fornecedores (Anthropic, OpenAI, Google Gemini),
 * atribuição a cada cliente (fornecedor, modelo, quota mensal de tokens, internet, acções no ERP) e consumo/custo.
 */
export default function PlataformaIA() {
  const fornecedores = useQuery({ queryKey: CHAVE, queryFn: () => obter<FornecedorIA[]>('/plataforma/ia/fornecedores') });
  return (
    <>
      <CabecalhoPagina titulo="Inteligência artificial" subtitulo="Fornecedores, atribuição aos clientes, quotas e consumo do assistente" />
      {fornecedores.isError && <Alert type="error" showIcon style={{ marginBottom: 12 }} title="Não foi possível carregar os fornecedores de IA" action={<Button size="small" onClick={() => void fornecedores.refetch()}>Tentar de novo</Button>} />}
      <Tabs
        items={[
          {
            key: 'fornecedores',
            label: 'Fornecedores',
            children: (
              <Row gutter={[12, 12]}>
                {(fornecedores.data ?? []).map((f) => <Col key={f.fornecedor} xs={24} xl={8}><CartaoFornecedor f={f} /></Col>)}
              </Row>
            ),
          },
          { key: 'atribuicoes', label: 'Atribuição aos clientes', children: <Atribuicoes fornecedores={fornecedores.data ?? []} /> },
          { key: 'consumo', label: 'Consumo', children: <ConsumoPlataforma /> },
        ]}
      />
    </>
  );
}
