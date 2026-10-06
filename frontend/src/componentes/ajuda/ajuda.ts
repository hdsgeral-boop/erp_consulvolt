import type { ModuloMenu } from '@/api/tipos';
import conteudo from './conteudo.json';

/**
 * Ajuda contextual (M-04): conteúdo de payroll_system_web/js/ajuda.js convertido para conteudo.json (script do R2-G4
 * converter_ajuda.cjs), indexado pelos ids dos ecrãs do catálogo de permissões. Os ecrãs sem texto próprio usam o do
 * ecrã-pai (ex.: os mapas usam «Mapas e Relatórios») ou, na falta dele, o resumo e as regras do módulo — como o legado.
 */
export interface TopicoEcra {
  modulo: string;
  titulo: string;
  serve: string;
  passos: string[];
  dicas: string[];
}

export interface ModuloAjuda {
  nome: string;
  resumo: string;
  regras: string[];
}

interface Conteudo {
  modulos: Record<string, ModuloAjuda>;
  moduloPorCatalogo: Record<string, string>;
  ecras: Record<string, TopicoEcra>;
}

export const AJUDA = conteudo as Conteudo;

export interface AjudaLocalizada {
  /** id do ecrã do catálogo (ou 'inicio') */
  id: string;
  titulo: string;
  modulo: ModuloAjuda & { id: string };
  ecra: TopicoEcra | null;
  /** outros ecrãs do mesmo módulo do catálogo, com ajuda própria e visíveis no menu */
  outros: { id: string; titulo: string }[];
}

/** Ajuda do ecrã do caminho actual (/m/{modulo}/{ecra}/… ou o Início). */
export function localizarAjuda(caminho: string, menu: ModuloMenu[]): AjudaLocalizada {
  const [, prefixo, moduloId, ecraId] = caminho.split('/');
  if (prefixo !== 'm' || !moduloId || !ecraId) return montar('inicio', 'geral', menu, 'Início');
  return montar(ecraId, moduloId, menu);
}

/** Ajuda de um ecrã do catálogo pelo id. */
export function ajudaDoEcra(ecraId: string, menu: ModuloMenu[]): AjudaLocalizada {
  const modulo = menu.find((m) => m.ecras.some((e) => e.id === ecraId))?.id ?? (ecraId === 'inicio' ? 'geral' : '');
  return montar(ecraId, modulo, menu);
}

function montar(ecraId: string, moduloId: string, menu: ModuloMenu[], nomeFixo?: string): AjudaLocalizada {
  const m = menu.find((x) => x.id === moduloId);
  const noMenu = m?.ecras.find((e) => e.id === ecraId);
  const pai = noMenu?.pai ? AJUDA.ecras[noMenu.pai] : undefined;
  const ecra = AJUDA.ecras[ecraId] ?? pai ?? null;
  const idModulo = ecra?.modulo ?? AJUDA.moduloPorCatalogo[moduloId] ?? 'inicio';
  const modulo = AJUDA.modulos[idModulo] ?? AJUDA.modulos.inicio;
  const outros = (m?.ecras ?? []).filter((e) => e.id !== ecraId && AJUDA.ecras[e.id]).map((e) => ({ id: e.id, titulo: e.nome }));
  return { id: ecraId, titulo: nomeFixo ?? noMenu?.nome ?? ecra?.titulo ?? modulo.nome, modulo: { ...modulo, id: idModulo }, ecra, outros };
}

const semAcentos = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** Pesquisa em toda a ajuda (títulos, «para que serve», passos, dicas e regras do módulo), como o legado. */
export function pesquisarAjuda(termo: string, menu: ModuloMenu[]): { id: string; titulo: string; modulo: string; serve: string }[] {
  const q = semAcentos(termo.trim());
  if (!q) return [];
  const visiveis = new Map(menu.flatMap((m) => m.ecras.map((e) => [e.id, e.nome] as const)));
  return Object.entries(AJUDA.ecras)
    .filter(([id]) => id === 'inicio' || visiveis.has(id))
    .filter(([, e]) => semAcentos([e.titulo, e.serve, ...e.passos, ...e.dicas, ...(AJUDA.modulos[e.modulo]?.regras ?? []), AJUDA.modulos[e.modulo]?.nome ?? ''].join(' ')).includes(q))
    .map(([id, e]) => ({ id, titulo: visiveis.get(id) ?? e.titulo, modulo: AJUDA.modulos[e.modulo]?.nome ?? '', serve: e.serve }));
}
