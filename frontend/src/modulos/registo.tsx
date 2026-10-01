import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/**
 * Ecrãs disponíveis no frontend, pelo id do ecrã do catálogo de permissões (resources/permissoes/catalogo.json).
 * Rota: /m/{modulo}/{ecra}/*. Os ecrãs que ainda não estão aqui mostram «em construção».
 */
export const ECRAS: Record<string, LazyExoticComponent<ComponentType>> = {
  vendas_faturacao: lazy(() => import('./vendas/Faturacao')),
};
