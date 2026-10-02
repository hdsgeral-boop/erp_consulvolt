import type { MenuProps } from 'antd';
import { HomeOutlined } from '@ant-design/icons';
import type { EcraMenu, ModuloMenu } from '@/api/tipos';
import { iconeModulo } from '@/componentes/icones/iconesModulos';

type ItemMenu = NonNullable<MenuProps['items']>[number];

/** Rota de um ecrã: /m/{modulo}/{ecra}. */
export const rotaEcra = (moduloId: string, ecraId: string) => `/m/${moduloId}/${ecraId}`;

/** Ecrãs de topo de um módulo (sem pai, ou cujo pai não está no menu do utilizador). */
export const ecrasDeTopo = (m: ModuloMenu): EcraMenu[] => m.ecras.filter((e) => !e.pai || !m.ecras.some((x) => x.id === e.pai));

/**
 * Itens do menu lateral a partir de GET /sistema/menu: Início + um submenu por módulo (com ícone SVG) e os ecrãs
 * (os que têm filhos, ex. Relatórios → mapas, viram submenu com o próprio ecrã em primeiro lugar).
 */
export function construirItensMenu(menu: ModuloMenu[]): NonNullable<MenuProps['items']> {
  const ecraItem = (moduloId: string, e: EcraMenu, todos: EcraMenu[]): ItemMenu => {
    const filhos = todos.filter((f) => f.pai === e.id);
    const chave = rotaEcra(moduloId, e.id);
    return filhos.length
      ? { key: `grupo:${chave}`, label: e.nome, children: [{ key: chave, label: e.nome }, ...filhos.map((f) => ecraItem(moduloId, f, todos))] }
      : { key: chave, label: e.nome };
  };
  return [
    { key: '/', icon: <HomeOutlined />, label: 'Início' },
    ...menu.map((m) => ({
      key: `mod:${m.id}`,
      icon: iconeModulo(m.id),
      label: m.nome,
      title: m.nome,
      children: ecrasDeTopo(m).map((e) => ecraItem(m.id, e, m.ecras)),
    })),
  ];
}

/** Chave seleccionada e módulo aberto para um caminho. */
export function estadoMenu(caminho: string): { seleccionado: string; abertos: string[] } {
  if (!caminho.startsWith('/m/')) return { seleccionado: '/', abertos: [] };
  const partes = caminho.split('/');
  return { seleccionado: partes.slice(0, 4).join('/'), abertos: [`mod:${partes[2]}`] };
}
