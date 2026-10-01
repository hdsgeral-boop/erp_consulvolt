import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrãs do módulo Activos (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  activos: lazy(() => import('./Activos')),
  activos_categorias: lazy(() => import('./Categorias')),
  activos_manutencao: lazy(() => import('./Manutencao')),
  activos_amortizacoes: lazy(() => import('./Amortizacoes')),
  activos_abates: lazy(() => import('./Abates')),
  activos_pendentes: lazy(() => import('./Pendentes')),
  activos_mapa: lazy(() => import('./Mapa')),
};
