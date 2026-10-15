import { createElement, Fragment, lazy, type ComponentType, type LazyExoticComponent } from 'react';
import { BarraTesouraria, GRUPO_DO_ECRA } from './BarraTesouraria';

/** Ecrã com o cabeçalho agregador do legado («Tesouraria - Operações / Contabilidade / Gestão») por cima. */
const comBarra = (ecra: string, carregar: () => Promise<{ default: ComponentType }>) =>
  lazy(async () => {
    const { default: Ecra } = await carregar();
    const grupo = GRUPO_DO_ECRA[ecra];
    return { default: () => createElement(Fragment, null, createElement(BarraTesouraria, { grupo }), createElement(Ecra)) };
  });

/** Ecrãs do módulo Tesouraria (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  teso_gestao_pagamentos: comBarra('teso_gestao_pagamentos', () => import('./Pagamentos')),
  teso_folha_caixa: comBarra('teso_folha_caixa', () => import('./FolhaCaixa')),
  teso_contab_integracao: comBarra('teso_contab_integracao', () => import('./Integracao')),
  teso_contab_historico: comBarra('teso_contab_historico', () => import('./Historico')),
  teso_gestao_mapas: comBarra('teso_gestao_mapas', () => import('./Mapas')),
  teso_gestao_conciliacao: comBarra('teso_gestao_conciliacao', () => import('./Conciliacao')),
  teso_gestao_conferencia: comBarra('teso_gestao_conferencia', () => import('./Conferencia')),
  // página própria no legado (renderMeiosPagamentoPage), com o seu cabeçalho e «Ecrã Cheio»
  teso_meios_pagamento: lazy(() => import('./MeiosPagamento')),
  // página própria no legado novo (renderCartasPagamento): Cartas emitidas / Emitir / Modelo de texto (Fase D, ADR-071)
  teso_cartas: lazy(() => import('./cartas/CartasPagamento')),
};
