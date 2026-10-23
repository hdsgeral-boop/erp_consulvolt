import { Component, Suspense, useState, type ComponentType, type ReactNode } from 'react';
import { Alert, Avatar, Breadcrumb, Button, Drawer, Dropdown, Grid, Input, Layout, Menu, Spin, Tag, Typography, type MenuProps } from 'antd';
import {
  AppstoreOutlined,
  AuditOutlined,
  BookOutlined,
  CustomerServiceOutlined,
  DashboardOutlined,
  DesktopOutlined,
  LogoutOutlined,
  MenuFoldOutlined,
  MenuOutlined,
  MenuUnfoldOutlined,
  RobotOutlined,
  SearchOutlined,
  SafetyCertificateOutlined,
  SafetyOutlined,
  SettingOutlined,
  SolutionOutlined,
  TeamOutlined,
  UserOutlined,
} from '@ant-design/icons';
import { BrowserRouter, Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { ecras } from '@/modulos/config/ecras';
import { PlataformaEntrar } from './PlataformaEntrar';
import { PainelTickets } from './PlataformaSuporte';

/** Entrada do menu da plataforma: id do registo de ecrãs → rótulo, ícone e grupo. O URL é /plataforma/<sufixo>. */
export interface EntradaPlataforma {
  id: string;
  rotulo: string;
  icone?: ReactNode;
  grupo?: string;
}

export const ENTRADAS_PLATAFORMA: EntradaPlataforma[] = [
  { id: 'plataforma_painel', rotulo: 'Painel', icone: <DashboardOutlined />, grupo: 'Visão geral' },
  { id: 'plataforma_clientes', rotulo: 'Clientes', icone: <TeamOutlined />, grupo: 'Operação' },
  { id: 'plataforma_sessoes', rotulo: 'Sessões activas', icone: <DesktopOutlined />, grupo: 'Operação' },
  { id: 'plataforma_planos', rotulo: 'Planos e versões', icone: <AppstoreOutlined />, grupo: 'Crescimento' },
  { id: 'plataforma_saas', rotulo: 'Activação SaaS e licenças', icone: <SafetyCertificateOutlined />, grupo: 'Crescimento' },
  { id: 'plataforma_ia', rotulo: 'Inteligência artificial', icone: <RobotOutlined />, grupo: 'Crescimento' },
  { id: 'plataforma_suporte', rotulo: 'Tickets de suporte', icone: <CustomerServiceOutlined />, grupo: 'Operação' },
  { id: 'plataforma_agentes_suporte', rotulo: 'Agentes de suporte', icone: <SolutionOutlined />, grupo: 'Operação' },
  { id: 'plataforma_administradores', rotulo: 'Administradores', icone: <UserOutlined />, grupo: 'Administração' },
  { id: 'plataforma_auditoria', rotulo: 'Auditoria', icone: <AuditOutlined />, grupo: 'Administração' },
  { id: 'plataforma_definicoes', rotulo: 'Definições', icone: <SettingOutlined />, grupo: 'Administração' },
  { id: 'plataforma_codigos', rotulo: 'Manual de códigos', icone: <BookOutlined />, grupo: 'Administração' },
];

/** Agente de suporte: só os tickets (ecrã próprio sobre /api/suporte; sem abrir nem eliminar tickets). */
export const ENTRADAS_SUPORTE: EntradaPlataforma[] = [{ id: 'plataforma_tickets', rotulo: 'Suporte', icone: <CustomerServiceOutlined /> }];

const ECRAS_SUPORTE: Record<string, ComponentType> = { plataforma_tickets: () => <PainelTickets agente /> };

const PAINEL = '/plataforma/painel';
const caminhoDe = (id: string) => `/plataforma/${id.replace('plataforma_', '')}`;

/** Evita que um ecrã que precise de empresa deite abaixo toda a área da plataforma. */
class Contido extends Component<{ children: ReactNode }, { erro: boolean }> {
  state = { erro: false };
  static getDerivedStateFromError() {
    return { erro: true };
  }
  render() {
    if (this.state.erro) return <Alert type="warning" showIcon message="Este ecrã não está disponível sem uma empresa activa." />;
    return this.props.children;
  }
}

/**
 * Raiz da aplicação da plataforma SaaS (view Blade plataforma), separada do ERP: sem sessão -> entrar / primeiro acesso;
 * com sessão entra o administrador da plataforma (e_plataforma) e, só com o menu «Suporte», o agente de suporte
 * (e_suporte). Nunca pede empresa.
 */
export function PlataformaApp() {
  const { estado, ePlataforma, eSuporte, sair } = useSessao();
  if (estado === 'a_carregar') return <Spin size="large" style={{ display: 'block', marginTop: '40vh' }} />;
  if (estado === 'anonimo') return <PlataformaEntrar />;
  if (!ePlataforma && eSuporte)
    return (
      <BrowserRouter>
        <PlataformaShell entradas={ENTRADAS_SUPORTE} />
      </BrowserRouter>
    );
  if (!ePlataforma)
    return (
      <Alert
        type="error"
        showIcon
        style={{ margin: 24 }}
        message="Esta área é reservada à administração da plataforma."
        action={<Button onClick={() => void sair()}>Sair</Button>}
      />
    );
  return (
    <BrowserRouter>
      <PlataformaShell />
    </BrowserRouter>
  );
}

/** Menu agrupado (Visão geral, Negócio, Suporte, Segurança, Sistema) a partir das entradas. */
function itensMenu(entradas: EntradaPlataforma[]): MenuProps['items'] {
  const grupos = [...new Set(entradas.map((e) => e.grupo ?? ''))];
  if (grupos.length <= 1) return entradas.map((e) => ({ key: e.id, icon: e.icone, label: e.rotulo }));
  return grupos.map((g) => ({
    type: 'group' as const,
    key: `grupo-${g}`,
    label: g,
    children: entradas.filter((e) => (e.grupo ?? '') === g).map((e) => ({ key: e.id, icon: e.icone, label: e.rotulo })),
  }));
}

/** Cabeçalho, menu lateral (escuro, por secções, como o Central Admin) e ecrãs da plataforma. */
export function PlataformaShell({ entradas = ENTRADAS_PLATAFORMA }: { entradas?: EntradaPlataforma[] }) {
  const { utilizador, sair } = useSessao();
  const { pathname } = useLocation();
  const navegar = useNavigate();
  const ecra = Grid.useBreakpoint();
  const [aberto, setAberto] = useState(false);
  const [recolhido, setRecolhido] = useState(false);
  const movel = ecra.md === false;
  const podePesquisar = entradas.some((e) => e.id === 'plataforma_clientes');

  const inicio = caminhoDe(entradas[0].id);
  const activo = entradas.find((e) => pathname.startsWith(caminhoDe(e.id)))?.id ?? entradas[0].id;
  const actual = entradas.find((e) => e.id === activo);
  const nome = utilizador?.nome_completo || utilizador?.nome_utilizador || '';
  const colapsado = !movel && recolhido;
  const menu = (
    <Menu
      mode="inline"
      theme="dark"
      selectedKeys={[activo]}
      items={itensMenu(entradas)}
      inlineCollapsed={colapsado}
      className="plataforma-menu"
      style={{ borderInlineEnd: 'none', background: 'transparent' }}
      onClick={({ key }) => {
        navegar(caminhoDe(String(key)));
        setAberto(false);
      }}
    />
  );
  const marca = (
    <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: colapsado ? '20px 18px 14px' : '20px 20px 14px' }}>
      <span style={{ width: 36, height: 36, borderRadius: 12, background: 'linear-gradient(135deg,#3b82f6,#8b5cf6)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 18, flex: 'none', boxShadow: '0 4px 12px rgba(59,130,246,.3)' }}>
        <SafetyOutlined />
      </span>
      {!colapsado && (
        <div style={{ lineHeight: 1.2 }}>
          <div style={{ color: '#fff', fontWeight: 800, fontSize: 16 }}>MEU ERP</div>
          <div style={{ color: '#64748b', fontSize: 12, fontWeight: 500 }}>Central Admin</div>
        </div>
      )}
    </div>
  );
  const espaco = !colapsado && (
    <div style={{ margin: '0 16px 12px', padding: '10px 14px', border: '1px solid rgba(255,255,255,.08)', borderRadius: 12, color: '#cbd5e1', fontSize: 13, fontWeight: 500, display: 'flex', alignItems: 'center', gap: 8, background: 'rgba(255,255,255,.02)' }}>
      <span style={{ width: 8, height: 8, borderRadius: '50%', background: '#10b981', boxShadow: '0 0 0 3px rgba(16,185,129,.15)' }} />
      Workspace principal
    </div>
  );
  const rodape = (
    <div style={{ padding: colapsado ? '14px 12px' : '14px 20px', borderTop: '1px solid rgba(255,255,255,.06)', display: 'flex', alignItems: 'center', gap: 10 }}>
      <Avatar style={{ background: '#2563eb', flex: 'none' }}>{nome.slice(0, 1).toUpperCase() || '?'}</Avatar>
      {!colapsado && (
        <div style={{ minWidth: 0, flex: 1, lineHeight: 1.3 }}>
          <div style={{ color: '#e2e8f0', fontWeight: 600, fontSize: 13, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{nome}</div>
          <Tag bordered={false} color="blue" style={{ margin: 0, fontSize: 11 }}>{entradas === ENTRADAS_SUPORTE ? 'SUPORTE' : 'ADMINISTRADOR'}</Tag>
        </div>
      )}
      <Button type="text" aria-label="Terminar sessão" title="Terminar sessão" icon={<LogoutOutlined />} style={{ color: '#94a3b8' }} onClick={() => void sair()} />
    </div>
  );
  const lateral = (
    <div style={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
      {marca}
      {espaco}
      <div style={{ flex: 1, overflowY: 'auto' }}>{menu}</div>
      {rodape}
    </div>
  );

  return (
    <Layout style={{ minHeight: '100vh', background: '#f8fafc' }}>
      <style>{`
        .plataforma-menu .ant-menu-item-group-title { font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #475569 !important; padding: 18px 24px 6px; }
        .plataforma-menu.ant-menu-dark .ant-menu-item { margin: 2px 12px; width: calc(100% - 24px); border-radius: 10px; color: #94a3b8; }
        .plataforma-menu.ant-menu-dark .ant-menu-item:hover { background: rgba(255,255,255,.05); color: #e2e8f0; }
        .plataforma-menu.ant-menu-dark .ant-menu-item-selected { background: rgba(59,130,246,.14); color: #fff; font-weight: 600; box-shadow: inset 3px 0 #3b82f6; }
        .plataforma-menu.ant-menu-dark .ant-menu-item-selected .anticon { color: #60a5fa; }
      `}</style>
      {!movel && (
        <Layout.Sider width={248} collapsedWidth={72} collapsed={recolhido} theme="dark" style={{ background: '#0f172a', position: 'sticky', top: 0, height: '100vh', overflow: 'hidden' }}>
          {lateral}
        </Layout.Sider>
      )}
      <Layout style={{ background: '#f8fafc' }}>
        <Layout.Header style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '0 20px', background: '#fff', borderBottom: '1px solid #e2e8f0', height: 64, lineHeight: '64px', position: 'sticky', top: 0, zIndex: 20 }}>
          <Button type="text" aria-label="Menu" icon={movel ? <MenuOutlined /> : recolhido ? <MenuUnfoldOutlined /> : <MenuFoldOutlined />} onClick={() => (movel ? setAberto(true) : setRecolhido((r) => !r))} />
          {podePesquisar && (
            <Input
              allowClear
              prefix={<SearchOutlined style={{ color: '#94a3b8' }} />}
              placeholder="Pesquisar clientes (nome ou endereço)"
              aria-label="Pesquisar clientes"
              style={{ maxWidth: 360, flex: 1 }}
              onPressEnter={(e) => {
                const q = e.currentTarget.value.trim();
                if (q) navegar(`/plataforma/clientes?q=${encodeURIComponent(q)}`);
              }}
            />
          )}
          <span style={{ flex: 1 }} />
          <Dropdown trigger={['click']} menu={{ items: [{ key: 'sair', icon: <LogoutOutlined />, label: 'Terminar sessão', onClick: () => void sair() }] }}>
            <Button type="text" style={{ display: 'flex', alignItems: 'center', gap: 8, height: 40 }}>
              <Avatar size="small" style={{ background: '#2563eb' }}>{nome.slice(0, 1).toUpperCase() || '?'}</Avatar>
              {!movel && <span style={{ fontWeight: 600 }}>{nome}</span>}
            </Button>
          </Dropdown>
        </Layout.Header>
        {movel && (
          <Drawer placement="left" open={aberto} onClose={() => setAberto(false)} width={260} styles={{ body: { padding: 0, background: '#0f172a' }, header: { display: 'none' } }}>
            {lateral}
          </Drawer>
        )}
        <Layout.Content style={{ padding: '16px 20px 24px', minWidth: 0 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12, gap: 12, flexWrap: 'wrap' }}>
            <Breadcrumb items={[{ title: 'Central Admin' }, { title: actual?.grupo }, { title: actual?.rotulo }].filter((x) => x.title)} />
            <Typography.Text type="secondary" style={{ fontSize: 13 }}>{new Date().toLocaleDateString('pt-PT', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</Typography.Text>
          </div>
          <Routes>
            {entradas.map(({ id }) => {
              const Ecra = ECRAS_SUPORTE[id] ?? ecras[id];
              return (
                <Route
                  key={id}
                  path={`${caminhoDe(id)}/*`}
                  element={
                    <Contido>
                      <Suspense fallback={<Spin style={{ display: 'block', marginTop: 80 }} />}>
                        {Ecra ? <Ecra /> : <Alert type="warning" showIcon message="Ecrã indisponível." />}
                      </Suspense>
                    </Contido>
                  }
                />
              );
            })}
            <Route path="*" element={<Navigate to={entradas === ENTRADAS_PLATAFORMA ? PAINEL : inicio} replace />} />
          </Routes>
        </Layout.Content>
      </Layout>
    </Layout>
  );
}
