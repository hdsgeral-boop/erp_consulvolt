import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrãs do módulo Geral (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  dashboard: lazy(() => import('./Dashboard')),
  relatorios_gestao: lazy(() => import('./RelatoriosGestao')),
  fluxo_processos: lazy(() => import('./FluxoProcessos')),
  accounting_bi: lazy(() => import('./AccountingBI')),
};
