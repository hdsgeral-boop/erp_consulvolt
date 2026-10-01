import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrãs do módulo Estrutura orgânica (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  est_estrutura: lazy(() => import('./Estrutura')),
  est_organigrama: lazy(() => import('./Organigrama')),
  est_mapa: lazy(() => import('./MapaPessoal')),
};
