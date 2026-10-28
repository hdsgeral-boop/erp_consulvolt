import type { ReactNode } from 'react';
import { Avatar, Button, Dropdown, Layout, Menu, Space, Tag, type MenuProps } from 'antd';
import {
  AuditOutlined,
  CloudServerOutlined,
  CreditCardOutlined,
  CustomerServiceOutlined,
  DashboardOutlined,
  DownOutlined,
  IdcardOutlined,
  LaptopOutlined,
  LayoutOutlined,
  LogoutOutlined,
  MenuOutlined,
  SafetyCertificateOutlined,
  SettingOutlined,
  SwapOutlined,
  TeamOutlined,
  CheckCircleFilled,
} from '@ant-design/icons';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { useModoMenuPlataforma } from './modoMenuPlataforma';
import './plataforma.css';

const LARGURA_MENU = 250;

const pagina = (caminho: string, texto: string, icon: ReactNode): Extract<NonNullable<MenuProps['items']>[number], { key?: unknown }> => ({
  key: caminho,
  icon,
  label: <Link to={caminho}>{texto}</Link>,
});

/** Grupos do menu: visão geral, operação comercial (clientes/planos/licenciamento), suporte à operação. */
const ITENS_MENU: MenuProps['items'] = [
  pagina('/admin', 'Dashboard Executivo', <DashboardOutlined />),
  { type: 'divider' },
  pagina('/admin/clientes', 'Clientes & Tenants', <TeamOutlined />),
  pagina('/admin/planos', 'Planos & Licenças', <CreditCardOutlined />),
  pagina('/admin/saas', 'Activação SaaS', <CloudServerOutlined />),
  { type: 'divider' },
  pagina('/admin/suporte', 'Suporte & Tickets', <CustomerServiceOutlined />),
  pagina('/admin/auditoria', 'Auditoria & Logs', <SafetyCertificateOutlined />),
  pagina('/admin/sessoes', 'Sessões Activas', <LaptopOutlined />),
  { type: 'divider' },
  pagina('/admin/administradores', 'Administradores', <IdcardOutlined />),
  pagina('/admin/agentes-suporte', 'Agentes de Suporte', <CustomerServiceOutlined />),
  pagina('/admin/definicoes', 'Definições da Plataforma', <SettingOutlined />),
];

export function LayoutPlataforma() {
  const { utilizador, sair } = useSessao();
  const navigate = useNavigate();
  const location = useLocation();
  const idUtilizador = utilizador?.id ?? utilizador?.nome_utilizador ?? null;
  const { modo, definir: definirModo } = useModoMenuPlataforma(idUtilizador);

  const nome = utilizador?.nome_completo || utilizador?.nome_utilizador || 'Administrador';
  const iniciais = nome
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join('');

  const logo = (
    <div style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 0 }}>
      <div
        style={{
          width: 36, height: 36, flex: 'none', borderRadius: 10, background: 'linear-gradient(135deg, #2f6fed 0%, #1947b3 100%)', color: '#fff',
          display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800, fontSize: 15, boxShadow: '0 4px 10px rgba(41,94,224,.35)',
        }}
      >
        CV
      </div>
      <div style={{ minWidth: 0 }}>
        <div style={{ color: '#fff', fontWeight: 800, fontSize: 15, letterSpacing: '-0.01em', whiteSpace: 'nowrap' }}>CONSULVOLT</div>
        <small style={{ color: '#7c93b8', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.06em', whiteSpace: 'nowrap' }}>Plataforma SaaS</small>
      </div>
    </div>
  );

  const utilizadorDropdown = (
    // Menu do utilizador — mesmo padrão do ERP principal (LayoutPrincipal): nome + papel e um ▾ com as opções.
    <Dropdown
      trigger={['click']}
      placement="bottomRight"
      menu={{
        items: [
          { key: 'quem', icon: <IdcardOutlined />, label: nome, disabled: true },
          { type: 'divider' },
          { key: 'sair', icon: <LogoutOutlined />, label: 'Terminar sessão', onClick: () => void sair().then(() => navigate('/admin/login')) },
        ],
      }}
    >
      <button type="button" className="erp-plataforma-utilizador" aria-label="Opções do utilizador" aria-haspopup="menu">
        <Avatar style={{ background: '#1947b3', fontWeight: 700, fontSize: 13, flex: 'none' }}>{iniciais || 'A'}</Avatar>
        <span style={{ lineHeight: 1.3, textAlign: 'left' }}>
          <span style={{ display: 'block', fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{nome}</span>
          <Tag color="blue" style={{ margin: 0, fontSize: 10, lineHeight: '16px', padding: '0 6px' }}>
            <AuditOutlined style={{ marginRight: 3 }} /> SUPER ADMIN
          </Tag>
        </span>
        <DownOutlined style={{ fontSize: 11, color: '#94a3b8' }} />
      </button>
    </Dropdown>
  );

  return (
    <Layout style={{ minHeight: '100vh', background: '#f6f8fb' }}>
      {modo === 'lateral' && (
        <Layout.Sider width={LARGURA_MENU} theme="dark" style={{ background: '#081b30', borderRight: '1px solid rgba(255,255,255,0.06)' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '22px 20px', borderBottom: '1px solid rgba(255,255,255,0.08)' }}>{logo}</div>
          <Menu theme="dark" mode="inline" selectedKeys={[location.pathname]} items={ITENS_MENU} style={{ background: 'transparent', marginTop: 10, border: 'none' }} />
        </Layout.Sider>
      )}

      <Layout>
        {modo === 'topo' && (
          <div style={{ background: '#081b30', padding: '0 24px', display: 'flex', alignItems: 'center', gap: 28, height: 64, borderBottom: '1px solid rgba(255,255,255,0.08)' }}>
            {logo}
            <Menu
              theme="dark"
              mode="horizontal"
              selectedKeys={[location.pathname]}
              items={(ITENS_MENU ?? []).filter((item) => item?.type !== 'divider')}
              style={{ background: 'transparent', border: 'none', flex: '1 1 auto', minWidth: 0 }}
            />
          </div>
        )}

        <Layout.Header
          style={{
            background: '#fff',
            padding: '0 24px',
            borderBottom: '1px solid #e7ecf3',
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            height: 64,
            lineHeight: 'normal',
          }}
        >
          <Space size={14} align="center">
            <Button
              type="text"
              icon={modo === 'lateral' ? <LayoutOutlined /> : <MenuOutlined />}
              onClick={() => definirModo(modo === 'lateral' ? 'topo' : 'lateral')}
            >
              {modo === 'lateral' ? 'Menu no topo' : 'Menu ao lado'}
            </Button>
            <Tag icon={<CheckCircleFilled style={{ color: '#10b981' }} />} color="success" style={{ margin: 0 }}>
              Cluster SaaS online (PostgreSQL + Redis)
            </Tag>
          </Space>

          <Space size={16} align="center">
            <Link to="/app">
              <Button icon={<SwapOutlined />}>Entrar no ERP</Button>
            </Link>
            {utilizadorDropdown}
          </Space>
        </Layout.Header>

        <Layout.Content style={{ padding: 28, maxWidth: 1480, width: '100%', margin: '0 auto' }}>
          <Outlet />
        </Layout.Content>
      </Layout>
    </Layout>
  );
}
