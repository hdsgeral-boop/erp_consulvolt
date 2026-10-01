import { useQuery } from '@tanstack/react-query';
import { obter, obterPagina } from '@/api/cliente';
import type { ContaPlano, Diario, RegistoAux } from '../api';

/** Consultas partilhadas (dados mestre da contabilidade). Chaves ['contab', ...] para invalidar após mutações. */

const CINCO_MIN = 300_000;

export function usePlanoContas(activo = true) {
  return useQuery({
    queryKey: ['contab', 'plano-contas'],
    queryFn: () => obter<ContaPlano[]>('/contabilidade/plano-contas'),
    staleTime: CINCO_MIN,
    retry: false,
    enabled: activo,
  });
}

export function useDiarios() {
  return useQuery({ queryKey: ['contab', 'diarios'], queryFn: () => obter<Diario[]>('/contabilidade/diarios'), staleTime: CINCO_MIN, retry: false });
}

export type TabelaAux = 'diarios' | 'notas-demonstracao' | 'notas-fluxo-caixa' | 'centros-custo';

export function useTabelaAux(tabela: TabelaAux, activo = true) {
  return useQuery({
    queryKey: ['contab', 'tabelas', tabela],
    queryFn: () => obter<RegistoAux[]>(`/contabilidade/tabelas/${tabela}`),
    staleTime: CINCO_MIN,
    retry: false,
    enabled: activo,
  });
}

export interface UnidadeNegocio {
  id: number;
  codigo: string | null;
  nome: string;
  estado?: string | null;
}

export function useUnidadesNegocio(activo = true) {
  return useQuery({
    queryKey: ['sistema', 'unidades-negocio'],
    queryFn: () => obter<UnidadeNegocio[]>('/sistema/unidades-negocio'),
    staleTime: CINCO_MIN,
    retry: false,
    enabled: activo,
  });
}

export interface TerceiroResumo {
  id: number;
  nome: string;
  nif: string | null;
  codigo_conta: string | null;
}

export function useTerceiros(pesquisa: string, activo = true) {
  return useQuery({
    queryKey: ['terceiros', 'pesquisa', pesquisa],
    queryFn: () => obterPagina<TerceiroResumo>('/terceiros', { pesquisa, por_pagina: 30 }),
    staleTime: 60_000,
    retry: false,
    enabled: activo,
  });
}

/** Mapa id → «código — descrição» para mostrar ids nas tabelas. */
export function porId<T extends { id: number }>(lista: T[] | undefined, rotulo: (x: T) => string): Map<number, string> {
  return new Map((lista ?? []).map((x) => [x.id, rotulo(x)]));
}
