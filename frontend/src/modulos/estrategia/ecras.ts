import { createElement, lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrã previsto no plano e ainda não construído (ondas seguintes): mostra «em construção» com o nome do ecrã. */
const emConstrucao = (nome: string): LazyExoticComponent<ComponentType> =>
  lazy(async () => {
    const { EmConstrucao } = await import('@/componentes/EmConstrucao');
    return { default: () => createElement(EmConstrucao, { nome }) };
  });

/** Ecrãs do módulo Planeamento Estratégico (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  est_painel: emConstrucao('Painel Estratégico'),
  est_diagnostico: emConstrucao('Diagnóstico'),
  est_mapa_estrategico: emConstrucao('Mapa Estratégico'),
  est_documento: emConstrucao('Documento do Plano'),
  est_plano: emConstrucao('Plano e Objectivos'),
  est_indicadores: emConstrucao('Indicadores e Metas'),
  est_iniciativas: emConstrucao('Iniciativas'),
  est_cascata: emConstrucao('Desdobramento'),
  est_revisoes: emConstrucao('Revisões'),
};
