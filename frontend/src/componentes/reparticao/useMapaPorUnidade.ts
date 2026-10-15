import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';
import { obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import type { MapaPorUnidade } from './TabelaPorUnidade';

/**
 * Mapa por unidade de negócio (GET {url}/por-unidade), com os mesmos parâmetros do mapa normal. Só consulta com o modo
 * «Por unidade de negócio» ligado e depois de «Calcular»; o comparativo não se aplica neste modo (como no legado).
 */
export function useMapaPorUnidade<T>(url: string, parametros: Record<string, unknown> | null, ligado: boolean) {
  const p = parametros ? Object.fromEntries(Object.entries(parametros).filter(([k]) => !['comparativo', 'modo_comparativo', 'unidade_negocio_id'].includes(k))) : null;
  const q = useQuery({
    queryKey: ['contab', 'mapa-por-unidade', url, p],
    queryFn: () => obter<MapaPorUnidade<T>>(`${url}/por-unidade`, p ?? undefined),
    enabled: ligado && p !== null,
    retry: false,
  });
  useEffect(() => {
    if (q.error) notificarErro(q.error, 'Não foi possível calcular o mapa por unidade de negócio');
  }, [q.error]);
  return q;
}
