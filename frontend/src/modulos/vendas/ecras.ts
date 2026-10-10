import { createElement, Fragment, lazy, type ComponentType, type LazyExoticComponent } from 'react';
import { BarraVendas } from './comum/BarraVendas';

/** Ecrã com o cabeçalho do agregador «Gestão de Vendas» (título, indicadores e separadores do legado) por cima. */
const comBarra = (carregar: () => Promise<{ default: ComponentType }>) =>
  lazy(async () => {
    const { default: Ecra } = await carregar();
    return { default: () => createElement(Fragment, null, createElement(BarraVendas), createElement(Ecra)) };
  });

/** Ecrãs do módulo Vendas (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  vendas_clientes: comBarra(() => import('./Clientes')),
  vendas_produtos: comBarra(() => import('./produtos/Produtos')),
  vendas_faturacao: comBarra(() => import('./Faturacao')),
  vendas_relatorios: comBarra(() => import('./relatorios/Relatorios')),
};
