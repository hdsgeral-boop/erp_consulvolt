import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrãs do módulo POS, Lavandaria e Hotelaria (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  pos: lazy(() => import('./FrenteCaixa')),
  pos_relatorios: lazy(() => import('./Relatorios')),
  pos_terminais: lazy(() => import('./Terminais')),
  pos_config_print: lazy(() => import('./ConfigImpressao')),
  pos_integracao: lazy(() => import('./Integracao')),
  pos_desvios: lazy(() => import('./Desvios')),
  pos_prestacao: lazy(() => import('./Prestacao')),
  pos_lavandaria: lazy(() => import('./lavandaria/Lavandaria')),
  pos_hotelaria: lazy(() => import('./hotelaria/Hotelaria')),
};
