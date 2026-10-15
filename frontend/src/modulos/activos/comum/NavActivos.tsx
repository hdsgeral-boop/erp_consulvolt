import { CabecalhoModulo, type SeparadorModulo } from '@/componentes/CabecalhoModulo';
import { useSessao } from '@/sessao/SessaoContexto';

/** Uma secção do agregador: `ecra` é o id do ecrã do catálogo que dá acesso; `caminho` é o sufixo opcional da rota. */
export interface SeccaoActivos {
  id: string;
  rotulo: string;
  /** nome Font Awesome 5 do legado (IconeFa) */
  icone: string;
  ecra: string;
  caminho?: string;
}

/**
 * Separadores do legado «Gestão de Activos Imobilizados» (renderAssets, js/ui_assets.js:38-48), pela mesma ordem, rótulos e
 * ícones. O «Mapa Fiscal» não tem ecrã próprio no catálogo de permissões: no legado partilha a permissão `activos_mapa`
 * («Mapa Fiscal de Amortizações», app_v2.js:3306), por isso vive em /m/activos/activos_mapa/fiscal.
 */
export const SECCOES_ACTIVOS: SeccaoActivos[] = [
  { id: 'activos', rotulo: 'Inventário de Activos', icone: 'list', ecra: 'activos' },
  // separador «Edifícios e Locais» do legado novo (ui_assets.js:41,59; permissão activos_edificios)
  { id: 'activos_edificios', rotulo: 'Edifícios e Locais', icone: 'city', ecra: 'activos_edificios' },
  { id: 'activos_categorias', rotulo: 'Categorias e Taxas', icone: 'tags', ecra: 'activos_categorias' },
  { id: 'activos_manutencao', rotulo: 'Manutenções', icone: 'tools', ecra: 'activos_manutencao' },
  { id: 'activos_amortizacoes', rotulo: 'Amortizações', icone: 'percent', ecra: 'activos_amortizacoes' },
  { id: 'activos_mapa', rotulo: 'Mapa de Amortizações', icone: 'table', ecra: 'activos_mapa' },
  { id: 'activos_mapa_fiscal', rotulo: 'Mapa Fiscal', icone: 'file-invoice', ecra: 'activos_mapa', caminho: 'fiscal' },
  { id: 'activos_abates', rotulo: 'Abates e Vendas', icone: 'trash-alt', ecra: 'activos_abates' },
  { id: 'activos_pendentes', rotulo: 'Aquisições Pendentes', icone: 'hourglass-start', ecra: 'activos_pendentes' },
];

/** Destino de uma secção. */
export function destinoSeccao(s: SeccaoActivos): string {
  return `/m/activos/${s.ecra}${s.caminho ? `/${s.caminho}` : ''}`;
}

/** Secções visíveis com os ecrãs do menu do servidor que o utilizador pode ver. */
export function seccoesVisiveis(ecrasVisiveis: Iterable<string>): SeccaoActivos[] {
  const v = new Set(ecrasVisiveis);
  return SECCOES_ACTIVOS.filter((s) => v.has(s.ecra));
}

/**
 * Cabeçalho do agregador dos Activos (h2.view-title «Gestão de Activos Imobilizados» + separadores .tab-btn do legado):
 * só as secções que o utilizador pode ver (menu do servidor); `actual` é a secção em destaque.
 */
export function NavActivos({ actual }: { actual: string }) {
  const { menu } = useSessao();
  const lista = seccoesVisiveis(menu.find((m) => m.id === 'activos')?.ecras.map((e) => e.id) ?? []);
  const separadores: SeparadorModulo[] = lista.map((s) => ({ chave: s.id, rotulo: s.rotulo, icone: s.icone, destino: destinoSeccao(s) }));
  return <CabecalhoModulo titulo="Gestão de Activos Imobilizados" separadores={separadores} activo={actual} />;
}
