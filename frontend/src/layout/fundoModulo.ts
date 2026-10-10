/**
 * Imagem de fundo da área de conteúdo por ecrã, como no legado (window.setModuleBackground, js/app_v2.js:10984-10992):
 * RH, Contabilidade, Armazém, Vendas/Tesouraria e Activos têm imagem própria; os restantes usam a do sistema.
 * Devolve a classe CSS a pôr na área de conteúdo (definidas em estilos/identidade.css).
 */
const FUNDOS: Record<string, string> = {
  // RH
  'rh/colaboradores': 'rh',
  'rh/funcoes': 'rh',
  'rh/infotipos': 'rh',
  'rh/calcular': 'rh',
  'rh/processamento': 'rh',
  'rh/relatorios': 'rh',
  'rh/contratos': 'rh',
  // Contabilidade
  'contab/lancamentos': 'contabilidade',
  'contab/relatorios_contabeis': 'contabilidade',
  'contab/contab_rotinas': 'contabilidade',
  // Armazém
  'stock/pos_armazem': 'armazem',
  // Vendas e Tesouraria
  'teso/teso_gestao_pagamentos': 'vendas',
  'teso/teso_gestao_mapas': 'vendas',
  'teso/teso_gestao_conciliacao': 'vendas',
  'teso/teso_contab_integracao': 'vendas',
  'teso/teso_contab_historico': 'vendas',
  'pos/pos': 'vendas',
  'vendas/vendas_clientes': 'vendas',
  // Activos
  'activos/activos': 'activos',
};

export function classeFundoModulo(caminho: string): string {
  const [, m, modulo, ecra] = caminho.split('/');
  if (m !== 'm' || !modulo || !ecra) return 'erp-fundo-sistema';
  // todos os ecrãs de Compras usam a imagem do armazém (chave «compras» do bgMap do legado)
  if (modulo === 'compras') return 'erp-fundo-armazem';
  const f = FUNDOS[`${modulo}/${ecra}`];
  return f ? `erp-fundo-${f}` : 'erp-fundo-sistema';
}
