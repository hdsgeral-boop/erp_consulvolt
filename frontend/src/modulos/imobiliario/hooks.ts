import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVES, obterListas, type Listas } from './api';

/** Enumerações do cadastro (rótulos, transições, províncias): mudam raramente, ficam em cache. */
export function useListas(): Listas | undefined {
  const q = useQuery({ queryKey: CHAVES.listas, queryFn: obterListas, staleTime: 5 * 60_000 });
  useEffect(() => { if (q.error) notificarErro(q.error, 'Não foi possível carregar as listas do módulo'); }, [q.error]);
  return q.data;
}

/** Invalida tudo o que é do módulo imobiliário (listas de imóveis, fracções, resumo, fichas). */
export function useRefrescar(): () => Promise<void> {
  const qc = useQueryClient();
  return () => qc.invalidateQueries({ queryKey: ['imobiliario'] });
}
