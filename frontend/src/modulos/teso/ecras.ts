import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrãs do módulo Tesouraria (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  teso_gestao_pagamentos: lazy(() => import('./Pagamentos')),
  teso_folha_caixa: lazy(() => import('./FolhaCaixa')),
  teso_contab_integracao: lazy(() => import('./Integracao')),
  teso_contab_historico: lazy(() => import('./Historico')),
  teso_gestao_mapas: lazy(() => import('./Mapas')),
  teso_gestao_conciliacao: lazy(() => import('./Conciliacao')),
  teso_gestao_conferencia: lazy(() => import('./Conferencia')),
  teso_meios_pagamento: lazy(() => import('./MeiosPagamento')),
};
