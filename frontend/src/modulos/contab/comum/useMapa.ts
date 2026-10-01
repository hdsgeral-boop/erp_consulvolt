import { useQuery } from '@tanstack/react-query';
import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';

/** Mapa calculado a pedido: só consulta depois de «Calcular» (parâmetros ≠ null); repetir com os mesmos parâmetros refaz o cálculo. */
export function useMapa<T>(nome: string, url: string) {
  const [parametros, setParametros] = useState<Record<string, unknown> | null>(null);
  const consulta = useQuery({
    queryKey: ['contab', 'mapa', nome, url, parametros],
    queryFn: () => obter<T>(url, parametros ?? undefined),
    enabled: parametros !== null,
    retry: false,
  });
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Não foi possível calcular o mapa');
  }, [consulta.error]);
  const { refetch } = consulta;
  const calcular = useCallback(
    (p: Record<string, unknown>) => {
      setParametros((anterior) => {
        if (JSON.stringify(anterior) === JSON.stringify(p)) void refetch();
        return p;
      });
    },
    [refetch],
  );
  return { ...consulta, parametros, calcular };
}

/** Abre o lançamento (drill-down dos mapas) no ecrã Lançamentos, se o utilizador o puder ver. */
export function useAbrirLancamento(): ((id: number) => void) | undefined {
  const navegar = useNavigate();
  const { pode } = useSessao();
  return pode('lancamentos_view') ? (id: number) => navegar(`/m/contab/lancamentos/${id}`) : undefined;
}
