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

/** Catálogo de produtos activos (preço com IVA). Partilha a cache do catálogo de Logística. */
export function useCatalogoPOS() {
  return useQuery({ queryKey: ['logistica', 'catalogo'], queryFn: () => obter<ProdutoPOS[]>('/logistica/produtos/catalogo'), staleTime: 300_000, retry: false });
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
  TPA: 'TPA (Multicaixa)',
  TRANSFERENCIA: 'Transferência',
};
