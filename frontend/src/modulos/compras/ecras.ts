import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrãs do módulo Compras e Aprovisionamento (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  compras_pedidos: lazy(() => import('./pedidos/Pedidos')),
  compras_prospeccao: lazy(() => import('./prospeccao/Prospeccao')),
  compras_encomendas: lazy(() => import('./encomendas/Encomendas')),
  compras_rececoes: lazy(() => import('./Rececoes')),
  compras_faturacao: lazy(() => import('./faturacao/FaturacaoCompras')),
  compras_fornecedores: lazy(() => import('./Fornecedores')),
  compras_encomendas_clientes: lazy(() => import('./EncomendasClientes')),
  compras_contratos: lazy(() => import('./contratos/Contratos')),
};
