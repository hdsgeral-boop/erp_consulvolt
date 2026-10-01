import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrãs do módulo CRM (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  crm_pipeline: lazy(() => import('./Pipeline')),
  crm_agenda: lazy(() => import('./Agenda')),
  crm_contas: lazy(() => import('./Contas')),
  crm_previsao: lazy(() => import('./Previsao')),
  crm_campanhas: lazy(() => import('./Campanhas')),
  crm_config: lazy(() => import('./ConfigCRM')),
};
