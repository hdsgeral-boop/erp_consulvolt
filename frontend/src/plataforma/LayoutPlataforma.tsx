import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Avatar, Button, Drawer, Dropdown, Layout, Menu, Space, Tag, type MenuProps } from 'antd';
import {
  AuditOutlined,
  CloudServerOutlined,
  CreditCardOutlined,
  CustomerServiceOutlined,
  DashboardOutlined,
  BookOutlined,
  DownOutlined,
  GiftOutlined,
  HeartOutlined,
  IdcardOutlined,
  LaptopOutlined,
  LayoutOutlined,
  LogoutOutlined,
  MenuOutlined,
  RobotOutlined,
  SafetyCertificateOutlined,
  SettingOutlined,
  SwapOutlined,
  TeamOutlined,
  UserSwitchOutlined,
  CheckCircleFilled,
} from '@ant-design/icons';
import { Link, Outlet, useLocation, useNavigate } from 'react-router';
import { useEcra } from '@/componentes/responsivo/useEcra';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeloRoot } from '@/layout/SeloRoot';
import { useModoMenuPlataforma } from './modoMenuPlataforma';
import './plataforma.css';

const LARGURA_MENU = 250;

type ItemConsola = Extract<NonNullable<MenuProps['items']>[number], { key?: unknown }>;

/** `ecra` = ecrã `plataforma_*` do catálogo; um operador só vê os que o servidor lhe devolve no menu (o super-administrador vê todos). */
const pagina = (caminho: string, texto: string, icon: ReactNode, ecra?: string): ItemConsola & { ecra?: string } => ({
  key: caminho,
  icon,
  label: <Link to={caminho}>{texto}</Link>,
  ecra,
});

type EntradaMenu = ReturnType<typeof pagina> | { type: 'divider' };

/**
 * Grupos do menu: visão geral, operação comercial (clientes/planos/licenciamento), suporte à operação, administração.
 * Reúne tudo o que antes estava no menu «Plataforma SaaS» do ERP do cliente (agora só «Conta e Subscrição»).
 */
const ENTRADAS_MENU: EntradaMenu[] = [
  pagina('/admin', 'Dashboard Executivo', <DashboardOutlined />, 'plataforma_painel'),
  pagina('/admin/monitorizacao', 'Monitorização', <HeartOutlined />, 'plataforma_monitorizacao'),
  { type: 'divider' },
  pagina('/admin/clientes', 'Clientes & Tenants', <TeamOutlined />, 'plataforma_clientes'),
  pagina('/admin/planos', 'Planos & Licenças', <CreditCardOutlined />, 'plataforma_planos'),
  pagina('/admin/saas', 'Activação SaaS', <CloudServerOutlined />, 'plataforma_saas'),
  pagina('/admin/cupoes', 'Cupões de desconto', <GiftOutlined />, 'plataforma_cupoes'),
  pagina('/admin/ia', 'Inteligência Artificial', <RobotOutlined />, 'plataforma_ia'),
  { type: 'divider' },
  pagina('/admin/suporte', 'Suporte & Tickets', <CustomerServiceOutlined />, 'plataforma_suporte'),
  pagina('/admin/auditoria', 'Auditoria & Logs', <SafetyCertificateOutlined />, 'plataforma_auditoria'),
  pagina('/admin/sessoes', 'Sessões Activas', <LaptopOutlined />, 'plataforma_sessoes'),
  { type: 'divider' },
  pagina('/admin/administradores', 'Administradores', <IdcardOutlined />, 'plataforma_administradores'),
  pagina('/admin/operadores', 'Operadores', <UserSwitchOutlined />, 'plataforma_operadores_plataforma'),
  pagina('/admin/agentes-suporte', 'Agentes de Suporte', <CustomerServiceOutlined />),
  pagina('/admin/definicoes', 'Definições da Plataforma', <SettingOutlined />, 'plataforma_definicoes'),
  pagina('/admin/codigos', 'Manual de códigos', <BookOutlined />, 'plataforma_codigos'),
];

/** Itens que o utilizador pode ver: todos para o super-administrador; para um operador, só os ecrãs que o menu do servidor lhe dá. */
export function itensConsola(superAdmin: boolean, ecrasPermitidos: ReadonlySet<string>): NonNullable<MenuProps['items']> {
  const itens = ENTRADAS_MENU.filter((x) => superAdmin || ('type' in x ? true : !!x.ecra && ecrasPermitidos.has(x.ecra)));
  // sem divisórias soltas (no início, no fim ou duas seguidas)
  const saida: EntradaMenu[] = [];
  for (const x of itens) {
    if ('type' in x && (saida.length === 0 || 'type' in saida[saida.length - 1])) continue;
    saida.push(x);
  }
  if (saida.length && 'type' in saida[saida.length - 1]) saida.pop();
  return saida.map((x) => {
    if ('type' in x) return x;
    const { ecra: _ecra, ...resto } = x;
    return resto;
  });
}

export function LayoutPlataforma() {
  const { utilizador, sair, menu } = useSessao();
  const navigate = useNavigate();
  const location = useLocation();
  const idUtilizador = utilizador?.id ?? utilizador?.nome_utilizador ?? null;
  const { modo, definir: definirModo } = useModoMenuPlataforma(idUtilizador);
  const superAdmin = utilizador?.papel === 'SUPER_ADMINISTRADOR';
  const ITENS_MENU = useMemo(() => itensConsola(superAdmin, new Set(menu.find((m) => m.id === 'config')?.ecras.map((e) => e.id) ?? [])), [superAdmin, menu]);

  // < 992 px: sem barra lateral nem barra do topo — o menu passa a uma gaveta aberta pelo botão «Abrir menu»
  const ecra = useEcra();
  const [gavetaAberta, setGavetaAberta] = useState(false);
  const lateral = modo === 'lateral' && !ecra.pequeno;
  const noTopo = modo === 'topo' && !ecra.pequeno;
  useEffect(() => setGavetaAberta(false), [location.pathname]);
  useEffect(() => { if (!ecra.pequeno) setGavetaAberta(false); }, [ecra.pequeno]);

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
        {ecra.md && <span style={{ lineHeight: 1.3, textAlign: 'left' }}>
          <span style={{ display: 'block', fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{nome}</span>
          <Tag color="blue" style={{ margin: 0, fontSize: 10, lineHeight: '16px', padding: '0 6px' }}>
            <AuditOutlined style={{ marginRight: 3 }} /> {superAdmin ? 'SUPER ADMIN' : 'OPERADOR'}
          </Tag>
        </span>}
        <DownOutlined style={{ fontSize: 11, color: '#94a3b8' }} />
      </button>
    </Dropdown>
  );

  return (
    <Layout style={{ minHeight: '100vh', background: '#f6f8fb' }}>
      {ecra.pequeno && (
        <Drawer
          placement="left"
          open={gavetaAberta}
          onClose={() => setGavetaAberta(false)}
          size={280}
          styles={{ body: { padding: 0, background: '#081b30' }, header: { display: 'none' } }}
          rootClassName="erp-gaveta-plataforma"
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '18px 20px', borderBottom: '1px solid rgba(255,255,255,0.08)' }}>{logo}</div>
          <Menu theme="dark" mode="inline" selectedKeys={[location.pathname]} items={ITENS_MENU} style={{ background: 'transparent', marginTop: 10, border: 'none' }} />
        </Drawer>
      )}
      {lateral && (
        <Layout.Sider width={LARGURA_MENU} theme="dark" style={{ background: '#081b30', borderRight: '1px solid rgba(255,255,255,0.06)' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '22px 20px', borderBottom: '1px solid rgba(255,255,255,0.08)' }}>{logo}</div>
          <Menu theme="dark" mode="inline" selectedKeys={[location.pathname]} items={ITENS_MENU} style={{ background: 'transparent', marginTop: 10, border: 'none' }} />
        </Layout.Sider>
      )}

      <Layout style={{ minWidth: 0 }}>
        {noTopo && (
          <div style={{ background: '#081b30', padding: '0 24px', display: 'flex', alignItems: 'center', gap: 28, height: 64, borderBottom: '1px solid rgba(255,255,255,0.08)' }}>
            {logo}
            <Menu
              theme="dark"
              mode="horizontal"
              selectedKeys={[location.pathname]}
              items={ITENS_MENU.filter((item) => item?.type !== 'divider')}
              style={{ background: 'transparent', border: 'none', flex: '1 1 auto', minWidth: 0 }}
            />
          </div>
        )}

        <Layout.Header
          style={{
            background: '#fff',
            padding: ecra.md ? '0 24px' : '0 10px',
            gap: 8,
            borderBottom: '1px solid #e7ecf3',
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            height: 64,
            lineHeight: 'normal',
          }}
        >
          <Space size={ecra.md ? 14 : 6} align="center" style={{ minWidth: 0 }}>
            {ecra.pequeno && <Button type="text" icon={<MenuOutlined />} aria-label="Abrir menu" aria-expanded={gavetaAberta} onClick={() => setGavetaAberta(true)} />}
            {!ecra.pequeno && <Button
              type="text"
              icon={modo === 'lateral' ? <LayoutOutlined /> : <MenuOutlined />}
              onClick={() => definirModo(modo === 'lateral' ? 'topo' : 'lateral')}
            >
              {modo === 'lateral' ? 'Menu no topo' : 'Menu ao lado'}
            </Button>}
            {ecra.xl && <Tag icon={<CheckCircleFilled style={{ color: '#10b981' }} />} color="success" style={{ margin: 0 }}>
              Cluster SaaS online (PostgreSQL + Redis)
            </Tag>}
          </Space>

          <Space size={ecra.md ? 16 : 6} align="center" style={{ flex: 'none' }}>
            <SeloRoot utilizador={utilizador} />
            <Link to="/app">
              <Button icon={<SwapOutlined />} aria-label="Entrar no ERP">{ecra.md ? 'Entrar no ERP' : null}</Button>
            </Link>
            {utilizadorDropdown}
          </Space>
        </Layout.Header>

        <Layout.Content style={{ padding: ecra.md ? 28 : ecra.sm ? 16 : 10, maxWidth: 1480, width: '100%', minWidth: 0, margin: '0 auto' }}>
          <Outlet />
        </Layout.Content>
      </Layout>
    </Layout>
  );
}
