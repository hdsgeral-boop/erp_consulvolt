import { Avatar, Button, Drawer, Dropdown, Flex, Layout, Menu, Select, Space, Spin, Tooltip, type MenuProps } from 'antd';
import {
  ApiOutlined,
  ArrowLeftOutlined,
  CheckOutlined,
  DownOutlined,
  LogoutOutlined,
  MenuFoldOutlined,
  MenuOutlined,
  MenuUnfoldOutlined,
  RobotOutlined,
  StarFilled,
  StarOutlined,
  SwapOutlined,
  UserOutlined,
} from '@ant-design/icons';
import { lazy, Suspense, useEffect, useMemo, useState, type ReactNode } from 'react';
import { Outlet, useLocation, useNavigate, useNavigationType } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { useIdentidade } from '@/sessao/identidade';
import { useEcra } from '@/componentes/responsivo/useEcra';
import { larguraGaveta } from '@/componentes/responsivo/utilitarios';
import { AjudaProvider, BotaoAjuda } from '@/componentes/ajuda/PainelAjuda';
import { OperacoesProvider } from '@/componentes/operacoes/Operacoes';
import { useFavoritos } from '@/componentes/preferencias/favoritos';
import { notificarErro } from '@/utilitarios/erros';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { CHAVE_MENU_GERAL, construirMenuLateral, estadoMenu } from './itensMenu';
import { MarcaEmpresa } from './MarcaEmpresa';
import { classeFundoModulo } from './fundoModulo';
import { rotuloPapel } from './marca';
import { BotaoPesquisa, PesquisaEcras } from './PesquisaEcras';

const PainelPowerBI = lazy(() => import('@/modulos/geral/integracoes/PainelPowerBI').then((m) => ({ default: m.PainelPowerBI })));
const AssistenteIA = lazy(() => import('@/modulos/geral/integracoes/AssistenteIA').then((m) => ({ default: m.AssistenteIA })));

const LARGURA_MENU = 280;
const ALTURA_CABECALHO = 72;
const ALTURA_CABECALHO_TELEMOVEL = 56;
export const PREFIXO_FAVORITO = 'fav:';
type ItemMenuLateral = NonNullable<MenuProps['items']>[number];

/**
 * Layout da aplicação: menu pelas permissões (GET /sistema/menu), identidade da empresa activa (logótipo + nome),
 * selector de empresa e menu do utilizador.
 *
 * Aspecto do sistema anterior (index_arrumado.html + css/styles.css): menu lateral escuro (#121212) de 280 px com a
 * marca no topo, item activo com barra azul à esquerda e sub-níveis com guia tracejada; barra superior branca com o
 * botão quadrado do menu, o nome da empresa e o crachá do NIF à esquerda, «Ajuda», «Trocar empresa»,
 * o utilizador e o botão vermelho de sair à direita; botão flutuante «Voltar» (ronda 2, botões do legado em falta).
 *
 * Funcionalidades transversais (ronda 2, R2-G4): ajuda contextual (botão «Ajuda» e F1), pesquisa de ecrãs (Ctrl+K),
 * favoritos (estrela na barra, grupo «Favoritos» no menu e no Início — guardados no servidor),
 * gestor de operações em segundo plano, Power BI (config_backup) e assistente IA (no menu do utilizador).
 *
 * Menu (VAGA 1B, relatório de verificação visual 0.1): estrutura do legado em menuLegado.ts — itens gerais soltos no topo,
 * Favoritos, «Menu Geral» (módulos e sub-grupos com os rótulos do legado) e «Sistema». Barra superior (0.2): «Ajuda» azul
 * cheio, «⇄ Trocar empresa» (volta à escolha de empresa; a seta ao lado,
 * «Empresa activa», troca directamente), utilizador com nome e papel sem avatar e sair a vermelho.
 *
 * Responsivo:
 *  - ≥ 992 px (lg): menu lateral fixo e recolhível (botão «Recolher menu»), «Trocar empresa» na barra superior;
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
  const { menu, empresa, empresas, utilizador, escolherEmpresa, largarEmpresa, sair, pode } = useSessao();
  const identidade = useIdentidade();
  const navegar = useNavigate();
  const local = useLocation();
  const tipoNavegacao = useNavigationType();
  const ecra = useEcra();
  const { favoritos, eFavorito, alternar } = useFavoritos();
  const [recolhido, setRecolhido] = useState(false);
  const [gavetaAberta, setGavetaAberta] = useState(false);
  const [pesquisa, setPesquisa] = useState(false);
  const [painel, setPainel] = useState<'powerbi' | 'ia' | null>(null);
  const [historico, setHistorico] = useState<string[]>([]);

  const lateral = useMemo(() => construirMenuLateral(menu), [menu]);
  const itens = useMemo(() => {
    // legado (app_v2.js:11119): «⋮» em cada ecrã do menu (excepto Início e Dashboard) abre o menu de contexto com os favoritos
    const comPontos = (lista: ItemMenuLateral[]): ItemMenuLateral[] =>
      lista.map((it) => {
        if (!it || typeof it !== 'object') return it;
        const x = it as { key?: string; label?: ReactNode; children?: ItemMenuLateral[] };
        if (x.children) return { ...it, children: comPontos(x.children) } as ItemMenuLateral;
        const [, m, mod, ecraId] = String(x.key ?? '').split('?')[0].split('/');
        if (m !== 'm' || !mod || !ecraId || (mod === 'geral' && ecraId === 'dashboard')) return it;
        const fav = eFavorito(mod, ecraId);
        return {
          ...it,
          label: (
            <span className="erp-menu-rotulo">
              <span className="erp-menu-rotulo-texto">{x.label}</span>
              <Dropdown
                trigger={['click']}
                placement="bottomRight"
                menu={{
                  items: [{ key: 'fav', icon: fav ? <StarFilled style={{ color: '#f59e0b' }} /> : <StarOutlined />, label: fav ? 'Remover dos Favoritos' : 'Adicionar aos Favoritos' }],
                  onClick: (e) => {
                    e.domEvent.stopPropagation();
                    alternar(mod, ecraId);
                  },
                }}
              >
                <span
                  role="button"
                  tabIndex={0}
                  className="erp-menu-pontos"
                  aria-label="Mais opções"
                  title="Mais opções"
                  onClick={(e) => e.stopPropagation()}
                  onKeyDown={(e) => e.stopPropagation()}
                >
                  <IconeFa nome="ellipsis-v" />
                </span>
              </Dropdown>
            </span>
          ),
        } as ItemMenuLateral;
      });
    const base = comPontos(lateral.itens as ItemMenuLateral[]);
    if (!favoritos.length) return base;
    const grupo = {
      key: 'grupo:favoritos',
      icon: <StarFilled style={{ color: '#f59e0b' }} />,
      label: 'Favoritos',
      children: favoritos.map((f) => ({ key: `${PREFIXO_FAVORITO}${f.rota}`, label: f.nome, title: `${f.nome} (${f.nomeModulo})` })),
    };
    // como no legado: Início, Dashboard Global, Relatórios de Gestão, Fluxo de Processos, Favoritos, Menu Geral, Sistema
    const i = base.findIndex((x) => x?.key === CHAVE_MENU_GERAL);
    const pos = i < 0 ? base.length : i;
    return [...base.slice(0, pos), grupo, ...base.slice(pos)];
  }, [lateral, favoritos]);
  const { seleccionado, abertos } = useMemo(() => estadoMenu(local.pathname, local.search, lateral, menu), [local.pathname, local.search, lateral, menu]);
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
  // legado: «⇄ Trocar empresa» volta sempre à escolha de empresa (CompanySwitch.trocar)
  const voltarEscolhaEmpresa = () => {
    navegar('/', { replace: true });
    largarEmpresa();
  };
  const botaoTrocarEmpresa = (
    <Space.Compact className="erp-trocar-empresa">
      <Button icon={<IconeFa nome="exchange-alt" />} onClick={voltarEscolhaEmpresa} title="Trocar de empresa">
        Trocar empresa
      </Button>
      {empresas.length > 1 && (
        <Dropdown
          trigger={['click']}
          placement="bottomRight"
          menu={{
            items: empresas.map((e) => ({ key: String(e.id), label: e.nome, icon: e.id === empresa?.id ? <CheckOutlined /> : <span style={{ display: 'inline-block', width: 14 }} /> })),
            selectedKeys: empresa ? [String(empresa.id)] : [],
            onClick: (i) => {
              if (Number(i.key) !== empresa?.id) void trocarEmpresa(Number(i.key));
            },
            style: { maxHeight: 360, overflowY: 'auto' },
          }}
        >
          <Button icon={<DownOutlined />} aria-label="Empresa activa" title={`Empresa activa: ${nomeEmpresa}`} />
        </Dropdown>
      )}
    </Space.Compact>
  );

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
      inlineIndent={16}
      onClick={(i) => navegar(i.key.startsWith(PREFIXO_FAVORITO) ? i.key.slice(PREFIXO_FAVORITO.length) : i.key)}
      style={{ borderInlineEnd: 0, background: 'transparent' }}
    />
  );

  const textoFavorito = favoritoActual ? 'Retirar dos favoritos' : 'Adicionar aos favoritos';
  const itensUtilizador: MenuProps['items'] = [
    // em telemóvel o nome não cabe na barra: aparece no topo do menu do utilizador
    ...(ecra.md ? [] : [{ key: 'quem', icon: <UserOutlined />, label: nomeUtilizador, disabled: true }, { type: 'divider' as const }]),
    { key: 'dados-pessoais', icon: <IconeFa nome="user-edit" />, label: 'Gerir Dados Pessoais', onClick: () => navegar('/dados-pessoais') },
    ...(!ecra.md && noEcra
      ? [{ key: 'favorito', icon: favoritoActual ? <StarFilled style={{ color: '#f59e0b' }} /> : <StarOutlined />, label: textoFavorito, onClick: () => alternar(moduloActual, ecraActual) }]
      : []),
    ...(podeIA ? [{ key: 'ia', icon: <RobotOutlined />, label: 'Assistente IA', onClick: () => setPainel('ia') }] : []),
    ...(podeBI ? [{ key: 'powerbi', icon: <ApiOutlined />, label: 'Power BI (feed OData)', onClick: () => setPainel('powerbi') }] : []),
    { type: 'divider' as const },
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
            <Button type="link" size="small" icon={<IconeFa nome="exchange-alt" />} onClick={voltarEscolhaEmpresa} style={{ paddingInline: 0, marginTop: 6, color: '#93c5fd' }}>
              Trocar empresa
            </Button>
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
              {!ecra.pequeno && botaoTrocarEmpresa}
              {/* nome + papel e o botão ▾ que mostra as opções (Gerir Dados Pessoais … Terminar sessão); em telemóvel, o avatar */}
              <Dropdown menu={{ items: itensUtilizador }} trigger={['click']} placement="bottomRight">
                <button type="button" className={ecra.md ? 'erp-utilizador erp-utilizador-botao' : 'erp-utilizador-botao'} aria-label="Opções do utilizador" aria-haspopup="menu">
                  {ecra.md && (
                    <span className="erp-utilizador-textos">
                      <span className="erp-utilizador-nome">{nomeUtilizador}</span>
                      {papel && <span className="erp-utilizador-papel">{papel}</span>}
                    </span>
                  )}
                  {!ecra.md && <Avatar icon={<UserOutlined />} style={{ flex: 'none', background: '#eff6ff', color: '#1d4ed8' }} />}
                  <span className="erp-utilizador-seta" aria-hidden>
                    <IconeFa nome="chevron-down" />
                  </span>
                </button>
              </Dropdown>
            </Flex>
          </Flex>
        </Layout.Header>
        <Layout.Content className={`erp-conteudo ${classeFundoModulo(local.pathname)}`}>
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
