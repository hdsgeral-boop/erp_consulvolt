import { createElement, lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrã previsto no plano e ainda não construído (ondas seguintes): mostra «em construção» com o nome do ecrã. */
const emConstrucao = (nome: string): LazyExoticComponent<ComponentType> =>
  lazy(async () => {
    const { EmConstrucao } = await import('@/componentes/EmConstrucao');
    return { default: () => createElement(EmConstrucao, { nome }) };
  });

/**
 * Ecrãs do módulo Gestão Imobiliária (ids do catálogo de permissões). Onda 1: cadastro, mapa de ocupação e modelos e taxas.
 * As ondas seguintes substituem as linhas «em construção» pelo ecrã real (contratos e rendas, parqueamento, áreas comuns,
 * obras, compra e venda, rentabilidade e fiscalidade).
 */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  imo_imoveis: lazy(() => import('./Imoveis')),
  imo_ocupacao: lazy(() => import('./MapaOcupacao')),
  imo_parque: emConstrucao('Parqueamento'),
  imo_areas: emConstrucao('Áreas Comuns e Reservas'),
  imo_fichas: emConstrucao('Fichas de Apetrechamento'),
  imo_obras: emConstrucao('Apetrechamento e Manutenção'),
  imo_contratos: emConstrucao('Contratos de Arrendamento'),
  imo_rendas: emConstrucao('Rendas do Período'),
  imo_sub: emConstrucao('Subarrendamento e Margem'),
  imo_negocios: emConstrucao('Compra e Venda de Imóveis'),
  imo_rentabilidade: emConstrucao('Rentabilidade por Fracção'),
  imo_fiscal: emConstrucao('Fiscalidade e Relato'),
  imo_modelos: lazy(() => import('./ModelosTaxas')),
};
