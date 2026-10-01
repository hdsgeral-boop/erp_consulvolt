import type { ComponentType, LazyExoticComponent } from 'react';
import { ecras as geral } from './geral/ecras';
import { ecras as rh } from './rh/ecras';
import { ecras as contab } from './contab/ecras';
import { ecras as teso } from './teso/ecras';
import { ecras as vendas } from './vendas/ecras';
import { ecras as pos } from './pos/ecras';
import { ecras as compras } from './compras/ecras';
import { ecras as stock } from './stock/ecras';
import { ecras as activos } from './activos/ecras';
import { ecras as projectos } from './projectos/ecras';
import { ecras as crm } from './crm/ecras';
import { ecras as acrescimos } from './acrescimos/ecras';
import { ecras as orcamento } from './orcamento/ecras';
import { ecras as estrutura } from './estrutura/ecras';
import { ecras as config } from './config/ecras';

/**
 * Ecrãs disponíveis no frontend, pelo id do ecrã do catálogo de permissões (resources/permissoes/catalogo.json).
 * Cada módulo regista os seus em src/modulos/<módulo>/ecras.ts. Rota: /m/{modulo}/{ecra}/*.
 * Os ecrãs que ainda não estão registados mostram «em construção».
 */
export const ECRAS: Record<string, LazyExoticComponent<ComponentType>> = {
  ...geral, ...rh, ...contab, ...teso, ...vendas, ...pos, ...compras, ...stock,
  ...activos, ...projectos, ...crm, ...acrescimos, ...orcamento, ...estrutura, ...config,
};
