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
  empresas_total: number;
  empresas_ativas: number;
  empresas_em_teste: number;
  empresas_expiradas: number;
  empresas_sem_limite: number;
}

interface EstadoSaas {
  saas_ativo: boolean;
  licencas_ativas: boolean;
  dias_teste_padrao: number;
  rls_cobertura_completa: boolean;
  tabelas_sem_rls: string[];
  estatisticas: EstatisticasSaas;
}

interface EmpresaSaas {
  id: number;
  nome: string;
  nif: string;
  tenant_id: number;
  tenant_nome: string;
  tenant_slug?: string;
  tenant_codigo?: string;
  tenant_plano?: string;
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
  const [empresaAEditar, setEmpresaAEditar] = useState<EmpresaSaas | null>(null);
  const [formEdicao] = Form.useForm();

  // 1. Obter estado geral
  const { data: estadoGeral, isLoading: loadingEstado, refetch: recarregarEstado } = useQuery({
    queryKey: ['plataforma', 'saas', 'estado'],
    queryFn: () => obter<EstadoSaas>('/plataforma/saas/estado'),
  });

  // 2. Obter empresas
  const { data: empresas = [], isLoading: loadingEmpresas, refetch: recarregarEmpresas } = useQuery({
    queryKey: ['plataforma', 'saas', 'empresas'],
    queryFn: () => obter<EmpresaSaas[]>('/plataforma/saas/empresas'),
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

  // 4. Mutação: Atualizar licença de empresa
  const mutacaoEmpresa = useMutation({
    mutationFn: ({ id, dados }: { id: number; dados: Record<string, unknown> }) =>
      enviar('put', `/plataforma/saas/empresas/${id}`, dados),
    onSuccess: () => {
      message.success('Licença da empresa atualizada com sucesso.');
      setEmpresaAEditar(null);
      formEdicao.resetFields();
      qc.invalidateQueries({ queryKey: ['plataforma', 'saas', 'empresas'] });
      qc.invalidateQueries({ queryKey: ['plataforma', 'saas', 'estado'] });
    },
    onError: (e) => notificarErro(e),
  });

  const abrirModalEdicao = (emp: EmpresaSaas) => {
    setEmpresaAEditar(emp);
    formEdicao.setFieldsValue({
      origem: emp.origem,
      datas: emp.inicio && emp.fim ? [dayjs(emp.inicio), dayjs(emp.fim)] : undefined,
    });
  };

  const guardarEdicaoModal = (valores: { origem: string; datas?: [dayjs.Dayjs, dayjs.Dayjs] }) => {
    if (!empresaAEditar) return;
    const payload: Record<string, unknown> = { origem: valores.origem };
    if (valores.origem !== 'SEM_LIMITE' && valores.datas && valores.datas.length === 2) {
      payload.inicio = valores.datas[0].format('YYYY-MM-DD');
      payload.fim = valores.datas[1].format('YYYY-MM-DD');
    }
    mutacaoEmpresa.mutate({ id: empresaAEditar.id, dados: payload });
  };

  const adicionarDiasRapido = (emp: EmpresaSaas, dias: number) => {
    mutacaoEmpresa.mutate({
      id: emp.id,
      dados: {
        origem: 'PLANO',
        dias_adicionar: dias,
      },
    });
  };

  const tornarVitalicia = (emp: EmpresaSaas) => {
    mutacaoEmpresa.mutate({
      id: emp.id,
      dados: { origem: 'SEM_LIMITE' },
    });
  };

  const revogarLicenca = (emp: EmpresaSaas) => {
    mutacaoEmpresa.mutate({
      id: emp.id,
      dados: { origem: 'PLANO', revogar: true },
    });
  };

  // Filtragem de dados na tabela
  const empresasFiltradas = empresas.filter((emp) => {
    const termo = pesquisa.toLowerCase().trim();
    const coincideTexto =
      !termo ||
      emp.nome.toLowerCase().includes(termo) ||
      (emp.nif && emp.nif.toLowerCase().includes(termo)) ||
      (emp.tenant_nome && emp.tenant_nome.toLowerCase().includes(termo));

    if (!coincideTexto) return false;

    if (filtroOrigem === 'TODAS') return true;
    if (filtroOrigem === 'EXPIRADAS') return emp.expirada;
    if (filtroOrigem === 'ATIVAS') return !emp.expirada;
    return emp.origem === filtroOrigem;
  });

  const stats = estadoGeral?.estatisticas;

  return (
    <>
      <CabecalhoPagina
        titulo="Gestão Geral do SaaS e Licenciamento"
        subtitulo="Controlo global da plataforma: ativação/desligamento de módulos SaaS, períodos de demonstração e licenciamento de empresas"
        accoes={
          <Button
            icon={<ReloadOutlined />}
            onClick={() => {
              recarregarEstado();
              recarregarEmpresas();
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
              title="Empresas Ativas"
              value={stats?.empresas_ativas ?? 0}
              suffix={`/ ${stats?.empresas_total ?? 0}`}
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
              title="Em Período de Demonstração"
              value={stats?.empresas_em_teste ?? 0}
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
              title="Licenças Expiradas"
              value={stats?.empresas_expiradas ?? 0}
              valueStyle={{ color: '#ff4d4f' }}
              prefix={<CloseCircleOutlined />}
            />
            <Text type="secondary" style={{ fontSize: 12 }}>
              Modo somente leitura
            </Text>
          </Card>
        </Col>

        <Col xs={24} sm={12} md={6}>
          <Card bordered={false} style={{ height: '100%' }}>
            <Statistic
              title="Licenças Vitalícias"
              value={stats?.empresas_sem_limite ?? 0}
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
                <Tooltip title="O isolamento real entre clientes (RLS) é uma decisão de infra-estrutura à parte, feita por quem opera o servidor — ver docs/arquitetura/SAAS_ATIVACAO.md. Este interruptor só restringe /admin ao subdomínio admin.erp.<domínio>.">
                  <div style={{ fontWeight: 600, cursor: 'help' }}>Área da Plataforma por Subdomínio</div>
                </Tooltip>
                <Text type="secondary" style={{ fontSize: 12 }}>
                  Restringe /admin ao subdomínio admin.erp.&lt;domínio&gt;
                </Text>
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
                <Text type="secondary" style={{ fontSize: 12 }}>Bloqueia escrita para empresas expiradas</Text>
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
                <div style={{ fontWeight: 600 }}>Dias de Demonstração Padrão</div>
                <Text type="secondary" style={{ fontSize: 12 }}>Duração do período de demonstração gratuita para novas empresas</Text>
              </div>
              <Space>
                <InputNumber
                  min={1}
                  max={90}
                  value={estadoGeral?.dias_teste_padrao ?? 30}
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

        {estadoGeral?.rls_cobertura_completa ? (
          <Alert
            message="Segurança Multi-Tenant Ativa (RLS 100%)"
            description="Todas as tabelas de negócio do PostgreSQL possuem políticas de isolamento estrito por tenant ativas."
            type="success"
            showIcon
            icon={<SafetyCertificateOutlined />}
            style={{ marginTop: 16 }}
          />
        ) : (
          <Alert
            message="Atenção: Tabelas sem Isolamento RLS"
            description={`As seguintes tabelas necessitam de política RLS: ${estadoGeral?.tabelas_sem_rls.join(', ')}`}
            type="warning"
            showIcon
            style={{ marginTop: 16 }}
          />
        )}
      </Card>

      {/* Tabela de Gestão de Licenças por Empresa */}
      <Card
        title="Gestão de Empresas e Licenças"
        extra={
          <Space wrap>
            <Input
              placeholder="Pesquisar por empresa, NIF ou tenant..."
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
                { label: 'Todas as Empresas', value: 'TODAS' },
                { label: 'Apenas Ativas', value: 'ATIVAS' },
                { label: 'Em Demonstração', value: 'TESTE' },
                { label: 'Com Plano Ativo', value: 'PLANO' },
                { label: 'Licença Vitalícia', value: 'SEM_LIMITE' },
                { label: 'Apenas Expiradas', value: 'EXPIRADAS' },
              ]}
            />
          </Space>
        }
      >
        <Table
          dataSource={empresasFiltradas}
          rowKey="id"
          loading={loadingEmpresas}
          pagination={{ pageSize: 10, showSizeChanger: true }}
          columns={[
            {
              title: 'Empresa',
              key: 'empresa',
              render: (_, emp: EmpresaSaas) => (
                <div>
                  <div style={{ fontWeight: 600 }}>{emp.nome}</div>
                  <Text type="secondary" style={{ fontSize: 12 }}>
                    NIF: {emp.nif || 'Não definido'}
                  </Text>
                </div>
              ),
            },
            {
              title: 'Tenant / Cliente SaaS',
              key: 'tenant',
              render: (_, emp: EmpresaSaas) => (
                <div>
                  <div>{emp.tenant_nome || `Tenant #${emp.tenant_id}`}</div>
                  {emp.tenant_slug && (
                    <Text type="secondary" style={{ fontSize: 11 }}>
                      Slug: {emp.tenant_slug}
                    </Text>
                  )}
                </div>
              ),
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
                  return <Tag color="blue" icon={<ClockCircleOutlined />}>Período de Demonstração</Tag>;
                }
                return <Tag color="purple" icon={<ThunderboltOutlined />}>Subscrição / Plano</Tag>;
              },
            },
            {
              title: 'Validade & Dias Restantes',
              key: 'validade',
              render: (_, emp: EmpresaSaas) => {
                if (emp.origem === 'SEM_LIMITE' || !emp.fim) {
                  return <Tag color="green">Ativa Indefinidamente</Tag>;
                }

                if (emp.expirada) {
                  return (
                    <div>
                      <Tag color="red" icon={<CloseCircleOutlined />}>Expirada</Tag>
                      <div style={{ fontSize: 11, color: '#ff4d4f', marginTop: 2 }}>
                        Terminou a {dayjs(emp.fim).format('DD/MM/YYYY')}
                      </div>
                    </div>
                  );
                }

                const dias = emp.dias_restantes ?? 0;
                const corTag = dias <= 7 ? 'volcano' : dias <= 15 ? 'orange' : 'green';

                return (
                  <div>
                    <Tag color={corTag} icon={<ClockCircleOutlined />}>
                      {dias} {dias === 1 ? 'dia restante' : 'dias restantes'}
                    </Tag>
                    <div style={{ fontSize: 11, color: '#666', marginTop: 2 }}>
                      Válida até {dayjs(emp.fim).format('DD/MM/YYYY')}
                    </div>
                  </div>
                );
              },
            },
            {
              title: 'Estado do Acesso',
              key: 'estado',
              render: (_, emp: EmpresaSaas) => {
                if (emp.somente_leitura) {
                  return <Tag color="error">Somente Leitura</Tag>;
                }
                return <Tag color="success">Total (Leitura + Escrita)</Tag>;
              },
            },
            {
              title: 'Ações Rápidas de Licenciamento',
              key: 'acoes',
              align: 'right',
              render: (_, emp: EmpresaSaas) => (
                <Space wrap>
                  <Tooltip title="Adicionar 30 dias de licença">
                    <Button
                      size="small"
                      icon={<PlusOutlined />}
                      loading={mutacaoEmpresa.isPending}
                      onClick={() => adicionarDiasRapido(emp, 30)}
                    >
                      +30d
                    </Button>
                  </Tooltip>

                  <Tooltip title="Adicionar 1 ano (365 dias)">
                    <Button
                      size="small"
                      icon={<PlusOutlined />}
                      loading={mutacaoEmpresa.isPending}
                      onClick={() => adicionarDiasRapido(emp, 365)}
                    >
                      +1 ano
                    </Button>
                  </Tooltip>

                  <Tooltip title="Tornar Licença Vitalícia">
                    <Button
                      size="small"
                      icon={<CrownOutlined />}
                      loading={mutacaoEmpresa.isPending}
                      onClick={() => tornarVitalicia(emp)}
                    />
                  </Tooltip>

                  <Tooltip title="Editar datas e modalidade">
                    <Button
                      size="small"
                      icon={<EditOutlined />}
                      onClick={() => abrirModalEdicao(emp)}
                    />
                  </Tooltip>

                  {!emp.expirada && emp.origem !== 'SEM_LIMITE' && (
                    <Popconfirm
                      title="Revogar Licença"
                      description={`Tem a certeza que deseja revogar o acesso de escrita para "${emp.nome}"? A empresa passará para modo somente leitura.`}
                      okText="Sim, Revogar"
                      cancelText="Cancelar"
                      okButtonProps={{ danger: true }}
                      onConfirm={() => revogarLicenca(emp)}
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
        title={`Gerir Licença — ${empresaAEditar?.nome ?? ''}`}
        open={!!empresaAEditar}
        onCancel={() => setEmpresaAEditar(null)}
        footer={null}
        destroyOnClose
      >
        <Form
          form={formEdicao}
          layout="vertical"
          onFinish={guardarEdicaoModal}
          initialValues={{ origem: empresaAEditar?.origem ?? 'PLANO' }}
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
                    description="Esta empresa terá acesso total sem restrições ou contagem de tempo de validade."
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
            <Button onClick={() => setEmpresaAEditar(null)}>Cancelar</Button>
            <Button type="primary" htmlType="submit" loading={mutacaoEmpresa.isPending}>
              Guardar Alterações
            </Button>
          </div>
        </Form>
      </Modal>
    </>
  );
}
