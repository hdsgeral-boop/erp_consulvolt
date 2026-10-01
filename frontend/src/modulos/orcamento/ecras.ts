import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrãs do módulo Orçamento (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  orc_rubricas: lazy(() => import('./Rubricas')),
  orc_orcamentos: lazy(() => import('./Orcamentos')),
  orc_controlo: lazy(() => import('./Controlo')),
  orc_previsoes: lazy(() => import('./Previsoes')),
  orc_cenarios: lazy(() => import('./Cenarios')),
  orc_alertas: lazy(() => import('./Alertas')),
};
