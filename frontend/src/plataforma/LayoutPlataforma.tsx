import { Button, Layout, Menu, Space, Tag } from 'antd';
import {
  CloudServerOutlined,
  CreditCardOutlined,
  CustomerServiceOutlined,
  DashboardOutlined,
  LogoutOutlined,
  SafetyCertificateOutlined,
  SettingOutlined,
  SwapOutlined,
  TeamOutlined,
  CheckCircleFilled
} from '@ant-design/icons';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';

export function LayoutPlataforma() {
  const { utilizador, sair } = useSessao();
  const navigate = useNavigate();
  const location = useLocation();

  const menuItems = [
    {
      key: '/admin',
      icon: <DashboardOutlined />,
      label: <Link to="/admin">Dashboard Executivo</Link>
    },
    {
      key: '/admin/clientes',
      icon: <TeamOutlined />,
      label: <Link to="/admin/clientes">Clientes & Tenants</Link>
    },
    {
      key: '/admin/planos',
      icon: <CreditCardOutlined />,
      label: <Link to="/admin/planos">Planos & Licenças</Link>
    },
    {
      key: '/admin/saas',
      icon: <CloudServerOutlined />,
      label: <Link to="/admin/saas">Activação SaaS</Link>
    },
    {
      key: '/admin/suporte',
      icon: <CustomerServiceOutlined />,
      label: <Link to="/admin/suporte">Suporte & Tickets</Link>
    },
    {
      key: '/admin/auditoria',
      icon: <SafetyCertificateOutlined />,
      label: <Link to="/admin/auditoria">Auditoria & Logs</Link>
    },
    {
      key: '/admin/definicoes',
      icon: <SettingOutlined />,
      label: <Link to="/admin/definicoes">Definições da Plataforma</Link>
    }
  ];

  return (
    <Layout style={{ minHeight: '100vh', background: '#f8fafc' }}>
      <Layout.Sider
        width={250}
        theme="dark"
        style={{
          background: '#071e33',
          borderRight: '1px solid rgba(255, 255, 255, 0.08)'
        }}
      >
        <div style={{ padding: '24px 20px', borderBottom: '1px solid rgba(255, 255, 255, 0.08)' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <div style={{
              width: 36,
              height: 36,
              borderRadius: 10,
              background: '#0052ff',
              color: '#ffffff',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontWeight: 800,
              fontSize: 16
            }}>
              CV
            </div>
            <div>
              <div style={{ color: '#ffffff', fontWeight: 800, fontSize: 15, letterSpacing: '-0.01em' }}>
                CONSULVOLT
              </div>
              <small style={{ color: '#94a3b8', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                Plataforma SaaS
              </small>
            </div>
          </div>
        </div>

        <Menu
          theme="dark"
          mode="inline"
          selectedKeys={[location.pathname]}
          items={menuItems}
          style={{ background: 'transparent', marginTop: 12 }}
        />
      </Layout.Sider>

      <Layout>
        <Layout.Header
          style={{
            background: '#ffffff',
            padding: '0 28px',
            borderBottom: '1px solid #edf2f7',
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            height: 64
          }}
        >
          <Space size="middle">
            <Tag icon={<CheckCircleFilled style={{ color: '#10b981' }} />} color="success">
              Cluster SaaS Online (PostgreSQL + Redis)
            </Tag>
            <span style={{ color: '#64748b', fontSize: 13 }}>
              Gestão Global de Empresas e Licenciamento
            </span>
          </Space>

          <Space size="middle">
            <Link to="/app">
              <Button icon={<SwapOutlined />}>Entrar no ERP</Button>
            </Link>
            <div style={{ textAlign: 'right' }}>
              <div style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>
                {utilizador?.nome_completo || utilizador?.nome_utilizador}
              </div>
              <Tag color="blue" style={{ margin: 0, fontSize: 10 }}>SUPER ADMIN</Tag>
            </div>
            <Button
              danger
              icon={<LogoutOutlined />}
              onClick={() => void sair().then(() => navigate('/admin/login'))}
            >
              Sair
            </Button>
          </Space>
        </Layout.Header>

        <Layout.Content style={{ padding: 28, maxWidth: 1400, width: '100%', margin: '0 auto' }}>
          <Outlet />
        </Layout.Content>
      </Layout>
    </Layout>
  );
}
