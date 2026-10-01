import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrãs do módulo Configurações (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  config_geral: lazy(() => import('./ConfigGeral')),
  config_empresas: lazy(() => import('./Empresas')),
  config_plano: lazy(() => import('./PlanoContas')),
  config_moedas: lazy(() => import('./Moedas')),
  config_utilizadores: lazy(() => import('./Utilizadores')),
  config_perfis: lazy(() => import('./Perfis')),
  config_logs: lazy(() => import('./Logs')),
  config_manutencao: lazy(() => import('./Manutencao')),
  config_migracao: lazy(() => import('./Migracao')),
};
