import type { MenuProps } from 'antd';
import type { EcraMenu, ModuloMenu } from '@/api/tipos';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { iconeModulo } from '@/componentes/icones/iconesModulos';
import { CASA_MODULO, ESCONDIDOS, MENU_GERAL_LEGADO, SISTEMA_LEGADO, TOPO_LEGADO, type EcraLegado, type GrupoLegado, type NoLegado } from './menuLegado';

type ItemMenu = NonNullable<MenuProps['items']>[number];

/** Rota de um ecrã: /m/{modulo}/{ecra}. */
export const rotaEcra = (moduloId: string, ecraId: string) => `/m/${moduloId}/${ecraId}`;

/** Ecrãs de topo de um módulo (sem pai, ou cujo pai não está no menu do utilizador). */
export const ecrasDeTopo = (m: ModuloMenu): EcraMenu[] => m.ecras.filter((e) => !e.pai || !m.ecras.some((x) => x.id === e.pai));

/** Chave do grupo «Menu Geral» (os favoritos entram imediatamente antes, como no legado). */
export const CHAVE_MENU_GERAL = MENU_GERAL_LEGADO.chave;

/**
 * Ícone (Font Awesome do legado) dos ecrãs exclusivos do sistema novo que entram soltos no topo do menu (CASA_MODULO
 * 'topo'): todos os itens de 1.º nível têm símbolo, para o menu recolhido (só ícones) os mostrar.
 */
export const ICONES_ECRAS_TOPO: Readonly<Record<string, string>> = {};
/** Símbolo dos itens de topo sem ícone próprio. */
export const ICONE_TOPO_POR_OMISSAO = 'file-alt';

const icone = (nome?: string, cor?: string) => (nome ? <IconeFa nome={nome} style={cor ? { color: cor } : undefined} /> : undefined);

export interface MenuLateral {
  itens: NonNullable<MenuProps['items']>;
  /** chave de cada item → chaves dos grupos que o contêm (para abrir o caminho do ecrã actual) */
  ancestrais: Map<string, string[]>;
}

/**
 * Chave do grupo «Plataforma SaaS» (menuLegado.ts) — para quem não é Super Administrador só sobram ecrãs de licença
 * e conta própria (os ecrãs `plataforma_*` ficam escondidos por ServicoPermissoes::elegivelPlataforma), por isso o
 * rótulo passa a «Minha Licença», menos confuso para um utilizador de empresa.
 */
const GRUPO_PLATAFORMA_SAAS = 'grupo:sistema-plataforma';
const ROTULO_PLATAFORMA_SAAS_UTILIZADOR_COMUM = 'Minha Licença';

/**
 * Menu lateral com a estrutura do sistema anterior (ver menuLegado.ts): Início e os itens gerais soltos no topo, grupo
 * «Menu Geral» com os módulos/sub-grupos e grupo «Sistema». Só entram os ecrãs que GET /sistema/menu devolve (permissões);
 * grupos sem ecrãs desaparecem. Ecrãs do catálogo fora da estrutura entram no fim do grupo do respectivo módulo.
 */
export function construirMenuLateral(menu: ModuloMenu[], opts: { superAdmin?: boolean } = {}): MenuLateral {
  const disponiveis = new Set<string>();
  for (const m of menu) for (const x of m.ecras) disponiveis.add(`${m.id}/${x.id}`);
  const naEstrutura = new Set<string>();
  const marcar = (n: NoLegado) => (n.tipo === 'ecra' ? naEstrutura.add(`${n.modulo}/${n.ecra}`) : n.filhos.forEach(marcar));
  TOPO_LEGADO.forEach(marcar);
  marcar(MENU_GERAL_LEGADO);
  marcar(SISTEMA_LEGADO);

  const ancestrais = new Map<string, string[]>();
  const registar = (chave: string, caminho: string[]) => {
    if (!ancestrais.has(chave)) ancestrais.set(chave, caminho);
  };
  /** Ecrã do catálogo fora da estrutura (com os seus filhos visíveis num sub-menu). */
  const ecraCatalogo = (moduloId: string, x: EcraMenu, todos: EcraMenu[], caminho: string[]): ItemMenu => {
    const chave = rotaEcra(moduloId, x.id);
    const filhos = todos.filter((f) => f.pai === x.id && !ESCONDIDOS.has(`${moduloId}/${f.id}`));
    registar(chave, caminho);
    if (!filhos.length) return { key: chave, label: x.nome, title: x.nome };
    const grupo = `grupo:${chave}`;
    return { key: grupo, label: x.nome, children: [{ key: chave, label: x.nome }, ...filhos.map((f) => ecraCatalogo(moduloId, f, todos, [...caminho, grupo]))] };
  };

  // ecrãs exclusivos do sistema novo, pelo grupo de destino; módulos sem grupo no legado ficam no fim do «Menu Geral»
  const extras = new Map<string, { m: ModuloMenu; x: EcraMenu }[]>();
  const modulosNovos: { m: ModuloMenu; soltos: EcraMenu[] }[] = [];
  for (const m of menu) {
    const casa = CASA_MODULO[m.id];
    const soltos = ecrasDeTopo(m).filter((x) => !naEstrutura.has(`${m.id}/${x.id}`) && !ESCONDIDOS.has(`${m.id}/${x.id}`));
    if (!soltos.length) continue;
    if (casa) extras.set(casa, [...(extras.get(casa) ?? []), ...soltos.map((x) => ({ m, x }))]);
    else modulosNovos.push({ m, soltos });
  }
  const itensExtra = (casa: string, caminho: string[]): ItemMenu[] => (extras.get(casa) ?? []).map(({ m, x }) => ecraCatalogo(m.id, x, m.ecras, caminho));
  const moduloNovo = ({ m, soltos }: { m: ModuloMenu; soltos: EcraMenu[] }, caminho: string[]): ItemMenu => {
    const chave = `mod:${m.id}`;
    return { key: chave, icon: iconeModulo(m.id), label: m.nome, title: m.nome, children: soltos.map((x) => ecraCatalogo(m.id, x, m.ecras, [...caminho, chave])) };
  };

  const ecraItem = (n: EcraLegado, caminho: string[]): ItemMenu | null => {
    if (!disponiveis.has(`${n.modulo}/${n.ecra}`)) return null;
    const chave = rotaEcra(n.modulo, n.ecra) + (n.query ? `?${n.query}` : '');
    registar(chave, caminho);
    return { key: chave, icon: icone(n.icone), label: n.rotulo, title: n.rotulo, className: n.topo ? 'erp-menu-topo' : undefined };
  };
  const grupoItem = (n: GrupoLegado, caminho: string[]): ItemMenu | null => {
    const dentro = [...caminho, n.chave];
    const filhos = n.filhos.map((f) => (f.tipo === 'ecra' ? ecraItem(f, dentro) : grupoItem(f, dentro))).filter(Boolean) as ItemMenu[];
    filhos.push(...itensExtra(n.chave, dentro));
    if (n.chave === CHAVE_MENU_GERAL) filhos.push(...modulosNovos.map((n) => moduloNovo(n, dentro)));
    if (!filhos.length) return null;
    return { key: n.chave, icon: icone(n.icone, n.cor), label: n.rotulo, title: n.rotulo, children: filhos };
  };

  const topo: ItemMenu[] = [
    { key: '/inicio', icon: icone('home'), label: 'Início', title: 'Início', className: 'erp-menu-topo' },
    ...(TOPO_LEGADO.map((n) => ecraItem(n, [])).filter(Boolean) as ItemMenu[]),
    ...(extras.get('topo') ?? []).map(
      ({ m, x }) =>
        ({
          ...(ecraCatalogo(m.id, x, m.ecras, []) as object),
          icon: icone(ICONES_ECRAS_TOPO[`${m.id}/${x.id}`] ?? ICONE_TOPO_POR_OMISSAO),
          className: 'erp-menu-topo',
        }) as ItemMenu,
    ),
  ];
  const itens = [...topo, grupoItem(MENU_GERAL_LEGADO, []), grupoItem(SISTEMA_LEGADO, [])].filter(Boolean) as ItemMenu[];
  if (!opts.superAdmin) {
    const sistema = itens.find((i) => i?.key === SISTEMA_LEGADO.chave) as (ItemMenu & { children?: ItemMenu[] }) | undefined;
    const plataforma = sistema?.children?.find((i) => i?.key === GRUPO_PLATAFORMA_SAAS) as (ItemMenu & { label?: unknown; title?: string }) | undefined;
    if (plataforma) {
      plataforma.label = ROTULO_PLATAFORMA_SAAS_UTILIZADOR_COMUM;
      plataforma.title = ROTULO_PLATAFORMA_SAAS_UTILIZADOR_COMUM;
    }
  }
  return { itens, ancestrais };
}

/** Itens do menu lateral (ver construirMenuLateral). */
export function construirItensMenu(menu: ModuloMenu[], opts?: { superAdmin?: boolean }): NonNullable<MenuProps['items']> {
  return construirMenuLateral(menu, opts).itens;
}

/**
 * Chave seleccionada e grupos abertos para um caminho (e query string). Pagamentos/Recebimentos: o mesmo ecrã com
 * `?tipo=`; Aprovisionamento/Pedidos Internos: `?vista=`. Ecrãs escondidos do menu (ex.: os mapas de Relatórios e Recibos) seleccionam o ecrã pai.
 */
export function estadoMenu(caminho: string, pesquisa = '', lateral?: MenuLateral, menu: ModuloMenu[] = []): { seleccionado: string; abertos: string[] } {
  if (!caminho.startsWith('/m/')) return { seleccionado: '/inicio', abertos: [] };
  const [, , moduloId, ecraId] = caminho.split('/');
  let seleccionado = rotaEcra(moduloId, ecraId);
  const parametros = new URLSearchParams(pesquisa);
  const tipo = parametros.get('tipo');
  const vista = parametros.get('vista');
  if (tipo && lateral?.ancestrais.has(`${seleccionado}?tipo=${tipo}`)) seleccionado = `${seleccionado}?tipo=${tipo}`;
  else if (vista && lateral?.ancestrais.has(`${seleccionado}?vista=${vista}`)) seleccionado = `${seleccionado}?vista=${vista}`;
  if (lateral && !lateral.ancestrais.has(seleccionado)) {
    const pai = menu.find((m) => m.id === moduloId)?.ecras.find((x) => x.id === ecraId)?.pai;
    if (pai && lateral.ancestrais.has(rotaEcra(moduloId, pai))) seleccionado = rotaEcra(moduloId, pai);
  }
  return { seleccionado, abertos: lateral?.ancestrais.get(seleccionado) ?? [] };
}
