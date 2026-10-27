import { useQueryClient } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import type { MeioPagamentoVenda } from '../api';

/**
 * Conta bancária (meio de pagamento) marcada como predefinida da empresa, para o IBAN aparecer sempre nas Facturas e
 * Recibos impressos (mesmo que ninguém tenha escolhido nenhuma conta manualmente no documento). Partilha a mesma
 * query da emissão de documentos (`['vendas', 'meios-pagamento']`), por isso normalmente já vem da cache.
 */
export function useMeioPagamentoPredefinido() {
  const cliente = useQueryClient();
  return async (): Promise<MeioPagamentoVenda | null> => {
    try {
      const lista = await cliente.fetchQuery({ queryKey: ['vendas', 'meios-pagamento'], queryFn: () => obter<MeioPagamentoVenda[]>('/vendas/meios-pagamento'), staleTime: 300_000 });
      return lista.find((m) => m.predefinido) ?? null;
    } catch {
      return null; // sem meio predefinido (ou sem permissão para o consultar): o documento imprime-se na mesma, sem o IBAN automático
    }
  };
}
