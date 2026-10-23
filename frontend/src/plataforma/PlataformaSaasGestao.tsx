import { useState } from 'react';
import {
  Card,
  Row,
  Col,
  Statistic,
  Switch,
  InputNumber,
  Button,
  Table,
  Tag,
  Space,
  Input,
  Select,
  Modal,
  Form,
  DatePicker,
  Popconfirm,
  Tooltip,
  Typography,
  Alert,
  message,
  Divider,
} from 'antd';
import {
  CheckCircleOutlined,
  ClockCircleOutlined,
  CloseCircleOutlined,
  SafetyCertificateOutlined,
  SearchOutlined,
  PoweroffOutlined,
  EditOutlined,
  ThunderboltOutlined,
  PlusOutlined,
  CrownOutlined,
  ReloadOutlined,
} from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';

const { Text } = Typography;

interface EstatisticasSaas {
  tenants: number;
  clientes_total: number;
  clientes_ativos: number;
  clientes_em_teste: number;
  clientes_expirados: number;
  clientes_sem_limite: number;
  empresas_total: number;
}

interface EstadoSaas {
  saas_ativo: boolean;
  licencas_ativas: boolean;
  dias_teste_padrao: number;
  rls_cobertura_completa: boolean;
  tabelas_sem_rls: string[];
  efetivo: { saas_ativo: boolean; licencas_ativas: boolean };
  divergencia: { saas_ativo: boolean; licencas_ativas: boolean };
  isolamento: {
    ativo: boolean;
    utilizador: string | null;
    verificacoes: Record<string, { ok: boolean; motivo: string | null }>;
  };
  estatisticas: EstatisticasSaas;
}

/** A licença é do cliente (conta SaaS) e vale para todas as suas empresas. */
interface ClienteSaas {
  id: number;
  nome: string;
  slug: string;
  estado: string;
  plano: string | null;
  empresas: number;
  utilizadores: number;
  origem: 'SEM_LIMITE' | 'TESTE' | 'PLANO';
  inicio: string | null;
  fim: string | null;
  dias_restantes: number | null;
  expirada: boolean;
  somente_leitura: boolean;
}

export default function PlataformaSaasGestao() {
  const qc = useQueryClient();
  const [pesquisa, setPesquisa] = useState('');
  const [filtroOrigem, setFiltroOrigem] = useState<string>('TODAS');
  const [clienteAEditar, setClienteAEditar] = useState<ClienteSaas | null>(null);
  const [formEdicao] = Form.useForm();

  // 1. Obter estado geral
  const { data: estadoGeral, isLoading: loadingEstado, refetch: recarregarEstado } = useQuery({
    queryKey: ['plataforma', 'saas', 'estado'],
    queryFn: () => obter<EstadoSaas>('/plataforma/saas/estado'),
  });

  // 2. Obter os clientes com a sua licença
  const { data: clientes = [], isLoading: loadingClientes, refetch: recarregarClientes } = useQuery({
    queryKey: ['plataforma', 'saas', 'clientes'],
    queryFn: () => obter<ClienteSaas[]>('/plataforma/saas/clientes'),
  });

  // 3. Mutação: Atualizar definições gerais do SaaS
  const mutacaoGeral = useMutation({
    mutationFn: (dados: Partial<EstadoSaas>) => enviar('put', '/plataforma/saas/geral', dados),
    onSuccess: () => {
      message.success('Definições gerais do SaaS atualizadas.');
      qc.invalidateQueries({ queryKey: ['plataforma', 'saas', 'estado'] });
    },
    onError: (e) => notificarErro(e),
  });

  // 4. Mutação: Atualizar a licença de um cliente (vale para todas as suas empresas)
  const mutacaoCliente = useMutation({
    mutationFn: ({ id, dados }: { id: number; dados: Record<string, unknown> }) =>
      enviar('put', `/plataforma/saas/clientes/${id}`, dados),
    onSuccess: () => {
      message.success('Licença do cliente atualizada com sucesso.');
      setClienteAEditar(null);
      formEdicao.resetFields();
      qc.invalidateQueries({ queryKey: ['plataforma', 'saas', 'clientes'] });
      qc.invalidateQueries({ queryKey: ['plataforma', 'painel'] });
      qc.invalidateQueries({ queryKey: ['plataforma', 'saas', 'estado'] });
    },
    onError: (e) => notificarErro(e),
  });

  const abrirModalEdicao = (cli: ClienteSaas) => {
    setClienteAEditar(cli);
    formEdicao.setFieldsValue({
      origem: cli.origem,
      datas: cli.inicio && cli.fim ? [dayjs(cli.inicio), dayjs(cli.fim)] : undefined,
    });
  };

  const guardarEdicaoModal = (valores: { origem: string; datas?: [dayjs.Dayjs, dayjs.Dayjs] }) => {
    if (!clienteAEditar) return;
    const payload: Record<string, unknown> = { origem: valores.origem };
    if (valores.origem !== 'SEM_LIMITE' && valores.datas && valores.datas.length === 2) {
      payload.inicio = valores.datas[0].format('YYYY-MM-DD');
      payload.fim = valores.datas[1].format('YYYY-MM-DD');
    }
    mutacaoCliente.mutate({ id: clienteAEditar.id, dados: payload });
  };

  const adicionarDiasRapido = (cli: ClienteSaas, dias: number) => {
    mutacaoCliente.mutate({
      id: cli.id,
      dados: {
        origem: 'PLANO',
        dias_adicionar: dias,
      },
    });
  };

  const tornarVitalicia = (cli: ClienteSaas) => {
    mutacaoCliente.mutate({
      id: cli.id,
      dados: { origem: 'SEM_LIMITE' },
    });
  };

  const revogarLicenca = (cli: ClienteSaas) => {
    mutacaoCliente.mutate({
      id: cli.id,
      dados: { origem: 'PLANO', revogar: true },
    });
  };

  // Filtragem de dados na tabela
  const clientesFiltrados = clientes.filter((cli) => {
    const termo = pesquisa.toLowerCase().trim();
    const coincideTexto =
      !termo ||
      cli.nome.toLowerCase().includes(termo) ||
      cli.slug.toLowerCase().includes(termo);

    if (!coincideTexto) return false;

    if (filtroOrigem === 'TODAS') return true;
    if (filtroOrigem === 'EXPIRADAS') return cli.expirada;
    if (filtroOrigem === 'ATIVAS') return !cli.expirada;
    return cli.origem === filtroOrigem;
  });

  const stats = estadoGeral?.estatisticas;

  return (
    <>
      <CabecalhoPagina
        titulo="Activação SaaS e licenças"
        subtitulo="A licença é do cliente: vale para todas as suas empresas. Controlo da activação do SaaS, dos períodos de teste e das licenças dos clientes"
        accoes={
          <Button
            icon={<ReloadOutlined />}
            onClick={() => {
              recarregarEstado();
              recarregarClientes();
            }}
          >
            Atualizar
          </Button>
        }
      />

      {/* Cards de Métricas e Estado Geral */}
      <Row gutter={[16, 16]} style={{ marginBottom: 20 }}>
        <Col xs={24} sm={12} md={6}>
          <Card bordered={false} style={{ height: '100%' }}>
            <Statistic
              title="Clientes com licença válida"
              value={stats?.clientes_ativos ?? 0}
              suffix={`/ ${stats?.clientes_total ?? 0}`}
              valueStyle={{ color: '#52c41a' }}
              prefix={<CheckCircleOutlined />}
            />
            <Text type="secondary" style={{ fontSize: 12 }}>
              Operando normalmente
            </Text>
          </Card>
        </Col>

        <Col xs={24} sm={12} md={6}>
          <Card bordered={false} style={{ height: '100%' }}>
            <Statistic
              title="Clientes em teste"
              value={stats?.clientes_em_teste ?? 0}
              valueStyle={{ color: '#1890ff' }}
              prefix={<ClockCircleOutlined />}
            />
            <Text type="secondary" style={{ fontSize: 12 }}>
              Avaliando o software
            </Text>
          </Card>
        </Col>

        <Col xs={24} sm={12} md={6}>
          <Card bordered={false} style={{ height: '100%' }}>
            <Statistic
              title="Licenças expiradas"
              value={stats?.clientes_expirados ?? 0}
              valueStyle={{ color: '#ff4d4f' }}
              prefix={<CloseCircleOutlined />}
            />
            <Text type="secondary" style={{ fontSize: 12 }}>
              Todas as empresas do cliente em somente leitura
            </Text>
          </Card>
        </Col>

        <Col xs={24} sm={12} md={6}>
          <Card bordered={false} style={{ height: '100%' }}>
            <Statistic
              title="Licenças vitalícias"
              value={stats?.clientes_sem_limite ?? 0}
              valueStyle={{ color: '#fa8c16' }}
              prefix={<CrownOutlined />}
            />
            <Text type="secondary" style={{ fontSize: 12 }}>
              Sem data de expiração
            </Text>
          </Card>
        </Col>
      </Row>

      {/* Painel de Controlo Master do SaaS */}
      <Card
        title={
          <Space>
            <PoweroffOutlined style={{ color: '#1890ff' }} />
            <span>Controlo Master da Plataforma SaaS</span>
          </Space>
        }
        loading={loadingEstado}
        style={{ marginBottom: 20 }}
      >
        <Row gutter={[24, 24]} align="middle">
          <Col xs={24} md={8}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 16px', background: '#f5f7fa', borderRadius: 8 }}>
              <div>
                <div style={{ fontWeight: 600 }}>Plataforma SaaS Global</div>
                <Text type="secondary" style={{ fontSize: 12 }}>Ativa isolamento multi-tenant e subdomínios</Text>
              </div>
              <Switch
                checked={estadoGeral?.saas_ativo ?? false}
                loading={mutacaoGeral.isPending}
                onChange={(checked) => mutacaoGeral.mutate({ saas_ativo: checked })}
                checkedChildren="LIGADO"
                unCheckedChildren="DESLIGADO"
              />
            </div>
          </Col>

          <Col xs={24} md={8}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 16px', background: '#f5f7fa', borderRadius: 8 }}>
              <div>
                <div style={{ fontWeight: 600 }}>Verificação Estrita de Licenças</div>
                <Text type="secondary" style={{ fontSize: 12 }}>Bloqueia a escrita nas empresas de clientes com a licença expirada</Text>
              </div>
              <Switch
                checked={estadoGeral?.licencas_ativas ?? false}
                loading={mutacaoGeral.isPending}
                onChange={(checked) => mutacaoGeral.mutate({ licencas_ativas: checked })}
                checkedChildren="LIGADO"
                unCheckedChildren="DESLIGADO"
              />
            </div>
          </Col>

          <Col xs={24} md={8}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 16px', background: '#f5f7fa', borderRadius: 8 }}>
              <div>
                <div style={{ fontWeight: 600 }}>Dias de Teste Padrão</div>
                <Text type="secondary" style={{ fontSize: 12 }}>Período de teste dos clientes novos</Text>
              </div>
              <Space>
                <InputNumber
                  min={1}
                  max={90}
                  value={estadoGeral?.dias_teste_padrao ?? 7}
                  onChange={(val) => {
                    if (val && val >= 1 && val <= 90) {
                      mutacaoGeral.mutate({ dias_teste_padrao: val });
                    }
                  }}
                  style={{ width: 70 }}
                />
                <Text type="secondary">dias</Text>
              </Space>
            </div>
          </Col>
        </Row>

        {estadoGeral && (estadoGeral.divergencia.saas_ativo || estadoGeral.divergencia.licencas_ativas) && (
          <Alert
            message="O interruptor guardado difere do estado efetivo do ambiente"
            description={`Estado efetivo (variáveis de ambiente do servidor): SaaS ${estadoGeral.efetivo.saas_ativo ? 'LIGADO' : 'DESLIGADO'}, licenças ${estadoGeral.efetivo.licencas_ativas ? 'LIGADAS' : 'DESLIGADAS'}. Os interruptores só gravam a definição; para mudar o ambiente é preciso alterar SAAS_ATIVO / LICENCAS_ATIVAS no servidor.`}
            type="warning"
            showIcon
            style={{ marginTop: 16 }}
          />
        )}

        {estadoGeral?.isolamento.ativo ? (
          <Alert
            message="Isolamento Multi-Tenant Efetivo (RLS ativa)"
            description={`SaaS ativo, ligação '${estadoGeral.isolamento.utilizador ?? ''}' sem privilégios, row_security ligado e políticas presentes em todas as tabelas.`}
            type="success"
            showIcon
            icon={<SafetyCertificateOutlined />}
            style={{ marginTop: 16 }}
          />
        ) : (
          <Alert
            message="Isolamento RLS não está efetivo"
            description={
              <ul style={{ margin: 0, paddingLeft: 18 }}>
                {Object.entries(estadoGeral?.isolamento.verificacoes ?? {})
                  .filter(([, v]) => !v.ok)
                  .map(([chave, v]) => (
                    <li key={chave}>{v.motivo ?? chave}</li>
                  ))}
              </ul>
            }
            type="warning"
            showIcon
            style={{ marginTop: 16 }}
          />
        )}
      </Card>

      {/* Tabela de Gestão de Licenças por Empresa */}
      <Card
        title="Licenças dos clientes"
        extra={
          <Space wrap>
            <Input
              placeholder="Pesquisar por cliente ou endereço..."
              prefix={<SearchOutlined />}
              value={pesquisa}
              onChange={(e) => setPesquisa(e.target.value)}
              style={{ width: 280 }}
              allowClear
            />
            <Select
              value={filtroOrigem}
              onChange={setFiltroOrigem}
              style={{ width: 180 }}
              options={[
                { label: 'Todos os clientes', value: 'TODAS' },
                { label: 'Apenas Ativas', value: 'ATIVAS' },
                { label: 'Em Teste', value: 'TESTE' },
                { label: 'Com Plano Ativo', value: 'PLANO' },
                { label: 'Licença Vitalícia', value: 'SEM_LIMITE' },
                { label: 'Apenas Expiradas', value: 'EXPIRADAS' },
              ]}
            />
          </Space>
        }
      >
        <Table
          dataSource={clientesFiltrados}
          rowKey="id"
          loading={loadingClientes}
          pagination={{ pageSize: 10, showSizeChanger: true }}
          columns={[
            {
              title: 'Cliente',
              key: 'cliente',
              render: (_, cli: ClienteSaas) => (
                <div>
                  <div style={{ fontWeight: 600 }}>{cli.nome}</div>
                  <Text type="secondary" style={{ fontSize: 12 }}>
                    {cli.slug}
                    {cli.plano ? ` · plano ${cli.plano}` : ''}
                  </Text>
                </div>
              ),
            },
            {
              title: 'Empresas / Utilizadores',
              key: 'uso',
              render: (_, cli: ClienteSaas) => `${cli.empresas} empresa(s) · ${cli.utilizadores} utilizador(es)`,
            },
            {
              title: 'Modalidade',
              dataIndex: 'origem',
              key: 'origem',
              render: (origem: string) => {
                if (origem === 'SEM_LIMITE') {
                  return <Tag color="gold" icon={<CrownOutlined />}>Vitalícia (Sem Limite)</Tag>;
                }
                if (origem === 'TESTE') {
                  return <Tag color="blue" icon={<ClockCircleOutlined />}>Período de Teste</Tag>;
                }
                return <Tag color="purple" icon={<ThunderboltOutlined />}>Subscrição / Plano</Tag>;
              },
            },
            {
              title: 'Validade & Dias Restantes',
              key: 'validade',
              render: (_, cli: ClienteSaas) => {
                if (cli.origem === 'SEM_LIMITE' || !cli.fim) {
                  return <Tag color="green">Ativa Indefinidamente</Tag>;
                }

                if (cli.expirada) {
                  return (
                    <div>
                      <Tag color="red" icon={<CloseCircleOutlined />}>Expirada</Tag>
                      <div style={{ fontSize: 11, color: '#ff4d4f', marginTop: 2 }}>
                        Terminou a {dayjs(cli.fim).format('DD/MM/YYYY')}
                      </div>
                    </div>
                  );
                }

                const dias = cli.dias_restantes ?? 0;
                const corTag = dias <= 7 ? 'volcano' : dias <= 15 ? 'orange' : 'green';

                return (
                  <div>
                    <Tag color={corTag} icon={<ClockCircleOutlined />}>
                      {dias} {dias === 1 ? 'dia restante' : 'dias restantes'}
                    </Tag>
                    <div style={{ fontSize: 11, color: '#666', marginTop: 2 }}>
                      Válida até {dayjs(cli.fim).format('DD/MM/YYYY')}
                    </div>
                  </div>
                );
              },
            },
            {
              title: 'Estado do Acesso',
              key: 'estado',
              render: (_, cli: ClienteSaas) => {
                if (cli.somente_leitura) {
                  return <Tag color="error">Somente Leitura</Tag>;
                }
                return <Tag color="success">Total (Leitura + Escrita)</Tag>;
              },
            },
            {
              title: 'Ações Rápidas de Licenciamento',
              key: 'acoes',
              align: 'right',
              render: (_, cli: ClienteSaas) => (
                <Space wrap>
                  <Tooltip title="Adicionar 30 dias de licença">
                    <Button
                      size="small"
                      icon={<PlusOutlined />}
                      loading={mutacaoCliente.isPending}
                      onClick={() => adicionarDiasRapido(cli, 30)}
                    >
                      +30d
                    </Button>
                  </Tooltip>

                  <Tooltip title="Adicionar 1 ano (365 dias)">
                    <Button
                      size="small"
                      icon={<PlusOutlined />}
                      loading={mutacaoCliente.isPending}
                      onClick={() => adicionarDiasRapido(cli, 365)}
                    >
                      +1 ano
                    </Button>
                  </Tooltip>

                  <Tooltip title="Tornar Licença Vitalícia">
                    <Button
                      size="small"
                      icon={<CrownOutlined />}
                      loading={mutacaoCliente.isPending}
                      onClick={() => tornarVitalicia(cli)}
                    />
                  </Tooltip>

                  <Tooltip title="Editar datas e modalidade">
                    <Button
                      size="small"
                      icon={<EditOutlined />}
                      onClick={() => abrirModalEdicao(cli)}
                    />
                  </Tooltip>

                  {!cli.expirada && cli.origem !== 'SEM_LIMITE' && (
                    <Popconfirm
                      title="Revogar Licença"
                      description={`Tem a certeza que deseja revogar o acesso de escrita de "${cli.nome}"? Todas as empresas do cliente passam para modo somente leitura.`}
                      okText="Sim, Revogar"
                      cancelText="Cancelar"
                      okButtonProps={{ danger: true }}
                      onConfirm={() => revogarLicenca(cli)}
                    >
                      <Button size="small" danger icon={<CloseCircleOutlined />}>
                        Revogar
                      </Button>
                    </Popconfirm>
                  )}
                </Space>
              ),
            },
          ]}
        />
      </Card>

      {/* Modal de Edição Manual de Licença */}
      <Modal
        title={`Licença do cliente — ${clienteAEditar?.nome ?? ''}`}
        open={!!clienteAEditar}
        onCancel={() => setClienteAEditar(null)}
        footer={null}
        destroyOnClose
      >
        <Form
          form={formEdicao}
          layout="vertical"
          onFinish={guardarEdicaoModal}
          initialValues={{ origem: clienteAEditar?.origem ?? 'PLANO' }}
        >
          <Form.Item
            name="origem"
            label="Tipo de Licença"
            rules={[{ required: true, message: 'Selecione a modalidade da licença' }]}
          >
            <Select
              options={[
                { label: 'Subscrição / Plano Comercial', value: 'PLANO' },
                { label: 'Período de Demonstração / Teste', value: 'TESTE' },
                { label: 'Vitalícia / Sem Limite de Tempo', value: 'SEM_LIMITE' },
              ]}
            />
          </Form.Item>

          <Form.Item
            noStyle
            shouldUpdate={(prev, curr) => prev.origem !== curr.origem}
          >
            {({ getFieldValue }) => {
              const origem = getFieldValue('origem');
              if (origem === 'SEM_LIMITE') {
                return (
                  <Alert
                    message="Licença Vitalícia Selecionada"
                    description="Este cliente (e todas as suas empresas) terá acesso total, sem prazo de validade."
                    type="info"
                    showIcon
                    style={{ marginBottom: 16 }}
                  />
                );
              }

              return (
                <Form.Item
                  name="datas"
                  label="Período de Validade (Início e Fim)"
                  rules={[{ required: true, message: 'Defina as datas da licença' }]}
                >
                  <DatePicker.RangePicker
                    format="DD/MM/YYYY"
                    style={{ width: '100%' }}
                  />
                </Form.Item>
              );
            }}
          </Form.Item>

          <Divider />

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            <Button onClick={() => setClienteAEditar(null)}>Cancelar</Button>
            <Button type="primary" htmlType="submit" loading={mutacaoCliente.isPending}>
              Guardar Alterações
            </Button>
          </div>
        </Form>
      </Modal>
    </>
  );
}
