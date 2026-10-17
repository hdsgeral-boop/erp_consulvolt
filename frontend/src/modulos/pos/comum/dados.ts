import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import type { DefinicoesPOS, ProdutoPOS, Terminal } from './tipos';

/** Consultas partilhadas do POS. Todas as chaves começam por 'pos' para a invalidação em bloco depois das acções. */

export const CHAVE_POS = ['pos'] as const;

export function useTerminais() {
  return useQuery({ queryKey: ['pos', 'terminais'], queryFn: () => obter<Terminal[]>('/pos/terminais') });
}

export function useDefinicoesPOS(activo = true) {
  return useQuery({ queryKey: ['pos', 'definicoes'], queryFn: () => obter<DefinicoesPOS>('/pos/definicoes'), enabled: activo, retry: false });
}

/**
 * Catálogo de produtos activos (preço com IVA). Com o terminal, só os produtos marcados para o serviço dele (filtro no
 * servidor: GET /pos/terminais/{id}/catalogo — produtos.servicos_pos); sem terminal, o catálogo comum de Logística.
 * A chave começa por ['logistica', 'catalogo'] para ser invalidada com o catálogo comum (e não a cada venda).
 */
export function chaveCatalogoPOS(terminalId?: number | null) {
  return terminalId ? (['logistica', 'catalogo', 'pos', terminalId] as const) : (['logistica', 'catalogo'] as const);
}

export function useCatalogoPOS(terminalId?: number | null) {
  return useQuery({
    queryKey: chaveCatalogoPOS(terminalId),
    queryFn: () => obter<ProdutoPOS[]>(terminalId ? `/pos/terminais/${terminalId}/catalogo` : '/logistica/produtos/catalogo'),
    staleTime: 300_000,
    retry: false,
  });
}

/** Categorias de produtos (exigem permissão de Vendas/Armazém; sem ela o filtro por categoria não aparece). */
export function useCategoriasPOS() {
  return useQuery({
    queryKey: ['logistica', 'categorias'],
    queryFn: () => obter<{ id: number; nome: string }[]>('/logistica/categorias-produtos'),
    staleTime: 600_000,
    retry: false,
  });
}

export const TIPOS_TERMINAL: Record<string, string> = {
  LOJA: 'Loja',
  RESTAURANTE: 'Restaurante',
  LAVANDARIA: 'Lavandaria',
  HOTELARIA: 'Hotelaria',
};

export const TIPOS_MEIO: Record<string, string> = {
  NUMERARIO: 'Numerário',
  TPA: 'Multicaixa (TPA)',
  TRANSFERENCIA: 'Transferência bancária',
};
