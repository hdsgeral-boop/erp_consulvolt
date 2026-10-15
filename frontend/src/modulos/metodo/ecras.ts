import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrãs do módulo Método de Trabalho (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  mt_assuntos: lazy(() => import('./Assuntos')),
};
