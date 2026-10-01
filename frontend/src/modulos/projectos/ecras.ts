import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrãs do módulo Projectos (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  projectos_carteira: lazy(() => import('./Carteira')),
  projectos_extracto: lazy(() => import('./Extracto')),
  projectos_gantt: lazy(() => import('./GanttGlobal')),
};
