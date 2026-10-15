import { Avatar, Button, ConfigProvider, Drawer, Dropdown, Flex, Input, Layout, Menu, Select, Space, Spin, Tooltip, type InputRef, type MenuProps } from 'antd';
import {
  ApiOutlined,
  ArrowLeftOutlined,
  CheckOutlined,
  DownOutlined,
  LayoutOutlined,
  LogoutOutlined,
  MenuFoldOutlined,
  MenuOutlined,
  MenuUnfoldOutlined,
  MoonOutlined,
  RobotOutlined,
  SearchOutlined,
  StarFilled,
  StarOutlined,
  SunOutlined,
  SwapOutlined,
  UserOutlined,
} from '@ant-design/icons';
import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent as EventoTeclado, type ReactNode } from 'react';
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
import { ROTULOS_MENU, useAspecto, type AspectoMenu } from './aspecto';
import { filtrarMenu, moverFoco, posicaoRealce, semIcones, textoContador, type ItemMenu } from './filtroMenu';
import { TEMA_NOCTURNO } from './temaNocturno';
import './telaNocturna.css';

const PainelPowerBI = lazy(() => import('@/modulos/geral/integracoes/PainelPowerBI').then((m) => ({ default: m.PainelPowerBI })));
const AssistenteIA = lazy(() => import('@/modulos/geral/integracoes/AssistenteIA').then((m) => ({ default: m.AssistenteIA })));

const LARGURA_MENU = 280;
const ALTURA_CABECALHO = 72;
const ALTURA_CABECALHO_TELEMOVEL = 56;
export const PREFIXO_FAVORITO = 'fav:';
type ItemMenuLateral = ItemMenu;

/** Rótulo com o pedaço encontrado pela busca do menu realçado (<mark>). */
function realcar(rotulo: string, termo: string): ReactNode {
  const p = posicaoRealce(rotulo, termo);
  if (!p) return rotulo;
  return (
    <>
      {rotulo.slice(0, p.inicio)}
      <mark className="erp-menu-realce">{rotulo.slice(p.inicio, p.fim)}</mark>
      {rotulo.slice(p.fim)}
    </>
  );
}

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
 * Versão Actual (Fase H, ADR-071):
 *  - busca no menu (js/busca_menu.js): caixa no topo da árvore que filtra em linha, sem acentos, pelo rótulo e pelo
 *    caminho dos grupos; setas e Enter escolhem o resultado, Esc limpa, contador aria-live; só filtra o que o menu já
 *    mostra (nunca revela ecrãs sem permissão) e, ao limpar, repõe os grupos que estavam abertos;
 *  - aspecto do menu (js/aspecto_menu.js): completo, só símbolos (nome ao passar o rato) ou só nomes — preferência do
 *    utilizador guardada no servidor; o botão «Recolher menu» alterna entre «só símbolos» e o aspecto anterior;
 *  - tela nocturna (js/tela_noturna.js): tema escuro por utilizador, botão na barra superior e Ctrl+Shift+N, aplicado
 *    antes da primeira pintura (layout/aspecto.ts, temaNocturno.ts, telaNocturna.css).
 *
 * Menu (VAGA 1B, relatório de verificação visual 0.1): estrutura do legado em menuLegado.ts — itens gerais soltos no topo,
 * Favoritos, «Menu Geral» (módulos e sub-grupos com os rótulos do legado) e «Sistema». Barra superior (0.2): «Ajuda» azul
 * cheio, «⇄ Trocar empresa» (volta à escolha de empresa; a seta ao lado,
 * «Empresa activa», troca directamente), utilizador com nome e papel sem avatar e sair a vermelho.
 *
 * Responsivo:
 *  - ≥ 992 px (lg): menu lateral fixo e recolhível (botão «Recolher menu»), «Trocar empresa» na barra superior;
 *  - < 992 px: o menu passa a uma gaveta escura aberta pelo botão «Abrir menu» (fecha ao navegar), com o selector de
 *    empresa e a busca; a barra superior mostra o logótipo e o nome da empresa; «Ajuda» e «Procurar» só com o ícone;
 *  - < 768 px: barra compacta (56 px) e menu do utilizador só com o avatar (o nome, a estrela do ecrã e a tela nocturna
 *    passam para dentro do menu).
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
  const { aspecto, definir } = useAspecto(utilizador?.id ?? utilizador?.nome_utilizador ?? null);
  const escuro = aspecto.tema === 'escuro';
  const recolhido = aspecto.menu === 'icones';
  const colapsado = recolhido && !ecra.pequeno;
  const [gavetaAberta, setGavetaAberta] = useState(false);
  const [pesquisa, setPesquisa] = useState(false);
  const [painel, setPainel] = useState<'powerbi' | 'ia' | null>(null);
  const [historico, setHistorico] = useState<string[]>([]);
  const [busca, setBusca] = useState('');
  const [focado, setFocado] = useState(-1);
  const campoBusca = useRef<InputRef>(null);
  const aBuscar = busca.trim() !== '';

  // «Recolher menu» alterna entre «só símbolos» e o último aspecto expandido (completo ou só nomes)
  const anteriorExpandido = useRef<AspectoMenu>(aspecto.menu === 'icones' ? 'completo' : aspecto.menu);
  useEffect(() => {
    if (aspecto.menu !== 'icones') anteriorExpandido.current = aspecto.menu;
  }, [aspecto.menu]);
  const alternarRecolhido = () => definir({ menu: recolhido ? anteriorExpandido.current : 'icones' });
  const alternarTema = useCallback(() => definir({ tema: escuro ? 'claro' : 'escuro' }), [definir, escuro]);

  const lateral = useMemo(() => construirMenuLateral(menu), [menu]);
  // menu com o grupo «Favoritos» (como no legado: Início, Dashboard Global, Relatórios de Gestão, Fluxo de Processos,
  // Favoritos, Menu Geral, Sistema)
  const base = useMemo(() => {
    const lista = lateral.itens as ItemMenuLateral[];
    if (!favoritos.length) return lista;
    const grupo = {
      key: 'grupo:favoritos',
      icon: <StarFilled style={{ color: '#f59e0b' }} />,
      label: 'Favoritos',
      children: favoritos.map((f) => ({ key: `${PREFIXO_FAVORITO}${f.rota}`, label: f.nome, title: `${f.nome} (${f.nomeModulo})` })),
    };
    const i = lista.findIndex((x) => x?.key === CHAVE_MENU_GERAL);
    const pos = i < 0 ? lista.length : i;
    return [...lista.slice(0, pos), grupo, ...lista.slice(pos)];
  }, [lateral, favoritos]);
  const filtro = useMemo(() => filtrarMenu(base, busca), [base, busca]);
  const chaveFocada = aBuscar && focado >= 0 ? (filtro.folhas[focado]?.key ?? null) : null;

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
    let lista = aBuscar ? filtrarMenu(base, busca, chaveFocada, (r) => realcar(r, busca)).itens : base;
    if (aspecto.menu === 'nomes' && !ecra.pequeno) lista = semIcones(lista);
    return comPontos(lista);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [base, busca, aBuscar, chaveFocada, aspecto.menu, ecra.pequeno, favoritos]);
  const { seleccionado, abertos } = useMemo(() => estadoMenu(local.pathname, local.search, lateral, menu), [local.pathname, local.search, lateral, menu]);
  const [, , moduloActual, ecraActual] = local.pathname.split('/');
  const noEcra = local.pathname.startsWith('/m/') && !!moduloActual && !!ecraActual;
  const favoritoActual = noEcra && eFavorito(moduloActual, ecraActual);

  // grupos abertos: os do utilizador (repostos ao limpar a busca), os da busca e, com o menu recolhido, os sub-menus flutuantes
  const [abertosMenu, setAbertosMenu] = useState<string[]>(abertos);
  const [abertosFlutuantes, setAbertosFlutuantes] = useState<string[]>([]);
  useEffect(() => {
    setAbertosMenu((a) => {
      const novos = abertos.filter((k) => !a.includes(k));
      return novos.length ? [...a, ...novos] : a;
    });
  }, [abertos]);
  useEffect(() => setAbertosFlutuantes([]), [colapsado]);
  const gruposAbertos = aBuscar ? filtro.abertos : colapsado ? abertosFlutuantes : abertosMenu;

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

  // Ctrl+K / ⌘K: pesquisa de ecrãs; Ctrl+Shift+N (legado) ou Alt+Shift+N: tela nocturna
  useEffect(() => {
    const tecla = (ev: KeyboardEvent) => {
      if ((ev.ctrlKey || ev.metaKey) && !ev.altKey && !ev.shiftKey && ev.key.toLowerCase() === 'k') {
        ev.preventDefault();
        setPesquisa(true);
      } else if (((ev.ctrlKey || ev.metaKey) && ev.shiftKey && !ev.altKey && ev.key.toLowerCase() === 'n')
        // Alt+Shift+N: no Chrome o Ctrl+Shift+N abre a janela anónima e não chega à página
        || (ev.altKey && ev.shiftKey && !ev.ctrlKey && !ev.metaKey && (ev.code === 'KeyN' || ev.key.toLowerCase() === 'n'))) {
        ev.preventDefault();
        alternarTema();
      }
    };
    window.addEventListener('keydown', tecla);
    return () => window.removeEventListener('keydown', tecla);
  }, [alternarTema]);

  // o resultado escolhido com as setas fica à vista
  useEffect(() => {
    if (!chaveFocada) return;
    const el = document.querySelector('.erp-menu .erp-menu-focado') as HTMLElement | null;
    el?.scrollIntoView?.({ block: 'nearest' });
  }, [chaveFocada]);

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
  const irPara = (chave: string) => navegar(chave.startsWith(PREFIXO_FAVORITO) ? chave.slice(PREFIXO_FAVORITO.length) : chave);
  const limparBusca = (focar = true) => {
    setBusca('');
    setFocado(-1);
    if (focar) campoBusca.current?.focus();
  };
  const teclaBusca = (e: EventoTeclado<HTMLInputElement>) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      if (busca) limparBusca();
      else campoBusca.current?.blur();
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      setFocado((f) => moverFoco(f, e.key === 'ArrowDown' ? 1 : -1, filtro.folhas.length));
    } else if (e.key === 'Enter' && aBuscar) {
      e.preventDefault();
      const alvo = filtro.folhas[focado >= 0 ? focado : 0];
      if (!alvo) return;
      limparBusca(false);
      campoBusca.current?.blur();
      irPara(alvo.key);
    }
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

  // busca de operações na árvore (não aparece com o menu só em símbolos)
  const caixaBusca = !colapsado && (
    <div className="erp-menu-busca" role="search">
      <Input
        ref={campoBusca}
        size="small"
        allowClear
        prefix={<SearchOutlined aria-hidden />}
        placeholder="Procurar no menu"
        aria-label="Procurar no menu"
        aria-describedby="erp-menu-busca-contador"
        value={busca}
        maxLength={80}
        onChange={(e) => {
          setBusca(e.target.value);
          setFocado(-1);
        }}
        onKeyDown={teclaBusca}
      />
      <span id="erp-menu-busca-contador" className={`erp-menu-busca-contador${aBuscar && !filtro.folhas.length ? ' vazio' : ''}`} role="status" aria-live="polite">
        {aBuscar ? textoContador(filtro.folhas.length) : ''}
      </span>
    </div>
  );

  const menuNavegacao = (
    <Menu
      className={`erp-menu${aspecto.menu === 'nomes' && !ecra.pequeno ? ' erp-menu-so-nomes' : ''}`}
      theme="dark"
      mode="inline"
      items={itens}
      selectedKeys={[seleccionado, `${PREFIXO_FAVORITO}${seleccionado}`]}
      openKeys={gruposAbertos}
      onOpenChange={(chaves) => {
        if (aBuscar) return;
        if (colapsado) setAbertosFlutuantes(chaves);
        else setAbertosMenu(chaves);
      }}
      inlineIndent={16}
      onClick={(i) => {
        // escolher um resultado fecha a busca e devolve a árvore ao estado anterior (legado)
        if (aBuscar) limparBusca(false);
        irPara(i.key);
      }}
      style={{ borderInlineEnd: 0, background: 'transparent' }}
    />
  );

  const textoFavorito = favoritoActual ? 'Retirar dos favoritos' : 'Adicionar aos favoritos';
  const textoTema = escuro ? 'Desligar a tela nocturna' : 'Tela nocturna';
  const itensUtilizador: MenuProps['items'] = [
    // em telemóvel o nome não cabe na barra: aparece no topo do menu do utilizador
    ...(ecra.md ? [] : [{ key: 'quem', icon: <UserOutlined />, label: nomeUtilizador, disabled: true }, { type: 'divider' as const }]),
    { key: 'dados-pessoais', icon: <IconeFa nome="user-edit" />, label: 'Gerir Dados Pessoais', onClick: () => navegar('/dados-pessoais') },
    ...(!ecra.md && noEcra
      ? [{ key: 'favorito', icon: favoritoActual ? <StarFilled style={{ color: '#f59e0b' }} /> : <StarOutlined />, label: textoFavorito, onClick: () => alternar(moduloActual, ecraActual) }]
      : []),
    { key: 'tema', icon: escuro ? <SunOutlined /> : <MoonOutlined />, label: textoTema, onClick: alternarTema },
    ...(!ecra.pequeno
      ? [{
          key: 'aspecto',
          icon: <LayoutOutlined />,
          label: 'Aspecto do menu',
          children: (Object.keys(ROTULOS_MENU) as AspectoMenu[]).map((m) => ({
            key: `aspecto:${m}`,
            label: ROTULOS_MENU[m],
            icon: aspecto.menu === m ? <CheckOutlined /> : <span style={{ display: 'inline-block', width: 14 }} />,
            onClick: () => definir({ menu: m }),
          })),
        }]
      : []),
    ...(podeIA ? [{ key: 'ia', icon: <RobotOutlined />, label: 'Assistente IA', onClick: () => setPainel('ia') }] : []),
    ...(podeBI ? [{ key: 'powerbi', icon: <ApiOutlined />, label: 'Power BI (feed OData)', onClick: () => setPainel('powerbi') }] : []),
    { type: 'divider' as const },
    { key: 'sair', icon: <LogoutOutlined />, label: 'Terminar sessão', onClick: () => void sair() },
  ];

  return (
    // tela nocturna: tema escuro do Ant Design só dentro do layout (sem remontar os ecrãs ao mudar)
    <ConfigProvider theme={escuro ? TEMA_NOCTURNO : undefined}>
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
            {caixaBusca}
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
            {caixaBusca}
            {menuNavegacao}
          </Drawer>
        )}

        <Layout className="erp-layout-coluna">
          <Layout.Header
            className="erp-cabecalho"
            style={{
              background: escuro ? '#111a2b' : '#ffffff',
              padding: ecra.lg ? '0 24px' : ecra.md ? '0 16px' : '0 8px 0 8px',
              height: ecra.md ? ALTURA_CABECALHO : ALTURA_CABECALHO_TELEMOVEL,
              lineHeight: 'normal',
              borderBottom: `1px solid ${escuro ? '#1f2a3d' : '#e2e8f0'}`,
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
                    onClick={alternarRecolhido}
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
                {ecra.md && (
                  <Tooltip title={`${textoTema} (Alt+Shift+N)`}>
                    <Button className="erp-botao-tema" icon={escuro ? <SunOutlined /> : <MoonOutlined />} aria-label="Tela nocturna" aria-pressed={escuro} onClick={alternarTema} />
                  </Tooltip>
                )}
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
    </ConfigProvider>
  );
}
