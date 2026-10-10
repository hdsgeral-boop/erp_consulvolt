import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/**
 * Ecrãs do módulo Orçamento (ids do catálogo de permissões), pela ordem do menu do sistema anterior (relatório 11.0.1):
 * Orçamentos, Controlo Orçamental, Previsões, Cenários, Alertas e Aprovações, Rubricas Orçamentais.
 */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  orc_orcamentos: lazy(() => import('./Orcamentos')),
  orc_controlo: lazy(() => import('./Controlo')),
  orc_previsoes: lazy(() => import('./Previsoes')),
  orc_cenarios: lazy(() => import('./Cenarios')),
  orc_alertas: lazy(() => import('./Alertas')),
  orc_rubricas: lazy(() => import('./Rubricas')),
};
