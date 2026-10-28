import { Avatar, Button, ConfigProvider, Drawer, Dropdown, Flex, Input, Layout, Menu, Modal, Select, Space, Spin, Tooltip, Typography, type MenuProps } from 'antd';
import {
  ApiOutlined,
  AuditOutlined,
  CheckOutlined,
  DownOutlined,
  EditOutlined,
  IdcardOutlined,
  LayoutOutlined,
  LogoutOutlined,
  MenuFoldOutlined,
  MenuOutlined,
  MenuUnfoldOutlined,
  MoonOutlined,
  RobotOutlined,
  StarFilled,
  StarOutlined,
  SunOutlined,
  SwapOutlined,
  UserOutlined,
} from '@ant-design/icons';
import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { useIdentidade } from '@/sessao/identidade';
import { useEcra } from '@/componentes/responsivo/useEcra';
import { larguraGaveta } from '@/componentes/responsivo/utilitarios';
import { AjudaProvider, BotaoAjuda } from '@/componentes/ajuda/PainelAjuda';
import { OperacoesProvider } from '@/componentes/operacoes/Operacoes';
import { MAX_ROTULO_FAVORITO, nomeOriginalFavorito, useFavoritos } from '@/componentes/preferencias/favoritos';
import { notificarErro } from '@/utilitarios/erros';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { ICONES_FA } from '@/componentes/fluxos/iconesFa';
import { CHAVE_MENU_GERAL, construirMenuLateral, estadoMenu } from './itensMenu';
import { MarcaEmpresa } from './MarcaEmpresa';
import { AvisoLicenca } from './AvisoLicenca';
import { ContadorLicencaHeader } from './ContadorLicencaHeader';
import { NotificacoesSinoHeader } from './NotificacoesSinoHeader';
import { classeFundoModulo } from './fundoModulo';
import { rotuloEmpresa, rotuloPapel } from './marca';
import { PesquisaCabecalho } from './PesquisaEcras';
import { useAspecto, type AspectoMenu } from './aspecto';
import { semIcones, type ItemMenu } from './filtroMenu';
import { TEMA_NOCTURNO } from './temaNocturno';
import { LARGURA_MENU_RECOLHIDO, useLarguraMenu } from './larguraMenu';
import { PuxadorMenu } from './PuxadorMenu';
import { useModoMenu } from './modoMenu';
import './telaNocturna.css';

const PainelPowerBI = lazy(() => import('@/modulos/geral/integracoes/PainelPowerBI').then((m) => ({ default: m.PainelPowerBI })));
const AssistenteIA = lazy(() => import('@/modulos/geral/integracoes/AssistenteIA').then((m) => ({ default: m.AssistenteIA })));

const ALTURA_CABECALHO = 60;
const ALTURA_CABECALHO_TELEMOVEL = 52;
export const PREFIXO_FAVORITO = 'fav:';
/** id da árvore do menu lateral (aria-controls do botão recolher/expandir) */
const ID_MENU_LATERAL = 'erp-menu-lateral';
type ItemMenuLateral = ItemMenu;

const temFilhos = (it: ItemMenuLateral): boolean => !!it && typeof it === 'object' && Array.isArray((it as { children?: unknown }).children);

/**
 * Modo «menu no topo»: substitui o grupo `chaveGrupo` (ex.: «Menu Geral») pelos seus próprios filhos, na mesma
 * posição — os módulos passam a dropdowns soltos na barra horizontal, em vez de ficarem dois níveis dentro de um
 * só grupo. Sem alteração se a chave não existir na lista.
 */
export function achatarGrupoNaBarra(itens: ItemMenuLateral[], chaveGrupo: string): ItemMenuLateral[] {
  const i = itens.findIndex((x) => x && typeof x === 'object' && (x as { key?: string }).key === chaveGrupo);
  if (i < 0) return itens;
  const filhos = (itens[i] as { children?: ItemMenuLateral[] }).children ?? [];
  return [...itens.slice(0, i), ...filhos, ...itens.slice(i + 1)];
}

/**
 * Seta dos grupos (legado: fa-chevron-down à direita, roda 180° quando o grupo está aberto). Desenhada à parte do IconeFa
 * (sem a classe fx-icone) para não contar como símbolo do item no aspecto «só nomes».
 */
const [LARGURA_SETA, ALTURA_SETA, CAMINHO_SETA] = ICONES_FA['chevron-down'];
const setaGrupo = ({ isOpen }: { isOpen?: boolean }) => (
  <svg
    className={`ant-menu-submenu-expand-icon erp-menu-seta${isOpen ? ' aberta' : ''}`}
    viewBox={`0 0 ${LARGURA_SETA} ${ALTURA_SETA}`}
    width="1em"
    height="1em"
    fill="currentColor"
    aria-hidden
    focusable="false"
  >
    <path d={CAMINHO_SETA} />
  </svg>
);

/**
 * Layout da aplicação: menu pelas permissões (GET /sistema/menu), identidade da empresa activa (logótipo + nome),
 * selector de empresa e menu do utilizador.
 *
 * Aspecto do sistema anterior (index_arrumado.html + css/styles.css): menu lateral escuro (#121212) de 280 px com a
 * marca no topo, item activo com barra azul à esquerda e sub-níveis com guia tracejada; largura ajustável à mão pelo
 * friso do bordo direito (220–800 px, guardada por utilizador, duplo clique repõe — larguraMenu.ts/PuxadorMenu.tsx);
 * barra superior branca com o botão quadrado do menu, o nome da empresa e o crachá do NIF à esquerda, «Ajuda», «Trocar
 * empresa», o utilizador e o botão vermelho de sair à direita.
 *
 * Funcionalidades transversais (ronda 2, R2-G4): ajuda contextual (botão «Ajuda» e F1), pesquisa «Procurar» no
 * cabeçalho (ecrãs e funções a que o utilizador tem acesso, Ctrl+K — PesquisaEcras.tsx),
 * favoritos (estrela na barra, grupo «Favoritos» no menu e no Início — guardados no servidor),
 * gestor de operações em segundo plano, Power BI (config_backup) e assistente IA (no menu do utilizador).
 *
 * Versão Actual (Fase H, ADR-071):
 *  - a busca no menu (js/busca_menu.js) saiu da árvore (pedido do utilizador, 16/10/2026): a pesquisa faz-se no campo
 *    «Procurar» da barra superior, que também encontra as funções dentro dos ecrãs;
 *  - aspecto do menu (js/aspecto_menu.js): completo ou só símbolos — preferência do utilizador guardada no servidor; o
 *    botão redondo a meio do bordo do menu (PuxadorMenu) e o botão do cabeçalho recolhem-no à barra estreita só com os
 *    ícones dos módulos e voltam a expandi-lo para a largura anterior;
 *  - modo «menu no topo» (28/10/2026): alternativa à barra lateral — os módulos passam a dropdowns numa barra
 *    horizontal (modoMenu.ts), preferência do utilizador guardada no navegador (botão no cabeçalho);
 *  - menu compacto (16/10/2026): itens de 34–36 px, ícones na mesma coluna, nomes longos com reticências e dica;
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
 *    empresa; a barra superior mostra o logótipo e o nome da empresa; «Ajuda» e «Procurar» só com o ícone (a lupa abre
 *    o mesmo campo de pesquisa por cima da barra);
 *  - < 768 px: barra compacta (56 px) e menu do utilizador só com o avatar (o nome e a estrela do ecrã passam para dentro
 *    do menu; a tela nocturna fica sempre no botão da barra — 17/10/2026).
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
  const ecra = useEcra();
  const { favoritos, eFavorito, alternar, renomear } = useFavoritos();
  // renomear favorito (lápis no menu «⋮» do item): rótulo próprio guardado com a preferência
  const [aRenomear, setARenomear] = useState<{ modulo: string; ecra: string; original: string } | null>(null);
  const [rotuloNovo, setRotuloNovo] = useState('');
  const abrirRenomear = useCallback(
    (modulo: string, ecra: string) => {
      const f = favoritos.find((x) => x.modulo === modulo && x.ecra === ecra);
      if (!f) return;
      setRotuloNovo(f.nome);
      setARenomear({ modulo, ecra, original: nomeOriginalFavorito(modulo, ecra, menu) });
    },
    [favoritos, menu],
  );
  const confirmarRenomear = () => {
    if (aRenomear) renomear(aRenomear.modulo, aRenomear.ecra, rotuloNovo);
    setARenomear(null);
  };
  const idUtilizador = utilizador?.id ?? utilizador?.nome_utilizador ?? null;
  const { aspecto, definir } = useAspecto(idUtilizador);
  const { largura: larguraMenu, puxador } = useLarguraMenu(idUtilizador);
  const { modo: modoMenu, definir: definirModoMenu } = useModoMenu(idUtilizador);
  const escuro = aspecto.tema === 'escuro';
  const recolhido = aspecto.menu === 'icones';
  const colapsado = recolhido && !ecra.pequeno;
  const [gavetaAberta, setGavetaAberta] = useState(false);
  const [painel, setPainel] = useState<'powerbi' | 'ia' | null>(null);

  // «Recolher menu» (botão a meio do bordo do menu) alterna entre «só símbolos» e o último aspecto expandido
  const anteriorExpandido = useRef<AspectoMenu>(aspecto.menu === 'icones' ? 'completo' : aspecto.menu);
  useEffect(() => {
    if (aspecto.menu !== 'icones') anteriorExpandido.current = aspecto.menu;
  }, [aspecto.menu]);
  const alternarRecolhido = () => definir({ menu: recolhido ? anteriorExpandido.current : 'icones' });
  const alternarTema = useCallback(() => definir({ tema: escuro ? 'claro' : 'escuro' }), [definir, escuro]);

  const superAdmin = utilizador?.papel === 'SUPER_ADMINISTRADOR';
  const lateral = useMemo(() => construirMenuLateral(menu, { superAdmin }), [menu, superAdmin]);
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

  const itens = useMemo(() => {
    // legado (app_v2.js:11119): «⋮» em cada ecrã do menu (excepto Início e Dashboard) abre o menu de contexto com os favoritos
    const comPontos = (lista: ItemMenuLateral[]): ItemMenuLateral[] =>
      lista.map((it) => {
        if (!it || typeof it !== 'object') return it;
        const x = it as { key?: string; label?: ReactNode; title?: string; children?: ItemMenuLateral[] };
        if (x.children) return { ...it, children: comPontos(x.children) } as ItemMenuLateral;
        const [, m, mod, ecraId] = String(x.key ?? '').split('?')[0].split('/');
        if (m !== 'm' || !mod || !ecraId || (mod === 'geral' && ecraId === 'dashboard')) return it;
        const fav = eFavorito(mod, ecraId);
        return {
          ...it,
          label: (
            <span className="erp-menu-rotulo">
              <span className="erp-menu-rotulo-texto" title={x.title ?? (typeof x.label === 'string' ? x.label : undefined)}>
                {x.label}
              </span>
              <Dropdown
                trigger={['click']}
                placement="bottomRight"
                menu={{
                  items: [
                    { key: 'fav', icon: fav ? <StarFilled style={{ color: '#f59e0b' }} /> : <StarOutlined />, label: fav ? 'Remover dos Favoritos' : 'Adicionar aos Favoritos' },
                    ...(fav ? [{ key: 'renomear', icon: <EditOutlined />, label: 'Renomear favorito' }] : []),
                  ],
                  onClick: (e) => {
                    e.domEvent.stopPropagation();
                    if (e.key === 'renomear') abrirRenomear(mod, ecraId);
                    else alternar(mod, ecraId);
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
    const lista = aspecto.menu === 'nomes' && !ecra.pequeno ? semIcones(base) : base;
    return comPontos(lista);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [base, aspecto.menu, ecra.pequeno, favoritos, abrirRenomear]);
  // como no legado, os itens soltos (Início, Dashboard Global, …) ficam por cima dos botões do aspecto e os grupos por baixo
  const itensTopo = useMemo(() => itens.filter((i) => !temFilhos(i)), [itens]);
  const itensGrupos = useMemo(() => itens.filter(temFilhos), [itens]);
  // modo «menu no topo»: os módulos do grupo «Menu Geral» passam a dropdowns soltos na barra (Favoritos e Sistema mantêm-se como grupo).
  // Parte de `base` (sem os pontos «⋮» do menu de contexto dos favoritos, que não têm espaço numa dropdown horizontal estreita).
  const itensBarraTopo = useMemo(() => achatarGrupoNaBarra(base, CHAVE_MENU_GERAL), [base]);
  const { seleccionado, abertos } = useMemo(() => estadoMenu(local.pathname, local.search, lateral, menu), [local.pathname, local.search, lateral, menu]);
  const [, , moduloActual, ecraActual] = local.pathname.split('/');
  const noEcra = local.pathname.startsWith('/m/') && !!moduloActual && !!ecraActual;
  const favoritoActual = noEcra && eFavorito(moduloActual, ecraActual);

  // grupos abertos: os do utilizador e, com o menu recolhido, os sub-menus flutuantes
  const [abertosMenu, setAbertosMenu] = useState<string[]>(abertos);
  const [abertosFlutuantes, setAbertosFlutuantes] = useState<string[]>([]);
  useEffect(() => {
    setAbertosMenu((a) => {
      const novos = abertos.filter((k) => !a.includes(k));
      return novos.length ? [...a, ...novos] : a;
    });
  }, [abertos]);
  useEffect(() => setAbertosFlutuantes([]), [colapsado]);
  const gruposAbertos = colapsado ? abertosFlutuantes : abertosMenu;

  // a gaveta fecha ao navegar e quando o ecrã passa a largo
  useEffect(() => setGavetaAberta(false), [local.pathname]);
  useEffect(() => {
    if (!ecra.pequeno) setGavetaAberta(false);
  }, [ecra.pequeno]);

  // Ctrl+Shift+N (legado) ou Alt+Shift+N: tela nocturna (o Ctrl+K da pesquisa está em PesquisaCabecalho)
  useEffect(() => {
    const tecla = (ev: KeyboardEvent) => {
      if (((ev.ctrlKey || ev.metaKey) && ev.shiftKey && !ev.altKey && ev.key.toLowerCase() === 'n')
        // Alt+Shift+N: no Chrome o Ctrl+Shift+N abre a janela anónima e não chega à página
        || (ev.altKey && ev.shiftKey && !ev.ctrlKey && !ev.metaKey && (ev.code === 'KeyN' || ev.key.toLowerCase() === 'n'))) {
        ev.preventDefault();
        alternarTema();
      }
    };
    window.addEventListener('keydown', tecla);
    return () => window.removeEventListener('keydown', tecla);
  }, [alternarTema]);

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
            items: empresas.map((e) => ({ key: String(e.id), label: rotuloEmpresa(e), icon: e.id === empresa?.id ? <CheckOutlined /> : <span style={{ display: 'inline-block', width: 14 }} /> })),
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
      options={empresas.map((e) => ({ value: e.id, label: rotuloEmpresa(e), title: rotuloEmpresa(e) }))}
      onChange={(id: number) => void trocarEmpresa(id)}
      aria-label="Empresa activa"
      prefix={<SwapOutlined aria-hidden style={{ color: '#64748b' }} />}
      showSearch={empresas.length > 8}
      optionFilterProp="label"
    />
  );

  const propsMenu: MenuProps = {
    theme: 'dark',
    mode: 'inline',
    selectedKeys: [seleccionado, `${PREFIXO_FAVORITO}${seleccionado}`],
    inlineIndent: 16,
    expandIcon: setaGrupo,
    onClick: (i) => irPara(i.key),
    style: { borderInlineEnd: 0, background: 'transparent' },
  };
  const classeMenu = `erp-menu${aspecto.menu === 'nomes' && !ecra.pequeno ? ' erp-menu-so-nomes' : ''}`;
  const menuTopo = itensTopo.length > 0 && <Menu {...propsMenu} className={`${classeMenu} erp-menu-lista-topo`} items={itensTopo} />;
  const menuBarraTopo = (
    <Menu
      theme="dark"
      mode="horizontal"
      selectedKeys={[seleccionado, `${PREFIXO_FAVORITO}${seleccionado}`]}
      onClick={(i) => irPara(i.key)}
      items={itensBarraTopo}
      style={{ borderBottom: 'none', background: 'transparent', flex: '1 1 auto', minWidth: 0 }}
    />
  );
  const menuGrupos = itensGrupos.length > 0 && (
    <Menu
      {...propsMenu}
      className={`${classeMenu} erp-menu-lista-grupos`}
      items={itensGrupos}
      openKeys={gruposAbertos}
      onOpenChange={(chaves) => {
        if (colapsado) setAbertosFlutuantes(chaves);
        else setAbertosMenu(chaves);
      }}
    />
  );

  const textoFavorito = favoritoActual ? 'Retirar dos favoritos' : 'Adicionar aos favoritos';
  const textoTema = escuro ? 'Desligar a tela nocturna' : 'Tela nocturna';
  const itensUtilizador: MenuProps['items'] = [
    // em telemóvel o nome não cabe na barra: aparece no topo do menu do utilizador
    ...(ecra.md ? [] : [{ key: 'quem', icon: <UserOutlined />, label: nomeUtilizador, disabled: true }, { type: 'divider' as const }]),
    { key: 'dados-pessoais', icon: <IconeFa nome="user-edit" />, label: 'Gerir Dados Pessoais', onClick: () => navegar('/dados-pessoais') },
    // portal autónomo do colaborador (Versão Actual: portal.html); o servidor decide se o utilizador lá pode entrar
    { key: 'portal', icon: <IdcardOutlined />, label: 'Abrir o portal', onClick: () => navegar('/portal') },
    ...(!ecra.md && noEcra
      ? [{ key: 'favorito', icon: favoritoActual ? <StarFilled style={{ color: '#f59e0b' }} /> : <StarOutlined />, label: textoFavorito, onClick: () => alternar(moduloActual, ecraActual) }]
      : []),
    ...(podeIA ? [{ key: 'ia', icon: <RobotOutlined />, label: 'Assistente IA', onClick: () => setPainel('ia') }] : []),
    ...(podeBI ? [{ key: 'powerbi', icon: <ApiOutlined />, label: 'Power BI (feed OData)', onClick: () => setPainel('powerbi') }] : []),
    // Super Administrador: o login já não força a consola da plataforma (vai direto à escolha de empresa/ERP), por
    // isso precisa deste link para lá chegar quando quiser (simétrico ao «Entrar no ERP» de LayoutPlataforma).
    ...(superAdmin ? [{ key: 'consola-plataforma', icon: <AuditOutlined />, label: 'Consola da Plataforma', onClick: () => navegar('/admin') }] : []),
    { type: 'divider' as const },
    { key: 'sair', icon: <LogoutOutlined />, label: 'Terminar sessão', onClick: () => void sair() },
  ];

  return (
    // tela nocturna: tema escuro do Ant Design só dentro do layout (sem remontar os ecrãs ao mudar)
    <ConfigProvider theme={escuro ? TEMA_NOCTURNO : undefined}>
      <Layout style={{ minHeight: '100vh' }}>
        {!ecra.pequeno && modoMenu === 'lateral' && (
          <Layout.Sider
            className={`erp-sider${puxador.aArrastar ? ' erp-sider-a-redimensionar' : ''}`}
            collapsible
            collapsed={recolhido}
            collapsedWidth={LARGURA_MENU_RECOLHIDO}
            trigger={null}
            width={larguraMenu}
            theme="dark"
          >
            <div className={`erp-sider-topo${recolhido ? ' recolhido' : ''}`}>
              <MarcaEmpresa soIcone={recolhido} tamanho={36} comProduto escuro />
            </div>
            <nav id={ID_MENU_LATERAL} className="erp-sider-rolagem" aria-label="Menu principal">
              {menuTopo}
              {menuGrupos}
            </nav>
            <PuxadorMenu largura={larguraMenu} puxador={puxador} recolhido={recolhido} aoAlternar={alternarRecolhido} controla={ID_MENU_LATERAL} />
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
            {menuTopo}
            {menuGrupos}
          </Drawer>
        )}

        <Layout className="erp-layout-coluna">
          {!ecra.pequeno && modoMenu === 'topo' && (
            <div className="erp-barra-menu-topo" style={{ background: '#121212', display: 'flex', alignItems: 'center', gap: 20, padding: '0 20px', height: 56 }}>
              <MarcaEmpresa tamanho={30} comProduto escuro />
              {menuBarraTopo}
            </div>
          )}
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
                ) : modoMenu === 'lateral' ? (
                  <Button
                    className="erp-botao-menu"
                    icon={recolhido ? <MenuUnfoldOutlined /> : <MenuFoldOutlined />}
                    aria-label="Mostrar os nomes do menu"
                    aria-pressed={!recolhido}
                    title={recolhido ? 'Expandir menu' : 'Recolher menu'}
                    onClick={alternarRecolhido}
                  />
                ) : null}
                <div className="erp-cabecalho-empresa" style={{ minWidth: 0, flex: '1 1 auto' }}>
                  <span className="erp-cabecalho-empresa-nome" title={nomeEmpresa || 'Consulvolt'}>
                    {nomeEmpresa || 'Consulvolt'}
                  </span>
                  {nif && <span className="erp-cracha-nif">NIF: {nif}</span>}
                </div>
              </Flex>
              {ecra.lg && (
                <div className="erp-cabecalho-pesquisa">
                  <PesquisaCabecalho idUtilizador={idUtilizador} />
                </div>
              )}
              <Flex align="center" gap={ecra.md ? 8 : 4} style={{ flex: 'none', minWidth: 0 }}>
                {/* a tela nocturna e o aspecto do menu não estão no menu do utilizador (pedido de 17/10/2026): o botão fica em todas as larguras */}
                <Tooltip title={`${textoTema} (Alt+Shift+N)`}>
                  <Button className="erp-botao-tema" icon={escuro ? <SunOutlined /> : <MoonOutlined />} aria-label="Tela nocturna" aria-pressed={escuro} onClick={alternarTema} />
                </Tooltip>
                {!ecra.pequeno && (
                  <Tooltip title={ecra.lg ? undefined : modoMenu === 'lateral' ? 'Menu no topo' : 'Menu ao lado'}>
                    <Button
                      className="erp-botao-modo-menu"
                      icon={modoMenu === 'lateral' ? <LayoutOutlined /> : <MenuOutlined />}
                      aria-label={modoMenu === 'lateral' ? 'Mudar para menu no topo' : 'Mudar para menu ao lado'}
                      aria-pressed={modoMenu === 'topo'}
                      onClick={() => definirModoMenu(modoMenu === 'lateral' ? 'topo' : 'lateral')}
                    >
                      {ecra.lg ? (modoMenu === 'lateral' ? 'Menu no topo' : 'Menu ao lado') : null}
                    </Button>
                  </Tooltip>
                )}
                <BotaoAjuda compacto={!ecra.xl} />
                {!ecra.lg && <PesquisaCabecalho compacto />}
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
                {!ecra.pequeno && <ContadorLicencaHeader />}
                <NotificacoesSinoHeader />
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
            <AvisoLicenca />
            <Suspense fallback={<Spin style={{ display: 'block', marginTop: 80 }} />}>
              <Outlet />
            </Suspense>
          </Layout.Content>
        </Layout>

        <Modal
          title="Renomear favorito"
          open={aRenomear !== null}
          okText="Guardar"
          cancelText="Cancelar"
          onOk={confirmarRenomear}
          onCancel={() => setARenomear(null)}
          destroyOnHidden
        >
          <Input
            autoFocus
            aria-label="Nome do favorito"
            value={rotuloNovo}
            maxLength={MAX_ROTULO_FAVORITO}
            showCount
            onChange={(ev) => setRotuloNovo(ev.target.value)}
            onPressEnter={confirmarRenomear}
          />
          {aRenomear && (
            <Typography.Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>
              Nome do ecrã: {aRenomear.original}. Deixe vazio para voltar a este nome.
            </Typography.Paragraph>
          )}
        </Modal>
        <Suspense fallback={null}>
          {painel === 'powerbi' && <PainelPowerBI aberto aoFechar={() => setPainel(null)} />}
          {painel === 'ia' && <AssistenteIA aberto aoFechar={() => setPainel(null)} />}
        </Suspense>
      </Layout>
    </ConfigProvider>
  );
}
