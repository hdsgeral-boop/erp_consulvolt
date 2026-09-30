// Normalização de enumerações textuais (decisão 2026-09-29): cada coluna listada passa a guardar
// um CÓDIGO normalizado e ganha a coluna irmã `<coluna_pt>_original` com o texto exacto do legado.
//
// Chave: 'tabela_legado.coluna_legado'. `mapa` é indexado pelo valor DOBRADO (maiúsculas, sem acentos,
// espaços/_/- colapsados — ver dobrar()). Valores fora do mapa não são inventados: o código fica NULL,
// o original é preservado e é registada uma ocorrência de migração para revisão.

export const dobrar = (s) =>
  String(s).normalize('NFD').replace(/[̀-ͯ�]/g, '').replace(/[\s_-]+/g, ' ').trim().toUpperCase();

export const NORMALIZACOES = {
  // ── Terceiros ──
  'third_parties.type': { dominio: ['CLIENTE', 'FORNECEDOR', 'COLABORADOR', 'CLIENTE_FORNECEDOR'], mapa: {
    CLIENTE: 'CLIENTE', FORNECEDOR: 'FORNECEDOR', COLABORADOR: 'COLABORADOR', 'FORNECEDOR, CLIENTE': 'CLIENTE_FORNECEDOR', 'CLIENTE, FORNECEDOR': 'CLIENTE_FORNECEDOR' } },

  // ── Vendas (códigos AGT / SAF-T AO) ──
  'sales.doc_type': { dominio: ['FT', 'FR', 'NC', 'ND', 'PF', 'OR', 'NE', 'GR', 'GD'], mapa: {
    FACTURA: 'FT', FATURA: 'FT', FT: 'FT', 'FACTURA RECIBO': 'FR', 'FATURA RECIBO': 'FR', FR: 'FR',
    'NOTA DE CREDITO': 'NC', NC: 'NC', 'NOTA DE DEBITO': 'ND', ND: 'ND',
    PROFORMA: 'PF', 'FACTURA PROFORMA': 'PF', 'FATURA PROFORMA': 'PF', 'FACTURA PRO FORMA': 'PF', PF: 'PF',
    ORCAMENTO: 'OR', OR: 'OR', ENCOMENDA: 'NE', 'NOTA DE ENCOMENDA': 'NE', NE: 'NE', 'GUIA DE REMESSA': 'GR', GR: 'GR',
    'GUIA DE DEVOLUCAO': 'GD', GD: 'GD' } },
  'sales.status': { dominio: ['PENDENTE', 'PARCIAL', 'PAGO', 'CONCLUIDO', 'ANULADO'], mapa: {
    PENDENTE: 'PENDENTE', PARCIAL: 'PARCIAL', PAGO: 'PAGO', CONCLUIDO: 'CONCLUIDO', ANULADO: 'ANULADO', ANULADA: 'ANULADO' } },
  'sales.payment_method': { dominio: ['NUMERARIO', 'TPA', 'TRANSFERENCIA', 'CONTA_CORRENTE', 'MISTO'], mapa: {   // MISTO: venda POS com vários meios
    NUMERARIO: 'NUMERARIO', MULTICAIXA: 'TPA', 'MULTICAIXA (TPA)': 'TPA', TPA: 'TPA',
    'TRANSFERENCIA BANCARIA': 'TRANSFERENCIA', TRANSFERENCIA: 'TRANSFERENCIA', 'CONTA CORRENTE': 'CONTA_CORRENTE' } },
  'sales.payment_mode': { dominio: ['PRONTO', 'PRAZO', 'MARCOS'], mapa: { PRONTO: 'PRONTO', PRAZO: 'PRAZO', MARCOS: 'MARCOS' } },

  // ── Compras ──
  'purchase_items.parent_type': { dominio: ['PEDIDO', 'COTACAO', 'ENCOMENDA', 'FATURA'], mapa: {
    REQUEST: 'PEDIDO', QUOTE: 'COTACAO', ORDER: 'ENCOMENDA', INVOICE: 'FATURA' } },
  'purchase_contracts.status': { dominio: ['ATIVO', 'EXPIRADO', 'CANCELADO'], mapa: { ATIVO: 'ATIVO', EXPIRADO: 'EXPIRADO', CANCELADO: 'CANCELADO' } },
  'purchase_contract_milestones.status': { dominio: ['PENDENTE', 'FATURADO', 'PAGO'], mapa: { PENDENTE: 'PENDENTE', FATURADO: 'FATURADO', PAGO: 'PAGO' } },
  'purchase_requests.status': { dominio: ['PENDENTE', 'APROVADO', 'REJEITADO', 'ADJUDICADO', 'FECHADO', 'ANULADO'], mapa: {
    PENDENTE: 'PENDENTE', APROVADO: 'APROVADO', REJEITADO: 'REJEITADO', ADJUDICADO: 'ADJUDICADO', FECHADO: 'FECHADO', ANULADO: 'ANULADO' } },
  'purchase_quotes.status': { dominio: ['PROPOSTA', 'PROPOSTA_ADJUDICACAO', 'ADJUDICADO', 'RECUSADA', 'ANULADA'], mapa: {
    PROPOSTA: 'PROPOSTA', 'PROPOSTA ADJUDICACAO': 'PROPOSTA_ADJUDICACAO', ADJUDICADO: 'ADJUDICADO', RECUSADA: 'RECUSADA', ANULADA: 'ANULADA' } },
  'purchase_orders.status': { dominio: ['EM_PROCESSAMENTO', 'PARCIAL', 'RECEBIDO', 'ANULADA'], mapa: {
    'EM PROCESSAMENTO': 'EM_PROCESSAMENTO', PARCIAL: 'PARCIAL', RECEBIDO: 'RECEBIDO', ANULADA: 'ANULADA' } },
  'purchase_deliveries.status': { dominio: ['RECEBIDO', 'VALIDADO', 'ANULADO'], mapa: {
    RECEBIDO: 'RECEBIDO', VALIDADO: 'VALIDADO', ANULADO: 'ANULADO' } },
  'purchase_invoices.status': { dominio: ['PENDENTE', 'PARCIAL', 'PAGO', 'ANULADA'], mapa: {
    PENDENTE: 'PENDENTE', PARCIAL: 'PARCIAL', PAGO: 'PAGO', ANULADA: 'ANULADA' } },

  // ── Logística ──
  'inventory_movements.type': { dominio: ['ENTRADA', 'SAIDA', 'TRANSFERENCIA', 'AJUSTE'], mapa: {
    ENTRADA: 'ENTRADA', SAIDA: 'SAIDA', TRANSFERENCIA: 'TRANSFERENCIA', AJUSTE: 'AJUSTE' } },
  'delivery_notes.type': { dominio: ['VENDA', 'BACK_TO_BACK', 'CONSUMO'], mapa: { VENDA: 'VENDA', 'BACK TO BACK': 'BACK_TO_BACK', CONSUMO: 'CONSUMO' } },
  'delivery_notes.status': { dominio: ['CONCLUIDO', 'FATURADA', 'ANULADA'], mapa: { CONCLUIDO: 'CONCLUIDO', FATURADA: 'FATURADA', ANULADA: 'ANULADA' } },
  'inventory_sessions.status': { dominio: ['EM_CONTAGEM', 'REVISAO', 'CONCLUIDA', 'ANULADA'], mapa: {
    'EM CONTAGEM': 'EM_CONTAGEM', REVISAO: 'REVISAO', CONCLUIDA: 'CONCLUIDA', ANULADA: 'ANULADA' } },

  // ── RH ──
  'infotypes.calculo_horas': { dominio: ['EXTRA', 'FALTA', 'NAO'], mapa: { EXTRA: 'EXTRA', FALTA: 'FALTA', NAO: 'NAO' } },
  'rh_attendance.origem': { dominio: ['MANUAL', 'FICHEIRO', 'RELOGIO'], mapa: { MANUAL: 'MANUAL', FICHEIRO: 'FICHEIRO', RELOGIO: 'RELOGIO' } },
  'rh_attendance_closures.estado': { dominio: ['FECHADO', 'REABERTO'], mapa: { FECHADO: 'FECHADO', REABERTO: 'REABERTO' } },
  'rh_absences.estado': { dominio: ['POR_JUSTIFICAR', 'PENDENTE_CHEFIA', 'PENDENTE_RH', 'APROVADO', 'RECUSADO', 'CANCELADO'], mapa: {
    'POR JUSTIFICAR': 'POR_JUSTIFICAR', 'PENDENTE CHEFIA': 'PENDENTE_CHEFIA', 'PENDENTE RH': 'PENDENTE_RH', APROVADO: 'APROVADO', RECUSADO: 'RECUSADO', CANCELADO: 'CANCELADO' } },
  'rh_absences.remunerada': { dominio: ['SIM', 'NAO', 'EMPREGADOR'], mapa: { TRUE: 'SIM', FALSE: 'NAO', EMPREGADOR: 'EMPREGADOR' } },
  'rh_vacations.status': { dominio: ['PEDIDO', 'PLANEADO', 'APROVADO', 'GOZADO', 'CANCELADO'], mapa: {
    PEDIDO: 'PEDIDO', PLANEADO: 'PLANEADO', APROVADO: 'APROVADO', GOZADO: 'GOZADO', CANCELADO: 'CANCELADO' } },
  'rh_prod_periods.estado': { dominio: ['ABERTO', 'FECHADO'], mapa: { ABERTO: 'ABERTO', FECHADO: 'FECHADO' } },
  'rh_prod_items.metrica': { dominio: ['QUANTIDADE', 'HORAS', 'OBJECTIVO', 'PONTOS', 'TAREFAS'], mapa: {
    QUANTIDADE: 'QUANTIDADE', HORAS: 'HORAS', OBJECTIVO: 'OBJECTIVO', PONTOS: 'PONTOS', TAREFAS: 'TAREFAS' } },
  'rh_portal_requests.tipo': { dominio: ['FERIAS', 'AUSENCIA', 'DOCUMENTO', 'AGREGADO'], mapa: { FERIAS: 'FERIAS', AUSENCIA: 'AUSENCIA', DOCUMENTO: 'DOCUMENTO', AGREGADO: 'AGREGADO' } },
  'rh_portal_requests.estado': { dominio: ['PENDENTE_CHEFIA', 'PENDENTE_RH', 'APROVADO', 'EMITIDO', 'RECUSADO', 'CANCELADO'], mapa: {
    'PENDENTE CHEFIA': 'PENDENTE_CHEFIA', 'PENDENTE RH': 'PENDENTE_RH', APROVADO: 'APROVADO', EMITIDO: 'EMITIDO', RECUSADO: 'RECUSADO', CANCELADO: 'CANCELADO' } },
  'org_units.tipo': { dominio: ['ORGAO_SOCIAL', 'DIRECCAO_GERAL', 'DIRECCAO', 'DEPARTAMENTO', 'GABINETE', 'SECCAO', 'EQUIPA', 'OUTRO'], mapa: {
    'ORGAO SOCIAL': 'ORGAO_SOCIAL', 'DIRECCAO GERAL': 'DIRECCAO_GERAL', DIRECCAO: 'DIRECCAO', DEPARTAMENTO: 'DEPARTAMENTO', GABINETE: 'GABINETE', SECCAO: 'SECCAO', EQUIPA: 'EQUIPA', OUTRO: 'OUTRO' } },
  'rh_evaluations.status': { dominio: ['RASCUNHO', 'CONCLUIDA'], mapa: { RASCUNHO: 'RASCUNHO', CONCLUIDA: 'CONCLUIDA' } },
  'rh_self_evaluations.status': { dominio: ['RASCUNHO', 'SUBMETIDA'], mapa: { RASCUNHO: 'RASCUNHO', SUBMETIDA: 'SUBMETIDA' } },
  'rh_eval_cycles.estado': { dominio: ['RASCUNHO', 'ABERTO', 'FECHADO'], mapa: { RASCUNHO: 'RASCUNHO', ABERTO: 'ABERTO', FECHADO: 'FECHADO' } },
  'rh_eval_bonus.estado': { dominio: ['PROPOSTA', 'APROVADA', 'LANCADA'], mapa: { PROPOSTA: 'PROPOSTA', APROVADA: 'APROVADA', LANCADA: 'LANCADA' } },
  'rh_evaluation_items.tipo': { dominio: ['CRITERIO', 'OBJECTIVO'], mapa: { CRITERIO: 'CRITERIO', OBJECTIVO: 'OBJECTIVO' } },
  'rh_evaluation_items.ambito': { dominio: ['COMUM', 'ESPECIFICO'], mapa: { COMUM: 'COMUM', ESPECIFICO: 'ESPECIFICO' } },
  'orc_budgets.status': { dominio: ['RASCUNHO', 'SUBMETIDO', 'APROVADO', 'SUBSTITUIDO'], mapa: {
    RASCUNHO: 'RASCUNHO', SUBMETIDO: 'SUBMETIDO', APROVADO: 'APROVADO', SUBSTITUIDO: 'SUBSTITUIDO' } },
  'orc_budgets.tipo': { dominio: ['EXPLORACAO', 'TESOURARIA'], mapa: { EXPLORACAO: 'EXPLORACAO', TESOURARIA: 'TESOURARIA' } },
  'orc_rubrics.tipo': { dominio: ['EXPLORACAO', 'TESOURARIA'], mapa: { EXPLORACAO: 'EXPLORACAO', TESOURARIA: 'TESOURARIA' } },
  'orc_rubrics.natureza': { dominio: ['PROVEITO', 'CUSTO', 'RECEBIMENTO', 'PAGAMENTO'], mapa: {
    PROVEITO: 'PROVEITO', CUSTO: 'CUSTO', RECEBIMENTO: 'RECEBIMENTO', PAGAMENTO: 'PAGAMENTO' } },
  'orc_excess_requests.status': { dominio: ['PENDENTE', 'APROVADO', 'REJEITADO', 'UTILIZADO'], mapa: {
    PENDENTE: 'PENDENTE', APROVADO: 'APROVADO', REJEITADO: 'REJEITADO', UTILIZADO: 'UTILIZADO' } },
  'orc_forecasts.status': { dominio: ['RASCUNHO', 'PUBLICADA'], mapa: { RASCUNHO: 'RASCUNHO', PUBLICADA: 'PUBLICADA' } },
  'payroll_periods.status': { dominio: ['ABERTO', 'FECHADO', 'VALIDADO'], mapa: { ABERTO: 'ABERTO', FECHADO: 'FECHADO', VALIDADO: 'VALIDADO' } },
  'employees.status': { dominio: ['ACTIVO', 'INACTIVO', 'SUSPENSO'], mapa: {
    ACTIVO: 'ACTIVO', ATIVO: 'ACTIVO', 'NAO ACTIVO': 'INACTIVO', 'NAO ATIVO': 'INACTIVO', INACTIVO: 'INACTIVO', INATIVO: 'INACTIVO', SUSPENSO: 'SUSPENSO' } },
  'employees.estado_civil': { dominio: ['SOLTEIRO', 'CASADO', 'DIVORCIADO', 'VIUVO', 'UNIAO_FACTO'], mapa: {
    'SOLTEIRO(A)': 'SOLTEIRO', SOLTEIRO: 'SOLTEIRO', SOLTEIRA: 'SOLTEIRO', 'CASADO(A)': 'CASADO', CASADO: 'CASADO', CASADA: 'CASADO',
    'DIVORCIADO(A)': 'DIVORCIADO', 'VIUVO(A)': 'VIUVO', 'UNIAO DE FACTO': 'UNIAO_FACTO' } },
  'rh_dependents.parentesco': { dominio: ['FILHO', 'CONJUGE', 'PAI', 'MAE', 'OUTRO'], mapa: {
    'FILHO(A)': 'FILHO', FILHO: 'FILHO', FILHA: 'FILHO', CONJUGE: 'CONJUGE', PAI: 'PAI', MAE: 'MAE' } },

  // ── Tesouraria / caixa / POS ──
  'treasury_documents.type': { dominio: ['PAGAMENTO', 'RECEBIMENTO'], mapa: { PAGAMENTO: 'PAGAMENTO', RECEBIMENTO: 'RECEBIMENTO' } },
  'treasury_documents.status': { dominio: ['PENDENTE', 'INTEGRADO', 'ANULADO'], mapa: { PENDENTE: 'PENDENTE', INTEGRADO: 'INTEGRADO', ANULADO: 'ANULADO' } },
  'cash_sessions.status': { dominio: ['ABERTA', 'FECHADA', 'CONTABILIZADA'], mapa: { ABERTA: 'ABERTA', FECHADA: 'FECHADA', CONTABILIZADA: 'CONTABILIZADA' } },
  'cash_lines.type': { dominio: ['REC', 'PAG'], mapa: { REC: 'REC', PAG: 'PAG', RECEBIMENTO: 'REC', PAGAMENTO: 'PAG' } },
  'cash_audits.status': { dominio: ['RASCUNHO', 'FINALIZADO'], mapa: { RASCUNHO: 'RASCUNHO', FINALIZADO: 'FINALIZADO' } },
  'bank_statement_lines.status': { dominio: ['PENDENTE', 'CONCILIADO', 'ANULADO'], mapa: {
    PENDING: 'PENDENTE', PENDENTE: 'PENDENTE', CONCILIATED: 'CONCILIADO', CONCILIADO: 'CONCILIADO' } },
  'reconciliations.type': { dominio: ['ATUALIZACAO_LOTE'], mapa: { 'BATCH UPDATE': 'ATUALIZACAO_LOTE' } },
  'reconciliation_matches.match_type': { dominio: ['AUTOMATICA', 'MANUAL'], mapa: { AUTO: 'AUTOMATICA', MANUAL: 'MANUAL' } },
  'cash_lines.source_type': { dominio: ['CONTABILIDADE', 'IMPORTACAO', 'MANUAL', 'FATURA_COMPRA', 'POS'], mapa: {
    CONTABILIDADE: 'CONTABILIDADE', IMPORT: 'IMPORTACAO', MANUAL: 'MANUAL', 'PURCHASE INVOICE': 'FATURA_COMPRA', POS: 'POS' } },
  'pos_terminals.type': { dominio: ['LOJA', 'RESTAURANTE', 'LAVANDARIA', 'HOTELARIA'], mapa: { LOJA: 'LOJA', RESTAURANTE: 'RESTAURANTE', LAVANDARIA: 'LAVANDARIA', HOTELARIA: 'HOTELARIA' } },
  'pos_sessions.deviation_status': { dominio: ['NAO_APLICAVEL', 'SEM_DESVIO', 'DELIBERADO', 'PENDENTE'], mapa: {
    'N/A': 'NAO_APLICAVEL', 'SEM DESVIO': 'SEM_DESVIO', DELIBERADO: 'DELIBERADO', PENDENTE: 'PENDENTE' } },

  // ── Contabilidade ──
  'journal_lines.source_doc_type': { dominio: ['FATURA_COMPRA', 'RECECAO_COMPRA'], mapa: {
    'PURCHASE INVOICE': 'FATURA_COMPRA', 'PURCHASE DELIVERY': 'RECECAO_COMPRA' } },
  'recycled_journal_lines.source_doc_type': { dominio: ['FATURA_COMPRA', 'RECECAO_COMPRA'], mapa: {
    'PURCHASE INVOICE': 'FATURA_COMPRA', 'PURCHASE DELIVERY': 'RECECAO_COMPRA' } },
  'historical_balances.type': { dominio: ['DEMONSTRACAO_RESULTADOS', 'FLUXO_CAIXA'], mapa: { DEMO: 'DEMONSTRACAO_RESULTADOS', FLUXO: 'FLUXO_CAIXA' } },
  'annual_reports.status': { dominio: ['RASCUNHO', 'APROVADO'], mapa: { DRAFT: 'RASCUNHO', RASCUNHO: 'RASCUNHO', APPROVED: 'APROVADO', APROVADO: 'APROVADO' } },

  // ── Activos / projectos ──
  'asset_maintenance_records.status': { dominio: ['PLANEADA', 'EM_CURSO', 'CONCLUIDA'], mapa: { CONCLUIDA: 'CONCLUIDA', 'EM CURSO': 'EM_CURSO', PLANEADA: 'PLANEADA' } },
  'project_tasks.status': { dominio: ['PENDENTE', 'EM_CURSO', 'CONCLUIDA'], mapa: {
    PENDENTE: 'PENDENTE', 'EM CURSO': 'EM_CURSO', FAZENDO: 'EM_CURSO', CONCLUIDA: 'CONCLUIDA' } },
  'project_ledger.nature': { dominio: ['CUSTO', 'CUSTO_REAL', 'PROVEITO'], mapa: {
    C: 'CUSTO', CUSTO: 'CUSTO', 'CUSTO REAL': 'CUSTO_REAL', PROVEITO: 'PROVEITO', P: 'PROVEITO' } },
  'project_ledger.source_doc_type': { dominio: ['AUTO_INTERNO', 'REGISTO_OBRA', 'FATURA'], mapa: {
    'AUTO INTERNO': 'AUTO_INTERNO', 'REGISTO DE OBRA': 'REGISTO_OBRA', FACTURA: 'FATURA', FATURA: 'FATURA' } },
  'project_review_lines.type': { dominio: ['MAO_OBRA', 'SUBEMPREITADA'], mapa: { LABOR: 'MAO_OBRA', SUBCONTRACT: 'SUBEMPREITADA' } },

  // ── CRM ──
  'crm_accounts.origem': { dominio: ['RECOMENDACAO', 'CLIENTE_EXISTENTE', 'SITE', 'CAMPANHA', 'OUTRO'], mapa: {
    RECOMENDACAO: 'RECOMENDACAO', 'CLIENTE EXISTENTE': 'CLIENTE_EXISTENTE' } },
  'crm_opportunities.origem': { dominio: ['RECOMENDACAO', 'CLIENTE_EXISTENTE', 'SITE', 'CAMPANHA', 'OUTRO'], mapa: {
    RECOMENDACAO: 'RECOMENDACAO', 'CLIENTE EXISTENTE': 'CLIENTE_EXISTENTE' } },

  // ── Sistema ──
  // SUPER_ADMINISTRADOR = acesso total (paridade: role 'superadmin' em js/permissoes.js:593).
  'users.role': { dominio: ['SUPER_ADMINISTRADOR', 'ADMINISTRADOR', 'UTILIZADOR'], mapa: {
    SUPERADMIN: 'SUPER_ADMINISTRADOR', ADMIN: 'ADMINISTRADOR', VIEWER: 'UTILIZADOR', USER: 'UTILIZADOR' } },
  'companies.status': { dominio: ['ATIVO', 'INATIVO'], mapa: { ACTIVE: 'ATIVO', ATIVO: 'ATIVO', ACTIVO: 'ATIVO', INACTIVE: 'INATIVO' } },
};

// Salvaguarda: as chaves do mapa têm de estar DOBRADAS (ex.: 'POR JUSTIFICAR', não 'POR_JUSTIFICAR'); uma chave não
// dobrada nunca coincide e o valor migraria como NULL (aconteceu com rh_absences.estado — ADR-040).
for (const [col, { mapa, dominio }] of Object.entries(NORMALIZACOES)) {
  for (const [k, v] of Object.entries(mapa)) {
    if (dobrar(k) !== k) throw new Error(`normalizacoes.mjs: chave não dobrada em ${col}: «${k}» (use «${dobrar(k)}»)`);
    if (!dominio.includes(v)) throw new Error(`normalizacoes.mjs: ${col}: «${v}» fora do domínio`);
  }
}
