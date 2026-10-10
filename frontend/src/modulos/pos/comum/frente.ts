import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import type { ProdutoPOS } from './tipos';

/**
 * Apoio da frente de caixa imersiva (paridade com renderPOSTab/renderPOSProductsGrid, js/ui_sales.js:6350-7044):
 * stock no armazém do terminal, imagens dos produtos, sessões abertas de dias anteriores, filtros e preferências do posto.
 */

export interface StockFrente {
  armazem_id: number;
  /** Stock por id de produto (só produtos que movimentam stock). */
  stock: Record<string, string>;
}

export interface SessaoAntiga {
  id: number;
  terminal_pos_id: number;
  codigo_sessao: string;
  codigo_terminal: string | null;
  nome_terminal: string | null;
  nome_operador: string | null;
  aberto_em: string | null;
}

export function useStockFrente(terminalId: number | null | undefined) {
  return useQuery({
    queryKey: ['pos', 'frente', 'stock', terminalId],
    queryFn: () => obter<StockFrente>(`/pos/frente/terminais/${terminalId}/stock`),
    enabled: !!terminalId,
    retry: false,
  });
}

/**
 * URL (relativo à API, autenticado) da imagem de cada produto que a tem, por id — exposto pelo módulo de Vendas
 * (GET /vendas/produtos/imagens); a imagem lê-se com `useImagemProduto` (blob). Mudam pouco: 10 min em cache.
 */
export function useImagensProdutos(activo = true) {
  return useQuery({
    queryKey: ['vendas', 'imagens-produtos'],
    queryFn: async (): Promise<Record<string, string>> =>
      Object.fromEntries((await obter<{ id: number; imagem_url: string }[]>('/vendas/produtos/imagens')).map((i) => [String(i.id), i.imagem_url])),
    enabled: activo,
    staleTime: 600_000,
    retry: false,
  });
}

export function useSessoesAntigas() {
  return useQuery({
    queryKey: ['pos', 'frente', 'sessoes-antigas'],
    queryFn: () => obter<{ sessoes: SessaoAntiga[]; aviso: string | null }>('/pos/frente/sessoes-antigas'),
    retry: false,
  });
}

// ───────────── Filtros de produtos ─────────────

export type FiltroStock = 'all' | 'in' | 'out';

/** Stock do produto no armazém do terminal (null = serviço / não movimenta stock, ou stock desconhecido). */
export function stockDe(p: ProdutoPOS, stock: StockFrente['stock'] | undefined): number | null {
  if (!p.movimenta_stock) return null;
  const v = stock?.[String(p.id)];
  return v === undefined ? null : Number(v);
}

/** É serviço (sem stock) — etiqueta «Serviço» no cartão. */
export const eServico = (p: ProdutoPOS): boolean => !p.movimenta_stock;

/**
 * Filtro «Todos / Com Stock / Sem Stock» do legado: os serviços contam sempre como disponíveis; «Sem Stock» só mostra
 * produtos de inventário esgotados. Sem dados de stock, «Com/Sem Stock» não esconde nada que não se saiba.
 */
export function filtrarPorStock(produtos: ProdutoPOS[], filtro: FiltroStock, stock: StockFrente['stock'] | undefined): ProdutoPOS[] {
  if (filtro === 'all' || !stock) return produtos;
  return produtos.filter((p) => {
    const s = stockDe(p, stock);
    if (filtro === 'in') return eServico(p) || (s ?? 0) > 0;
    return !eServico(p) && (s ?? 0) <= 0;
  });
}

/** Contagem de produtos por categoria para os chips («Todos 14», «Serviços 8», …), pela ordem das categorias. */
export function contarCategorias(produtos: ProdutoPOS[], categorias: { id: number; nome: string }[]): { id: number; nome: string; total: number }[] {
  const n = new Map<number, number>();
  for (const p of produtos) if (p.categoria_produto_id) n.set(p.categoria_produto_id, (n.get(p.categoria_produto_id) ?? 0) + 1);
  return categorias.map((c) => ({ ...c, total: n.get(c.id) ?? 0 }));
}

/** Ícone da categoria (getPOSCategoryIcon, ui_sales.js:6272-6280). */
export function iconeCategoria(nome: string | null | undefined): string {
  const n = (nome ?? '').toLowerCase();
  if (/bebida|sumo|vinho|cerveja/.test(n)) return 'glass-martini-alt';
  if (/refei|prato|almo|jantar|comida/.test(n)) return 'utensils';
  if (/sobremesa|doce|gelado|bolo/.test(n)) return 'ice-cream';
  if (/cafetaria|café|cafe|chá/.test(n)) return 'coffee';
  if (/entrada|sopa|petisco/.test(n)) return 'cookie';
  return 'box';
}

/** Cor da etiqueta de stock do legado: esgotado vermelho, abaixo de 5 laranja, restantes verde. */
export function corStock(s: number): 'error' | 'warning' | 'success' {
  if (s <= 0) return 'error';
  return s < 5 ? 'warning' : 'success';
}

/** Só o URL relativo da API para a imagem do produto (nada de endereços externos, data: ou javascript:). */
export function urlImagemSegura(url: string | undefined | null): string | null {
  return url && /^\/vendas\/produtos\/\d+\/imagem(\?v=\d+)?$/.test(url) ? url : null;
}

// ───────────── Preferências do posto (por utilizador) ─────────────

export type Dispositivo = 'pc' | 'tablet' | 'mobile';

export interface PreferenciasFrente {
  dispositivo: Dispositivo;
  disposicao: 'grid' | 'list';
  ocultarImagens: boolean;
  filtroStock: FiltroStock;
}

export const PREFERENCIAS_FRENTE_PADRAO: PreferenciasFrente = { dispositivo: 'pc', disposicao: 'grid', ocultarImagens: false, filtroStock: 'all' };

const chavePref = (utilizador: number | null | undefined) => `erp.pos.frente.${utilizador ?? 0}`;

export function lerPreferenciasFrente(utilizador: number | null | undefined): PreferenciasFrente {
  try {
    const bruto = window.localStorage.getItem(chavePref(utilizador));
    if (!bruto) return { ...PREFERENCIAS_FRENTE_PADRAO };
    const p = JSON.parse(bruto) as Partial<PreferenciasFrente>;
    return {
      dispositivo: p.dispositivo === 'tablet' || p.dispositivo === 'mobile' ? p.dispositivo : 'pc',
      disposicao: p.disposicao === 'list' ? 'list' : 'grid',
      ocultarImagens: p.ocultarImagens === true,
      filtroStock: p.filtroStock === 'in' || p.filtroStock === 'out' ? p.filtroStock : 'all',
    };
  } catch {
    return { ...PREFERENCIAS_FRENTE_PADRAO };
  }
}

export function gravarPreferenciasFrente(utilizador: number | null | undefined, p: PreferenciasFrente): void {
  try {
    window.localStorage.setItem(chavePref(utilizador), JSON.stringify(p));
  } catch {
    /* armazenamento indisponível: a preferência vale só nesta página */
  }
}

// ───────────── Terminais POS ─────────────

/** Série das facturas-recibo de cada terminal no ano corrente (prevista = ainda sem vendas este ano). */
export function useSeriesTerminais() {
  return useQuery({
    queryKey: ['pos', 'frente', 'series'],
    queryFn: () => obter<Record<string, { serie: string; prevista: boolean }>>('/pos/frente/series'),
    staleTime: 300_000,
    retry: false,
  });
}

/** Ícone do meio de pagamento nos chips dos terminais (💵 Numerário, 💳 Multicaixa (TPA), 🏦 Transferência). */
export function iconeMeio(tipo: string | null | undefined): string {
  if (tipo === 'NUMERARIO') return 'money-bill-wave';
  if (tipo === 'TPA') return 'credit-card';
  if (tipo === 'TRANSFERENCIA') return 'university';
  return 'wallet';
}
