import { createElement, Fragment, lazy, type ComponentType, type LazyExoticComponent } from 'react';
import { BarraArmazem, BarraInventario } from './comum/BarraArmazem';

/** Ecrã com o cabeçalho do módulo (CabecalhoModulo, G.5) por cima. */
const comCabecalho = (Barra: ComponentType) => (carregar: () => Promise<{ default: ComponentType }>) =>
  lazy(async () => {
    const { default: Ecra } = await carregar();
    return { default: () => createElement(Fragment, null, createElement(Barra), createElement(Ecra)) };
  });

/** «Gestão de Armazém» com os separadores do legado. */
const comBarra = comCabecalho(BarraArmazem);
/** «Inventário de Stock (Logística)» com os separadores do legado (2.7.1). */
const comBarraInventario = comCabecalho(BarraInventario);

/** Ecrãs do módulo Armazém e Inventário (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  armazem_stock: comBarra(() => import('./NiveisStock')),
  armazem_rececoes: comBarra(() => import('./ValidarEntradas')),
  armazem_guias: comBarra(() => import('./Guias')),
  armazem_movimentos: comBarra(() => import('./Movimentos')),
  armazem_armazens: comBarra(() => import('./Armazens')),
  pos_armazem: lazy(() => import('./PosArmazem')),
  inventario_sessoes: comBarraInventario(() => import('./InventarioSessoes')),
  inventario_contagem: comBarraInventario(() => import('./InventarioContagem')),
  inventario_revisao: comBarraInventario(() => import('./InventarioRevisao')),
};
