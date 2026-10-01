import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrãs do módulo Armazém e Inventário (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  armazem_stock: lazy(() => import('./NiveisStock')),
  armazem_rececoes: lazy(() => import('./ValidarEntradas')),
  armazem_guias: lazy(() => import('./Guias')),
  armazem_movimentos: lazy(() => import('./Movimentos')),
  armazem_armazens: lazy(() => import('./Armazens')),
  pos_armazem: lazy(() => import('./PosArmazem')),
  inventario_sessoes: lazy(() => import('./InventarioSessoes')),
  inventario_contagem: lazy(() => import('./InventarioContagem')),
  inventario_revisao: lazy(() => import('./InventarioRevisao')),
};
