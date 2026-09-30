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
  'sales.payment_method': { dominio: ['NUMERARIO', 'TPA', 'TRANSFERENCIA', 'CONTA_CORRENTE'], mapa: {
    NUMERARIO: 'NUMERARIO', MULTICAIXA: 'TPA', 'MULTICAIXA (TPA)': 'TPA', TPA: 'TPA',
    'TRANSFERENCIA BANCARIA': 'TRANSFERENCIA', TRANSFERENCIA: 'TRANSFERENCIA', 'CONTA CORRENTE': 'CONTA_CORRENTE' } },
  'sales.payment_mode': { dominio: ['PRONTO', 'PRAZO', 'MARCOS'], mapa: { PRONTO: 'PRONTO', PRAZO: 'PRAZO', MARCOS: 'MARCOS' } },

  // ── Compras ──
  'purchase_items.parent_type': { dominio: ['PEDIDO', 'COTACAO', 'ENCOMENDA', 'FATURA'], mapa: {
    REQUEST: 'PEDIDO', QUOTE: 'COTACAO', ORDER: 'ENCOMENDA', INVOICE: 'FATURA' } },
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
  'inventory_sessions.status': { dominio: ['EM_CONTAGEM', 'CONCLUIDA', 'ANULADA'], mapa: {
    'EM CONTAGEM': 'EM_CONTAGEM', CONCLUIDA: 'CONCLUIDA', ANULADA: 'ANULADA' } },

  // ── RH ──
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
  'bank_statement_lines.status': { dominio: ['PENDENTE', 'CONCILIADO'], mapa: {
    PENDING: 'PENDENTE', PENDENTE: 'PENDENTE', CONCILIATED: 'CONCILIADO', CONCILIADO: 'CONCILIADO' } },
  'reconciliations.type': { dominio: ['ATUALIZACAO_LOTE'], mapa: { 'BATCH UPDATE': 'ATUALIZACAO_LOTE' } },
  'reconciliation_matches.match_type': { dominio: ['AUTOMATICA', 'MANUAL'], mapa: { AUTO: 'AUTOMATICA', MANUAL: 'MANUAL' } },
  'cash_lines.source_type': { dominio: ['CONTABILIDADE', 'IMPORTACAO', 'MANUAL', 'FATURA_COMPRA', 'POS'], mapa: {
    CONTABILIDADE: 'CONTABILIDADE', IMPORT: 'IMPORTACAO', MANUAL: 'MANUAL', 'PURCHASE INVOICE': 'FATURA_COMPRA', POS: 'POS' } },
  'pos_terminals.type': { dominio: ['LOJA', 'LAVANDARIA', 'HOTELARIA'], mapa: { LOJA: 'LOJA', LAVANDARIA: 'LAVANDARIA', HOTELARIA: 'HOTELARIA' } },
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
