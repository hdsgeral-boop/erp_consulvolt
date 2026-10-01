import { useQuery } from '@tanstack/react-query';
import { obter, obterPagina } from '@/api/cliente';
import type { ConfiguracaoCRM, ContaCRM, Funil, ModeloEmail } from './tipos';

/** Consultas partilhadas do CRM. Todas as chaves começam por ['crm'] para invalidar após mutações. */
export const CHAVE_CRM = ['crm'];

export function useConfigCRM() {
  return useQuery({ queryKey: ['crm', 'configuracao'], queryFn: () => obter<ConfiguracaoCRM>('/crm/configuracao'), staleTime: 300_000 });
}

export function useFunis() {
  return useQuery({ queryKey: ['crm', 'funis'], queryFn: () => obter<Funil[]>('/crm/funis'), staleTime: 300_000 });
}

export function useModelosEmail() {
  return useQuery({ queryKey: ['crm', 'modelos'], queryFn: () => obter<ModeloEmail[]>('/crm/modelos-email'), staleTime: 300_000 });
}

export function useContasPesquisa(pesquisa: string, activo = true) {
  return useQuery({
    queryKey: ['crm', 'contas', 'pesquisa', pesquisa],
    queryFn: () => obterPagina<ContaCRM>('/crm/contas', { pesquisa, por_pagina: 30 }),
    staleTime: 60_000,
    enabled: activo,
  });
}
