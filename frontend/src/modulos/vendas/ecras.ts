import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrãs do módulo Vendas (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  vendas_clientes: lazy(() => import('./Clientes')),
  vendas_produtos: lazy(() => import('./produtos/Produtos')),
  vendas_faturacao: lazy(() => import('./Faturacao')),
  vendas_relatorios: lazy(() => import('./relatorios/Relatorios')),
};
