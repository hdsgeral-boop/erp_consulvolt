import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

type Mapa = 'Relatorios' | 'MapaRemuneracoes' | 'MapaIrt' | 'MapaInss' | 'MapaPagamentos' | 'MapaBanco' | 'MapaRecibos';
const mapa = (nome: Mapa) => lazy(() => import('./relatorios/Mapas').then((m) => ({ default: m[nome] })));

/** Ecrãs do módulo RH e Salários (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  colaboradores: lazy(() => import('./Colaboradores')),
  contratos: lazy(() => import('./Contratos')),
  funcoes: lazy(() => import('./Funcoes')),
  infotipos: lazy(() => import('./Infotipos')),
  bancario: lazy(() => import('./Bancario')),
  calcular: lazy(() => import('./Calcular')),
  processamento: lazy(() => import('./Processamento')),
  rh_assiduidade: lazy(() => import('./Assiduidade')),
  rh_produtividade: lazy(() => import('./Produtividade')),
  rh_ferias: lazy(() => import('./Ferias')),
  rh_avaliacao: lazy(() => import('./Avaliacao')),
  rh_avaliacao_config: lazy(() => import('./AvaliacaoConfig')),
  rh_avaliacao_ciclo: lazy(() => import('./AvaliacaoCiclo')),
  rh_portal: lazy(() => import('./Portal')),
  rh_portal_gestao: lazy(() => import('./PortalGestao')),
  relatorios: mapa('Relatorios'),
  rh_rel_remuneracoes: mapa('MapaRemuneracoes'),
  rh_rel_irt: mapa('MapaIrt'),
  rh_rel_inss: mapa('MapaInss'),
  rh_rel_pagamentos: mapa('MapaPagamentos'),
  rh_rel_banco: mapa('MapaBanco'),
  rh_rel_recibos: mapa('MapaRecibos'),
};
