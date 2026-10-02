import { Avatar, Button, Drawer, Dropdown, Flex, Layout, Menu, Select, Spin, Typography, theme, type MenuProps } from 'antd';
import { LogoutOutlined, MenuFoldOutlined, MenuOutlined, MenuUnfoldOutlined, UserOutlined } from '@ant-design/icons';
import { Suspense, useEffect, useMemo, useState } from 'react';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { useEcra } from '@/componentes/responsivo/useEcra';
import { larguraGaveta } from '@/componentes/responsivo/utilitarios';
import { notificarErro } from '@/utilitarios/erros';
import { construirItensMenu, estadoMenu } from './itensMenu';
import { MarcaEmpresa } from './MarcaEmpresa';

const LARGURA_MENU = 272;
const ALTURA_CABECALHO = 64;
const ALTURA_CABECALHO_TELEMOVEL = 56;

/**
 * Layout da aplicação: menu pelas permissões (GET /sistema/menu), identidade da empresa activa (logótipo + nome),
 * selector de empresa e menu do utilizador.
 *
 * Responsivo:
 *  - ≥ 992 px (lg): menu lateral fixo e recolhível (botão «Recolher menu»), selector de empresa na barra superior;
 *  - < 992 px: o menu passa a uma gaveta aberta pelo botão «Abrir menu» (fecha ao navegar), com o selector de empresa;
 *    a barra superior mostra o logótipo e o nome da empresa;
 *  - < 768 px: barra compacta (56 px) e menu do utilizador só com o avatar (o nome passa para dentro do menu).
 */
export function LayoutPrincipal() {
  const { menu, empresa, empresas, utilizador, escolherEmpresa, sair } = useSessao();
  const navegar = useNavigate();
  const local = useLocation();
  const ecra = useEcra();
  const { token } = theme.useToken();
  const [recolhido, setRecolhido] = useState(false);
  const [gavetaAberta, setGavetaAberta] = useState(false);

  const itens = useMemo(() => construirItensMenu(menu), [menu]);
  const { seleccionado, abertos } = useMemo(() => {
    const e = estadoMenu(local.pathname);
    // ecrã filho (ex. um mapa dentro de Relatórios): abre também o grupo
    const [, , moduloId, ecraId] = local.pathname.split('/');
    const pai = menu.find((m) => m.id === moduloId)?.ecras.find((x) => x.id === ecraId)?.pai;
    return pai ? { ...e, abertos: [...e.abertos, `grupo:/m/${moduloId}/${pai}`] } : e;
  }, [local.pathname, menu]);

  // a gaveta fecha ao navegar e quando o ecrã passa a largo
  useEffect(() => setGavetaAberta(false), [local.pathname]);
  useEffect(() => {
    if (!ecra.pequeno) setGavetaAberta(false);
  }, [ecra.pequeno]);

  const nomeUtilizador = utilizador?.nome_completo || utilizador?.nome_utilizador || '';
  const trocarEmpresa = (id: number) =>
    escolherEmpresa(id)
      .then(() => navegar('/'))
      .catch((e) => notificarErro(e));

  const seletorEmpresa = (largura: number | string) => (
    <Select
      style={{ width: largura, maxWidth: '100%' }}
      value={empresa?.id}
      options={empresas.map((e) => ({ value: e.id, label: e.nome, title: e.nome }))}
      onChange={(id: number) => void trocarEmpresa(id)}
      aria-label="Empresa activa"
      showSearch={empresas.length > 8}
      optionFilterProp="label"
    />
  );

  const menuNavegacao = (
    <Menu mode="inline" items={itens} selectedKeys={[seleccionado]} defaultOpenKeys={abertos} onClick={(i) => navegar(i.key)} style={{ borderInlineEnd: 0 }} />
  );

  const itensUtilizador: MenuProps['items'] = [
    // em telemóvel o nome não cabe na barra: aparece no topo do menu do utilizador
    ...(ecra.md ? [] : [{ key: 'quem', icon: <UserOutlined />, label: nomeUtilizador, disabled: true }, { type: 'divider' as const }]),
    { key: 'sair', icon: <LogoutOutlined />, label: 'Terminar sessão', onClick: () => void sair() },
  ];

  return (
    <Layout style={{ minHeight: '100vh' }}>
      {!ecra.pequeno && (
        <Layout.Sider
          className="erp-sider"
          collapsible
          collapsed={recolhido}
          trigger={null}
          width={LARGURA_MENU}
          theme="light"
          style={{ borderRight: `1px solid ${token.colorBorderSecondary}` }}
        >
          <div
            style={{
              height: ALTURA_CABECALHO,
              display: 'flex',
              alignItems: 'center',
              justifyContent: recolhido ? 'center' : 'flex-start',
              padding: recolhido ? '0 8px' : '0 16px',
              borderBottom: `1px solid ${token.colorBorderSecondary}`,
            }}
          >
            <MarcaEmpresa soIcone={recolhido} tamanho={recolhido ? 32 : 36} comProduto />
          </div>
          {menuNavegacao}
        </Layout.Sider>
      )}

      {ecra.pequeno && (
        <Drawer
          className="erp-gaveta-menu"
          placement="left"
          open={gavetaAberta}
          onClose={() => setGavetaAberta(false)}
          width={larguraGaveta(300)}
          title={<MarcaEmpresa tamanho={32} comProduto />}
          destroyOnHidden
          aria-label="Menu principal"
        >
          <div style={{ padding: '12px 16px', borderBottom: `1px solid ${token.colorBorderSecondary}` }}>
            <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>
              Empresa activa
            </Typography.Text>
            {seletorEmpresa('100%')}
          </div>
          {menuNavegacao}
        </Drawer>
      )}

      <Layout className="erp-layout-coluna">
        <Layout.Header
          className="erp-cabecalho"
          style={{
            background: token.colorBgContainer,
            padding: ecra.md ? '0 16px' : '0 8px 0 4px',
            height: ecra.md ? ALTURA_CABECALHO : ALTURA_CABECALHO_TELEMOVEL,
            lineHeight: 'normal',
            borderBottom: `1px solid ${token.colorBorderSecondary}`,
          }}
        >
          <Flex justify="space-between" align="center" gap={ecra.md ? 12 : 8} style={{ height: '100%' }}>
            <Flex align="center" gap={ecra.md ? 12 : 4} style={{ minWidth: 0, flex: '1 1 auto' }}>
              {ecra.pequeno ? (
                <Button type="text" icon={<MenuOutlined />} aria-label="Abrir menu" aria-expanded={gavetaAberta} onClick={() => setGavetaAberta(true)} />
              ) : (
                <Button
                  type="text"
                  icon={recolhido ? <MenuUnfoldOutlined /> : <MenuFoldOutlined />}
                  aria-label="Recolher menu"
                  aria-expanded={!recolhido}
                  title={recolhido ? 'Expandir menu' : 'Recolher menu'}
                  onClick={() => setRecolhido(!recolhido)}
                />
              )}
              {ecra.pequeno ? (
                <div style={{ minWidth: 0, flex: '1 1 auto' }}>
                  <MarcaEmpresa tamanho={ecra.md ? 32 : 28} />
                </div>
              ) : (
                seletorEmpresa(ecra.xl ? 340 : 280)
              )}
            </Flex>
            <Dropdown menu={{ items: itensUtilizador }} trigger={ecra.pequeno ? ['click'] : ['hover']} placement="bottomRight">
              <Flex align="center" gap={8} style={{ cursor: 'pointer', minWidth: 0, flex: 'none' }} role="button" tabIndex={0} aria-label="Menu do utilizador">
                <Avatar icon={<UserOutlined />} style={{ flex: 'none' }} />
                {ecra.md && (
                  <Typography.Text ellipsis style={{ maxWidth: 220 }}>
                    {nomeUtilizador}
                  </Typography.Text>
                )}
              </Flex>
            </Dropdown>
          </Flex>
        </Layout.Header>
        <Layout.Content className="erp-conteudo">
          <Suspense fallback={<Spin style={{ display: 'block', marginTop: 80 }} />}>
            <Outlet />
          </Suspense>
        </Layout.Content>
      </Layout>
    </Layout>
  );
}
