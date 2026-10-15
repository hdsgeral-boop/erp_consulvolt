import { useQuery } from '@tanstack/react-query';
import { enviar, obter } from '@/api/cliente';
import type { Alocacao, CriterioReparticao, Forma, ResultadoSimulacao } from './regras';

/** Objectos com alocação gravada (PUT /reparticao/alocacoes/{tipo}/{id}). */
export type TipoObjectoAlocacao = 'colaborador' | 'pedido_compra' | 'encomenda_compra' | 'fatura_compra' | 'venda' | 'recibo_venda';

export const CHAVE_CRITERIOS = ['reparticao', 'criterios'] as const;

/** Critérios de repartição da empresa activa (só os activos, para os formulários). */
export function useCriterios(soActivos = true) {
  return useQuery({
    queryKey: [...CHAVE_CRITERIOS, soActivos],
    queryFn: () => obter<CriterioReparticao[]>('/reparticao/criterios', soActivos ? { activos: 1 } : undefined),
    staleTime: 60_000,
  });
}

export function useFormas() {
  return useQuery({ queryKey: ['reparticao', 'formas'], queryFn: () => obter<Forma[]>('/reparticao/formas'), staleTime: 10 * 60_000 });
}

/** Percentagens (sem valor) ou parcelas (com valor) de uma alocação — cálculo no servidor, em BCMath. */
export function simularReparticao(alocacao: Alocacao, valor?: number | null): Promise<ResultadoSimulacao> {
  return enviar<ResultadoSimulacao>('post', '/reparticao/simular', { unidades_alocadas: alocacao, ...(valor && valor > 0 ? { valor } : {}) }).then((r) => r.dados);
}

export function obterAlocacao(tipo: TipoObjectoAlocacao, id: number) {
  return obter<{ unidades_alocadas: Alocacao | null; unidade_negocio_id: number | null }>(`/reparticao/alocacoes/${tipo}/${id}`);
}

export function gravarAlocacao(tipo: TipoObjectoAlocacao, id: number, alocacao: Alocacao | null) {
  return enviar<{ unidades_alocadas: Alocacao | null }>('put', `/reparticao/alocacoes/${tipo}/${id}`, { unidades_alocadas: alocacao });
}
