import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrãs do módulo Acréscimos e Diferimentos (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  ad_registos: lazy(() => import('./Registos')),
  ad_propostas: lazy(() => import('./Propostas')),
  ad_recolher: lazy(() => import('./Recolher')),
};
