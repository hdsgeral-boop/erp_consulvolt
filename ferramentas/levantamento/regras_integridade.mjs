// Regras de tratamento de inconsistências do legado, aplicadas pelo ETL (erp:migrar-backup-legado).
// Princípio: nada é inventado nem perdido em silêncio. Toda a correcção gera uma linha em
// `ocorrencias_migracao` com a tabela, o id legado, a regra aplicada e o payload original (JSONB),
// que alimenta o ecrã "Relatório de Migração" no novo sistema.
//
// Acções:
//   QUARENTENA         -> a linha não entra na tabela de destino; fica em `quarentena_migracao` (payload integral).
//   ANULAR_FK          -> a FK fica NULL; o valor original fica em `ocorrencias_migracao.valor_original`.
//   DIARIO_RECUPERACAO -> cria (uma vez por empresa) o diário "REC - Diário Recuperado do Legado" e reaponta
//                         a linha, para não retirar lançamentos contabilísticos dos saldos.
//   CODIGO_LEGADO      -> o valor não é FK de uma linha persistida; guarda-se como texto em `<coluna>_legado`.
//   SEMANTICA          -> valor sentinela do legado convertido em modelo explícito (documentado em cada regra).

export const EMPRESA_FICTICIA = 11;              // "SISTEMA - DADO MESTRE"
export const EMPRESAS_REAIS_COM_FLAG_MESTRE = [10, 18];

export const REGRAS = [
  { alvo: 'journals.company_id', quando: 'empresa inexistente ou fictícia (11)', acao: 'QUARENTENA',
    motivo: 'Diários criados automaticamente para a empresa fictícia 11; não têm lançamentos reais.' },
  { alvo: 'org_types.company_id', quando: 'empresa inexistente (2)', acao: 'QUARENTENA',
    motivo: 'Tipos de órgão de uma empresa eliminada no legado.' },
  { alvo: 'audit_logs.company_id', quando: 'empresa inexistente (21, 11)', acao: 'MANTER_SEM_FK',
    motivo: 'A auditoria é histórica e imutável: logs_auditoria.empresa_id não tem FK para sobreviver a empresas eliminadas.' },
  { alvo: 'journal_lines.journal_id', quando: 'diário inexistente (103) ou NaN', acao: 'DIARIO_RECUPERACAO',
    motivo: 'Retirar estas linhas alteraria saldos e balancetes. São reapontadas para o diário REC e reportadas.' },
  { alvo: 'recycled_journal_lines.journal_id', quando: 'diário inexistente (103)', acao: 'DIARIO_RECUPERACAO',
    motivo: 'Idem, para o arquivo de estornos.' },
  { alvo: 'journal_lines.cashflow_note_id', quando: 'NaN', acao: 'ANULAR_FK', motivo: 'Valor numérico inválido gravado pelo legado.' },
  { alvo: 'journal_lines.cost_center_id', quando: 'centro de custo inexistente', acao: 'ANULAR_FK', motivo: 'Centro de custo eliminado no legado.' },
  { alvo: 'sales.pos_session_id', quando: "texto 'POS_SESS_<epoch>'", acao: 'CODIGO_LEGADO',
    motivo: 'Sessões de caixa POS antigas viviam só no localStorage do browser (js/ui_sales.js:8510); não há linha para referenciar.' },
  { alvo: 'purchase_items.order_id', quando: 'encomenda inexistente', acao: 'ANULAR_FK',
    motivo: 'A ligação válida da linha é documento_origem_id + tipo_documento_origem (parent_id/parent_type).' },
  { alvo: 'employees.role_id', quando: 'cargo inexistente', acao: 'ANULAR_FK', motivo: 'Cargo eliminado no legado.' },
  { alvo: 'accounting_mapos.org_type_id', quando: '-1', acao: 'SEMANTICA',
    motivo: "-1 = coluna 'Avençado' do mapeamento (js/app_v2.js:2648). Destino: tipo_organizacao_id NULL + avencado = true." },
  { alvo: 'system_accounting_mapos.org_type_id', quando: '-1', acao: 'SEMANTICA',
    motivo: "Idem: -1 = 'Avençado'. NaN -> ANULAR_FK." },
  { alvo: 'treasury_items.doc_id', quando: 'documento de tesouraria inexistente', acao: 'QUARENTENA',
    motivo: 'Linhas cujo documento-pai foi eliminado no legado (sem atomicidade). Não têm contexto contabilístico.' },
  { alvo: 'fixed_assets.category_id', quando: 'categoria inexistente', acao: 'ANULAR_FK', motivo: 'Categoria eliminada no legado.' },
  { alvo: 'fixed_assets.journal_line_id', quando: 'lançamento inexistente', acao: 'ANULAR_FK', motivo: 'Lançamento de aquisição eliminado/estornado.' },
  { alvo: 'asset_movements.asset_id', quando: 'activo inexistente', acao: 'QUARENTENA', motivo: 'Transferência de um bem eliminado.' },
  { alvo: 'reconciliation_matches.internal_id', quando: 'lançamento inexistente (323 de 1 635)', acao: 'ANULAR_FK',
    motivo: 'Linhas apagadas pelo "descontabilizar" do legado (ADR-016). O emparelhamento fica com valor/data e a ocorrência regista o id original.' },
  { alvo: 'reconciliation_matches.external_id', quando: 'linha de extracto inexistente (58 de 1 761)', acao: 'ANULAR_FK', motivo: 'Linha de extracto eliminada no legado.' },
  { alvo: 'third_party_addresses', quando: 'ligação por NIF', acao: 'MANTER_SEM_FK',
    motivo: 'O legado liga moradas ao terceiro pelo NIF (não único entre terceiros): sem FK; resolução por (empresa_id, nif).' },
  { alvo: '*.<fk>', quando: '0, "0", "" ou NaN', acao: 'ANULAR_FK', motivo: 'O legado usa 0/""/NaN como "sem valor".' },
  { alvo: '*.<lista_ids>', quando: '"112,113" ou array', acao: 'PIVO',
    motivo: 'Listas de ids (invoice_ids, order_ids, hotel_stay_ids, related_doc_id) passam a tabelas pivô com FK real.' },
  { alvo: 'journal_lines.value', quando: 'valor = 0 (38 linhas)', acao: 'MANTER_E_REPORTAR',
    motivo: 'CHECK (valor >= 0) em vez de > 0 para dados migrados; linhas a zero aparecem no relatório de validação.' },
  { alvo: 'journal_lines (saldo por empresa)', quando: 'SUM(D) <> SUM(C) (empresas 5, 8, 10)', acao: 'MANTER_E_REPORTAR',
    motivo: 'Decisão 2026-09-29: importar como está; relatório de diferenças no próprio sistema.' },
];
