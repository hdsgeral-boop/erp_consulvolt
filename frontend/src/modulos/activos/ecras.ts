import type { ComponentType, LazyExoticComponent } from 'react';

/** Ecrãs do módulo activos (ids do catálogo de permissões). Acrescentar: id: lazy(() => import('./Ficheiro')). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {};
