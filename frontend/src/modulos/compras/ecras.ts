import { createElement, Fragment, lazy, type ComponentType, type LazyExoticComponent } from 'react';
import { BarraCompras } from './comum/BarraCompras';

/** Ecrã com a barra do módulo (acções rápidas e separadores, como no legado) por cima. */
const comBarra = (carregar: () => Promise<{ default: ComponentType }>) =>
  lazy(async () => {
    const { default: Ecra } = await carregar();
    return { default: () => createElement(Fragment, null, createElement(BarraCompras), createElement(Ecra)) };
  });

/** Ecrãs do módulo Compras e Aprovisionamento (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  compras_pedidos: comBarra(() => import('./pedidos/Pedidos')),
  compras_prospeccao: comBarra(() => import('./prospeccao/Prospeccao')),
  compras_encomendas: comBarra(() => import('./encomendas/Encomendas')),
  compras_rececoes: comBarra(() => import('./Rececoes')),
  compras_faturacao: comBarra(() => import('./faturacao/FaturacaoCompras')),
  compras_fornecedores: comBarra(() => import('./Fornecedores')),
  compras_encomendas_clientes: comBarra(() => import('./EncomendasClientes')),
  compras_contratos: comBarra(() => import('./contratos/Contratos')),
};
