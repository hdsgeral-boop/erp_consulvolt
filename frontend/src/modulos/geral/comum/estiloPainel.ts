/**
 * Aspecto do Dashboard Global do sistema anterior (js/ui_painel_modulos.js): ícone e cor de cada painel (MODULOS, linhas
 * 31-48) e de cada cartão KPI (`icon`/`cor` dos construtores, linhas 132-760). Só apresentação: os valores e as
 * permissões vêm do servidor (GET /gestao/paineis/*).
 */

export interface EstiloVisual {
  icone: string;
  cor: string;
}

/** Painéis (separadores em pílula): ícone Font Awesome e cor do módulo. */
export const ESTILO_PAINEIS: Record<string, EstiloVisual> = {
  geral: { icone: 'th-large', cor: '#0f172a' },
  rh: { icone: 'users', cor: '#4f46e5' },
  vendas: { icone: 'shopping-cart', cor: '#2563eb' },
  compras: { icone: 'truck-loading', cor: '#ea580c' },
  armazem: { icone: 'warehouse', cor: '#0d9488' },
  tesouraria: { icone: 'university', cor: '#0891b2' },
  contabilidade: { icone: 'book', cor: '#7c3aed' },
  ativos: { icone: 'couch', cor: '#db2777' },
  projetos: { icone: 'hard-hat', cor: '#b45309' },
  orcamento: { icone: 'file-invoice-dollar', cor: '#1d4ed8' },
  crm: { icone: 'handshake', cor: '#059669' },
  acrescimos: { icone: 'exchange-alt', cor: '#0d9488' },
  estrutura: { icone: 'sitemap', cor: '#0891b2' },
  cubo: { icone: 'cube', cor: '#0f766e' },
  grupo: { icone: 'sitemap', cor: '#7c3aed' },
  __comparacao: { icone: 'balance-scale', cor: '#7c3aed' },
};

const NEUTRO: EstiloVisual = { icone: 'chart-line', cor: '#64748b' };

export function estiloPainel(id: string): EstiloVisual {
  return ESTILO_PAINEIS[id] ?? NEUTRO;
}

const VERDE = '#10b981';
const VERMELHO = '#ef4444';

/**
 * Regra da cor que depende do valor (legado: resultado ≥ 0 verde senão vermelho; contadores de alerta coloridos só
 * quando > 0).
 */
type Regra = { tipo: 'sinal' } | { tipo: 'alerta'; com: string; sem: string };

interface EstiloKpi extends EstiloVisual {
  regra?: Regra;
}

const sinal: Regra = { tipo: 'sinal' };
const alerta = (com: string, sem: string): Regra => ({ tipo: 'alerta', com, sem });

/** Cartões por painel e id do indicador (o mesmo id pode ter cores diferentes em painéis diferentes, como no legado). */
const KPIS: Record<string, Record<string, EstiloKpi>> = {
  geral: {
    vendas_mes: { icone: 'shopping-cart', cor: '#2563eb' },
    compras_mes: { icone: 'truck-loading', cor: '#ea580c' },
    resultado_ano: { icone: 'chart-line', cor: VERDE, regra: sinal },
    disponibilidades: { icone: 'university', cor: '#0891b2' },
    clientes_receber: { icone: 'hand-holding-usd', cor: '#6366f1' },
    fornecedores_pagar: { icone: 'file-invoice-dollar', cor: '#f97316' },
    valor_inventario: { icone: 'boxes', cor: '#0d9488' },
    colaboradores_activos: { icone: 'users', cor: '#4f46e5' },
  },
  rh: {
    iliquido: { icone: 'money-bill-wave', cor: '#4f46e5' },
    inss_trabalhador: { icone: 'shield-alt', cor: '#ef4444' },
    inss_empresa: { icone: 'building', cor: '#f97316' },
    irt: { icone: 'percent', cor: '#dc2626' },
    liquido: { icone: 'wallet', cor: '#10b981' },
    custo_total: { icone: 'coins', cor: '#0f172a' },
    colaboradores_activos: { icone: 'users', cor: '#6366f1' },
    periodos_abertos: { icone: 'folder-open', cor: '#f59e0b' },
  },
  vendas: {
    faturacao_mes: { icone: 'file-invoice', cor: '#2563eb' },
    faturacao_ano: { icone: 'calendar-alt', cor: '#1d4ed8' },
    faturas_mes: { icone: 'receipt', cor: '#0ea5e9' },
    valor_medio_fatura: { icone: 'balance-scale', cor: '#6366f1' },
    notas_credito_mes: { icone: 'undo', cor: '#ef4444' },
    clientes_receber: { icone: 'hand-holding-usd', cor: '#10b981' },
    por_converter: { icone: 'clipboard-list', cor: '#f59e0b' },
  },
  compras: {
    compras_mes: { icone: 'truck-loading', cor: '#ea580c' },
    compras_ano: { icone: 'calendar-alt', cor: '#c2410c' },
    fornecedores_pagar: { icone: 'file-invoice-dollar', cor: '#ef4444' },
    pedidos_por_aprovar: { icone: 'clipboard-list', cor: '#f59e0b' },
    encomendas_em_curso: { icone: 'shipping-fast', cor: '#0ea5e9' },
    faturas_por_contabilizar: { icone: 'book', cor: '#7c3aed' },
  },
  armazem: {
    valor_inventario: { icone: 'boxes', cor: '#0d9488' },
    artigos_stock: { icone: 'cubes', cor: '#0891b2' },
    stock_baixo: { icone: 'exclamation-triangle', cor: '#f59e0b' },
    sem_stock: { icone: 'ban', cor: '#ef4444' },
    rececoes_por_validar: { icone: 'dolly', cor: '#6366f1' },
    guias_saida_mes: { icone: 'truck', cor: '#2563eb' },
  },
  tesouraria: {
    saldo_bancos: { icone: 'university', cor: '#0891b2' },
    saldo_caixa: { icone: 'cash-register', cor: '#0d9488' },
    recebimentos_mes: { icone: 'arrow-circle-down', cor: '#10b981' },
    pagamentos_mes: { icone: 'arrow-circle-up', cor: '#ef4444' },
    documentos_por_integrar: { icone: 'sync', cor: '#f59e0b' },
    movimentos_por_conciliar: { icone: 'check-double', cor: '#6366f1' },
    sessao_caixa: { icone: 'door-open', cor: '#64748b' },
  },
  contabilidade: {
    proveitos_ano: { icone: 'arrow-up', cor: '#10b981' },
    custos_ano: { icone: 'arrow-down', cor: '#ef4444' },
    resultado_ano: { icone: 'chart-line', cor: VERDE, regra: sinal },
    resultado_liquido_dr: { icone: 'chart-line', cor: VERDE, regra: sinal },
    margem: { icone: 'percentage', cor: '#7c3aed' },
    lancamentos_mes: { icone: 'book-open', cor: '#2563eb' },
    lancamentos_ano: { icone: 'layer-group', cor: '#64748b' },
  },
  projetos: {
    projetos_activos: { icone: 'hard-hat', cor: '#b45309' },
    // Versão Actual (ADR-071, G2 — ui_painel_modulos.js:485-495)
    venda_contratada: { icone: 'handshake', cor: '#8b5cf6' },
    venda_global: { icone: 'file-contract', cor: '#7c3aed' },
    faturado_venda: { icone: 'receipt', cor: '#2563eb' },
    por_faturar: { icone: 'hourglass-half', cor: '#f59e0b' },
    margem_prevista: { icone: 'bullseye', cor: '#0891b2' },
    orcamento: { icone: 'file-invoice-dollar', cor: '#0d9488' },
    custo_realizado: { icone: 'coins', cor: '#ef4444' },
    faturado: { icone: 'receipt', cor: '#2563eb' },
    margem: { icone: 'chart-line', cor: VERDE, regra: sinal },
    aditamentos: { icone: 'file-signature', cor: '#0891b2' },
    tarefas_concluidas: { icone: 'tasks', cor: '#10b981' },
    horas: { icone: 'clock', cor: '#64748b' },
  },
  ativos: {
    ativos_em_uso: { icone: 'couch', cor: '#db2777' },
    valor_aquisicao: { icone: 'tags', cor: '#7c3aed' },
    amortizacao_acumulada: { icone: 'percent', cor: '#ef4444' },
    valor_liquido: { icone: 'balance-scale', cor: '#10b981' },
    amortizacoes_ano: { icone: 'calendar-check', cor: '#f59e0b' },
    manutencoes_planeadas: { icone: 'tools', cor: '#0ea5e9' },
    sem_amortizacao: { icone: 'exclamation-circle', cor: '#64748b' },
  },
  orcamento: {
    orcado: { icone: 'file-invoice-dollar', cor: '#1d4ed8' },
    consumido: { icone: 'coins', cor: '#ef4444' },
    disponivel: { icone: 'wallet', cor: VERDE, regra: sinal },
    rubricas_aviso: { icone: 'exclamation-triangle', cor: '#f59e0b' },
    rubricas_excedidas: { icone: 'times-circle', cor: '#dc2626' },
    excessos_por_decidir: { icone: 'bell', cor: '#7c3aed' },
    orcamentos_aprovados: { icone: 'check-double', cor: '#059669' },
  },
  crm: {
    valor_funil: { icone: 'columns', cor: '#1d4ed8' },
    ponderado: { icone: 'balance-scale', cor: '#7c3aed' },
    valor_ganho: { icone: 'trophy', cor: '#10b981' },
    taxa_ganho: { icone: 'percentage', cor: '#059669' },
    ciclo_medio: { icone: 'hourglass-half', cor: '#0891b2' },
    ticket_medio: { icone: 'receipt', cor: '#2563eb' },
    atividades_atraso: { icone: 'calendar-times', cor: '#dc2626', regra: alerta('#dc2626', '#64748b') },
  },
  acrescimos: {
    acrescimos_aberto: { icone: 'plus-circle', cor: '#0d9488' },
    diferimentos_aberto: { icone: 'hourglass-half', cor: '#0891b2' },
    reconhecido_mes: { icone: 'calendar-check', cor: '#2563eb' },
    por_regularizar: { icone: 'exclamation-circle', cor: '#f59e0b' },
    contas_diferenca: { icone: 'balance-scale', cor: '#dc2626', regra: alerta('#dc2626', '#10b981') },
  },
  estrutura: {
    unidades: { icone: 'sitemap', cor: '#1d4ed8' },
    vagas_previstas: { icone: 'briefcase', cor: '#0891b2' },
    vagas_em_aberto: { icone: 'user-plus', cor: '#f59e0b', regra: alerta('#f59e0b', '#10b981') },
    acima_vagas: { icone: 'user-times', cor: '#dc2626', regra: alerta('#dc2626', '#64748b') },
    sem_unidade: { icone: 'user-slash', cor: '#f59e0b', regra: alerta('#f59e0b', '#10b981') },
    massa_salarial: { icone: 'money-bill-wave', cor: '#7c3aed' },
  },
  grupo: {
    proveitos: { icone: 'arrow-up', cor: '#10b981' },
    custos: { icone: 'arrow-down', cor: '#ef4444' },
    resultado: { icone: 'chart-line', cor: VERDE, regra: sinal },
    eliminacoes: { icone: 'exchange-alt', cor: '#7c3aed' },
    maior_contributo: { icone: 'trophy', cor: '#2563eb' },
    maior_resultado: { icone: 'medal', cor: '#047857' },
    empresas_prejuizo: { icone: 'exclamation-circle', cor: '#ef4444', regra: alerta('#ef4444', '#64748b') },
    disponibilidades: { icone: 'university', cor: '#0891b2' },
  },
};

/** Indicadores com o mesmo significado em qualquer painel (comparação de empresas, holding). */
const COMUNS: Record<string, EstiloKpi> = { ...KPIS.geral, ...KPIS.grupo };

function numero(v: unknown): number | null {
  if (v === null || v === undefined || v === '') return null;
  const n = typeof v === 'number' ? v : Number(v);
  return Number.isFinite(n) ? n : null;
}

/**
 * Ícone e cor de um cartão KPI do painel. Os indicadores sem entrada (novos no sistema actual) usam o ícone do painel e
 * a cor do módulo, para a grelha manter o aspecto do antigo.
 */
export function estiloKpi(painel: string, id: string, valor: unknown): EstiloVisual {
  const e = KPIS[painel]?.[id] ?? COMUNS[id];
  if (!e) return estiloPainel(painel === '__comparacao' ? 'contabilidade' : painel);
  const n = numero(valor);
  if (e.regra?.tipo === 'sinal' && n !== null) return { icone: e.icone, cor: n >= 0 ? VERDE : VERMELHO };
  if (e.regra?.tipo === 'alerta') return { icone: e.icone, cor: n !== null && n > 0 ? e.regra.com : e.regra.sem };
  return { icone: e.icone, cor: e.cor };
}
