import { Alert, Button, Card, Checkbox, Col, Collapse, Flex, Form, Input, Modal, Popconfirm, Radio, Row, Select, Space, Table, Tabs, Tag, Typography } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined, SwapOutlined } from '@ant-design/icons';
import { useMutation } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { enviar } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { scrollTabela } from '@/componentes/responsivo';
import { TabelaLocalImprimivel } from './comum/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarNumero } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { usePlanoContas } from '@/modulos/contab/comum/dados';
import { BotaoCsv } from '@/modulos/contab/comum/Componentes';
import type { ContaPlano } from '@/modulos/contab/api';

interface ContagemTabela {
  tabela: string;
  coluna: string;
  rotulo: string;
  registos: number;
}

interface SimulacaoEmpresa {
  empresa_id: number;
  empresa: string;
  origem: { codigo: string; descricao: string | null; tipo: string } | null;
  destino: { codigo: string; descricao: string | null; tipo: string } | null;
  pode_substituir: boolean;
  motivo: string | null;
  alteraveis: ContagemTabela[];
  total_alteraveis: number;
  informativos: ContagemTabela[];
  total_informativos: number;
}

/** Configurações › Plano de contas (config_plano): contas (M/T), criação/edição/eliminação e substituição de conta com simulação. */
export default function PlanoContas() {
  const { pode } = useSessao();
  return (
    <>
      <CabecalhoPagina titulo="Plano de contas" subtitulo="Contas de movimento e totalizadoras da empresa activa" />
      <Tabs
        items={[
          { key: 'contas', label: 'Contas', children: <Contas /> },
          ...(pode('contab_conta_substituir') ? [{ key: 'substituir', label: 'Substituir conta', children: <Substituir /> }] : []),
        ]}
      />
    </>
  );
}

function Contas() {
  const { pode } = useSessao();
  const plano = usePlanoContas();
  const [filtro, setFiltro] = useState('');
  const [tipo, setTipo] = useState<'M' | 'T' | undefined>();
  const [edicao, setEdicao] = useState<ContaPlano | 'nova' | null>(null);
  const [form] = Form.useForm<{ codigo: string; descricao: string; tipo: 'M' | 'T'; codigo_moeda?: string; natureza_conta?: string }>();
  const gerir = pode('contab_plano_gerir');
  const accao = useAccao({ invalidar: [['contab', 'plano-contas']], aoSucesso: () => setEdicao(null) });

  useEffect(() => {
    if (!edicao) return;
    form.resetFields();
    if (edicao === 'nova') form.setFieldsValue({ tipo: 'M' });
    else form.setFieldsValue({ ...edicao, descricao: edicao.descricao ?? '', codigo_moeda: edicao.codigo_moeda ?? undefined, natureza_conta: edicao.natureza_conta ?? undefined });
  }, [edicao, form]);

  const linhas = useMemo(() => {
    const f = filtro.toLowerCase();
    return (plano.data ?? []).filter((c) => (!tipo || c.tipo === tipo) && (!f || c.codigo.startsWith(f) || (c.descricao ?? '').toLowerCase().includes(f)));
  }, [plano.data, filtro, tipo]);

  return (
    <Card>
      <TabelaLocalImprimivel<ContaPlano>
        titulo="Plano de contas"
        filtros={[tipo ? `Tipo: ${tipo === 'M' ? 'Movimento' : 'Totalizadoras'}` : null, filtro ? `Pesquisa: ${filtro}` : null]}
        filtrosEcra={<>
          <Input.Search placeholder="Código (prefixo) ou descrição" allowClear style={{ width: 280 }} onChange={(e) => setFiltro(e.target.value)} />
          <Radio.Group value={tipo ?? ''} onChange={(e) => setTipo(e.target.value || undefined)} optionType="button" options={[{ value: '', label: 'Todas' }, { value: 'M', label: 'Movimento' }, { value: 'T', label: 'Totalizadoras' }]} />
        </>}
        accoes={<>
          <BotaoCsv<ContaPlano>
            nome="plano_contas"
            linhas={linhas}
            colunas={[{ titulo: 'Código', valor: (c) => c.codigo }, { titulo: 'Descrição', valor: (c) => c.descricao }, { titulo: 'Tipo', valor: (c) => c.tipo }, { titulo: 'Moeda', valor: (c) => c.codigo_moeda }]}
          />
          {gerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('nova')}>Nova conta</Button>}
        </>}
        rowKey="id"
        size="small"
        loading={plano.isLoading}
        dataSource={linhas}
        pagination={{ pageSize: 100, showSizeChanger: false, showTotal: (n) => `${n} conta(s)` }}
        columns={[
          { title: 'Código', dataIndex: 'codigo', width: 150, render: (v: string, c) => <span style={{ paddingLeft: Math.max(0, v.replace(/\./g, '').length - 1) * 6, fontWeight: c.tipo === 'T' ? 600 : 400 }}>{v}</span> },
          { title: 'Descrição', dataIndex: 'descricao', render: (v: string | null, c) => <span style={{ fontWeight: c.tipo === 'T' ? 600 : 400 }}>{v}</span> },
          { title: 'Tipo', dataIndex: 'tipo', width: 130, valorImpressao: (c) => (c.tipo === 'T' ? 'Totalizadora' : 'Movimento'), render: (t: string) => (t === 'T' ? <Tag color="blue">Totalizadora</Tag> : <Tag>Movimento</Tag>) },
          { title: 'Moeda', dataIndex: 'codigo_moeda', width: 90, responsive: ['md'], render: (v: string | null) => v ?? '—' },
          {
            title: '',
            width: 90,
            render: (_, c) => (
              <Space>
                {gerir && <Button size="small" type="text" icon={<EditOutlined />} aria-label="Editar" title="Editar" onClick={() => setEdicao(c)} />}
                {pode('contab_tabelas_del') && (
                  <Popconfirm title={`Eliminar a conta ${c.codigo}?`} description="Só é possível sem movimentos nem subcontas." okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/contabilidade/plano-contas/${c.id}` })}>
                    <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" title="Eliminar" />
                  </Popconfirm>
                )}
              </Space>
            ),
          },
        ]}
      />
      <Modal title={edicao === 'nova' ? 'Nova conta' : `Conta ${edicao?.codigo ?? ''}`} open={!!edicao} onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
        <Form
          form={form}
          layout="vertical"
          onFinish={(v) =>
            accao.mutate({
              metodo: edicao === 'nova' ? 'post' : 'put',
              url: edicao === 'nova' ? '/contabilidade/plano-contas' : `/contabilidade/plano-contas/${(edicao as ContaPlano).id}`,
              dados: { ...v, codigo_moeda: v.codigo_moeda || null, natureza_conta: v.natureza_conta || null },
            })
          }
        >
          <Row gutter={16}>
            <Col xs={24} sm={12} md={10}>
              <Form.Item name="codigo" label="Código" rules={[{ required: true, message: 'Indique o código.' }, { pattern: /^[0-9A-Za-z.]+$/, message: 'Só algarismos, letras e pontos.' }]}>
                <Input maxLength={20} />
              </Form.Item>
            </Col>
            <Col xs={24} md={14}>
              <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
                <Radio.Group options={[{ value: 'M', label: 'Movimento' }, { value: 'T', label: 'Totalizadora' }]} />
              </Form.Item>
            </Col>
          </Row>
          <Form.Item name="descricao" label="Descrição" rules={[{ required: true, message: 'Indique a descrição.' }]}><Input maxLength={255} /></Form.Item>
          <Row gutter={16}>
            <Col xs={24} sm={12} md={10}><Form.Item name="codigo_moeda" label="Moeda (opcional)"><Input maxLength={10} /></Form.Item></Col>
            <Col xs={24} md={14}><Form.Item name="natureza_conta" label="Natureza"><Input maxLength={255} /></Form.Item></Col>
          </Row>
        </Form>
      </Modal>
    </Card>
  );
}

function Substituir() {
  const { empresas, empresa } = useSessao();
  const plano = usePlanoContas();
  const [form] = Form.useForm<{ origem: string; destino: string; empresas: number[] }>();
  const [simulacao, setSimulacao] = useState<SimulacaoEmpresa[] | null>(null);
  const [confirmado, setConfirmado] = useState(false);
  const opcoes = (plano.data ?? []).filter((c) => c.tipo === 'M').map((c) => ({ value: c.codigo, label: `${c.codigo} — ${c.descricao ?? ''}` }));

  const simular = useMutation({
    mutationFn: async () => enviar<SimulacaoEmpresa[]>('post', '/sistema/plano-contas/substituir/simular', await form.validateFields()),
    onSuccess: ({ dados }) => {
      setSimulacao(dados);
      setConfirmado(false);
    },
    onError: (e) => notificarErro(e, 'Não foi possível simular'),
  });
  const executar = useAccao<{ empresa_id: number; empresa: string; alterados: Record<string, number>; total: number }[]>({
    invalidar: [['contab']],
    aoSucesso: () => {
      setSimulacao(null);
      setConfirmado(false);
    },
    tituloErro: 'A substituição não foi feita',
  });
  const total = (simulacao ?? []).reduce((s, e) => s + e.total_alteraveis, 0);
  const bloqueadas = (simulacao ?? []).filter((e) => !e.pode_substituir);

  return (
    <Card>
      <Alert
        type="warning"
        showIcon
        style={{ marginBottom: 16 }}
        message="Substitui a conta de origem pela de destino nos dados mestre e documentos por contabilizar"
        description="Lançamentos e documentos já contabilizados não são alterados (use estornos). Simule sempre antes; a operação é registada na auditoria."
      />
      <Form form={form} layout="vertical" initialValues={{ empresas: empresa ? [empresa.id] : [] }} onValuesChange={() => setSimulacao(null)}>
        <Row gutter={16}>
          <Col xs={24} md={8}>
            <Form.Item name="origem" label="Conta de origem (sai)" rules={[{ required: true, message: 'Indique a conta.' }]}>
              <Select showSearch optionFilterProp="label" options={opcoes} loading={plano.isLoading} />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item name="destino" label="Conta de destino (entra)" dependencies={['origem']} rules={[{ required: true, message: 'Indique a conta.' }, ({ getFieldValue }) => ({ validator: (_, v) => (v && v === getFieldValue('origem') ? Promise.reject(new Error('A conta de destino tem de ser diferente.')) : Promise.resolve()) })]}>
              <Select showSearch optionFilterProp="label" options={opcoes} loading={plano.isLoading} />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item name="empresas" label="Empresas" rules={[{ required: true, type: 'array', min: 1, message: 'Escolha pelo menos uma.' }]} extra="As contas têm de existir em cada empresa.">
              <Select mode="multiple" optionFilterProp="label" options={empresas.map((e) => ({ value: e.id, label: e.nome }))} />
            </Form.Item>
          </Col>
        </Row>
        <Button icon={<SwapOutlined />} loading={simular.isPending} onClick={() => simular.mutate()}>Simular</Button>
      </Form>
      {simulacao && (
        <div style={{ marginTop: 16 }}>
          <Collapse
            defaultActiveKey={simulacao.map((e) => String(e.empresa_id))}
            items={simulacao.map((e) => ({
              key: String(e.empresa_id),
              label: (
                <Space wrap>
                  <strong>{e.empresa}</strong>
                  {e.pode_substituir ? <Tag color="green">{formatarNumero(e.total_alteraveis)} registo(s) a alterar</Tag> : <Tag color="red">Impossível</Tag>}
                  {e.total_informativos > 0 && <Tag>{formatarNumero(e.total_informativos)} informativo(s)</Tag>}
                </Space>
              ),
              children: (
                <>
                  {e.motivo && <Alert type="error" showIcon message={e.motivo} style={{ marginBottom: 8 }} />}
                  <Row gutter={16}>
                    <Col xs={24} lg={12}>
                      <Typography.Text strong>Alterados</Typography.Text>
                      <Table size="small" rowKey={(l) => `${l.tabela}.${l.coluna}`} pagination={false} scroll={scrollTabela()} dataSource={e.alteraveis} locale={{ emptyText: 'Nenhum.' }} columns={[{ title: 'Onde', dataIndex: 'rotulo' }, { title: 'Registos', dataIndex: 'registos', align: 'right', render: formatarNumero }]} />
                    </Col>
                    <Col xs={24} lg={12}>
                      <Typography.Text strong>Não alterados (rever)</Typography.Text>
                      <Table size="small" rowKey={(l) => `${l.tabela}.${l.coluna}`} pagination={false} scroll={scrollTabela()} dataSource={e.informativos} locale={{ emptyText: 'Nenhum.' }} columns={[{ title: 'Onde', dataIndex: 'rotulo' }, { title: 'Registos', dataIndex: 'registos', align: 'right', render: formatarNumero }]} />
                    </Col>
                  </Row>
                </>
              ),
            }))}
          />
          <Flex gap={16} align="center" wrap style={{ marginTop: 16 }}>
            <Checkbox checked={confirmado} disabled={!!bloqueadas.length || total === 0} onChange={(e) => setConfirmado(e.target.checked)}>
              Confirmo a substituição de {formatarNumero(total)} registo(s) em {simulacao.length} empresa(s)
            </Checkbox>
            <Button
              type="primary"
              danger
              disabled={!confirmado}
              loading={executar.isPending}
              onClick={() => executar.mutate({ url: '/sistema/plano-contas/substituir', dados: { ...form.getFieldsValue(), confirmar: true } })}
            >
              Substituir
            </Button>
            {bloqueadas.length > 0 && <Typography.Text type="danger">Corrija as empresas marcadas como impossíveis (ou retire-as) antes de substituir.</Typography.Text>}
          </Flex>
        </div>
      )}
    </Card>
  );
}
