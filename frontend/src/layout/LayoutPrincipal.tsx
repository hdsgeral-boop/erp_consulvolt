import { Avatar, Button, Drawer, Dropdown, Flex, Layout, Menu, Select, Spin, Tooltip, type MenuProps } from 'antd';
import {
  ApiOutlined,
  ArrowLeftOutlined,
  LogoutOutlined,
  MenuFoldOutlined,
  MenuOutlined,
  MenuUnfoldOutlined,
  MobileOutlined,
  RobotOutlined,
  StarFilled,
  StarOutlined,
  SwapOutlined,
  UserOutlined,
} from '@ant-design/icons';
import { lazy, Suspense, useEffect, useMemo, useState } from 'react';
import { Outlet, useLocation, useNavigate, useNavigationType } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { useIdentidade } from '@/sessao/identidade';
import { useEcra } from '@/componentes/responsivo/useEcra';
import { useModoResponsivo } from '@/componentes/responsivo/modoResponsivo';
import { larguraGaveta } from '@/componentes/responsivo/utilitarios';
import { AjudaProvider, BotaoAjuda } from '@/componentes/ajuda/PainelAjuda';
import { OperacoesProvider } from '@/componentes/operacoes/Operacoes';
import { useFavoritos } from '@/componentes/preferencias/favoritos';
import { notificarErro } from '@/utilitarios/erros';
import { construirItensMenu, estadoMenu } from './itensMenu';
import { MarcaEmpresa } from './MarcaEmpresa';
import { rotuloPapel } from './marca';
import { BotaoPesquisa, PesquisaEcras } from './PesquisaEcras';

const PainelPowerBI = lazy(() => import('@/modulos/geral/integracoes/PainelPowerBI').then((m) => ({ default: m.PainelPowerBI })));
const AssistenteIA = lazy(() => import('@/modulos/geral/integracoes/AssistenteIA').then((m) => ({ default: m.AssistenteIA })));

const LARGURA_MENU = 280;
const ALTURA_CABECALHO = 72;
const ALTURA_CABECALHO_TELEMOVEL = 56;
export const PREFIXO_FAVORITO = 'fav:';

/**
 * Layout da aplicação: menu pelas permissões (GET /sistema/menu), identidade da empresa activa (logótipo + nome),
 * selector de empresa e menu do utilizador.
 *
 * Aspecto do sistema anterior (index_arrumado.html + css/styles.css): menu lateral escuro (#121212) de 280 px com a
 * marca no topo, item activo com barra azul à esquerda e sub-níveis com guia tracejada; barra superior branca com o
 * botão quadrado do menu, o nome da empresa e o crachá do NIF à esquerda, «Ajuda», «Modo responsivo», «Trocar empresa»,
 * o utilizador e o botão vermelho de sair à direita; botão flutuante «Voltar» (ronda 2, botões do legado em falta).
 *
 * Funcionalidades transversais (ronda 2, R2-G4): ajuda contextual (botão «Ajuda» e F1), pesquisa de ecrãs (Ctrl+K),
 * favoritos (estrela na barra, grupo «Favoritos» no menu e no Início — guardados no servidor), modo responsivo,
 * gestor de operações em segundo plano, Power BI (config_backup) e assistente IA (no menu do utilizador).
 *
 * Responsivo:
 *  - ≥ 992 px (lg): menu lateral fixo e recolhível (botão «Recolher menu»), selector de empresa na barra superior;
 *  - < 992 px: o menu passa a uma gaveta escura aberta pelo botão «Abrir menu» (fecha ao navegar), com o selector de
 *    empresa; a barra superior mostra o logótipo e o nome da empresa; «Ajuda» e «Procurar» só com o ícone;
 *  - < 768 px: barra compacta (56 px) e menu do utilizador só com o avatar (o nome, a estrela do ecrã e o modo
 *    responsivo passam para dentro do menu).
 */
export function LayoutPrincipal() {
  return (
    <OperacoesProvider>
      <AjudaProvider>
        <Estrutura />
      </AjudaProvider>
    </OperacoesProvider>
  );
}

function Estrutura() {
  const { menu, empresa, empresas, utilizador, escolherEmpresa, sair, pode } = useSessao();
  const identidade = useIdentidade();
  const navegar = useNavigate();
  const local = useLocation();
  const tipoNavegacao = useNavigationType();
  const ecra = useEcra();
  const [modoResponsivo, definirModoResponsivo] = useModoResponsivo();
  const { favoritos, eFavorito, alternar } = useFavoritos();
  const [recolhido, setRecolhido] = useState(false);
  const [gavetaAberta, setGavetaAberta] = useState(false);
  const [pesquisa, setPesquisa] = useState(false);
  const [painel, setPainel] = useState<'powerbi' | 'ia' | null>(null);
  const [historico, setHistorico] = useState<string[]>([]);

  const itens = useMemo(() => {
    const base = construirItensMenu(menu);
    if (!favoritos.length) return base;
    const grupo = {
      key: 'grupo:favoritos',
      icon: <StarFilled style={{ color: '#f59e0b' }} />,
      label: 'Favoritos',
      children: favoritos.map((f) => ({ key: `${PREFIXO_FAVORITO}${f.rota}`, label: f.nome, title: `${f.nome} (${f.nomeModulo})` })),
    };
    return [base[0], grupo, ...base.slice(1)];
  }, [menu, favoritos]);
  const { seleccionado, abertos } = useMemo(() => {
    const e = estadoMenu(local.pathname);
    // ecrã filho (ex. um mapa dentro de Relatórios): abre também o grupo
    const [, , moduloId, ecraId] = local.pathname.split('/');
    const pai = menu.find((m) => m.id === moduloId)?.ecras.find((x) => x.id === ecraId)?.pai;
    return pai ? { ...e, abertos: [...e.abertos, `grupo:/m/${moduloId}/${pai}`] } : e;
  }, [local.pathname, menu]);
  const [, , moduloActual, ecraActual] = local.pathname.split('/');
  const noEcra = local.pathname.startsWith('/m/') && !!moduloActual && !!ecraActual;
  const favoritoActual = noEcra && eFavorito(moduloActual, ecraActual);

  // a gaveta fecha ao navegar e quando o ecrã passa a largo
  useEffect(() => setGavetaAberta(false), [local.pathname]);
  useEffect(() => {
    if (!ecra.pequeno) setGavetaAberta(false);
  }, [ecra.pequeno]);

  // histórico de navegação dentro da aplicação, para o botão flutuante «Voltar» (legado navigationBack)
  useEffect(() => {
    setHistorico((h) => {
      if (tipoNavegacao === 'POP' && h.length > 1 && h[h.length - 2] === local.pathname) return h.slice(0, -1);
      return h[h.length - 1] === local.pathname ? h : [...h, local.pathname].slice(-50);
    });
  }, [local.pathname, tipoNavegacao]);

  // Ctrl+K / ⌘K: pesquisa de ecrãs
  useEffect(() => {
    const tecla = (ev: KeyboardEvent) => {
      if ((ev.ctrlKey || ev.metaKey) && !ev.altKey && ev.key.toLowerCase() === 'k') {
        ev.preventDefault();
        setPesquisa(true);
      }
    };
    window.addEventListener('keydown', tecla);
    return () => window.removeEventListener('keydown', tecla);
  }, []);

  // modo responsivo: classe no <body> para o CSS (grelhas empilhadas, largura de telemóvel)
  useEffect(() => {
    document.body.classList.toggle('erp-modo-responsivo', modoResponsivo);
    return () => document.body.classList.remove('erp-modo-responsivo');
  }, [modoResponsivo]);

  const nomeUtilizador = utilizador?.nome_completo || utilizador?.nome_utilizador || '';
  const papel = rotuloPapel(utilizador?.papel) ?? utilizador?.nome_utilizador ?? null;
  const nomeEmpresa = identidade.data?.nome || empresa?.nome || '';
  const nif = identidade.data?.nif ?? empresa?.nif ?? null;
  const podeBI = !!pode?.('config_backup');
  const podeIA = !!pode?.('lancamentos_post', 'aux_gerir', 'config_empresas_gerir');
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
      selectedKeys={[seleccionado, `${PREFIXO_FAVORITO}${seleccionado}`]}
      defaultOpenKeys={abertos}
      onClick={(i) => navegar(i.key.startsWith(PREFIXO_FAVORITO) ? i.key.slice(PREFIXO_FAVORITO.length) : i.key)}
      style={{ borderInlineEnd: 0, background: 'transparent' }}
    />
  );

  const textoFavorito = favoritoActual ? 'Retirar dos favoritos' : 'Adicionar aos favoritos';
  const itensUtilizador: MenuProps['items'] = [
    // em telemóvel o nome não cabe na barra: aparece no topo do menu do utilizador
    ...(ecra.md ? [] : [{ key: 'quem', icon: <UserOutlined />, label: nomeUtilizador, disabled: true }, { type: 'divider' as const }]),
    ...(!ecra.md && noEcra
      ? [{ key: 'favorito', icon: favoritoActual ? <StarFilled style={{ color: '#f59e0b' }} /> : <StarOutlined />, label: textoFavorito, onClick: () => alternar(moduloActual, ecraActual) }]
      : []),
    ...(!ecra.lg || modoResponsivo
      ? [{ key: 'responsivo', icon: <MobileOutlined />, label: modoResponsivo ? 'Sair do modo responsivo' : 'Modo responsivo', onClick: () => definirModoResponsivo(!modoResponsivo) }]
      : []),
    ...(podeIA ? [{ key: 'ia', icon: <RobotOutlined />, label: 'Assistente IA', onClick: () => setPainel('ia') }] : []),
    ...(podeBI ? [{ key: 'powerbi', icon: <ApiOutlined />, label: 'Power BI (feed OData)', onClick: () => setPainel('powerbi') }] : []),
    { type: 'divider' as const },
    { key: 'sair', icon: <LogoutOutlined />, label: 'Terminar sessão', onClick: () => void sair() },
  ];

  return (
    <Layout style={{ minHeight: '100vh' }} className={modoResponsivo ? 'erp-layout-responsivo' : undefined}>
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
          <Flex justify="space-between" align="center" gap={ecra.md ? 12 : 6} style={{ height: '100%' }}>
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
            <Flex align="center" gap={ecra.md ? 8 : 4} style={{ flex: 'none', minWidth: 0 }}>
              <BotaoAjuda compacto={!ecra.xl} />
              <BotaoPesquisa compacto={!ecra.xl} aoAbrir={() => setPesquisa(true)} />
              {ecra.md && noEcra && (
                <Tooltip title={textoFavorito}>
                  <Button
                    className="erp-botao-favorito"
                    icon={favoritoActual ? <StarFilled style={{ color: '#f59e0b' }} /> : <StarOutlined />}
                    aria-label={textoFavorito}
                    aria-pressed={favoritoActual}
                    onClick={() => alternar(moduloActual, ecraActual)}
                  />
                </Tooltip>
              )}
              {ecra.lg && !modoResponsivo && (
                <Tooltip title="Ver os ecrãs como num telemóvel">
                  <Button className="erp-botao-responsivo" icon={<MobileOutlined />} aria-label="Modo responsivo" aria-pressed={false} onClick={() => definirModoResponsivo(true)}>
                    {ecra.xxl ? 'Modo responsivo' : null}
                  </Button>
                </Tooltip>
              )}
              {!ecra.pequeno && seletorEmpresa(ecra.xl ? 280 : 210)}
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
        {modoResponsivo && (
          <div className="erp-aviso-responsivo no-print" role="status">
            <MobileOutlined aria-hidden /> Modo responsivo activo — os ecrãs aparecem como num telemóvel.
            <Button size="small" type="link" onClick={() => definirModoResponsivo(false)}>Sair do modo responsivo</Button>
          </div>
        )}
        <Layout.Content className="erp-conteudo">
          <Suspense fallback={<Spin style={{ display: 'block', marginTop: 80 }} />}>
            <Outlet />
          </Suspense>
        </Layout.Content>
      </Layout>

      {historico.length > 1 && local.pathname !== '/' && (
        <Button className="erp-botao-voltar no-print" type="primary" shape="round" icon={<ArrowLeftOutlined />} onClick={() => navegar(-1)} title="Voltar ao ecrã anterior">
          Voltar
        </Button>
      )}
      <PesquisaEcras aberta={pesquisa} aoFechar={() => setPesquisa(false)} />
      <Suspense fallback={null}>
        {painel === 'powerbi' && <PainelPowerBI aberto aoFechar={() => setPainel(null)} />}
        {painel === 'ia' && <AssistenteIA aberto aoFechar={() => setPainel(null)} />}
      </Suspense>
    </Layout>
  );
}
