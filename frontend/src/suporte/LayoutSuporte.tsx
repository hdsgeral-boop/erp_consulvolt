import { Avatar, Dropdown, Layout, Menu, Space, Tag, type MenuProps } from 'antd';
import { CustomerServiceOutlined, DownOutlined, IdcardOutlined, LogoutOutlined } from '@ant-design/icons';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import '@/plataforma/plataforma.css';

const ITENS_MENU: MenuProps['items'] = [
  { key: '/suporte', icon: <CustomerServiceOutlined />, label: <Link to="/suporte">Tickets de suporte</Link> },
];

/**
 * Casca do backoffice de suporte (ADR-076): deliberadamente isolada de LayoutPlataforma — um agente nunca vê sequer
 * os itens de menu da administração da plataforma (clientes, planos, definições…), não só por não ter acesso à API.
 */
export function LayoutSuporte() {
  const { utilizador, sair } = useSessao();
  const navigate = useNavigate();
  const location = useLocation();

  const nome = utilizador?.nome_completo || utilizador?.nome_utilizador || 'Agente';
  const iniciais = nome.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join('');

  return (
    <Layout style={{ minHeight: '100vh', background: '#f6f8fb' }}>
      <Layout.Sider width={230} theme="dark" style={{ background: '#081b30', borderRight: '1px solid rgba(255,255,255,0.06)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '22px 20px', borderBottom: '1px solid rgba(255,255,255,0.08)' }}>
          <div style={{ width: 36, height: 36, flex: 'none', borderRadius: 10, background: 'linear-gradient(135deg, #2f6fed 0%, #1947b3 100%)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800, fontSize: 15, boxShadow: '0 4px 10px rgba(41,94,224,.35)' }}>CV</div>
          <div style={{ minWidth: 0 }}>
            <div style={{ color: '#fff', fontWeight: 800, fontSize: 15, letterSpacing: '-0.01em', whiteSpace: 'nowrap' }}>CONSULVOLT</div>
            <small style={{ color: '#7c93b8', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.06em' }}>Central de Suporte</small>
          </div>
        </div>
        <Menu theme="dark" mode="inline" selectedKeys={[location.pathname]} items={ITENS_MENU} style={{ background: 'transparent', marginTop: 10, border: 'none' }} />
      </Layout.Sider>

      <Layout>
        <Layout.Header style={{ background: '#fff', padding: '0 24px', borderBottom: '1px solid #e7ecf3', display: 'flex', justifyContent: 'flex-end', alignItems: 'center', height: 64, lineHeight: 'normal' }}>
          <Space size={16} align="center">
            {/* Menu do utilizador — mesmo padrão do ERP principal (LayoutPrincipal) e da plataforma (LayoutPlataforma). */}
            <Dropdown
              trigger={['click']}
              placement="bottomRight"
              menu={{
                items: [
                  { key: 'quem', icon: <IdcardOutlined />, label: nome, disabled: true },
                  { type: 'divider' },
                  { key: 'sair', icon: <LogoutOutlined />, label: 'Terminar sessão', onClick: () => void sair().then(() => navigate('/suporte/login')) },
                ],
              }}
            >
              <button type="button" className="erp-plataforma-utilizador" aria-label="Opções do utilizador" aria-haspopup="menu">
                <Avatar style={{ background: '#1947b3', fontWeight: 700, fontSize: 13, flex: 'none' }}>{iniciais || 'A'}</Avatar>
                <span style={{ lineHeight: 1.3, textAlign: 'left' }}>
                  <span style={{ display: 'block', fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{nome}</span>
                  <Tag color="geekblue" style={{ margin: 0, fontSize: 10, lineHeight: '16px', padding: '0 6px' }}>
                    <CustomerServiceOutlined style={{ marginRight: 3 }} /> AGENTE DE SUPORTE
                  </Tag>
                </span>
                <DownOutlined style={{ fontSize: 11, color: '#94a3b8' }} />
              </button>
            </Dropdown>
          </Space>
        </Layout.Header>

        <Layout.Content style={{ padding: 28, maxWidth: 1480, width: '100%', margin: '0 auto' }}>
          <Outlet />
        </Layout.Content>
      </Layout>
    </Layout>
  );
}
