import { Avatar, Dropdown, Flex, Layout, Menu, Select, Spin, Typography, theme, type MenuProps } from 'antd';
import { HomeOutlined, LogoutOutlined, MenuFoldOutlined, MenuUnfoldOutlined, UserOutlined } from '@ant-design/icons';
import { Suspense, useMemo, useState } from 'react';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import type { EcraMenu } from '@/api/tipos';
import { notificarErro } from '@/utilitarios/erros';

type ItemMenu = NonNullable<MenuProps['items']>[number];

/** Layout da aplicação: menu lateral pelas permissões (GET /sistema/menu), empresa activa e utilizador. */
export function LayoutPrincipal() {
  const { menu, empresa, empresas, utilizador, escolherEmpresa, sair } = useSessao();
  const navegar = useNavigate();
  const local = useLocation();
  const [recolhido, setRecolhido] = useState(false);
  const { token } = theme.useToken();

  const itens = useMemo<MenuProps['items']>(() => {
    const ecraItem = (moduloId: string, e: EcraMenu, todos: EcraMenu[]): ItemMenu => {
      const filhos = todos.filter((f) => f.pai === e.id);
      const chave = `/m/${moduloId}/${e.id}`;
      return filhos.length
        ? { key: `grupo:${chave}`, label: e.nome, children: [{ key: chave, label: e.nome }, ...filhos.map((f) => ecraItem(moduloId, f, todos))] }
        : { key: chave, label: e.nome };
    };
    return [
      { key: '/', icon: <HomeOutlined />, label: 'Início' },
      ...menu.map((m) => ({
        key: `mod:${m.id}`,
        label: m.nome,
        children: m.ecras.filter((e) => !e.pai || !m.ecras.some((x) => x.id === e.pai)).map((e) => ecraItem(m.id, e, m.ecras)),
      })),
    ];
  }, [menu]);

  const seleccionado = local.pathname === '/' ? '/' : local.pathname.split('/').slice(0, 4).join('/');
  const moduloAberto = local.pathname.startsWith('/m/') ? [`mod:${local.pathname.split('/')[2]}`] : [];

  return (
    <Layout style={{ minHeight: '100vh' }}>
      <Layout.Sider collapsible collapsed={recolhido} trigger={null} width={272} theme="light" style={{ borderRight: `1px solid ${token.colorBorderSecondary}` }}>
        <div style={{ padding: '16px 20px', fontWeight: 700, fontSize: recolhido ? 14 : 18, color: token.colorPrimary }}>{recolhido ? 'ERP' : 'ERP Consulvolt'}</div>
        <Menu mode="inline" items={itens} selectedKeys={[seleccionado]} defaultOpenKeys={moduloAberto} onClick={(i) => navegar(i.key)} style={{ borderInlineEnd: 0 }} />
      </Layout.Sider>
      <Layout>
        <Layout.Header style={{ background: token.colorBgContainer, padding: '0 16px', borderBottom: `1px solid ${token.colorBorderSecondary}` }}>
          <Flex justify="space-between" align="center" style={{ height: '100%' }}>
            <Flex align="center" gap={12}>
              <span onClick={() => setRecolhido(!recolhido)} style={{ cursor: 'pointer', fontSize: 18 }} aria-label="Recolher menu" role="button">
                {recolhido ? <MenuUnfoldOutlined /> : <MenuFoldOutlined />}
              </span>
              <Select
                style={{ minWidth: 280 }}
                value={empresa?.id}
                options={empresas.map((e) => ({ value: e.id, label: e.nome }))}
                onChange={(id: number) => escolherEmpresa(id).then(() => navegar('/')).catch((e) => notificarErro(e))}
                aria-label="Empresa activa"
              />
            </Flex>
            <Dropdown menu={{ items: [{ key: 'sair', icon: <LogoutOutlined />, label: 'Terminar sessão', onClick: () => void sair() }] }}>
              <Flex align="center" gap={8} style={{ cursor: 'pointer' }}>
                <Avatar icon={<UserOutlined />} />
                <Typography.Text>{utilizador?.nome_completo || utilizador?.nome_utilizador}</Typography.Text>
              </Flex>
            </Dropdown>
          </Flex>
        </Layout.Header>
        <Layout.Content style={{ padding: 24 }}>
          <Suspense fallback={<Spin style={{ display: 'block', marginTop: 80 }} />}>
            <Outlet />
          </Suspense>
        </Layout.Content>
      </Layout>
    </Layout>
  );
}
