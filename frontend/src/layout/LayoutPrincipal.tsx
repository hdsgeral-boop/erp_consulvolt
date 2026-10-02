import { Avatar, Button, Drawer, Dropdown, Flex, Layout, Menu, Select, Spin, Tooltip, type MenuProps } from 'antd';
import { LogoutOutlined, MenuFoldOutlined, MenuOutlined, MenuUnfoldOutlined, SwapOutlined, UserOutlined } from '@ant-design/icons';
import { Suspense, useEffect, useMemo, useState } from 'react';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { useIdentidade } from '@/sessao/identidade';
import { useEcra } from '@/componentes/responsivo/useEcra';
import { larguraGaveta } from '@/componentes/responsivo/utilitarios';
import { notificarErro } from '@/utilitarios/erros';
import { construirItensMenu, estadoMenu } from './itensMenu';
import { MarcaEmpresa } from './MarcaEmpresa';
import { rotuloPapel } from './marca';

const LARGURA_MENU = 280;
const ALTURA_CABECALHO = 72;
const ALTURA_CABECALHO_TELEMOVEL = 56;

/**
 * Layout da aplicação: menu pelas permissões (GET /sistema/menu), identidade da empresa activa (logótipo + nome),
 * selector de empresa e menu do utilizador.
 *
 * Aspecto do sistema anterior (index_arrumado.html + css/styles.css): menu lateral escuro (#121212) de 280 px com a
 * marca no topo, item activo com barra azul à esquerda e sub-níveis com guia tracejada; barra superior branca com o
 * botão quadrado do menu, o nome da empresa e o crachá do NIF à esquerda, «Trocar empresa», o utilizador e o botão
 * vermelho de sair à direita.
 *
 * Responsivo:
 *  - ≥ 992 px (lg): menu lateral fixo e recolhível (botão «Recolher menu»), selector de empresa na barra superior;
 *  - < 992 px: o menu passa a uma gaveta escura aberta pelo botão «Abrir menu» (fecha ao navegar), com o selector de
 *    empresa; a barra superior mostra o logótipo e o nome da empresa;
 *  - < 768 px: barra compacta (56 px) e menu do utilizador só com o avatar (o nome passa para dentro do menu).
 */
export function LayoutPrincipal() {
  const { menu, empresa, empresas, utilizador, escolherEmpresa, sair } = useSessao();
  const identidade = useIdentidade();
  const navegar = useNavigate();
  const local = useLocation();
  const ecra = useEcra();
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
  const papel = rotuloPapel(utilizador?.papel) ?? utilizador?.nome_utilizador ?? null;
  const nomeEmpresa = identidade.data?.nome || empresa?.nome || '';
  const nif = identidade.data?.nif ?? empresa?.nif ?? null;
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
      prefix={<SwapOutlined aria-hidden style={{ color: '#64748b' }} />}
      showSearch={empresas.length > 8}
      optionFilterProp="label"
    />
  );

  const menuNavegacao = (
    <Menu
      className="erp-menu"
      theme="dark"
      mode="inline"
      items={itens}
      selectedKeys={[seleccionado]}
      defaultOpenKeys={abertos}
      onClick={(i) => navegar(i.key)}
      style={{ borderInlineEnd: 0, background: 'transparent' }}
    />
  );

  const itensUtilizador: MenuProps['items'] = [
    // em telemóvel o nome não cabe na barra: aparece no topo do menu do utilizador
    ...(ecra.md ? [] : [{ key: 'quem', icon: <UserOutlined />, label: nomeUtilizador, disabled: true }, { type: 'divider' as const }]),
    { key: 'sair', icon: <LogoutOutlined />, label: 'Terminar sessão', onClick: () => void sair() },
  ];

  return (
    <Layout style={{ minHeight: '100vh' }}>
      {!ecra.pequeno && (
        <Layout.Sider className="erp-sider" collapsible collapsed={recolhido} trigger={null} width={LARGURA_MENU} theme="dark">
          <div
            className="erp-sider-topo"
            style={{
              height: ALTURA_CABECALHO,
              display: 'flex',
              alignItems: 'center',
              justifyContent: recolhido ? 'center' : 'flex-start',
              padding: recolhido ? '0 8px' : '0 20px',
            }}
          >
            <MarcaEmpresa soIcone={recolhido} tamanho={recolhido ? 34 : 40} comProduto escuro />
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
          title={<MarcaEmpresa tamanho={34} comProduto escuro />}
          destroyOnHidden
          aria-label="Menu principal"
        >
          <div className="erp-gaveta-empresa">
            <span className="erp-gaveta-empresa-rotulo">Empresa activa</span>
            {seletorEmpresa('100%')}
          </div>
          {menuNavegacao}
        </Drawer>
      )}

      <Layout className="erp-layout-coluna">
        <Layout.Header
          className="erp-cabecalho"
          style={{
            background: '#ffffff',
            padding: ecra.lg ? '0 24px' : ecra.md ? '0 16px' : '0 8px 0 8px',
            height: ecra.md ? ALTURA_CABECALHO : ALTURA_CABECALHO_TELEMOVEL,
            lineHeight: 'normal',
            borderBottom: '1px solid #e2e8f0',
          }}
        >
          <Flex justify="space-between" align="center" gap={ecra.md ? 12 : 8} style={{ height: '100%' }}>
            <Flex align="center" gap={ecra.md ? 14 : 8} style={{ minWidth: 0, flex: '1 1 auto' }}>
              {ecra.pequeno ? (
                <Button className="erp-botao-menu" icon={<MenuOutlined />} aria-label="Abrir menu" aria-expanded={gavetaAberta} onClick={() => setGavetaAberta(true)} />
              ) : (
                <Button
                  className="erp-botao-menu"
                  icon={recolhido ? <MenuUnfoldOutlined /> : <MenuFoldOutlined />}
                  aria-label="Recolher menu"
                  aria-expanded={!recolhido}
                  title={recolhido ? 'Expandir menu' : 'Recolher menu'}
                  onClick={() => setRecolhido(!recolhido)}
                />
              )}
              {ecra.pequeno ? (
                <div style={{ minWidth: 0, flex: '1 1 auto' }}>
                  <MarcaEmpresa tamanho={ecra.md ? 34 : 28} />
                </div>
              ) : (
                <div className="erp-cabecalho-empresa">
                  <span className="erp-cabecalho-empresa-nome" title={nomeEmpresa}>
                    {nomeEmpresa}
                  </span>
                  {nif && <span className="erp-cracha-nif">NIF: {nif}</span>}
                </div>
              )}
            </Flex>
            <Flex align="center" gap={ecra.md ? 12 : 4} style={{ flex: 'none', minWidth: 0 }}>
              {!ecra.pequeno && seletorEmpresa(ecra.xl ? 300 : 230)}
              <Dropdown menu={{ items: itensUtilizador }} trigger={ecra.pequeno ? ['click'] : ['hover']} placement="bottomRight">
                <div className={ecra.md ? 'erp-utilizador' : undefined} style={{ cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 10 }} role="button" tabIndex={0} aria-label="Menu do utilizador">
                  {ecra.md && (
                    <div className="erp-utilizador-textos">
                      <span className="erp-utilizador-nome">{nomeUtilizador}</span>
                      {papel && <span className="erp-utilizador-papel">{papel}</span>}
                    </div>
                  )}
                  <Avatar icon={<UserOutlined />} style={{ flex: 'none', background: '#eff6ff', color: '#1d4ed8' }} />
                </div>
              </Dropdown>
              {ecra.md && (
                <Tooltip title="Sair do sistema">
                  <Button className="erp-botao-sair" icon={<LogoutOutlined />} aria-label="Sair do sistema" onClick={() => void sair()} />
                </Tooltip>
              )}
            </Flex>
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
