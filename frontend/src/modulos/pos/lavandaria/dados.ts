import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import type { DefinicoesLav, DetalheOrdem, Peca, ServicoLav } from './tipos';

export function usePecas(incluirInativas = false) {
  return useQuery({ queryKey: ['pos', 'lavandaria', 'pecas', incluirInativas], queryFn: () => obter<Peca[]>('/pos/lavandaria/pecas', { incluir_inativas: incluirInativas ? 1 : undefined }), staleTime: 300_000 });
}

export function useServicosLav(incluirInativos = false) {
  return useQuery({ queryKey: ['pos', 'lavandaria', 'servicos', incluirInativos], queryFn: () => obter<ServicoLav[]>('/pos/lavandaria/servicos', { incluir_inativos: incluirInativos ? 1 : undefined }), staleTime: 300_000 });
}

export function useDefinicoesLav() {
  return useQuery({ queryKey: ['pos', 'lavandaria', 'definicoes'], queryFn: () => obter<DefinicoesLav>('/pos/lavandaria/definicoes'), staleTime: 300_000 });
}

export function useOrdem(id: number | null) {
  return useQuery({ queryKey: ['pos', 'lavandaria', 'ordem', id], queryFn: () => obter<DetalheOrdem>(`/pos/lavandaria/ordens/${id}`), enabled: !!id });
}

export const GRUPOS_LAV: Record<string, string> = { LAVANDARIA: 'Lavandaria', ALFAIATARIA: 'Alfaiataria' };
export const UNIDADES_LAV: Record<string, string> = { PECA: 'Peça', KG: 'Kg' };
