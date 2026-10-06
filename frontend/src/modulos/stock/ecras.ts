import { createElement, Fragment, lazy, type ComponentType, type LazyExoticComponent } from 'react';
import { BarraArmazem } from './comum/BarraArmazem';

/** Ecrã do «Gestão de Armazém» com os separadores do legado por cima. */
const comBarra = (carregar: () => Promise<{ default: ComponentType }>) =>
  lazy(async () => {
    const { default: Ecra } = await carregar();
    return { default: () => createElement(Fragment, null, createElement(BarraArmazem), createElement(Ecra)) };
  });

/** Ecrãs do módulo Armazém e Inventário (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  armazem_stock: comBarra(() => import('./NiveisStock')),
  armazem_rececoes: comBarra(() => import('./ValidarEntradas')),
  armazem_guias: comBarra(() => import('./Guias')),
  armazem_movimentos: comBarra(() => import('./Movimentos')),
  armazem_armazens: comBarra(() => import('./Armazens')),
  pos_armazem: lazy(() => import('./PosArmazem')),
  inventario_sessoes: lazy(() => import('./InventarioSessoes')),
  inventario_contagem: lazy(() => import('./InventarioContagem')),
  inventario_revisao: lazy(() => import('./InventarioRevisao')),
};
