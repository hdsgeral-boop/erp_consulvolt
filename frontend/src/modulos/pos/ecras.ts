import { createElement, Fragment, lazy, type ComponentType, type LazyExoticComponent } from 'react';
import { BarraPos } from './comum/BarraPos';

/** Ecrã com o cabeçalho do módulo POS (título, subtítulo e separadores do legado) por cima. */
const comBarra = (carregar: () => Promise<{ default: ComponentType }>) =>
  lazy(async () => {
    const { default: Ecra } = await carregar();
    return { default: () => createElement(Fragment, null, createElement(BarraPos), createElement(Ecra)) };
  });

/** Ecrãs do módulo POS, Lavandaria e Hotelaria (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  pos: comBarra(() => import('./FrenteCaixa')),
  pos_relatorios: comBarra(() => import('./Relatorios')),
  pos_terminais: comBarra(() => import('./Terminais')),
  pos_config_print: comBarra(() => import('./ConfigImpressao')),
  pos_integracao: comBarra(() => import('./Integracao')),
  pos_desvios: comBarra(() => import('./Desvios')),
  pos_prestacao: comBarra(() => import('./Prestacao')),
  pos_lavandaria: comBarra(() => import('./lavandaria/Lavandaria')),
  pos_hotelaria: comBarra(() => import('./hotelaria/Hotelaria')),
};
