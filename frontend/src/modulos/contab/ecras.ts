import { createElement, lazy, type ComponentType, type LazyExoticComponent } from 'react';
import type { ChaveSeparadorRelatorio } from './mapas/separadoresRelatorios';

/**
 * Ecrã único «Relatórios e mapas contabilísticos» (como no legado) aberto no separador pedido; `null` = o primeiro
 * permitido (entrada «Mapas e relatórios»). As rotas contab_mapa_* continuam a existir e mostram o mesmo ecrã.
 */
const relatorios = (separador: ChaveSeparadorRelatorio | null) =>
  lazy(async () => {
    const { EcraRelatorios } = await import('./RelatoriosContabeis');
    return { default: () => createElement(EcraRelatorios, { separador }) };
  });

/** Rotinas abertas num separador (imposto_selo abre as Rotinas no separador «Imposto de Selo», como no legado). */
const rotinas = (separador?: string) =>
  lazy(async () => {
    const { default: Rotinas } = await import('./Rotinas');
    return { default: () => createElement(Rotinas, { separadorInicial: separador }) };
  });

/** Ecrãs do módulo Contabilidade (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  lancamentos: lazy(() => import('./Lancamentos')),
  encerramento: lazy(() => import('./Encerramento')),
  relatorios_contabeis: relatorios(null),
  contab_mapa_extrato: relatorios('extrato'),
  contab_mapa_balancete: relatorios('balancete'),
  contab_mapa_balanco: relatorios('balanco'),
  contab_mapa_dr: relatorios('dr'),
  contab_mapa_fluxo: relatorios('fluxo'),
  contab_mapa_evolucao: relatorios('evolucao'),
  relatorio_contas: lazy(() => import('./RelatorioContas')),
  saft_contabilidade: lazy(() => import('./SaftContabilidade')),
  contab_rotinas: rotinas(),
  imposto_selo: rotinas('selo'),
  consolidacao: lazy(() => import('./Consolidacao')),
  tabelas_aux: lazy(() => import('./TabelasAux')),
  contabilidade: lazy(() => import('./MapeamentoSalarios')),
  // Versão Actual (Fase H): Assistente IA com regras em ficheiro JSON
  ia_assistente: lazy(() => import('./IAAssistente')),
};
