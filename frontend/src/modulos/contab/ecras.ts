import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrãs do módulo Contabilidade (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  lancamentos: lazy(() => import('./Lancamentos')),
  encerramento: lazy(() => import('./Encerramento')),
  relatorios_contabeis: lazy(() => import('./RelatoriosContabeis')),
  contab_mapa_extrato: lazy(() => import('./mapas/MapaExtrato')),
  contab_mapa_balancete: lazy(() => import('./mapas/MapaBalancete')),
  contab_mapa_balanco: lazy(() => import('./mapas/MapaBalanco')),
  contab_mapa_dr: lazy(() => import('./mapas/MapaDR')),
  contab_mapa_fluxo: lazy(() => import('./mapas/MapaFluxo')),
  contab_mapa_evolucao: lazy(() => import('./mapas/MapaEvolucao')),
  relatorio_contas: lazy(() => import('./RelatorioContas')),
  contab_rotinas: lazy(() => import('./Rotinas')),
  imposto_selo: lazy(() => import('./ImpostoSelo')),
  consolidacao: lazy(() => import('./Consolidacao')),
  tabelas_aux: lazy(() => import('./TabelasAux')),
  contabilidade: lazy(() => import('./MapeamentoSalarios')),
};
