import { Alert, Avatar, Button, Card, Col, Divider, Empty, List, Row, Space, Spin, Statistic, Tag, Typography } from 'antd';
import {
  ArrowRightOutlined,
  BankOutlined,
  ClockCircleOutlined,
  CloudServerOutlined,
  CreditCardOutlined,
  DownloadOutlined,
  ExclamationCircleFilled,
  SafetyCertificateOutlined,
  TeamOutlined,
  UserOutlined,
  WarningFilled,
} from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { descarregar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData } from '@/utilitarios/formatacao';

interface UltimoCliente {
  id: number;
  nome: string;
  slug: string;
  criado_em: string;
}

interface Painel {
  clientes: { total: number; ativos: number; suspensos: number };
  empresas: { total: number; ativas: number; em_teste: number; licenca_expirada: number };
  utilizadores: number;
  ultimos_clientes: UltimoCliente[];
}

/** Cartão de estatística com ícone, cor de estado e subtítulo opcional — mesma linguagem visual do Início do ERP. */
function Kpi({
  titulo,
  valor,
  icone,
  cor,
  subtitulo,
}: {
  titulo: string;
  valor: number;
  icone: React.ReactNode;
  cor: string;
  subtitulo?: string;
}) {
  return (
    <Card styles={{ body: { padding: '18px 20px' } }}>
      <Space align="start" size={14}>
        <div
          style={{
            width: 42,
            height: 42,
            flex: 'none',
            borderRadius: 10,
            background: `${cor}1a`,
            color: cor,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            fontSize: 19,
          }}
        >
          {icone}
        </div>
        <div>
          <Typography.Text type="secondary" style={{ fontSize: 13 }}>
            {titulo}
          </Typography.Text>
          <div style={{ fontSize: 26, fontWeight: 700, color: '#0f172a', lineHeight: 1.3 }}>{valor}</div>
          {subtitulo && (
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
              {subtitulo}
            </Typography.Text>
          )}
        </div>
      </Space>
    </Card>
  );
}

export function PlataformaDashboardExecutivo() {
  const painel = useQuery({ queryKey: ['plataforma-painel'], queryFn: () => obter<Painel>('/plataforma/painel') });

  if (painel.isLoading) {
    return <Spin size="large" style={{ display: 'block', marginTop: 120 }} />;
  }
  if (painel.isError || !painel.data) {
    return <Alert type="error" showIcon message="Não foi possível carregar o painel da plataforma." />;
  }

  const p = painel.data;

  return (
    <>
      <CabecalhoPagina titulo="Plataforma SaaS" subtitulo="Visão executiva da operação Consulvolt: clientes, empresas e licenciamento em tempo real" />

      {p.empresas.licenca_expirada > 0 && (
        <Alert
          style={{ marginBottom: 16 }}
          type="warning"
          showIcon
          icon={<WarningFilled />}
          message={`${p.empresas.licenca_expirada} empresa(s) com a licença expirada`}
          description="Estão em modo só-leitura até regularizarem o pagamento ou um super-administrador prolongar a licença."
          action={
            <Link to="/admin/saas">
              <Button size="small">Ver em Activação SaaS</Button>
            </Link>
          }
        />
      )}

      <Row gutter={[16, 16]}>
        <Col xs={24} sm={12} lg={6}>
          <Kpi titulo="Clientes (tenants)" valor={p.clientes.total} icone={<TeamOutlined />} cor="#1947b3" subtitulo={`${p.clientes.ativos} activo(s) · ${p.clientes.suspensos} suspenso(s)`} />
        </Col>
        <Col xs={24} sm={12} lg={6}>
          <Kpi titulo="Empresas" valor={p.empresas.total} icone={<BankOutlined />} cor="#0f766e" subtitulo={`${p.empresas.ativas} com acesso activo`} />
        </Col>
        <Col xs={24} sm={12} lg={6}>
          <Kpi
            titulo="Em período de demonstração"
            valor={p.empresas.em_teste}
            icone={<ClockCircleOutlined />}
            cor="#b45309"
            subtitulo="A avaliar o sistema"
          />
        </Col>
        <Col xs={24} sm={12} lg={6}>
          <Kpi
            titulo="Licenças expiradas"
            valor={p.empresas.licenca_expirada}
            icone={<ExclamationCircleFilled />}
            cor={p.empresas.licenca_expirada > 0 ? '#dc2626' : '#16a34a'}
            subtitulo="Modo só-leitura"
          />
        </Col>
      </Row>

      <Row gutter={[16, 16]} style={{ marginTop: 16 }}>
        <Col xs={24} lg={14}>
          <Card title="Acções rápidas" styles={{ body: { padding: 12 } }}>
            <Row gutter={[12, 12]}>
              {[
                { to: '/admin/clientes', icone: <TeamOutlined />, titulo: 'Gerir clientes', desc: 'Criar, suspender ou arquivar contas SaaS' },
                { to: '/admin/planos', icone: <CreditCardOutlined />, titulo: 'Planos & versões', desc: 'Preços, limites e módulos incluídos' },
                { to: '/admin/saas', icone: <CloudServerOutlined />, titulo: 'Activação SaaS', desc: 'Licenciamento, isolamento e período de teste' },
                { to: '/admin/auditoria', icone: <SafetyCertificateOutlined />, titulo: 'Auditoria', desc: 'Todos os registos de todos os clientes' },
              ].map((a) => (
                <Col xs={24} sm={12} key={a.to}>
                  <Link to={a.to}>
                    <div
                      style={{
                        border: '1px solid #e7ecf3',
                        borderRadius: 10,
                        padding: '14px 16px',
                        display: 'flex',
                        alignItems: 'center',
                        gap: 12,
                        height: '100%',
                        transition: 'border-color .15s, box-shadow .15s',
                      }}
                      className="erp-plataforma-acao-rapida"
                    >
                      <div style={{ fontSize: 18, color: '#1947b3' }}>{a.icone}</div>
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ fontWeight: 600, color: '#0f172a', fontSize: 13.5 }}>{a.titulo}</div>
                        <div style={{ color: '#64748b', fontSize: 12 }}>{a.desc}</div>
                      </div>
                      <ArrowRightOutlined style={{ color: '#94a3b8', fontSize: 12 }} />
                    </div>
                  </Link>
                </Col>
              ))}
            </Row>
          </Card>

          <Card title="Relatórios" style={{ marginTop: 16 }}>
            <Space wrap>
              <Button icon={<DownloadOutlined />} onClick={() => void descarregar('/plataforma/relatorios/clientes.csv', undefined, 'clientes.csv').catch(notificarErro)}>
                Exportar clientes (CSV)
              </Button>
              <Button icon={<DownloadOutlined />} onClick={() => void descarregar('/plataforma/relatorios/empresas.csv', undefined, 'empresas.csv').catch(notificarErro)}>
                Exportar empresas (CSV)
              </Button>
            </Space>
          </Card>
        </Col>

        <Col xs={24} lg={10}>
          <Card
            title={
              <Space>
                <UserOutlined /> Utilizadores totais
              </Space>
            }
          >
            <Statistic value={p.utilizadores} valueStyle={{ fontSize: 32, fontWeight: 700 }} suffix={<span style={{ fontSize: 13, color: '#64748b', fontWeight: 400 }}>em {p.clientes.total} cliente(s)</span>} />
          </Card>

          <Card title="Clientes mais recentes" style={{ marginTop: 16 }} extra={<Link to="/admin/clientes">Ver todos</Link>}>
            {p.ultimos_clientes.length === 0 ? (
              <Empty description="Ainda não há clientes." image={Empty.PRESENTED_IMAGE_SIMPLE} />
            ) : (
              <List
                itemLayout="horizontal"
                dataSource={p.ultimos_clientes}
                renderItem={(c) => (
                  <List.Item>
                    <List.Item.Meta
                      avatar={<Avatar style={{ background: '#1947b3' }}>{c.nome.slice(0, 2).toUpperCase()}</Avatar>}
                      title={c.nome}
                      description={<Tag style={{ marginTop: 2 }}>{c.slug}</Tag>}
                    />
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                      {formatarData(c.criado_em)}
                    </Typography.Text>
                  </List.Item>
                )}
              />
            )}
          </Card>
        </Col>
      </Row>
      <Divider style={{ margin: '24px 0 0' }} />
    </>
  );
}
