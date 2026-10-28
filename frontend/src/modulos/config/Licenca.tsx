import { useState } from 'react';
import {
  Alert,
  Button,
  Card,
  Col,
  Descriptions,
  Divider,
  Form,
  Input,
  InputNumber,
  Modal,
  Popconfirm,
  Radio,
  Row,
  Select,
  Space,
  Spin,
  Switch,
  Table,
  Tabs,
  Tag,
  Typography,
  Upload,
  message,
} from 'antd';
import type { UploadFile } from 'antd/es/upload/interface';
import {
  CreditCardOutlined,
  MobileOutlined,
  BankOutlined,
  CopyOutlined,
  CheckCircleOutlined,
  ClockCircleOutlined,
  ThunderboltOutlined,
  PaperClipOutlined,
} from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarNumero } from '@/utilitarios/formatacao';
import { PainelSuporteCliente } from './comum/PainelSuporteCliente';

const { Text, Paragraph } = Typography;

export interface EstadoLicenca {
  licencas_ativas: boolean;
  origem: 'SEM_LIMITE' | 'TESTE' | 'PLANO';
  inicio: string | null;
  fim: string | null;
  dias_restantes: number | null;
  expirada: boolean;
  somente_leitura: boolean;
  aviso: 'A_EXPIRAR' | 'EXPIRADA' | null;
  plano: { id: number; codigo: string; nome: string } | null;
  pendentes: number;
  dias_teste: number;
}

interface CicloPreco {
  ciclo_codigo: string;
  meses: number;
  desconto_percentagem: number;
  valor_bruto: number;
  valor_desconto_ciclo: number;
  valor_apos_ciclo: number;
  preco_mensal_efetivo: number;
  rotulo: string;
}

interface Plano {
  id: number;
  codigo: string;
  nome: string;
  descricao: string | null;
  preco_mensal: number;
  moeda: string;
  max_utilizadores: number | null;
  ativo: boolean;
  ordem: number;
  ciclos: Record<string, CicloPreco>;
}

interface Subscricao {
  id: number;
  plano: { id: number; codigo: string; nome: string } | null;
  meses: number;
  valor: number;
  moeda: string;
  estado: 'PENDENTE' | 'PAGO' | 'CANCELADO';
  referencia: string;
  ciclo_codigo: string;
  valor_bruto: number;
  desconto_ciclo_percentagem: number;
  valor_desconto_ciclo: number;
  valor_apos_ciclo: number;
  cupom_codigo: string | null;
  valor_desconto_cupom: number;
  periodo_inicio: string | null;
  periodo_fim: string | null;
  pago_em: string | null;
  metodo: string | null;
}

const CICLOS_ORDEM = ['MENSAL', 'TRIMESTRAL', 'SEMESTRAL', 'ANUAL'];

interface ResultadoCupom {
  valido: boolean;
  mensagem: string | null;
  cupom: { codigo: string; tipo_desconto: 'PERCENTAGEM' | 'VALOR_FIXO'; valor_desconto: number } | null;
  valor_desconto_cupom: number;
  valor_final: number;
}

interface DadosPagamentoIniciado {
  gateway: 'REFERENCIA' | 'EXPRESS';
  entidade?: string;
  referencia?: string;
  montante?: number;
  moeda?: string;
  telemovel?: string;
  estado?: string;
  validade?: string;
  mensagem?: string;
  dados_bancarios?: {
    banco?: string;
    iban?: string;
    titular?: string;
  };
}

export const CHAVE_LICENCA = ['licenca'];
const ORIGENS = { SEM_LIMITE: 'Sem limite', TESTE: 'Período de Demonstração', PLANO: 'Plano pago' } as const;
const COR_ESTADO = { PENDENTE: 'gold', PAGO: 'green', CANCELADO: 'default' } as const;

export function useEstadoLicenca(activo = true) {
  return useQuery({
    queryKey: [...CHAVE_LICENCA, 'estado'],
    queryFn: () => obter<EstadoLicenca>('/licenca/estado'),
    enabled: activo,
    staleTime: 60_000,
  });
}

/** Configurações — Licença e plano (config_licenca): estado, renovação por plano e tempo, pedidos e pagamentos via gateway. */
export default function Licenca() {
  const { pode, utilizador } = useSessao();
  const superAdmin = utilizador?.papel === 'SUPER_ADMINISTRADOR';
  const gerir = pode('config_licenca_gerir');
  const qc = useQueryClient();
  const estado = useEstadoLicenca();
  const planos = useQuery({
    queryKey: [...CHAVE_LICENCA, 'planos', superAdmin],
    queryFn: () => obter<Plano[]>(`/licenca/planos${superAdmin ? '?todos=1' : ''}`),
  });
  const subs = useQuery({
    queryKey: [...CHAVE_LICENCA, 'subscricoes'],
    queryFn: () => obter<Subscricao[]>('/licenca/subscricoes'),
    enabled: gerir, // GET /licenca/subscricoes exige config_licenca_gerir — não pedir sem essa permissão
  });

  const [planoId, setPlanoId] = useState<number>();
  const [cicloCodigo, setCicloCodigo] = useState<string>('MENSAL');
  const [cupomCodigo, setCupomCodigo] = useState('');
  const [cupomResultado, setCupomResultado] = useState<ResultadoCupom | null>(null);
  const [edicao, setEdicao] = useState<Partial<Plano> | null>(null);
  const [form] = Form.useForm<Plano>();

  // Estado do Modal de Pagamento
  const [subscricaoPagamento, setSubscricaoPagamento] = useState<Subscricao | null>(null);
  const [metodoSelecionado, setMetodoSelecionado] = useState<'REFERENCIA' | 'EXPRESS' | 'BANCO'>('REFERENCIA');
  const [telemovelExpress, setTelemovelExpress] = useState('');
  const [dadosIniciados, setDadosIniciados] = useState<DadosPagamentoIniciado | null>(null);
  const [comprovativo, setComprovativo] = useState<UploadFile[]>([]);

  const refrescar = () => qc.invalidateQueries({ queryKey: CHAVE_LICENCA });

  const renovar = useMutation({
    mutationFn: () =>
      enviar('post', '/licenca/renovacao', {
        plano_id: planoId,
        meses: plano?.ciclos[cicloCodigo]?.meses ?? 1,
        cupom_codigo: cupomResultado?.valido ? cupomCodigo : undefined,
      }),
    onSuccess: (res: any) => {
      message.success('Pedido de renovação criado.');
      refrescar();
      setCupomCodigo('');
      setCupomResultado(null);
      const dados = res?.dados ?? res;
      if (dados && dados.id) {
        abrirModalPagamento(dados);
      }
    },
    onError: (e) => notificarErro(e),
  });

  const validarCupom = useMutation({
    mutationFn: () => enviar<ResultadoCupom>('post', '/licenca/cupoes/validar', { codigo: cupomCodigo, plano_id: planoId, ciclo_codigo: cicloCodigo }),
    onSuccess: (res) => {
      setCupomResultado(res.dados);
      if (!res.dados.valido) message.warning(res.dados.mensagem ?? 'Cupão inválido.');
    },
    onError: (e) => notificarErro(e),
  });

  const confirmar = useMutation({
    mutationFn: (id: number) => enviar('post', `/licenca/subscricoes/${id}/confirmar-pagamento`, { metodo: 'MANUAL' }),
    onSuccess: () => {
      message.success('Pagamento confirmado. Licença renovada.');
      refrescar();
      setSubscricaoPagamento(null);
    },
    onError: (e) => notificarErro(e),
  });

  const cancelar = useMutation({
    mutationFn: (id: number) => enviar('delete', `/licenca/subscricoes/${id}`),
    onSuccess: () => {
      message.success('Pedido cancelado.');
      refrescar();
    },
    onError: (e) => notificarErro(e),
  });

  const gravarPlano = useMutation({
    mutationFn: (v: Plano) =>
      edicao?.id ? enviar('put', `/licenca/planos/${edicao.id}`, v) : enviar('post', '/licenca/planos', v),
    onSuccess: () => {
      message.success('Plano gravado.');
      setEdicao(null);
      refrescar();
    },
    onError: (e) => notificarErro(e),
  });

  // Mutação para iniciar pagamento no Gateway (Referência ou Express)
  const iniciarGateway = useMutation({
    mutationFn: (dados: { id: number; metodo: string; telemovel?: string }) =>
      enviar<any>('post', `/licenca/subscricoes/${dados.id}/iniciar-pagamento`, {
        metodo: dados.metodo,
        telemovel: dados.telemovel,
      }),
    onSuccess: (res: any) => {
      const payload: DadosPagamentoIniciado = res?.dados ?? res;
      setDadosIniciados(payload);
      message.success(
        metodoSelecionado === 'EXPRESS'
          ? 'Pedido enviado para a app Multicaixa Express! Autorize no telemóvel.'
          : 'Referência Multicaixa gerada com sucesso.'
      );
    },
    onError: (e) => notificarErro(e),
  });

  const abrirModalPagamento = (s: Subscricao) => {
    setSubscricaoPagamento(s);
    setDadosIniciados(null);
    setMetodoSelecionado('REFERENCIA');
    setTelemovelExpress('');
    setComprovativo([]);
  };

  const enviarComprovativo = useMutation({
    mutationFn: (id: number) => {
      const fd = new FormData();
      const ficheiro = comprovativo[0]?.originFileObj;
      if (ficheiro) fd.append('comprovativo', ficheiro);

      return enviar('post', `/licenca/subscricoes/${id}/comprovativo`, fd);
    },
    onSuccess: () => {
      message.success('Comprovativo enviado. Aguarda validação da equipa.');
      setComprovativo([]);
    },
    onError: (e) => notificarErro(e),
  });

  const copiarTexto = (txt: string, rotulo: string) => {
    navigator.clipboard.writeText(txt);
    message.success(`${rotulo} copiado!`);
  };

  const e = estado.data;
  const plano = planos.data?.find((p) => p.id === planoId);
  const ciclo = plano?.ciclos[cicloCodigo];
  const total = cupomResultado?.valido ? cupomResultado.valor_final : (ciclo?.valor_apos_ciclo ?? null);

  return (
    <>
      <CabecalhoPagina titulo="Licença e plano" subtitulo="Estado da licença da empresa activa, renovação por plano e pagamentos" />

      {e?.aviso && (
        <Alert
          style={{ marginBottom: 16 }}
          showIcon
          type={e.aviso === 'EXPIRADA' ? 'error' : 'warning'}
          message={e.aviso === 'EXPIRADA' ? 'Licença expirada: a empresa está em modo somente leitura.' : `A licença expira em ${e.dias_restantes} dia(s).`}
          description={
            e.aviso === 'EXPIRADA'
              ? 'Para desbloquear a emissão de documentos e gravação de dados, selecione um plano abaixo e efetue o pagamento.'
              : 'Renove a subscrição com antecedência para evitar interrupções de serviço.'
          }
        />
      )}

      <Tabs
        items={[
          {
            key: 'estado',
            label: 'Estado e Renovação',
            children: (
              <Space direction="vertical" size={16} style={{ width: '100%' }}>
                <Card loading={estado.isLoading} title="Licença da Empresa Ativa">
                  {e && (
                    <Descriptions column={{ xs: 1, md: 3 }} size="middle">
                      <Descriptions.Item label="Origem">
                        <Tag color={e.origem === 'SEM_LIMITE' ? 'gold' : e.origem === 'TESTE' ? 'blue' : 'purple'}>
                          {ORIGENS[e.origem]}
                        </Tag>
                      </Descriptions.Item>
                      <Descriptions.Item label="Plano Atual">{e.plano?.nome ?? 'Plano Base'}</Descriptions.Item>
                      <Descriptions.Item label="Cobrança">
                        {e.licencas_ativas ? <Tag color="green">Ativa</Tag> : <Tag>Desligada</Tag>}
                      </Descriptions.Item>
                      <Descriptions.Item label="Data Início">{e.inicio ? formatarData(e.inicio) : '—'}</Descriptions.Item>
                      <Descriptions.Item label="Data Fim">{e.fim ? formatarData(e.fim) : 'Vitalícia (Sem Prazo)'}</Descriptions.Item>
                      <Descriptions.Item label="Tempo Restante">
                        {e.origem === 'SEM_LIMITE' ? (
                          <Tag color="green">Indefinido</Tag>
                        ) : e.expirada ? (
                          <Tag color="red">Expirada</Tag>
                        ) : (
                          <Tag color={e.dias_restantes && e.dias_restantes <= 15 ? 'volcano' : 'green'}>
                            {e.dias_restantes} dias restantes
                          </Tag>
                        )}
                      </Descriptions.Item>
                    </Descriptions>
                  )}
                  {e?.origem === 'TESTE' && (
                    <Text type="secondary" style={{ display: 'block', marginTop: 12, fontSize: 12 }}>
                      O período de demonstração desta plataforma tem {e.dias_teste} dia(s) de duração. Pode subscrever um
                      plano comercial a qualquer momento, mesmo antes de terminar.
                    </Text>
                  )}
                </Card>

                {gerir && (
                  <Card title="Pedir Renovação / Nova Subscrição">
                    <Space direction="vertical" size={12} style={{ width: '100%' }}>
                      <Space wrap align="end">
                        <div>
                          <div style={{ fontSize: 12, marginBottom: 4, fontWeight: 500 }}>Escolha o Plano:</div>
                          <Select
                            style={{ width: 280 }}
                            placeholder="Selecione um plano comercial"
                            value={planoId}
                            onChange={(v) => { setPlanoId(v); setCupomResultado(null); }}
                            options={(planos.data ?? [])
                              .filter((p) => p.ativo)
                              .map((p) => ({
                                value: p.id,
                                label: `${p.nome} — ${formatarNumero(p.preco_mensal)} ${p.moeda}/mês`,
                              }))}
                          />
                        </div>

                        <div>
                          <div style={{ fontSize: 12, marginBottom: 4, fontWeight: 500 }}>Ciclo de facturação:</div>
                          <Radio.Group value={cicloCodigo} onChange={(ev) => { setCicloCodigo(ev.target.value); setCupomResultado(null); }} optionType="button">
                            {CICLOS_ORDEM.map((codigo) => {
                              const c = plano?.ciclos[codigo];
                              return (
                                <Radio.Button key={codigo} value={codigo}>
                                  {c?.rotulo ?? codigo}
                                  {c && c.desconto_percentagem > 0 ? ` −${formatarNumero(c.desconto_percentagem)}%` : ''}
                                </Radio.Button>
                              );
                            })}
                          </Radio.Group>
                        </div>

                        <Button
                          type="primary"
                          disabled={!planoId}
                          loading={renovar.isPending}
                          onClick={() => renovar.mutate()}
                          icon={<ThunderboltOutlined />}
                        >
                          Subscrever{total !== null ? ` (${formatarNumero(total)} ${plano?.moeda})` : ''}
                        </Button>
                      </Space>

                      {planoId && (
                        <div>
                          <div style={{ fontSize: 12, marginBottom: 4, fontWeight: 500 }}>Cupão de desconto:</div>
                          <Space.Compact>
                            <Input
                              style={{ width: 200 }}
                              placeholder="Código do cupão"
                              value={cupomCodigo}
                              onChange={(ev) => { setCupomCodigo(ev.target.value); setCupomResultado(null); }}
                            />
                            <Button loading={validarCupom.isPending} disabled={!cupomCodigo.trim()} onClick={() => validarCupom.mutate()}>Aplicar</Button>
                          </Space.Compact>
                          {cupomResultado && !cupomResultado.valido && (
                            <div style={{ marginTop: 4 }}><Tag color="warning">{cupomResultado.mensagem ?? 'Cupão inválido.'}</Tag></div>
                          )}
                        </div>
                      )}

                      {ciclo && (
                        <Descriptions size="small" column={{ xs: 1, sm: 2, md: 4 }} bordered style={{ maxWidth: 680 }}>
                          <Descriptions.Item label="Subtotal bruto">{formatarNumero(ciclo.valor_bruto)} {plano?.moeda}</Descriptions.Item>
                          <Descriptions.Item label="Desconto do ciclo">
                            {ciclo.desconto_percentagem > 0 ? (
                              <Tag color="green">−{formatarNumero(ciclo.valor_desconto_ciclo)} {plano?.moeda} ({formatarNumero(ciclo.desconto_percentagem)}%)</Tag>
                            ) : '—'}
                          </Descriptions.Item>
                          <Descriptions.Item label="Preço mensal efectivo">{formatarNumero(ciclo.preco_mensal_efetivo)} {plano?.moeda}/mês</Descriptions.Item>
                          <Descriptions.Item label="Desconto do cupão">
                            {cupomResultado?.valido ? (
                              <Tag color="green">−{formatarNumero(cupomResultado.valor_desconto_cupom)} {plano?.moeda} ({cupomResultado.cupom?.codigo})</Tag>
                            ) : '—'}
                          </Descriptions.Item>
                          <Descriptions.Item label="Valor a pagar"><strong>{formatarNumero(total ?? ciclo.valor_apos_ciclo)} {plano?.moeda}</strong></Descriptions.Item>
                        </Descriptions>
                      )}
                    </Space>
                  </Card>
                )}

                {gerir && (
                <Card title="Histórico de Subscrições e Pagamentos">
                  <Table<Subscricao>
                    rowKey="id"
                    size="middle"
                    loading={subs.isLoading}
                    dataSource={subs.data ?? []}
                    pagination={{ pageSize: 10 }}
                    scroll={{ x: 'max-content' }}
                    columns={[
                      { title: 'Referência', dataIndex: 'referencia', render: (txt: string) => <strong>{txt}</strong> },
                      { title: 'Plano', render: (_, s) => s.plano?.nome ?? '—' },
                      { title: 'Duração', dataIndex: 'meses', align: 'center', render: (m: number) => `${m} mês(es)` },
                      {
                        title: 'Valor Total',
                        align: 'right',
                        render: (_, s) => `${formatarNumero(s.valor)} ${s.moeda}`,
                      },
                      {
                        title: 'Cupão',
                        render: (_, s) => s.cupom_codigo ? <Tag color="green">{s.cupom_codigo} (−{formatarNumero(s.valor_desconto_cupom)})</Tag> : '—',
                      },
                      {
                        title: 'Estado',
                        render: (_, s) => <Tag color={COR_ESTADO[s.estado]}>{s.estado}</Tag>,
                      },
                      {
                        title: 'Período',
                        render: (_, s) =>
                          s.periodo_inicio ? `${formatarData(s.periodo_inicio)} → ${formatarData(s.periodo_fim ?? '')}` : 'Pendente de ativação',
                      },
                      {
                        title: 'Ações de Pagamento',
                        align: 'right',
                        render: (_, s) =>
                          s.estado === 'PENDENTE' && gerir ? (
                            <Space wrap>
                              <Button
                                type="primary"
                                size="small"
                                icon={<CreditCardOutlined />}
                                onClick={() => abrirModalPagamento(s)}
                              >
                                Pagar Agora
                              </Button>

                              {superAdmin && (
                                <Popconfirm
                                  title="Confirmar o pagamento manual?"
                                  description="Esta ação ativará a licença imediatamente."
                                  onConfirm={() => confirmar.mutate(s.id)}
                                >
                                  <Button size="small">Confirmar Manual</Button>
                                </Popconfirm>
                              )}

                              <Popconfirm
                                title="Cancelar o pedido de renovação?"
                                onConfirm={() => cancelar.mutate(s.id)}
                              >
                                <Button size="small" danger>Cancelar</Button>
                              </Popconfirm>
                            </Space>
                          ) : (
                            <Text type="secondary" style={{ fontSize: 12 }}>
                              {s.pago_em ? `Pago a ${formatarData(s.pago_em)}` : 'Concluído'}
                            </Text>
                          ),
                      },
                    ]}
                  />
                </Card>
                )}
              </Space>
            ),
          },
          {
            key: 'suporte',
            label: 'Suporte',
            children: <PainelSuporteCliente base="/licenca/suporte" anexosBase="/licenca/suporte/anexos" />,
          },
          ...(superAdmin
            ? [
                {
                  key: 'planos',
                  label: 'Gestão de Planos Comerciais',
                  children: (
                    <Card
                      extra={
                        <Button
                          type="primary"
                          onClick={() => {
                            form.resetFields();
                            form.setFieldsValue({ moeda: 'AOA', ativo: true, ordem: 0 } as Plano);
                            setEdicao({});
                          }}
                        >
                          Novo Plano
                        </Button>
                      }
                    >
                      <Table<Plano>
                        rowKey="id"
                        size="middle"
                        loading={planos.isLoading}
                        dataSource={planos.data ?? []}
                        pagination={false}
                        columns={[
                          { title: 'Código', dataIndex: 'codigo' },
                          { title: 'Nome Comercial', dataIndex: 'nome' },
                          {
                            title: 'Preço Mensal',
                            align: 'right',
                            render: (_, p) => `${formatarNumero(p.preco_mensal)} ${p.moeda}`,
                          },
                          {
                            title: 'Limite Utilizadores',
                            render: (_, p) => p.max_utilizadores ?? 'Ilimitado',
                          },
                          {
                            title: 'Ativo',
                            render: (_, p) => (p.ativo ? <Tag color="green">Sim</Tag> : <Tag>Não</Tag>),
                          },
                          {
                            title: 'Ações',
                            align: 'right',
                            render: (_, p) => (
                              <Button
                                size="small"
                                onClick={() => {
                                  form.setFieldsValue(p);
                                  setEdicao(p);
                                }}
                              >
                                Editar
                              </Button>
                            ),
                          },
                        ]}
                      />
                    </Card>
                  ),
                },
              ]
            : []),
        ]}
      />

      {/* Modal de Pagamento de Licença via Gateway */}
      <Modal
        title={`Efetuar Pagamento da Licença — Ref: ${subscricaoPagamento?.referencia ?? ''}`}
        open={!!subscricaoPagamento}
        onCancel={() => setSubscricaoPagamento(null)}
        footer={null}
        width={680}
        destroyOnClose
      >
        {subscricaoPagamento && (
          <div>
            <div
              style={{
                background: '#f8fafc',
                padding: '16px 20px',
                borderRadius: 8,
                marginBottom: 20,
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
              }}
            >
              <div>
                <div style={{ fontSize: 13, color: '#64748b' }}>Montante a Pagar:</div>
                <div style={{ fontSize: 24, fontWeight: 700, color: '#0f172a' }}>
                  {formatarNumero(subscricaoPagamento.valor)} {subscricaoPagamento.moeda}
                </div>
              </div>
              <div style={{ textAlign: 'right' }}>
                <div style={{ fontSize: 13, color: '#64748b' }}>Plano / Duração:</div>
                <div style={{ fontSize: 15, fontWeight: 600 }}>
                  {subscricaoPagamento.plano?.nome} ({subscricaoPagamento.meses} mês/meses)
                </div>
              </div>
            </div>

            <div style={{ marginBottom: 16 }}>
              <div style={{ fontWeight: 600, marginBottom: 8 }}>Selecione o Método de Pagamento:</div>
              <Radio.Group
                value={metodoSelecionado}
                onChange={(e) => {
                  setMetodoSelecionado(e.target.value);
                  setDadosIniciados(null);
                }}
                style={{ width: '100%' }}
              >
                <Row gutter={[12, 12]}>
                  <Col xs={24} sm={8}>
                    <Radio.Button value="REFERENCIA" style={{ height: 'auto', padding: '12px', width: '100%', textAlign: 'center' }}>
                      <CreditCardOutlined style={{ fontSize: 22, color: '#1677ff', display: 'block', marginBottom: 4 }} />
                      <div style={{ fontWeight: 600 }}>Referência Multicaixa</div>
                      <div style={{ fontSize: 11, color: '#888' }}>Pagar no ATM ou Net</div>
                    </Radio.Button>
                  </Col>

                  <Col xs={24} sm={8}>
                    <Radio.Button value="EXPRESS" style={{ height: 'auto', padding: '12px', width: '100%', textAlign: 'center' }}>
                      <MobileOutlined style={{ fontSize: 22, color: '#722ed1', display: 'block', marginBottom: 4 }} />
                      <div style={{ fontWeight: 600 }}>Multicaixa Express</div>
                      <div style={{ fontSize: 11, color: '#888' }}>Notificação no Telemóvel</div>
                    </Radio.Button>
                  </Col>

                  <Col xs={24} sm={8}>
                    <Radio.Button value="BANCO" style={{ height: 'auto', padding: '12px', width: '100%', textAlign: 'center' }}>
                      <BankOutlined style={{ fontSize: 22, color: '#52c41a', display: 'block', marginBottom: 4 }} />
                      <div style={{ fontWeight: 600 }}>Transferência / IBAN</div>
                      <div style={{ fontSize: 11, color: '#888' }}>Comprovativo bancário</div>
                    </Radio.Button>
                  </Col>
                </Row>
              </Radio.Group>
            </div>

            <Divider style={{ margin: '16px 0' }} />

            {/* 1. MODO: REFERÊNCIA MULTICAIXA */}
            {metodoSelecionado === 'REFERENCIA' && (
              <div>
                {!dadosIniciados ? (
                  <div style={{ textAlign: 'center', padding: '20px 0' }}>
                    <Paragraph type="secondary">
                      Clique no botão abaixo para gerar uma referência de 9 dígitos exclusiva para esta subscrição.
                    </Paragraph>
                    <Button
                      type="primary"
                      size="large"
                      icon={<CreditCardOutlined />}
                      loading={iniciarGateway.isPending}
                      onClick={() =>
                        iniciarGateway.mutate({
                          id: subscricaoPagamento.id,
                          metodo: 'REFERENCIA',
                        })
                      }
                    >
                      Gerar Referência Multicaixa
                    </Button>
                  </div>
                ) : (
                  <Card
                    style={{
                      background: 'linear-gradient(135deg, #1e293b 0%, #0f172a 100%)',
                      color: '#fff',
                      borderRadius: 12,
                    }}
                  >
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
                      <div style={{ fontSize: 16, fontWeight: 700, color: '#38bdf8' }}>
                        PAGAMENTO MULTICAIXA
                      </div>
                      <Tag color="cyan">ATM / Homebanking</Tag>
                    </div>

                    <Row gutter={[16, 16]}>
                      <Col span={12}>
                        <div style={{ color: '#94a3b8', fontSize: 12 }}>ENTIDADE:</div>
                        <div style={{ fontSize: 20, fontWeight: 700, letterSpacing: 2, color: '#fff' }}>
                          {dadosIniciados.entidade || '00999'}
                        </div>
                      </Col>

                      <Col span={12}>
                        <div style={{ color: '#94a3b8', fontSize: 12 }}>REFERÊNCIA:</div>
                        <div style={{ fontSize: 20, fontWeight: 700, letterSpacing: 2, color: '#38bdf8' }}>
                          {dadosIniciados.referencia}
                        </div>
                      </Col>
                    </Row>

                    <Row gutter={[16, 16]} style={{ marginTop: 12 }}>
                      <Col span={12}>
                        <div style={{ color: '#94a3b8', fontSize: 12 }}>VALOR:</div>
                        <div style={{ fontSize: 18, fontWeight: 600, color: '#4ade80' }}>
                          {formatarNumero(dadosIniciados.montante ?? subscricaoPagamento.valor)} {dadosIniciados.moeda || 'AOA'}
                        </div>
                      </Col>

                      <Col span={12}>
                        <div style={{ color: '#94a3b8', fontSize: 12 }}>ESTADO:</div>
                        <div style={{ fontSize: 14, color: '#fbbf24' }}>
                          <ClockCircleOutlined /> Aguarda Pagamento
                        </div>
                      </Col>
                    </Row>

                    <Divider style={{ borderColor: '#334155', margin: '16px 0' }} />

                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                      <Text style={{ color: '#94a3b8', fontSize: 11 }}>
                        Válido até 24 horas após emissão. A confirmação é instantânea após o pagamento.
                      </Text>
                      <Button
                        size="small"
                        icon={<CopyOutlined />}
                        onClick={() =>
                          copiarTexto(
                            `Entidade: ${dadosIniciados.entidade}\nReferência: ${dadosIniciados.referencia}\nValor: ${dadosIniciados.montante} ${dadosIniciados.moeda}`,
                            'Dados de Pagamento'
                          )
                        }
                      >
                        Copiar Dados
                      </Button>
                    </div>
                  </Card>
                )}
              </div>
            )}

            {/* 2. MODO: MULTICAIXA EXPRESS */}
            {metodoSelecionado === 'EXPRESS' && (
              <div>
                {!dadosIniciados ? (
                  <div style={{ padding: '10px 0' }}>
                    <Paragraph type="secondary">
                      Introduza o número de telemóvel associado à sua conta Multicaixa Express (9 dígitos).
                      Irá receber uma notificação push para confirmar o pagamento introduzindo o seu PIN.
                    </Paragraph>

                    <Form layout="vertical">
                      <Form.Item
                        label="Número de Telemóvel Multicaixa Express"
                        required
                        extra="Exemplo: 923 123 456"
                      >
                        <Input
                          size="large"
                          prefix={<MobileOutlined />}
                          placeholder="9XXXXXXXX"
                          maxLength={9}
                          value={telemovelExpress}
                          onChange={(e) => setTelemovelExpress(e.target.value.replace(/\D/g, ''))}
                          style={{ maxWidth: 320 }}
                        />
                      </Form.Item>

                      <Button
                        type="primary"
                        size="large"
                        icon={<MobileOutlined />}
                        loading={iniciarGateway.isPending}
                        disabled={telemovelExpress.length < 9}
                        onClick={() =>
                          iniciarGateway.mutate({
                            id: subscricaoPagamento.id,
                            metodo: 'EXPRESS',
                            telemovel: telemovelExpress,
                          })
                        }
                      >
                        Enviar Notificação de Pagamento
                      </Button>
                    </Form>
                  </div>
                ) : (
                  <Alert
                    message="Notificação Express Enviada com Sucesso!"
                    description={
                      <div>
                        <Paragraph style={{ margin: '8px 0' }}>
                          Enviámos o pedido de pagamento no valor de{' '}
                          <strong>
                            {formatarNumero(subscricaoPagamento.valor)} {subscricaoPagamento.moeda}
                          </strong>{' '}
                          para o telemóvel <strong>{dadosIniciados.telemovel}</strong>.
                        </Paragraph>
                        <Paragraph type="secondary" style={{ fontSize: 12 }}>
                          Abra agora a aplicação <strong>Multicaixa Express</strong> no seu telemóvel e introduza o seu PIN para concluir. A sua licença será prolongada no mesmo instante.
                        </Paragraph>
                        <Spin tip="A aguardar confirmação da rede..." style={{ marginTop: 8 }} />
                      </div>
                    }
                    type="info"
                    showIcon
                  />
                )}
              </div>
            )}

            {/* 3. MODO: TRANSFERÊNCIA BANCÁRIA */}
            {metodoSelecionado === 'BANCO' && (
              <div>
                <Card style={{ background: '#f8fafc', borderRadius: 8 }}>
                  <Descriptions column={1} size="small" bordered>
                    <Descriptions.Item label="Banco Destinatário">
                      <strong>BFA / BAI / BCI</strong>
                    </Descriptions.Item>
                    <Descriptions.Item label="IBAN">
                      <Space>
                        <Text copyable strong>AO06 0006 0000 0000 0000 0000 0</Text>
                      </Space>
                    </Descriptions.Item>
                    <Descriptions.Item label="Titular da Conta">
                      CONSULVOLT — TECNOLOGIAS, LDA
                    </Descriptions.Item>
                    <Descriptions.Item label="Descritivo / Referência">
                      {subscricaoPagamento.referencia}
                    </Descriptions.Item>
                  </Descriptions>

                  <Paragraph type="secondary" style={{ fontSize: 12, marginTop: 12 }}>
                    Após realizar a transferência bancária, anexe o comprovativo abaixo mencionando a referência <strong>{subscricaoPagamento.referencia}</strong>. A equipa valida manualmente e a licença é prolongada logo após a confirmação.
                  </Paragraph>
                </Card>

                <div style={{ marginTop: 16 }}>
                  <Upload
                    accept="image/jpeg,image/png,application/pdf"
                    fileList={comprovativo}
                    beforeUpload={() => false}
                    onChange={({ fileList }) => setComprovativo(fileList.slice(-1))}
                    maxCount={1}
                  >
                    <Button icon={<PaperClipOutlined />}>Seleccionar comprovativo (JPEG, PNG ou PDF, até 5 MB)</Button>
                  </Upload>
                  <Button
                    type="primary"
                    style={{ marginTop: 8 }}
                    icon={<BankOutlined />}
                    disabled={comprovativo.length === 0}
                    loading={enviarComprovativo.isPending}
                    onClick={() => enviarComprovativo.mutate(subscricaoPagamento.id)}
                  >
                    Enviar comprovativo
                  </Button>
                </div>
              </div>
            )}

            {superAdmin && (
              <div style={{ marginTop: 24, textAlign: 'right' }}>
                <Popconfirm
                  title="Simular pagamento instantâneo?"
                  description="Como superadministrador, pode aprovar o pagamento de teste agora mesmo."
                  onConfirm={() => confirmar.mutate(subscricaoPagamento.id)}
                >
                  <Button type="dashed" size="small" icon={<CheckCircleOutlined />}>
                    [SuperAdmin] Aprovar Imediatamente
                  </Button>
                </Popconfirm>
              </div>
            )}
          </div>
        )}
      </Modal>

      {/* Modal de Edição de Planos (SuperAdmin) */}
      <Modal
        open={edicao !== null}
        title={edicao?.id ? 'Editar plano' : 'Novo plano'}
        okText="Gravar"
        confirmLoading={gravarPlano.isPending}
        onCancel={() => setEdicao(null)}
        onOk={() => form.validateFields().then((v) => gravarPlano.mutate(v))}
        destroyOnClose
      >
        <Form form={form} layout="vertical">
          <Form.Item name="codigo" label="Código" rules={[{ required: true }]}>
            <Input maxLength={40} disabled={!!edicao?.id} />
          </Form.Item>
          <Form.Item name="nome" label="Nome" rules={[{ required: true }]}>
            <Input maxLength={120} />
          </Form.Item>
          <Form.Item name="descricao" label="Descrição">
            <Input.TextArea rows={2} />
          </Form.Item>
          <Space>
            <Form.Item name="preco_mensal" label="Preço mensal" rules={[{ required: true }]}>
              <InputNumber min={0} precision={2} />
            </Form.Item>
            <Form.Item name="moeda" label="Moeda" rules={[{ required: true }]}>
              <Input maxLength={3} style={{ width: 80 }} />
            </Form.Item>
            <Form.Item name="max_utilizadores" label="Máx. utilizadores">
              <InputNumber min={1} />
            </Form.Item>
          </Space>
          <Space>
            <Form.Item name="ordem" label="Ordem">
              <InputNumber min={0} />
            </Form.Item>
            <Form.Item name="ativo" label="Activo" valuePropName="checked">
              <Switch />
            </Form.Item>
          </Space>
        </Form>
      </Modal>
    </>
  );
}
