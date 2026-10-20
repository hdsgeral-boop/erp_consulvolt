import { Component, Suspense, useState, type ReactNode } from 'react';
import { Alert, Button, Drawer, Grid, Layout, Menu, Spin, Typography } from 'antd';
import { MenuOutlined } from '@ant-design/icons';
import { BrowserRouter, Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { ecras } from '@/modulos/config/ecras';
import { PlataformaEntrar } from './PlataformaEntrar';

/** Entradas do menu da plataforma SaaS: id do registo de ecrãs → rótulo. O URL é /plataforma/<sufixo>. */
export const ENTRADAS_PLATAFORMA: { id: string; rotulo: string }[] = [
  { id: 'plataforma_painel', rotulo: 'Painel' },
  { id: 'plataforma_clientes', rotulo: 'Clientes' },
  { id: 'plataforma_planos', rotulo: 'Planos e versões' },
  { id: 'plataforma_saas', rotulo: 'Gestão de Activação SaaS' },
  { id: 'plataforma_suporte', rotulo: 'Suporte' },
  { id: 'plataforma_administradores', rotulo: 'Administradores' },
  { id: 'plataforma_auditoria', rotulo: 'Auditoria' },
  { id: 'plataforma_sessoes', rotulo: 'Sessões activas' },
  { id: 'plataforma_definicoes', rotulo: 'Definições' },
  { id: 'plataforma_codigos', rotulo: 'Manual de códigos' },
];

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
 * Raiz da aplicação da plataforma SaaS (plataforma.html), separada do ERP: sem sessão -> entrar / primeiro acesso;
 * com sessão só entra quem é administrador da plataforma (e_plataforma). Nunca pede empresa.
 */
export function PlataformaApp() {
  const { estado, ePlataforma, sair } = useSessao();
  if (estado === 'a_carregar') return <Spin size="large" style={{ display: 'block', marginTop: '40vh' }} />;
  if (estado === 'anonimo') return <PlataformaEntrar />;
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

/** Cabeçalho, menu lateral e ecrãs da plataforma. */
export function PlataformaShell() {
  const { utilizador, sair } = useSessao();
  const { pathname } = useLocation();
  const navegar = useNavigate();
  const ecra = Grid.useBreakpoint();
  const [aberto, setAberto] = useState(false);
  const movel = ecra.md === false;

  const activo = ENTRADAS_PLATAFORMA.find((e) => pathname.startsWith(caminhoDe(e.id)))?.id ?? 'plataforma_painel';
  const menu = (
    <Menu
      mode="inline"
      selectedKeys={[activo]}
      items={ENTRADAS_PLATAFORMA.map((e) => ({ key: e.id, label: e.rotulo }))}
      onClick={({ key }) => {
        navegar(caminhoDe(key));
        setAberto(false);
      }}
    />
  );

  return (
    <Layout style={{ minHeight: '100vh' }}>
      <Layout.Header style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '0 16px' }}>
        {movel && <Button type="text" aria-label="Menu" icon={<MenuOutlined style={{ color: '#fff' }} />} onClick={() => setAberto(true)} />}
        <Typography.Text strong style={{ color: '#fff', flex: 1 }}>MEU ERP · Plataforma</Typography.Text>
        {!movel && <Typography.Text style={{ color: '#fff' }}>{utilizador?.nome_completo || utilizador?.nome_utilizador}</Typography.Text>}
        <Button onClick={() => void sair()}>Sair</Button>
      </Layout.Header>
      <Layout>
        {movel ? (
          <Drawer placement="left" open={aberto} onClose={() => setAberto(false)} title="Plataforma" styles={{ body: { padding: 0 } }}>
            {menu}
          </Drawer>
        ) : (
          <Layout.Sider width={250} theme="light">{menu}</Layout.Sider>
        )}
        <Layout.Content style={{ padding: 16, minWidth: 0 }}>
          <Routes>
            {ENTRADAS_PLATAFORMA.map(({ id }) => {
              const Ecra = ecras[id];
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
            <Route path="*" element={<Navigate to={PAINEL} replace />} />
          </Routes>
        </Layout.Content>
      </Layout>
    </Layout>
  );
}
