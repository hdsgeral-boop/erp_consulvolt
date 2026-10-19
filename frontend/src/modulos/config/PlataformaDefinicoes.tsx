import { useEffect } from 'react';
import {
  Button,
  Card,
  Form,
  Input,
  Tabs,
  Switch,
  Select,
  Row,
  Col,
  Alert,
  Divider,
  Typography,
  message,
  Space,
} from 'antd';
import {
  SettingOutlined,
  CreditCardOutlined,
  BankOutlined,
  MobileOutlined,
  SafetyCertificateOutlined,
  CopyOutlined,
} from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';

const { Paragraph } = Typography;

interface DefinicoesGerais {
  nome_plataforma: string | null;
  email_suporte: string | null;
  mensagem_manutencao: string | null;
}

interface GatewayConfig {
  referencia: {
    ativo?: boolean;
    entidade?: string;
    subentidade?: string;
    api_key?: string;
    webhook_secret?: string;
  };
  express: {
    ativo?: boolean;
    merchant_id?: string;
    api_key?: string;
    ambiente?: string;
    webhook_secret?: string;
  };
  dados_bancarios: {
    banco?: string;
    iban?: string;
    titular?: string;
    instrucoes?: string;
  };
}

const CHAVE_GERAL = ['plataforma', 'definicoes'];
const CHAVE_GATEWAYS = ['plataforma', 'pagamentos', 'config'];

export default function PlataformaDefinicoes() {
  const qc = useQueryClient();
  const [formGeral] = Form.useForm<DefinicoesGerais>();
  const [formGateways] = Form.useForm<GatewayConfig>();

  // 1. Definições Gerais
  const { data: dadosGerais, isLoading: loadingGeral } = useQuery({
    queryKey: CHAVE_GERAL,
    queryFn: () => obter<DefinicoesGerais>('/plataforma/definicoes'),
  });

  useEffect(() => {
    if (dadosGerais) formGeral.setFieldsValue(dadosGerais);
  }, [dadosGerais, formGeral]);

  const mutacaoGeral = useMutation({
    mutationFn: (v: DefinicoesGerais) => enviar('put', '/plataforma/definicoes', v),
    onSuccess: () => {
      message.success('Definições gerais guardadas com sucesso.');
      qc.invalidateQueries({ queryKey: CHAVE_GERAL });
    },
    onError: (e) => notificarErro(e),
  });

  // 2. Configurações de Gateways e Dados Bancários
  const { data: dadosGateways, isLoading: loadingGateways } = useQuery({
    queryKey: CHAVE_GATEWAYS,
    queryFn: () => obter<GatewayConfig>('/plataforma/pagamentos/config'),
  });

  useEffect(() => {
    if (dadosGateways) {
      formGateways.setFieldsValue({
        referencia: {
          ativo: dadosGateways.referencia?.ativo ?? true,
          entidade: dadosGateways.referencia?.entidade ?? '',
          subentidade: dadosGateways.referencia?.subentidade ?? '',
          api_key: dadosGateways.referencia?.api_key ?? '',
          webhook_secret: dadosGateways.referencia?.webhook_secret ?? '',
        },
        express: {
          ativo: dadosGateways.express?.ativo ?? true,
          merchant_id: dadosGateways.express?.merchant_id ?? '',
          api_key: dadosGateways.express?.api_key ?? '',
          ambiente: dadosGateways.express?.ambiente ?? 'sandbox',
          webhook_secret: dadosGateways.express?.webhook_secret ?? '',
        },
        dados_bancarios: {
          banco: dadosGateways.dados_bancarios?.banco ?? '',
          iban: dadosGateways.dados_bancarios?.iban ?? '',
          titular: dadosGateways.dados_bancarios?.titular ?? '',
          instrucoes: dadosGateways.dados_bancarios?.instrucoes ?? '',
        },
      });
    }
  }, [dadosGateways, formGateways]);

  const mutacaoGateways = useMutation({
    mutationFn: (v: GatewayConfig) => enviar('put', '/plataforma/pagamentos/config', v),
    onSuccess: () => {
      message.success('Métodos de pagamento e credenciais guardadas com sucesso.');
      qc.invalidateQueries({ queryKey: CHAVE_GATEWAYS });
    },
    onError: (e) => notificarErro(e),
  });

  const webhookUrlReferencia = `${window.location.origin}/api/pagamentos/webhook/referencia`;
  const webhookUrlExpress = `${window.location.origin}/api/pagamentos/webhook/express`;

  const copiarTexto = (txt: string, rotulo: string) => {
    navigator.clipboard.writeText(txt);
    message.success(`${rotulo} copiado para a área de transferência!`);
  };

  return (
    <>
      <CabecalhoPagina
        titulo="Definições da Plataforma"
        subtitulo="Configurações globais da aplicação, dados bancários da empresa e credenciais de gateways de pagamento"
      />

      <Card>
        <Tabs
          defaultActiveKey="gateways"
          items={[
            {
              key: 'gateways',
              label: (
                <span>
                  <CreditCardOutlined />
                  Gateways de Pagamento & Dados Bancários
                </span>
              ),
              children: (
                <div style={{ maxWidth: 900 }}>
                  <Alert
                    message="Pronto para Integração com API Real (Multicaixa & Express)"
                    description="O ERP já possui toda a lógica de checkout, geração de referências, chamadas push Express e webhooks de confirmação automática. Basta preencher as credenciais fornecidas pela EMIS ou pelo seu banco abaixo para ativar o processamento em tempo real."
                    type="info"
                    showIcon
                    icon={<SafetyCertificateOutlined />}
                    style={{ marginBottom: 24 }}
                  />

                  <Form
                    form={formGateways}
                    layout="vertical"
                    onFinish={(v) => mutacaoGateways.mutate(v)}
                  >
                    {/* 1. Dados Bancários da Empresa / Plataforma */}
                    <Card
                      title={
                        <Space>
                          <BankOutlined style={{ color: '#1890ff' }} />
                          <span>1. Dados da Empresa e Coordenadas Bancárias (Transferência Manual)</span>
                        </Space>
                      }
                      style={{ marginBottom: 24 }}
                    >
                      <Paragraph type="secondary" style={{ fontSize: 13 }}>
                        Estes dados serão apresentados aos clientes nas faturas e no ecrã de renovação para pagamentos por transferência bancária.
                      </Paragraph>

                      <Row gutter={16}>
                        <Col xs={24} md={8}>
                          <Form.Item
                            name={['dados_bancarios', 'banco']}
                            label="Banco"
                            rules={[{ required: true, message: 'Indique o nome do banco' }]}
                          >
                            <Input placeholder="Ex: BFA, BAI, BCI, BIC" />
                          </Form.Item>
                        </Col>

                        <Col xs={24} md={16}>
                          <Form.Item
                            name={['dados_bancarios', 'iban']}
                            label="IBAN"
                            rules={[{ required: true, message: 'Indique o IBAN da conta' }]}
                          >
                            <Input placeholder="AO06 0006 0000 0000 0000 0000 0" />
                          </Form.Item>
                        </Col>
                      </Row>

                      <Row gutter={16}>
                        <Col xs={24} md={12}>
                          <Form.Item
                            name={['dados_bancarios', 'titular']}
                            label="Titular da Conta / Razão Social"
                          >
                            <Input placeholder="Ex: CONSULVOLT, LDA" />
                          </Form.Item>
                        </Col>

                        <Col xs={24} md={12}>
                          <Form.Item
                            name={['dados_bancarios', 'instrucoes']}
                            label="Instruções Adicionais"
                          >
                            <Input placeholder="Ex: Enviar comprovativo com o NIF da empresa" />
                          </Form.Item>
                        </Col>
                      </Row>
                    </Card>

                    {/* 2. Pagamento por Referência Multicaixa */}
                    <Card
                      title={
                        <Space>
                          <CreditCardOutlined style={{ color: '#52c41a' }} />
                          <span>2. Pagamento por Referência Multicaixa</span>
                        </Space>
                      }
                      extra={
                        <Form.Item name={['referencia', 'ativo']} valuePropName="checked" noStyle>
                          <Switch checkedChildren="ATIVO" unCheckedChildren="INATIVO" />
                        </Form.Item>
                      }
                      style={{ marginBottom: 24 }}
                    >
                      <Paragraph type="secondary" style={{ fontSize: 13 }}>
                        Permite a geração de referências automáticas de 9 dígitos que o cliente pode pagar em qualquer ATM Multicaixa ou Homebanking (Multicaixa Net).
                      </Paragraph>

                      <Row gutter={16}>
                        <Col xs={24} md={8}>
                          <Form.Item
                            name={['referencia', 'entidade']}
                            label="Código da Entidade (5 Dígitos)"
                            tooltip="Código atribuído pela EMIS ou pelo Banco (ex: 00999)"
                          >
                            <Input maxLength={10} placeholder="Ex: 00999" />
                          </Form.Item>
                        </Col>

                        <Col xs={24} md={8}>
                          <Form.Item
                            name={['referencia', 'subentidade']}
                            label="Subentidade / Prefixo"
                            tooltip="Identificador do cliente ou da carteira no gateway"
                          >
                            <Input maxLength={10} placeholder="Ex: 12" />
                          </Form.Item>
                        </Col>

                        <Col xs={24} md={8}>
                          <Form.Item
                            name={['referencia', 'api_key']}
                            label="Chave de API / Token"
                            tooltip="Chave secreta de autenticação do gateway de pagamento por referência"
                          >
                            <Input.Password placeholder="Inserir chave da API Real" />
                          </Form.Item>
                        </Col>
                      </Row>

                      <Divider style={{ margin: '12px 0' }} />

                      <div style={{ background: '#f5f7fa', padding: 12, borderRadius: 6 }}>
                        <div style={{ fontSize: 12, fontWeight: 600, color: '#333', marginBottom: 4 }}>
                          URL de Webhook para Confirmação Automática (Configurar no Portal da EMIS / Gateway):
                        </div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                          <Input value={webhookUrlReferencia} readOnly style={{ background: '#fff' }} />
                          <Button
                            icon={<CopyOutlined />}
                            onClick={() => copiarTexto(webhookUrlReferencia, 'URL do Webhook de Referência')}
                          >
                            Copiar
                          </Button>
                        </div>
                      </div>
                    </Card>

                    {/* 3. Multicaixa Express */}
                    <Card
                      title={
                        <Space>
                          <MobileOutlined style={{ color: '#722ed1' }} />
                          <span>3. Gateway Multicaixa Express</span>
                        </Space>
                      }
                      extra={
                        <Form.Item name={['express', 'ativo']} valuePropName="checked" noStyle>
                          <Switch checkedChildren="ATIVO" unCheckedChildren="INATIVO" />
                        </Form.Item>
                      }
                      style={{ marginBottom: 24 }}
                    >
                      <Paragraph type="secondary" style={{ fontSize: 13 }}>
                        Envia uma notificação push imediata para o telemóvel do cliente através da app Multicaixa Express para autorização com PIN.
                      </Paragraph>

                      <Row gutter={16}>
                        <Col xs={24} md={8}>
                          <Form.Item
                            name={['express', 'merchant_id']}
                            label="ID do Comerciante (Merchant ID)"
                            tooltip="Identificador da empresa no Multicaixa Express"
                          >
                            <Input placeholder="Ex: MCX_EMPRESA_01" />
                          </Form.Item>
                        </Col>

                        <Col xs={24} md={10}>
                          <Form.Item
                            name={['express', 'api_key']}
                            label="Chave Secreta da API (API Secret Key)"
                            tooltip="Token secreto para assinar e autorizar as requisições push"
                          >
                            <Input.Password placeholder="Inserir chave de API do Express" />
                          </Form.Item>
                        </Col>

                        <Col xs={24} md={6}>
                          <Form.Item
                            name={['express', 'ambiente']}
                            label="Ambiente"
                            tooltip="Escolha 'Produção' assim que tiver as credenciais reais ativas"
                          >
                            <Select
                              options={[
                                { label: 'Sandbox (Testes)', value: 'sandbox' },
                                { label: 'Produção (Real)', value: 'producao' },
                              ]}
                            />
                          </Form.Item>
                        </Col>
                      </Row>

                      <Divider style={{ margin: '12px 0' }} />

                      <div style={{ background: '#f5f7fa', padding: 12, borderRadius: 6 }}>
                        <div style={{ fontSize: 12, fontWeight: 600, color: '#333', marginBottom: 4 }}>
                          URL de Webhook Multicaixa Express (Callback de Confirmação):
                        </div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                          <Input value={webhookUrlExpress} readOnly style={{ background: '#fff' }} />
                          <Button
                            icon={<CopyOutlined />}
                            onClick={() => copiarTexto(webhookUrlExpress, 'URL do Webhook do Express')}
                          >
                            Copiar
                          </Button>
                        </div>
                      </div>
                    </Card>

                    <Button
                      type="primary"
                      size="large"
                      htmlType="submit"
                      loading={mutacaoGateways.isPending || loadingGateways}
                      style={{ minWidth: 200 }}
                    >
                      Guardar Métodos de Pagamento
                    </Button>
                  </Form>
                </div>
              ),
            },
            {
              key: 'geral',
              label: (
                <span>
                  <SettingOutlined />
                  Definições Gerais da Plataforma
                </span>
              ),
              children: (
                <div style={{ maxWidth: 640 }}>
                  <Form form={formGeral} layout="vertical" onFinish={(v) => mutacaoGeral.mutate(v)}>
                    <Form.Item
                      name="nome_plataforma"
                      label="Nome da plataforma"
                      rules={[{ required: true, message: 'Indique o nome da plataforma' }]}
                    >
                      <Input maxLength={80} />
                    </Form.Item>

                    <Form.Item
                      name="email_suporte"
                      label="Email de suporte"
                      rules={[{ type: 'email', message: 'Email inválido' }]}
                    >
                      <Input maxLength={150} />
                    </Form.Item>

                    <Form.Item name="mensagem_manutencao" label="Mensagem de manutenção">
                      <Input.TextArea rows={3} maxLength={500} placeholder="Mensagem exibida aos utilizadores se a plataforma estiver em manutenção..." />
                    </Form.Item>

                    <Button
                      type="primary"
                      htmlType="submit"
                      loading={mutacaoGeral.isPending || loadingGeral}
                    >
                      Guardar Definições Gerais
                    </Button>
                  </Form>
                </div>
              ),
            },
          ]}
        />
      </Card>
    </>
  );
}