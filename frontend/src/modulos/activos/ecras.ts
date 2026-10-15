import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrãs do módulo Activos (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  activos: lazy(() => import('./Activos')),
  // Fase F (Versão Actual): Edifícios e Locais e folhas de inventário físico
  activos_edificios: lazy(() => import('./edificios/Edificios')),
  activos_categorias: lazy(() => import('./Categorias')),
  activos_manutencao: lazy(() => import('./Manutencao')),
  activos_amortizacoes: lazy(() => import('./Amortizacoes')),
  activos_abates: lazy(() => import('./Abates')),
  activos_pendentes: lazy(() => import('./Pendentes')),
  // «Mapa de Amortizações» e, em /fiscal, o «Mapa Fiscal» (mesma permissão no legado)
  activos_mapa: lazy(() => import('./fiscal/RotaMapa')),
};
