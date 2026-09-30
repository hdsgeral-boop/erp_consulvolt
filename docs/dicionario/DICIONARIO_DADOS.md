# Dicionário de Dados — Migração ERP_CONSULVOLT (legado Dexie → PostgreSQL)

> Gerado automaticamente por `ferramentas/levantamento/gerar_dicionario.mjs` em 2026-09-30T21:39:51.578Z. **Não editar à mão**: alterar `glossario.mjs` / `tabelas.mjs` e regenerar.

## Resumo

| Indicador | Valor |
| :--- | ---: |
| Tabelas | 154 |
| Linhas no backup | 205 033 |
| Linhas fictícias descartadas (is_master_data) | 1215 |
| Linhas reais a migrar | 203 818 |
| Colunas reais mapeadas | 1686 |
| Tabelas sem linhas reais (esquema a derivar do código JS) | 29 |
| Chaves estrangeiras com órfãos | 17 |
| Colunas enumeradas a normalizar (código + `*_original`) | 122 |

## Convenções

- Tenant: todas as variantes (`company_id`, `rh_company_id`, `crm_company_id`, `pos_company_id`, …) → `empresa_id`.
- Carimbos: `criado_em` / `atualizado_em` / `eliminado_em` (constantes `CREATED_AT`/`UPDATED_AT` nos models).
- Dinheiro `numeric(15,2)` · quantidades `numeric(12,3)` · taxas/percentagens `numeric(9,4)` · câmbio `numeric(18,6)`.
- Enumerações textuais: coluna com código normalizado + coluna `<nome>_original` com o texto do legado.
- Linhas `is_master_data=1` são placeholders do legado e não migram, excepto as empresas 10 e 18 (reais).

## Regras de integridade aplicadas pelo ETL

| Alvo | Quando | Acção | Motivo |
| :--- | :--- | :--- | :--- |
| `journals.company_id` | empresa inexistente ou fictícia (11) | **QUARENTENA** | Diários criados automaticamente para a empresa fictícia 11; não têm lançamentos reais. |
| `org_types.company_id` | empresa inexistente (2) | **QUARENTENA** | Tipos de órgão de uma empresa eliminada no legado. |
| `audit_logs.company_id` | empresa inexistente (21, 11) | **MANTER_SEM_FK** | A auditoria é histórica e imutável: logs_auditoria.empresa_id não tem FK para sobreviver a empresas eliminadas. |
| `journal_lines.journal_id` | diário inexistente (103) ou NaN | **DIARIO_RECUPERACAO** | Retirar estas linhas alteraria saldos e balancetes. São reapontadas para o diário REC e reportadas. |
| `recycled_journal_lines.journal_id` | diário inexistente (103) | **DIARIO_RECUPERACAO** | Idem, para o arquivo de estornos. |
| `journal_lines.cashflow_note_id` | NaN | **ANULAR_FK** | Valor numérico inválido gravado pelo legado. |
| `journal_lines.cost_center_id` | centro de custo inexistente | **ANULAR_FK** | Centro de custo eliminado no legado. |
| `sales.pos_session_id` | texto 'POS_SESS_<epoch>' | **CODIGO_LEGADO** | Sessões de caixa POS antigas viviam só no localStorage do browser (js/ui_sales.js:8510); não há linha para referenciar. |
| `purchase_items.order_id` | encomenda inexistente | **ANULAR_FK** | A ligação válida da linha é documento_origem_id + tipo_documento_origem (parent_id/parent_type). |
| `employees.role_id` | cargo inexistente | **ANULAR_FK** | Cargo eliminado no legado. |
| `accounting_mapos.org_type_id` | -1 | **SEMANTICA** | -1 = coluna 'Avençado' do mapeamento (js/app_v2.js:2648). Destino: tipo_organizacao_id NULL + avencado = true. |
| `system_accounting_mapos.org_type_id` | -1 | **SEMANTICA** | Idem: -1 = 'Avençado'. NaN -> ANULAR_FK. |
| `treasury_items.doc_id` | documento de tesouraria inexistente | **QUARENTENA** | Linhas cujo documento-pai foi eliminado no legado (sem atomicidade). Não têm contexto contabilístico. |
| `fixed_assets.category_id` | categoria inexistente | **ANULAR_FK** | Categoria eliminada no legado. |
| `fixed_assets.journal_line_id` | lançamento inexistente | **ANULAR_FK** | Lançamento de aquisição eliminado/estornado. |
| `asset_movements.asset_id` | activo inexistente | **QUARENTENA** | Transferência de um bem eliminado. |
| `reconciliation_matches.internal_id` | lançamento inexistente (323 de 1 635) | **ANULAR_FK** | Linhas apagadas pelo "descontabilizar" do legado (ADR-016). O emparelhamento fica com valor/data e a ocorrência regista o id original. |
| `reconciliation_matches.external_id` | linha de extracto inexistente (58 de 1 761) | **ANULAR_FK** | Linha de extracto eliminada no legado. |
| `third_party_addresses` | ligação por NIF | **MANTER_SEM_FK** | O legado liga moradas ao terceiro pelo NIF (não único entre terceiros): sem FK; resolução por (empresa_id, nif). |
| `*.<fk>` | 0, "0", "" ou NaN | **ANULAR_FK** | O legado usa 0/""/NaN como "sem valor". |
| `*.<lista_ids>` | "112,113" ou array | **PIVO** | Listas de ids (invoice_ids, order_ids, hotel_stay_ids, related_doc_id) passam a tabelas pivô com FK real. |
| `journal_lines.value` | valor = 0 (38 linhas) | **MANTER_E_REPORTAR** | CHECK (valor >= 0) em vez de > 0 para dados migrados; linhas a zero aparecem no relatório de validação. |
| `journal_lines (saldo por empresa)` | SUM(D) <> SUM(C) (empresas 5, 8, 10) | **MANTER_E_REPORTAR** | Decisão 2026-09-29: importar como está; relatório de diferenças no próprio sistema. |

## Chaves estrangeiras com órfãos no backup

| Tabela.coluna | Alvo | Correspondência | Órfãos | Exemplos |
| :--- | :--- | ---: | ---: | :--- |
| `journals.company_id` | `companies` | 97.9% | 9 | 11 |
| `journal_lines.journal_id` | `journals` | 99.7% | 117 | NaN, 103 |
| `journal_lines.cashflow_note_id` | `cashflow_notes` | 99.9% | 6 | NaN |
| `journal_lines.cost_center_id` | `cost_centers` | 99.8% | 1 | 5 |
| `recycled_journal_lines.journal_id` | `journals` | 98.4% | 40 | 103 |
| `sales.pos_session_id` | `pos_sessions` | 60.7% | 11 | POS_SESS_1787321170305, POS_SESS_1787322886792 |
| `purchase_items.order_id` | `purchase_orders` | 0.0% | 3 | 45, 46, 47 |
| `employees.role_id` | `roles` | 98.5% | 2 | 46 |
| `org_types.company_id` | `companies` | 92.9% | 2 | 2 |
| `accounting_mapos.org_type_id` | `org_types` | 80.4% | 44 | -1 |
| `system_accounting_mapos.org_type_id` | `org_types` | 90.9% | 10 | NaN, -1 |
| `treasury_items.doc_id` | `treasury_documents` | 99.8% | 15 | 4595, 4651, 4650, 4652, 4685, 4686, 5710, 5711 |
| `reconciliation_matches.internal_id` | `journal_lines` | 80.2% | 323 | 7750, 20824, 22086, 22072, 21339, 22210, 22190, 22142 |
| `reconciliation_matches.external_id` | `bank_statement_lines` | 96.7% | 58 | 2972, 2975, 2981, 2982, 2983, 2984, 2986, 2987 |
| `fixed_assets.category_id` | `asset_categories` | 99.4% | 1 | 10 |
| `fixed_assets.journal_line_id` | `journal_lines` | 97.1% | 5 | 37569, 42514 |
| `asset_movements.asset_id` | `fixed_assets` | 0.0% | 1 | 1 |

## Normalizações de enumerações (código + texto original)

| Tabela.coluna | Domínio de códigos | Valores do legado → código |
| :--- | :--- | :--- |
| `third_parties.type` | CLIENTE, FORNECEDOR, COLABORADOR, CLIENTE_FORNECEDOR | CLIENTE → CLIENTE; FORNECEDOR → FORNECEDOR; Colaborador → COLABORADOR; COLABORADOR → COLABORADOR; FORNECEDOR, CLIENTE → CLIENTE_FORNECEDOR |
| `sales.doc_type` | FT, FR, NC, ND, PF, OR, NE, GR, GD | Factura → FT; Factura-Recibo → FR; Encomenda → NE; Orçamento → OR; Orcamento → OR; Proforma → PF; Guia de Remessa → GR; Factura Proforma → PF; Nota de CRÉDITO → NC |
| `sales.status` | PENDENTE, PARCIAL, PAGO, CONCLUIDO, ANULADO | PENDENTE → PENDENTE; PAGO → PAGO; CONCLUIDO → CONCLUIDO; Pendente → PENDENTE |
| `sales.payment_method` | NUMERARIO, TPA, TRANSFERENCIA, CONTA_CORRENTE, MISTO | Numerário → NUMERARIO; Numerario → NUMERARIO; Multicaixa → TPA; Transferência bancária → TRANSFERENCIA; Conta corrente → CONTA_CORRENTE; Multicaixa (TPA) → TPA; Transferencia → TRANSFERENCIA |
| `sales.payment_mode` | PRONTO, PRAZO, MARCOS | MARCOS → MARCOS; PRONTO → PRONTO |
| `purchase_items.parent_type` | PEDIDO, COTACAO, ENCOMENDA, FATURA | QUOTE → COTACAO; ORDER → ENCOMENDA; REQUEST → PEDIDO; INVOICE → FATURA |
| `purchase_contracts.status` | ATIVO, EXPIRADO, CANCELADO | ATIVO → ATIVO |
| `purchase_contract_milestones.status` | PENDENTE, FATURADO, PAGO |  |
| `purchase_requests.status` | PENDENTE, APROVADO, REJEITADO, ADJUDICADO, FECHADO, ANULADO | ADJUDICADO → ADJUDICADO; PENDENTE → PENDENTE; APROVADO → APROVADO |
| `purchase_quotes.status` | PROPOSTA, PROPOSTA_ADJUDICACAO, ADJUDICADO, RECUSADA, ANULADA | ADJUDICADO → ADJUDICADO; PROPOSTA → PROPOSTA; PROPOSTA_ADJUDICACAO → PROPOSTA_ADJUDICACAO |
| `purchase_orders.status` | EM_PROCESSAMENTO, PARCIAL, RECEBIDO, ANULADA | RECEBIDO → RECEBIDO; EM_PROCESSAMENTO → EM_PROCESSAMENTO |
| `purchase_deliveries.status` | RECEBIDO, VALIDADO, ANULADO | RECEBIDO → RECEBIDO |
| `purchase_invoices.status` | PENDENTE, PARCIAL, PAGO, ANULADA | PENDENTE → PENDENTE; PAGO → PAGO |
| `inventory_movements.type` | ENTRADA, SAIDA, TRANSFERENCIA, AJUSTE | SAÍDA → SAIDA; ENTRADA → ENTRADA |
| `delivery_notes.type` | VENDA, BACK_TO_BACK, CONSUMO, VENDA_BALCAO | VENDA → VENDA; BACK_TO_BACK → BACK_TO_BACK |
| `delivery_notes.status` | CONCLUIDO, FATURADA, ANULADA | CONCLUIDO → CONCLUIDO |
| `inventory_sessions.status` | EM_CONTAGEM, REVISAO, CONCLUIDA, ANULADA | CONCLUIDA → CONCLUIDA; EM CONTAGEM → EM_CONTAGEM |
| `infotypes.calculo_horas` | EXTRA, FALTA, NAO |  |
| `rh_attendance.origem` | MANUAL, FICHEIRO, RELOGIO | FICHEIRO → FICHEIRO; MANUAL → MANUAL |
| `rh_attendance_closures.estado` | FECHADO, REABERTO | FECHADO → FECHADO |
| `rh_absences.estado` | POR_JUSTIFICAR, PENDENTE_CHEFIA, PENDENTE_RH, APROVADO, RECUSADO, CANCELADO | POR_JUSTIFICAR → POR_JUSTIFICAR |
| `rh_absences.remunerada` | SIM, NAO, EMPREGADOR |  |
| `rh_vacations.status` | PEDIDO, PLANEADO, APROVADO, GOZADO, CANCELADO | PEDIDO → PEDIDO; APROVADO → APROVADO |
| `rh_prod_periods.estado` | ABERTO, FECHADO | FECHADO → FECHADO |
| `rh_prod_items.metrica` | QUANTIDADE, HORAS, OBJECTIVO, PONTOS, TAREFAS | QUANTIDADE → QUANTIDADE |
| `rh_portal_requests.tipo` | FERIAS, AUSENCIA, DOCUMENTO, AGREGADO | FERIAS → FERIAS; DOCUMENTO → DOCUMENTO; AGREGADO → AGREGADO |
| `rh_portal_requests.estado` | PENDENTE_CHEFIA, PENDENTE_RH, APROVADO, EMITIDO, RECUSADO, CANCELADO | PENDENTE_RH → PENDENTE_RH; APROVADO → APROVADO; EMITIDO → EMITIDO |
| `org_units.tipo` | ORGAO_SOCIAL, DIRECCAO_GERAL, DIRECCAO, DEPARTAMENTO, GABINETE, SECCAO, EQUIPA, OUTRO | DEPARTAMENTO → DEPARTAMENTO; DIRECCAO_GERAL → DIRECCAO_GERAL; DIRECCAO → DIRECCAO; ORGAO_SOCIAL → ORGAO_SOCIAL; EQUIPA → EQUIPA |
| `rh_evaluations.status` | RASCUNHO, CONCLUIDA |  |
| `rh_self_evaluations.status` | RASCUNHO, SUBMETIDA | SUBMETIDA → SUBMETIDA |
| `rh_eval_cycles.estado` | RASCUNHO, ABERTO, FECHADO | ABERTO → ABERTO; RASCUNHO → RASCUNHO |
| `rh_eval_bonus.estado` | PROPOSTA, APROVADA, LANCADA |  |
| `rh_evaluation_items.tipo` | CRITERIO, OBJECTIVO | CRITERIO → CRITERIO; OBJECTIVO → OBJECTIVO |
| `rh_evaluation_items.ambito` | COMUM, ESPECIFICO | COMUM → COMUM; ESPECIFICO → ESPECIFICO |
| `orc_budgets.status` | RASCUNHO, SUBMETIDO, APROVADO, SUBSTITUIDO | RASCUNHO → RASCUNHO |
| `orc_budgets.tipo` | EXPLORACAO, TESOURARIA | EXPLORACAO → EXPLORACAO; TESOURARIA → TESOURARIA |
| `orc_rubrics.tipo` | EXPLORACAO, TESOURARIA | EXPLORACAO → EXPLORACAO; TESOURARIA → TESOURARIA |
| `orc_rubrics.natureza` | PROVEITO, CUSTO, RECEBIMENTO, PAGAMENTO | PROVEITO → PROVEITO; CUSTO → CUSTO; PAGAMENTO → PAGAMENTO; RECEBIMENTO → RECEBIMENTO |
| `orc_excess_requests.status` | PENDENTE, APROVADO, REJEITADO, UTILIZADO |  |
| `orc_forecasts.status` | RASCUNHO, PUBLICADA |  |
| `payroll_periods.status` | ABERTO, FECHADO, VALIDADO | VALIDADO → VALIDADO; ABERTO → ABERTO |
| `employees.status` | ACTIVO, INACTIVO, SUSPENSO | ACTIVO → ACTIVO; Não ACTIVO → INACTIVO; NÃO ACTIVO → INACTIVO |
| `employees.estado_civil` | SOLTEIRO, CASADO, DIVORCIADO, VIUVO, UNIAO_FACTO | Solteiro(a) → SOLTEIRO; Casado(a) → CASADO |
| `rh_dependents.parentesco` | FILHO, CONJUGE, PAI, MAE, OUTRO | Filho(a) → FILHO |
| `treasury_documents.type` | PAGAMENTO, RECEBIMENTO | RECEBIMENTO → RECEBIMENTO; PAGAMENTO → PAGAMENTO |
| `treasury_documents.status` | PENDENTE, INTEGRADO, ANULADO | INTEGRADO → INTEGRADO; PENDENTE → PENDENTE |
| `cash_sessions.status` | ABERTA, FECHADA, CONTABILIZADA | CONTABILIZADA → CONTABILIZADA; ABERTA → ABERTA; FECHADA → FECHADA |
| `cash_lines.type` | REC, PAG | PAG → PAG; REC → REC |
| `cash_audits.status` | RASCUNHO, FINALIZADO | FINALIZADO → FINALIZADO |
| `bank_statement_lines.status` | PENDENTE, CONCILIADO, ANULADO | CONCILIATED → CONCILIADO; PENDING → PENDENTE; CONCILIADO → CONCILIADO |
| `reconciliations.type` | ATUALIZACAO_LOTE | BATCH_UPDATE → ATUALIZACAO_LOTE |
| `reconciliation_matches.match_type` | AUTOMATICA, MANUAL | AUTO → AUTOMATICA; MANUAL → MANUAL |
| `cash_lines.source_type` | CONTABILIDADE, IMPORTACAO, MANUAL, FATURA_COMPRA, POS | contabilidade → CONTABILIDADE; import → IMPORTACAO; manual → MANUAL; purchase_invoice → FATURA_COMPRA; pos → POS |
| `pos_terminals.type` | LOJA, RESTAURANTE, LAVANDARIA, HOTELARIA | loja → LOJA; lavandaria → LAVANDARIA; hotelaria → HOTELARIA |
| `pos_sessions.deviation_status` | NAO_APLICAVEL, SEM_DESVIO, DELIBERADO, PENDENTE | N/A → NAO_APLICAVEL; SEM_DESVIO → SEM_DESVIO; DELIBERADO → DELIBERADO |
| `journal_lines.source_doc_type` | FATURA_COMPRA, RECECAO_COMPRA | PURCHASE_INVOICE → FATURA_COMPRA; PURCHASE_DELIVERY → RECECAO_COMPRA |
| `recycled_journal_lines.source_doc_type` | FATURA_COMPRA, RECECAO_COMPRA | PURCHASE_INVOICE → FATURA_COMPRA |
| `historical_balances.type` | DEMONSTRACAO_RESULTADOS, FLUXO_CAIXA | DEMO → DEMONSTRACAO_RESULTADOS; FLUXO → FLUXO_CAIXA |
| `annual_reports.status` | RASCUNHO, APROVADO | DRAFT → RASCUNHO |
| `asset_maintenance_records.status` | PLANEADA, EM_CURSO, CONCLUIDA | concluida → CONCLUIDA |
| `project_tasks.status` | PENDENTE, EM_CURSO, CONCLUIDA, BLOQUEADA | PENDENTE → PENDENTE; EM_CURSO → EM_CURSO; CONCLUIDA → CONCLUIDA; FAZENDO → EM_CURSO |
| `project_ledger.nature` | CUSTO, CUSTO_REAL, PROVEITO | C → CUSTO; custo_real → CUSTO_REAL; custo → CUSTO; proveito → PROVEITO |
| `project_ledger.source_doc_type` | AUTO_INTERNO, REGISTO_OBRA, FATURA, FATURA_RECIBO, PROCESSAMENTO_SALARIAL | AUTO_INTERNO → AUTO_INTERNO; Registo de Obra → REGISTO_OBRA; Factura → FATURA |
| `fixed_assets.status` | ACTIVO, INACTIVO, ABATIDO | ACTIVO → ACTIVO |
| `asset_disposals.type` | SINISTRO, VENDA, FIM_VIDA |  |
| `asset_maintenance_records.type` | PREVENTIVA, CORRECTIVA | CORRECTIVA → CORRECTIVA |
| `project_review_lines.type` | MAO_OBRA, SUBEMPREITADA | LABOR → MAO_OBRA; SUBCONTRACT → SUBEMPREITADA |
| `crm_accounts.origem` | RECOMENDACAO, CLIENTE_EXISTENTE, SITE, CAMPANHA, OUTRO | Recomendação → RECOMENDACAO; Cliente existente → CLIENTE_EXISTENTE |
| `crm_opportunities.origem` | RECOMENDACAO, CLIENTE_EXISTENTE, SITE, CAMPANHA, OUTRO | Recomendação → RECOMENDACAO; Cliente existente → CLIENTE_EXISTENTE |
| `users.role` | SUPER_ADMINISTRADOR, ADMINISTRADOR, UTILIZADOR | viewer → UTILIZADOR; superadmin → SUPER_ADMINISTRADOR |
| `companies.status` | ATIVO, INATIVO | active → ATIVO |

## Tabelas sem dados reais (29) — esquema a derivar do código legado

`sales_accounting_config`, `fe_series`, `purchase_contract_milestones`, `payment_letters`, `payment_letter_items`, `rh_education`, `rh_attendance_config`, `rh_evaluations`, `rh_eval_bonus`, `rh_upward_participation`, `rh_upward_responses`, `rh_doc_templates`, `lav_claims`, `lav_settings`, `asset_disposals`, `asset_maintenance_plans`, `maintenance_requests`, `project_billings`, `project_asset_allocations`, `project_change_orders`, `project_documents`, `project_activity_log`, `orc_forecasts`, `orc_forecast_lines`, `orc_scenarios`, `orc_excess_requests`, `orc_alert_log`, `crm_settings`, `crm_sequences`

## Módulo: Sistema

### `companies` → `empresas` (model `Empresa`, `/api/sistema/empresas`)

Linhas reais: **14** · fictícias descartadas: 1

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `name` | `nome` | varchar(100) | sim | 100% |  |  |
| `nif` | `nif` | varchar(30) | sim | 100% |  | tipos mistos: string_inteiro=13, string=1 |
| `address` | `endereco` | text | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `logo` | `logotipo` | text | sim | 86% |  |  |
| `inss_patronal` | `taxa_inss_patronal` | numeric(9,4) | sim | 93% |  |  |
| `inss_trabalhador` | `taxa_inss_trabalhador` | numeric(9,4) | sim | 93% |  |  |
| `province` | `provincia` | varchar(30) | sim | 36% |  |  |
| `municipality` | `municipio` | varchar(20) | sim | 36% |  |  |
| `commune` | `comuna` | varchar(50) | sim | 36% |  |  |
| `ai_rules` | `regras_ia` | text | sim | 7% |  |  |
| `status` | `estado` | varchar(20) | sim | 14% |  | código normalizado ∈ {ATIVO, INATIVO}; texto original em estado_original |
| `status` | `estado_original` | varchar(10) | sim | 14% |  | texto exacto do legado |
| `is_consolidation` | `e_consolidacao` | boolean | sim | 7% |  |  |
| `consolidation_currency` | `moeda_consolidacao` | varchar(10) | sim | 7% |  |  |
| `consolidation_date_end` | `data_fim_consolidacao` | date | sim | 7% |  |  |
| `consolidation_run_id` | `execucao_consolidacao_id` | bigint | sim | 7% | `execucoes_consolidacao.id` |  |
| `phone` | `telefone` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `email` | `email` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `website` | `website` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `crc` | `numero_registo_comercial` | varchar(30) | sim | 7% |  |  |
| `doc_footer` | `rodape_documento` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |

### `users` → `utilizadores` (model `Utilizador`, `/api/sistema/utilizadores`)

Linhas reais: **4** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `username` | `nome_utilizador` | varchar(20) | sim | 100% |  |  |
| `role` | `papel` | varchar(24) | sim | 100% |  | código normalizado ∈ {SUPER_ADMINISTRADOR, ADMINISTRADOR, UTILIZADOR}; texto original em papel_original |
| `role` | `papel_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `allowed_companies` | `empresas_permitidas` | jsonb | sim | 100% |  | tipo forçado (inferido: jsonb) |
| `allowed_modules` | `modulos_permitidos` | jsonb | sim | 75% |  | tipos mistos: object=2, array=1 |
| `profile_id` | `perfil_utilizador_id` | bigint | sim | 100% | `perfis_utilizador.id` |  |
| `password_hash` | `hash_password` | varchar(100) | sim | 100% |  |  |
| `password_salt` | `salt_password` | varchar(50) | sim | 100% |  |  |
| `password_algo` | `algoritmo_password` | varchar(30) | sim | 100% |  |  |
| `colaboradores` | `colaboradores` | jsonb | sim | 100% |  | tipo forçado (inferido: jsonb) |

### `user_profiles` → `perfis_utilizador` (model `PerfilUtilizador`, `/api/sistema/perfis`)

Linhas reais: **25** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `name` | `nome` | varchar(50) | sim | 100% |  |  |
| `permissions` | `permissoes` | jsonb | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `audit_logs` → `logs_auditoria` (model `LogAuditoria`, `/api/sistema/logs`)

Linhas reais: **106221** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% |  | tipo forçado (inferido: integer) |
| `timestamp` | `ocorrido_em` | timestamptz | sim | 100% |  |  |
| `user` | `nome_utilizador` | varchar(100) | sim | 100% |  | tipos mistos: string=106203, object=18; tipo forçado (inferido: jsonb) |
| `module` | `modulo` | varchar(50) | sim | 100% |  |  |
| `action` | `acao` | varchar(100) | sim | 100% |  |  |
| `record_id` | `registo_id` | varchar(255) | sim | 100% |  | tipos mistos: inteiro=44763, string_inteiro=131, string=61082, array=72; tipo forçado (inferido: jsonb) |
| `details` | `detalhes` | text | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `system_config` → `configuracoes_sistema` (model `ConfiguracaoSistema`, `/api/sistema/configuracoes`)

Linhas reais: **4** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `key` | `chave` | varchar(30) | sim | 100% |  |  |
| `value` | `valor` | text | sim | 100% |  | tipos mistos: string=3, inteiro=1 |

### `business_units` → `unidades_negocio` (model `UnidadeNegocio`, `/api/sistema/unidades-negocio`)

Linhas reais: **16** · fictícias descartadas: 15

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `code` | `codigo` | varchar(20) | sim | 100% |  |  |
| `name` | `nome` | varchar(100) | sim | 100% |  |  |
| `short_name` | `nome_abreviado` | varchar(10) | sim | 6% |  |  |
| `description` | `descricao` | text | sim | 6% |  |  |
| `parent_id` | `unidade_negocio_pai_id` | bigint | sim | 0% | `unidades_negocio.id` |  |
| `seq_order` | `ordem_sequencia` | integer | sim | 100% |  |  |
| `status` | `estado` | varchar(10) | sim | 100% |  |  |
| `valid_from` | `valido_de` | date | sim | 6% |  |  |
| `valid_to` | `valido_ate` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `address` | `endereco` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `city` | `cidade` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `state` | `estado_fluxo` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `postal_code` | `codigo_postal` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `country` | `pais` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `phone` | `telefone` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `email` | `email` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `fax` | `fax` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `website` | `website` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `currency` | `codigo_moeda` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `stock_exchange` | `bolsa_valores` | numeric(15,2) | sim | 0% |  |  |
| `ticker_symbol` | `simbolo_bolsa` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `manager_employee_id` | `colaborador_gestor_id` | bigint | sim | 6% | `colaboradores.id` |  |
| `has_sales` | `tem_vendas` | boolean | sim | 100% |  |  |
| `has_service` | `tem_servico` | boolean | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `cost_centers` → `centros_custo` (model `CentroCusto`, `/api/sistema/centros-custo`)

Linhas reais: **38** · fictícias descartadas: 15

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `code` | `codigo` | varchar(20) | sim | 100% |  |  |
| `description` | `descricao` | text | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `currencies` → `moedas` (model `Moeda`, `/api/sistema/moedas`)

Linhas reais: **5** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `code` | `codigo` | varchar(10) | sim | 100% |  |  |
| `name` | `nome` | varchar(30) | sim | 100% |  |  |
| `symbol` | `simbolo` | varchar(10) | sim | 100% |  |  |
| `decimals` | `casas_decimais` | integer | sim | 100% |  |  |
| `is_active` | `ativo` | boolean | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `exchange_rates` → `taxas_cambio` (model `TaxaCambio`, `/api/sistema/taxas-cambio`)

Linhas reais: **36** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `scope_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `currency` | `codigo_moeda` | varchar(10) | sim | 100% |  |  |
| `rate_date` | `data_taxa` | date | sim | 100% |  |  |
| `rate` | `taxa` | numeric(18,6) | sim | 100% |  | tipo forçado (inferido: numeric(9,4)) |
| `source` | `fonte_dados` | varchar(50) | sim | 100% |  | tipo forçado (inferido: varchar(10)) |
| `bai_buy` | `taxa_compra_bai` | numeric(18,6) | sim | 100% |  |  |
| `bai_sell` | `taxa_venda_bai` | numeric(18,6) | sim | 100% |  |  |
| `created_at` | `criado_em` | timestamptz | sim | 100% |  |  |
| `created_by` | `criado_por` | varchar(100) | sim | 100% |  | tipo forçado (inferido: varchar(10)) |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `updated_at` | `atualizado_em` | timestamptz | sim | 31% |  |  |
| `updated_by` | `atualizado_por` | varchar(100) | sim | 31% |  | tipo forçado (inferido: varchar(10)) |

### `internal_rules` → `regras_internas_ia` (model `RegraInternaIA`, `/api/sistema/regras-ia`)

Linhas reais: **1** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `name` | `nome` | varchar(100) | sim | 100% |  |  |
| `keywords` | `palavras_chave` | text | sim | 100% |  |  |
| `template` | `modelo` | text | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `document_types` → `tipos_documento` (model `TipoDocumento`, `/api/sistema/tipos-documento`)

Linhas reais: **1** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `name` | `nome` | varchar(50) | sim | 100% |  |  |
| `module_context` | `contexto_modulo` | varchar(30) | sim | 100% |  | tipo forçado (inferido: varchar(10)) |
| `description` | `descricao` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `documents` → `documentos_anexos` (model `DocumentoAnexo`, `/api/sistema/documentos`)

Linhas reais: **1** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `type_id` | `tipo_documento_id` | bigint | sim | 100% | `tipos_documento.id` |  |
| `entity_type` | `tipo_entidade` | varchar(10) | sim | 100% |  |  |
| `entity_id` | `entidade_id` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `file_name` | `nome_ficheiro` | varchar(30) | sim | 100% |  |  |
| `file_data` | `conteudo_ficheiro` | text | sim | 100% |  |  |
| `mime_type` | `tipo_mime` | varchar(30) | sim | 100% |  |  |
| `upload_date` | `data_carregamento` | timestamptz | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

## Módulo: Contabilidade

### `chart_of_accounts` → `plano_contas` (model `PlanoConta`, `/api/contabilidade/plano-contas`)

Linhas reais: **18035** · fictícias descartadas: 15

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `code` | `codigo` | varchar(20) | sim | 100% |  | tipos mistos: string_inteiro=18033, string=2 |
| `description` | `descricao` | text | sim | 100% |  |  |
| `type` | `tipo` | varchar(10) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `currency` | `codigo_moeda` | varchar(10) | sim | 0% |  |  |

### `journals` → `diarios_contabeis` (model `DiarioContabil`, `/api/contabilidade/diarios`)

Linhas reais: **436** · fictícias descartadas: 15

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` ⚠ 97.9% (9 órfãos) |  |
| `code` | `codigo` | varchar(20) | sim | 100% |  | tipos mistos: string=433, string_inteiro=3 |
| `description` | `descricao` | text | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `name` | `nome` | varchar(100) | sim | 9% |  |  |

### `journal_lines` → `lancamentos_contabeis` (model `LancamentoContabil`, `/api/contabilidade/lancamentos`)

Linhas reais: **44400** · fictícias descartadas: 15

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `journal_id` | `diario_id` | bigint | sim | 100% | `diarios_contabeis.id` ⚠ 99.7% (117 órfãos) | tipos Dexie: {"nan":9} |
| `doc_date` | `data_documento` | date | sim | 100% |  | tipos mistos: string_data=44344, string_datahora=52, string=4; tipo forçado (inferido: timestamptz) |
| `entry_date` | `data_lancamento` | timestamptz | sim | 100% |  | tipos mistos: string_data=32819, string_datahora=11087, string=492 |
| `reference` | `referencia` | varchar(100) | sim | 100% |  | tipos mistos: string_inteiro=4723, string=39662 |
| `doc_number` | `numero_documento` | varchar(100) | sim | 100% |  | tipos mistos: string_inteiro=6016, string=38377 |
| `description` | `descricao` | text | sim | 94% |  |  |
| `value` | `valor` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=27330, decimal=17070 |
| `type_dc` | `tipo_dc` | varchar(10) | sim | 100% |  |  |
| `account_code` | `codigo_conta` | varchar(20) | sim | 100% |  | tipos mistos: string_inteiro=44386, string=12, string_decimal=2 |
| `third_party_id` | `terceiro_id` | bigint | sim | 48% | `terceiros.id` | tipos Dexie: {"undef":2} |
| `demo_note_id` | `nota_demonstracao_id` | bigint | sim | 91% | `notas_demonstracao_resultados.id` | tipos Dexie: {"undef":4} |
| `cashflow_note_id` | `nota_fluxo_caixa_id` | bigint | sim | 19% | `notas_fluxo_caixa.id` ⚠ 99.9% (6 órfãos) | tipos Dexie: {"undef":4,"nan":6} |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `project_id` | `projeto_id` | bigint | sim | 0% | `projetos.id` | tipos Dexie: {"undef":10} |
| `project_code` | `codigo_projeto` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado; tipos Dexie: {"undef":28} |
| `reconciliation_id` | `reconciliacao_codigo` | varchar(50) | sim | 14% |  |  |
| `period_id` | `periodo_id` | integer | sim | 2% |  |  |
| `doc_ref` | `referencia_documento` | varchar(20) | sim | 0% |  |  |
| `created_at` | `criado_em` | timestamptz | sim | 12% |  | tipos mistos: string_datahora=48, string_data=5495 |
| `acc_period` | `periodo_contabil` | integer | sim | 3% |  |  |
| `source_doc_id` | `documento_origem_id` | varchar(10) | sim | 0% |  |  |
| `source_doc_type` | `tipo_documento_origem` | varchar(20) | sim | 0% |  | código normalizado ∈ {FATURA_COMPRA, RECECAO_COMPRA}; texto original em tipo_documento_origem_original |
| `source_doc_type` | `tipo_documento_origem_original` | varchar(30) | sim | 0% |  | texto exacto do legado |
| `doc_url` | `url_documento` | varchar(255) | sim | 19% |  | tipos Dexie: {"undef":266} |
| `source` | `fonte_dados` | varchar(30) | sim | 0% |  |  |
| `business_unit_id` | `unidade_negocio_id` | bigint | sim | 1% | `unidades_negocio.id` |  |
| `cost_center_id` | `centro_custo_id` | bigint | sim | 1% | `centros_custo.id` ⚠ 99.8% (1 órfãos) |  |
| `lan_number` | `numero_lan` | varchar(30) | sim | 40% |  |  |
| `currency` | `codigo_moeda` | varchar(10) | sim | 0% |  |  |
| `value_currency` | `valor_moeda` | numeric(15,2) | sim | 0% |  | tipos mistos: inteiro=12, decimal=2 |
| `exchange_rate` | `taxa_cambio` | numeric(18,6) | sim | 0% |  |  |
| `exchange_rate_id` | `taxa_cambio_id` | bigint | sim | 0% | `taxas_cambio.id` |  |
| `exchange_rate_manual` | `taxa_cambio_manual` | boolean | sim | 0% |  |  |
| `source_type` | `tipo_origem` | varchar(20) | sim | 0% |  |  |
| `pos_session_id` | `sessao_pos_id` | bigint | sim | 0% | `sessoes_pos.id` |  |
| `task_id` | `tarefa_projeto_id` | bigint | sim | 0% | `tarefas_projeto.id` |  |
| `source_company_id` | `empresa_origem_id` | bigint | sim | 31% | `empresas.id` |  |
| `source_line_id` | `linha_origem_id` | integer | sim | 31% |  |  |
| `consolidation_run_id` | `execucao_consolidacao_id` | bigint | sim | 31% | `execucoes_consolidacao.id` |  |
| `consolidation_type` | `tipo_consolidacao` | varchar(20) | sim | 31% |  |  |
| `value_kz_origem` | `valor_kz_origem` | numeric(15,2) | sim | 31% |  | tipos mistos: decimal=6480, inteiro=7084 |
| `intragroup_company_id` | `empresa_intragrupo_id` | bigint | sim | 0% | `empresas.id` |  |
| `ad_item_id` | `item_acrescimo_diferimento_id` | bigint | sim | 0% | `itens_acrescimos_diferimentos.id` |  |

### `recycled_journal_lines` → `lancamentos_estornados` (model `LancamentoEstornado`, `/api/contabilidade/estornos`)

Linhas reais: **2505** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `journal_id` | `diario_id` | bigint | sim | 100% | `diarios_contabeis.id` ⚠ 98.4% (40 órfãos) |  |
| `doc_date` | `data_documento` | date | sim | 100% |  | tipo forçado (inferido: date) |
| `entry_date` | `data_lancamento` | timestamptz | sim | 100% |  | tipos mistos: string_data=2176, string_datahora=327 |
| `reference` | `referencia` | varchar(50) | sim | 100% |  |  |
| `doc_number` | `numero_documento` | varchar(50) | sim | 100% |  | tipos mistos: string=2397, string_inteiro=108 |
| `description` | `descricao` | text | sim | 100% |  |  |
| `value` | `valor` | numeric(15,2) | sim | 100% |  | tipos mistos: decimal=985, inteiro=1520 |
| `type_dc` | `tipo_dc` | varchar(10) | sim | 100% |  |  |
| `account_code` | `codigo_conta` | varchar(20) | sim | 100% |  | tipos mistos: string_inteiro=2493, string_decimal=12 |
| `third_party_id` | `terceiro_id` | bigint | sim | 46% | `terceiros.id` |  |
| `demo_note_id` | `nota_demonstracao_id` | bigint | sim | 93% | `notas_demonstracao_resultados.id` | tipos Dexie: {"undef":11} |
| `cashflow_note_id` | `nota_fluxo_caixa_id` | bigint | sim | 7% | `notas_fluxo_caixa.id` | tipos Dexie: {"undef":11} |
| `doc_url` | `url_documento` | varchar(150) | sim | 28% |  | tipos Dexie: {"undef":24} |
| `original_id` | `lancamento_original_id` | integer | sim | 100% |  |  |
| `deleted_at` | `eliminado_em` | timestamptz | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `reconciliation_id` | `reconciliacao_codigo` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `acc_period` | `periodo_contabil` | integer | sim | 0% |  |  |
| `period_id` | `periodo_id` | integer | sim | 17% |  |  |
| `business_unit_id` | `unidade_negocio_id` | bigint | sim | 16% | `unidades_negocio.id` |  |
| `cost_center_id` | `centro_custo_id` | bigint | sim | 15% | `centros_custo.id` |  |
| `created_at` | `criado_em` | date | sim | 7% |  |  |
| `lan_number` | `numero_lan` | varchar(20) | sim | 7% |  |  |
| `source_doc_id` | `documento_origem_id` | varchar(10) | sim | 0% |  |  |
| `source_doc_type` | `tipo_documento_origem` | varchar(20) | sim | 0% |  | código normalizado ∈ {FATURA_COMPRA, RECECAO_COMPRA}; texto original em tipo_documento_origem_original |
| `source_doc_type` | `tipo_documento_origem_original` | varchar(30) | sim | 0% |  | texto exacto do legado |
| `project_id` | `projeto_id` | bigint | sim | 0% | `projetos.id` |  |
| `project_code` | `codigo_projeto` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado; tipos Dexie: {"undef":2} |
| `system_origin` | `sistema_origem` | varchar(20) | sim | 0% |  |  |
| `user` | `nome_utilizador` | varchar(20) | sim | 0% |  |  |
| `tax_value` | `valor_imposto` | numeric(15,2) | sim | 0% |  |  |

### `demo_notes` → `notas_demonstracao_resultados` (model `NotaDemonstracao`, `/api/contabilidade/notas-demonstracao`)

Linhas reais: **366** · fictícias descartadas: 15

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `code` | `codigo` | varchar(10) | sim | 100% |  |  |
| `description` | `descricao` | text | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `cashflow_notes` → `notas_fluxo_caixa` (model `NotaFluxoCaixa`, `/api/contabilidade/notas-fluxo-caixa`)

Linhas reais: **411** · fictícias descartadas: 15

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `code` | `codigo` | varchar(10) | sim | 100% |  | tipos mistos: string_inteiro=393, string=18 |
| `description` | `descricao` | text | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `historical_balances` → `saldos_historicos` (model `SaldoHistorico`, `/api/contabilidade/saldos-historicos`)

Linhas reais: **114** · fictícias descartadas: 15

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `year` | `ano` | integer | sim | 99% |  | tipos Dexie: {"undef":1} |
| `type` | `tipo` | varchar(28) | sim | 99% |  | tipos Dexie: {"undef":1}; código normalizado ∈ {DEMONSTRACAO_RESULTADOS, FLUXO_CAIXA}; texto original em tipo_original |
| `type` | `tipo_original` | varchar(10) | sim | 99% |  | texto exacto do legado |
| `code` | `codigo` | varchar(20) | sim | 100% |  | tipos mistos: string_inteiro=105, string=9 |
| `value` | `valor` | numeric(15,2) | sim | 100% |  | tipos mistos: decimal=52, inteiro=62 |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `annual_reports` → `relatorios_anuais_contas` (model `RelatorioAnualContas`, `/api/contabilidade/relatorios-anuais`)

Linhas reais: **1** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `report_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `report_year` | `ano_relatorio` | integer | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {RASCUNHO, APROVADO}; texto original em estado_original |
| `status` | `estado_original` | varchar(10) | sim | 100% |  | texto exacto do legado |
| `config` | `configuracao` | jsonb | sim | 100% |  |  |
| `textos` | `textos` | jsonb | sim | 100% |  |  |
| `notas_incluir` | `notas_incluir` | jsonb | sim | 100% |  |  |
| `criado_em` | `criado_em` | timestamptz | sim | 100% |  |  |
| `atualizado_em` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `atualizado_por` | `atualizado_por` | varchar(10) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `consolidation_groups` → `grupos_consolidacao` (model `GrupoConsolidacao`, `/api/contabilidade/consolidacao/grupos`)

Linhas reais: **1** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `holding_company_id` | `empresa_holding_id` | bigint | sim | 100% | `empresas.id` |  |
| `name` | `nome` | varchar(10) | sim | 100% |  |  |
| `presentation_currency` | `moeda_apresentacao` | varchar(10) | sim | 100% |  |  |
| `fx_reserve_account` | `conta_reserva_cambial` | varchar(10) | sim | 100% |  |  |
| `elim_enabled` | `eliminacao_ativa` | boolean | sim | 100% |  |  |
| `elim_exclude_prefixes` | `prefixos_excluidos_eliminacao` | varchar(10) | sim | 100% |  | 1 valores são listas separadas por vírgulas -> tabela pivô |
| `elim_diff_account` | `conta_diferenca_eliminacao` | varchar(10) | sim | 100% |  |  |
| `created_on` | `criado_em` | date | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `last_run_id` | `ultima_execucao_id` | bigint | sim | 100% | `execucoes_consolidacao.id` |  |

### `consolidation_members` → `membros_consolidacao` (model `MembroConsolidacao`, `/api/contabilidade/consolidacao/membros`)

Linhas reais: **3** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `group_id` | `grupo_consolidacao_id` | bigint | sim | 100% | `grupos_consolidacao.id` |  |
| `member_company_id` | `empresa_membro_id` | bigint | sim | 100% | `empresas.id` |  |
| `percentage` | `percentagem` | numeric(9,4) | sim | 100% |  |  |
| `method` | `metodo` | varchar(20) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `consolidation_runs` → `execucoes_consolidacao` (model `ExecucaoConsolidacao`, `/api/contabilidade/consolidacao/execucoes`)

Linhas reais: **4** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `group_id` | `grupo_consolidacao_id` | bigint | sim | 100% | `grupos_consolidacao.id` |  |
| `holding_company_id` | `empresa_holding_id` | bigint | sim | 100% | `empresas.id` |  |
| `run_date` | `data_execucao` | date | sim | 100% |  |  |
| `run_at` | `executado_em` | timestamptz | sim | 100% |  |  |
| `date_end` | `data_fim` | date | sim | 100% |  |  |
| `currency` | `codigo_moeda` | varchar(10) | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  |  |
| `user` | `nome_utilizador` | varchar(10) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `totals` | `totais` | jsonb | sim | 100% |  |  |

## Módulo: Terceiros

### `third_parties` → `terceiros` (model `Terceiro`, `/api/terceiros`)

Linhas reais: **6545** · fictícias descartadas: 15

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `nif` | `nif` | varchar(50) | sim | 100% |  | tipos mistos: string=656, string_inteiro=5889 |
| `name` | `nome` | varchar(150) | sim | 100% |  |  |
| `type` | `tipo` | varchar(23) | sim | 100% |  | código normalizado ∈ {CLIENTE, FORNECEDOR, COLABORADOR, CLIENTE_FORNECEDOR}; texto original em tipo_original |
| `type` | `tipo_original` | varchar(30) | sim | 100% |  | texto exacto do legado |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `address` | `endereco` | text | sim | 1% |  |  |
| `account_code` | `codigo_conta` | varchar(10) | sim | 5% |  | tipos mistos: string_inteiro=301, string_decimal=8 |
| `account_purchase_clearing` | `conta_compra_transitoria` | varchar(10) | sim | 4% |  |  |
| `email` | `email` | varchar(50) | sim | 0% |  |  |
| `currency` | `codigo_moeda` | varchar(10) | sim | 0% |  |  |
| `phone` | `telefone` | varchar(30) | sim | 0% |  |  |

### `third_party_addresses` → `enderecos_terceiros` (model `EnderecoTerceiro`, `/api/terceiros/enderecos`)

Linhas reais: **54** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `nif` | `nif` | varchar(30) | sim | 100% |  | tipos mistos: string_inteiro=44, string=10 |
| `street` | `rua` | varchar(100) | sim | 6% |  |  |
| `door_number` | `numero_porta` | varchar(10) | sim | 2% |  |  |
| `neighborhood` | `bairro` | varchar(20) | sim | 4% |  |  |
| `municipality` | `municipio` | varchar(20) | sim | 6% |  |  |
| `province` | `provincia` | varchar(10) | sim | 4% |  |  |
| `country` | `pais` | varchar(10) | sim | 6% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

## Módulo: Vendas

### `sales` → `vendas` (model `Venda`, `/api/vendas/documentos`)

Linhas reais: **102** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `customer_id` | `cliente_id` | bigint | sim | 98% | `terceiros.id` |  |
| `doc_type` | `tipo_documento` | varchar(20) | sim | 100% |  | código normalizado ∈ {FT, FR, NC, ND, PF, OR, NE, GR, GD}; texto original em tipo_documento_original |
| `doc_type` | `tipo_documento_original` | varchar(30) | sim | 100% |  | texto exacto do legado |
| `doc_number` | `numero_documento` | varchar(50) | sim | 100% |  |  |
| `date` | `data_emissao` | timestamptz | sim | 100% |  | tipos mistos: string_datahora=47, string_data=55 |
| `total_net` | `total_liquido` | numeric(15,2) | sim | 98% |  | tipos mistos: inteiro=57, decimal=43 |
| `total_tax` | `total_imposto` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=29, decimal=73 |
| `total_gross` | `total_bruto` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=70, decimal=32 |
| `related_doc_id` | `documentos_relacionados` | varchar(10) | sim | 33% |  | tipos mistos: inteiro=27, string_inteiro=7 |
| `delivery_date` | `data_entrega` | date | sim | 0% |  | sem valores reais: tipo a confirmar no código legado; tipo forçado (inferido: text) |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `paid_amount` | `valor_pago` | numeric(15,2) | sim | 61% |  |  |
| `pending_amount` | `valor_pendente` | numeric(15,2) | sim | 61% |  | tipos mistos: inteiro=32, decimal=30 |
| `is_posted` | `contabilizado` | boolean | sim | 70% |  | tipos mistos: boolean=70, inteiro=1 |
| `status` | `estado` | varchar(20) | sim | 68% |  | código normalizado ∈ {PENDENTE, PARCIAL, PAGO, CONCLUIDO, ANULADO}; texto original em estado_original |
| `status` | `estado_original` | varchar(20) | sim | 68% |  | texto exacto do legado |
| `project_id` | `projeto_id` | bigint | sim | 0% | `projetos.id` |  |
| `project_code` | `codigo_projeto` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `delivery_location` | `local_entrega` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `notes` | `observacoes` | text | sim | 21% |  |  |
| `payment_accounts` | `contas_pagamento` | jsonb | sim | 27% |  |  |
| `payment_terms` | `condicoes_pagamento` | text | sim | 5% |  |  |
| `hide_payment_methods` | `ocultar_meios_pagamento` | boolean | sim | 21% |  |  |
| `business_unit_id` | `unidade_negocio_id` | bigint | sim | 45% | `unidades_negocio.id` |  |
| `cost_center_id` | `centro_custo_id` | bigint | sim | 46% | `centros_custo.id` |  |
| `discount` | `desconto` | numeric(15,2) | sim | 27% |  |  |
| `pos_session_id` | `sessao_pos_id` | bigint | sim | 27% | `sessoes_pos.id` ⚠ 60.7% (11 órfãos) |  |
| `payment_method` | `meio_pagamento` | varchar(20) | sim | 27% |  | código normalizado ∈ {NUMERARIO, TPA, TRANSFERENCIA, CONTA_CORRENTE, MISTO}; texto original em meio_pagamento_original |
| `payment_method` | `meio_pagamento_original` | varchar(50) | sim | 27% |  | texto exacto do legado |
| `table_name` | `nome_tabela` | varchar(50) | sim | 8% |  |  |
| `third_party_id` | `terceiro_id` | bigint | sim | 0% | `terceiros.id` | tipos Dexie: {"undef":2} |
| `due_date` | `data_vencimento` | date | sim | 8% |  |  |
| `subtotal` | `subtotal` | numeric(15,2) | sim | 5% |  |  |
| `total_discount` | `total_desconto` | numeric(15,2) | sim | 5% |  |  |
| `total_amount` | `montante_total` | numeric(15,2) | sim | 5% |  |  |
| `lines` | `linhas` | jsonb | sim | 2% |  |  |
| `currency` | `codigo_moeda` | varchar(10) | sim | 7% |  |  |
| `exchange_rate` | `taxa_cambio` | numeric(18,6) | sim | 7% |  |  |
| `exchange_rate_id` | `taxa_cambio_id` | bigint | sim | 7% | `taxas_cambio.id` |  |
| `exchange_rate_manual` | `taxa_cambio_manual` | boolean | sim | 7% |  |  |
| `total_net_currency` | `total_liquido_moeda` | numeric(15,2) | sim | 7% |  |  |
| `total_tax_currency` | `total_imposto_moeda` | numeric(15,2) | sim | 7% |  | tipos mistos: inteiro=5, decimal=2 |
| `total_gross_currency` | `total_bruto_moeda` | numeric(15,2) | sim | 7% |  | tipos mistos: inteiro=5, decimal=2 |
| `pos_terminal_id` | `terminal_pos_id` | bigint | sim | 17% | `terminais_pos.id` |  |
| `pos_terminal_code` | `codigo_terminal_pos` | varchar(10) | sim | 17% |  |  |
| `pos_payments` | `pos_pagamentos` | jsonb | sim | 14% |  |  |
| `pos_change` | `pos_troco` | numeric(15,2) | sim | 17% |  |  |
| `pos_operator` | `pos_operador` | varchar(10) | sim | 17% |  |  |
| `pos_posting_lans` | `pos_lans_contabilizacao` | jsonb | sim | 14% |  |  |
| `lav_order_id` | `pedido_lavandaria_id` | bigint | sim | 3% | `pedidos_lavandaria.id` |  |
| `lav_order_number` | `numero_pedido_lavandaria` | varchar(30) | sim | 3% |  |  |
| `amount_paid` | `montante_pago` | numeric(15,2) | sim | 3% |  |  |
| `hotel_stay_ids` | `estadias_hotel_ids` | jsonb | sim | 8% |  |  |
| `valid_until` | `valido_ate` | date | sim | 5% |  |  |
| `validity_days` | `dias_validade` | integer | sim | 5% |  |  |
| `payment_mode` | `modo_pagamento` | varchar(20) | sim | 5% |  | código normalizado ∈ {PRONTO, PRAZO, MARCOS}; texto original em modo_pagamento_original |
| `payment_mode` | `modo_pagamento_original` | varchar(10) | sim | 5% |  | texto exacto do legado |
| `payment_schedule` | `plano_pagamentos` | jsonb | sim | 5% |  |  |
| `crm_opportunity_id` | `oportunidade_crm_id` | bigint | sim | 2% | `oportunidades_venda_crm.id` |  |

### `sale_items` → `itens_venda` (model `ItemVenda`, `/api/vendas/itens`)

Linhas reais: **95** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `product_id` | `produto_id` | bigint | sim | 96% | `produtos.id` |  |
| `quantity` | `quantidade` | numeric(12,3) | sim | 100% |  | tipos mistos: inteiro=93, decimal=2 |
| `unit_price` | `preco_unitario` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=84, decimal=11 |
| `tax_rate` | `taxa_imposto` | numeric(9,4) | sim | 100% |  |  |
| `total` | `total` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=82, decimal=13 |
| `sale_id` | `venda_id` | bigint | sim | 100% | `vendas.id` |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `project_id` | `projeto_id` | bigint | sim | 0% | `projetos.id` |  |
| `project_code` | `codigo_projeto` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `notes` | `observacoes` | text | sim | 1% |  | tipo forçado (inferido: integer) |
| `billed_qty` | `quantidade_faturada` | numeric(12,3) | sim | 61% |  |  |
| `purchase_request_id` | `pedido_compra_id` | bigint | sim | 7% | `pedidos_compra.id` |  |
| `delivered_qty` | `quantidade_entregue` | numeric(12,3) | sim | 45% |  |  |
| `description` | `descricao` | text | sim | 18% |  | tipo forçado (inferido: text) |
| `discount_pct` | `percentagem_desconto` | numeric(9,4) | sim | 3% |  |  |
| `line_total` | `total_linha` | numeric(15,2) | sim | 3% |  |  |
| `account_code` | `codigo_conta` | varchar(10) | sim | 3% |  |  |
| `unit_price_currency` | `preco_unitario_moeda` | numeric(15,2) | sim | 7% |  | tipos mistos: inteiro=5, decimal=2 |
| `total_currency` | `total_moeda` | numeric(15,2) | sim | 7% |  | tipos mistos: inteiro=5, decimal=2 |
| `tax_currency` | `imposto_moeda` | numeric(15,2) | sim | 7% |  | tipos mistos: inteiro=5, decimal=2 |

### `receipts` → `recibos_venda` (model `ReciboVenda`, `/api/vendas/recibos`)

Linhas reais: **2** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `customer_id` | `cliente_id` | bigint | sim | 100% | `terceiros.id` |  |
| `business_unit_id` | `unidade_negocio_id` | bigint | sim | 100% | `unidades_negocio.id` |  |
| `cost_center_id` | `centro_custo_id` | bigint | sim | 100% | `centros_custo.id` |  |
| `receipt_number` | `numero_recibo` | varchar(20) | sim | 100% |  |  |
| `date` | `data` | date | sim | 100% |  |  |
| `total_amount` | `montante_total` | numeric(15,2) | sim | 100% |  |  |
| `payment_method` | `meio_pagamento` | varchar(20) | sim | 100% |  |  |
| `bank_id` | `banco_id` | bigint | sim | 0% | `bancos.id` |  |
| `account_code` | `codigo_conta` | varchar(20) | sim | 100% |  |  |
| `payment_reference` | `referencia_pagamento` | varchar(20) | sim | 50% |  |  |
| `is_posted` | `contabilizado` | boolean | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `receipt_items` → `itens_recibo_venda` (model `ItemReciboVenda`, `/api/vendas/recibos/itens`)

Linhas reais: **2** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `receipt_id` | `recibo_venda_id` | bigint | sim | 100% | `recibos_venda.id` |  |
| `sale_id` | `venda_id` | bigint | sim | 100% | `vendas.id` |  |
| `amount_paid` | `montante_pago` | numeric(15,2) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `sales_accounting_config` → `configuracoes_contabeis_vendas` (model `ConfigContabilVenda`, `/api/vendas/config-contabil`)

Linhas reais: **0** · fictícias descartadas: 16 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `fe_config` → `configuracoes_faturacao_eletronica` (model `ConfigFaturacaoEletronica`, `/api/vendas/faturacao-eletronica/config`)

Linhas reais: **1** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `fe_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `activo` | `ativo` | boolean | sim | 100% |  |  |
| `data_inicio` | `data_inicio` | date | sim | 100% |  |  |
| `estabelecimentos` | `estabelecimentos` | jsonb | sim | 100% |  |  |
| `pais_padrao` | `pais_padrao` | varchar(10) | sim | 100% |  |  |
| `isencao_padrao` | `isencao_padrao` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `software` | `software` | jsonb | sim | 100% |  |  |
| `actualizado_em` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `actualizado_por` | `atualizado_por` | varchar(10) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `fe_series` → `series_faturacao_eletronica` (model `SerieFaturacaoEletronica`, `/api/vendas/faturacao-eletronica/series`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

## Módulo: Compras

### `purchase_requests` → `pedidos_compra` (model `PedidoCompra`, `/api/compras/pedidos`)

Linhas reais: **21** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `requester_name` | `nome_requerente` | varchar(50) | sim | 100% |  |  |
| `date` | `data` | timestamptz | sim | 100% |  | tipos mistos: string_data=14, string_datahora=7 |
| `status` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {PENDENTE, APROVADO, REJEITADO, ADJUDICADO, FECHADO, ANULADO}; texto original em estado_original |
| `status` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `source_sale_id` | `venda_origem_id` | bigint | sim | 33% | `vendas.id` |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `project_id` | `projeto_id` | bigint | sim | 33% | `projetos.id` |  |
| `project_code` | `codigo_projeto` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `description` | `descricao` | text | sim | 29% |  |  |
| `delivery_date` | `data_entrega` | date | sim | 33% |  |  |
| `notes` | `observacoes` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `deliberacao` | `deliberacao` | jsonb | sim | 29% |  |  |
| `business_unit_id` | `unidade_negocio_id` | bigint | sim | 33% | `unidades_negocio.id` |  |
| `cost_center_id` | `centro_custo_id` | bigint | sim | 33% | `centros_custo.id` |  |
| `expected_date` | `data_prevista` | date | sim | 14% |  |  |
| `criado_por` | `criado_por` | varchar(20) | sim | 5% |  |  |
| `requester_employee_id` | `colaborador_requerente_id` | bigint | sim | 5% | `colaboradores.id` |  |

### `purchase_quotes` → `cotacoes_compra` (model `CotacaoCompra`, `/api/compras/cotacoes`)

Linhas reais: **23** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `request_id` | `pedido_compra_id` | bigint | sim | 100% | `pedidos_compra.id` |  |
| `supplier_id` | `fornecedor_id` | bigint | sim | 100% | `terceiros.id` |  |
| `reference` | `referencia` | varchar(50) | sim | 100% |  |  |
| `total_amount` | `montante_total` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=21, decimal=2 |
| `date` | `data` | date | sim | 100% |  |  |
| `delivery_date` | `data_entrega` | date | sim | 70% |  |  |
| `status` | `estado` | varchar(25) | sim | 100% |  | código normalizado ∈ {PROPOSTA, PROPOSTA_ADJUDICACAO, ADJUDICADO, RECUSADA, ANULADA}; texto original em estado_original |
| `status` | `estado_original` | varchar(30) | sim | 100% |  | texto exacto do legado |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `business_unit_id` | `unidade_negocio_id` | bigint | sim | 39% | `unidades_negocio.id` |  |
| `cost_center_id` | `centro_custo_id` | bigint | sim | 39% | `centros_custo.id` |  |
| `currency` | `codigo_moeda` | varchar(10) | sim | 4% |  |  |
| `exchange_rate` | `taxa_cambio` | numeric(18,6) | sim | 4% |  |  |
| `exchange_rate_id` | `taxa_cambio_id` | bigint | sim | 4% | `taxas_cambio.id` |  |
| `exchange_rate_manual` | `taxa_cambio_manual` | boolean | sim | 4% |  |  |
| `total_amount_currency` | `montante_total_moeda` | numeric(15,2) | sim | 4% |  |  |

### `purchase_orders` → `encomendas_compra` (model `EncomendaCompra`, `/api/compras/encomendas`)

Linhas reais: **17** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `request_id` | `pedido_compra_id` | bigint | sim | 94% | `pedidos_compra.id` |  |
| `quote_id` | `cotacao_compra_id` | bigint | sim | 94% | `cotacoes_compra.id` |  |
| `supplier_id` | `fornecedor_id` | bigint | sim | 100% | `terceiros.id` |  |
| `order_number` | `numero_encomenda` | varchar(30) | sim | 100% |  |  |
| `date` | `data` | timestamptz | sim | 100% |  | tipos mistos: string_datahora=16, string_data=1 |
| `status` | `estado` | varchar(21) | sim | 100% |  | código normalizado ∈ {EM_PROCESSAMENTO, PARCIAL, RECEBIDO, ANULADA}; texto original em estado_original |
| `status` | `estado_original` | varchar(30) | sim | 100% |  | texto exacto do legado |
| `is_posted` | `contabilizado` | boolean | sim | 94% |  |  |
| `source_sale_id` | `venda_origem_id` | bigint | sim | 35% | `vendas.id` | tipos Dexie: {"undef":2} |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `project_id` | `projeto_id` | bigint | sim | 6% | `projetos.id` |  |
| `project_code` | `codigo_projeto` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `contract_id` | `contrato_fornecedor_id` | bigint | sim | 12% | `contratos_fornecedores.id` |  |
| `business_unit_id` | `unidade_negocio_id` | bigint | sim | 35% | `unidades_negocio.id` |  |
| `cost_center_id` | `centro_custo_id` | bigint | sim | 35% | `centros_custo.id` |  |
| `currency` | `codigo_moeda` | varchar(10) | sim | 6% |  |  |
| `exchange_rate` | `taxa_cambio` | numeric(18,6) | sim | 6% |  |  |
| `exchange_rate_id` | `taxa_cambio_id` | bigint | sim | 6% | `taxas_cambio.id` |  |
| `exchange_rate_manual` | `taxa_cambio_manual` | boolean | sim | 6% |  |  |
| `total_amount` | `montante_total` | numeric(15,2) | sim | 6% |  |  |
| `total_amount_currency` | `montante_total_moeda` | numeric(15,2) | sim | 6% |  |  |

### `purchase_deliveries` → `rececoes_compra` (model `RececaoCompra`, `/api/compras/rececoes`)

Linhas reais: **22** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `order_id` | `encomenda_compra_id` | bigint | sim | 100% | `encomendas_compra.id` |  |
| `delivery_number` | `numero_entrega` | varchar(30) | sim | 100% |  |  |
| `date` | `data` | date | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {RECEBIDO, VALIDADO, ANULADO}; texto original em estado_original |
| `status` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `is_posted` | `contabilizado` | boolean | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `is_validated` | `validado` | boolean | sim | 91% |  |  |
| `warehouse_id` | `armazem_id` | bigint | sim | 91% | `armazens.id` |  |
| `business_unit_id` | `unidade_negocio_id` | bigint | sim | 36% | `unidades_negocio.id` |  |
| `cost_center_id` | `centro_custo_id` | bigint | sim | 36% | `centros_custo.id` |  |
| `currency` | `codigo_moeda` | varchar(10) | sim | 5% |  |  |
| `exchange_rate` | `taxa_cambio` | numeric(18,6) | sim | 5% |  |  |
| `exchange_rate_id` | `taxa_cambio_id` | bigint | sim | 5% | `taxas_cambio.id` |  |
| `exchange_rate_manual` | `taxa_cambio_manual` | boolean | sim | 14% |  |  |
| `total_value_kz` | `valor_total_kz` | numeric(15,2) | sim | 14% |  | tipos mistos: inteiro=2, decimal=1 |

### `purchase_invoices` → `faturas_compra` (model `FaturaCompra`, `/api/compras/faturas`)

Linhas reais: **25** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `order_id` | `encomenda_compra_id` | bigint | sim | 80% | `encomendas_compra.id` |  |
| `supplier_id` | `fornecedor_id` | bigint | sim | 100% | `terceiros.id` |  |
| `invoice_number` | `numero_fatura` | varchar(50) | sim | 100% |  |  |
| `date` | `data` | date | sim | 100% |  |  |
| `total_amount` | `montante_total` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=20, decimal=5 |
| `total_tax` | `total_imposto` | numeric(15,2) | sim | 84% |  | tipos mistos: inteiro=8, decimal=13 |
| `status` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {PENDENTE, PARCIAL, PAGO, ANULADA}; texto original em estado_original |
| `status` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `is_posted` | `contabilizado` | boolean | sim | 100% |  | tipos mistos: boolean=24, inteiro=1 |
| `items` | `itens` | jsonb | sim | 84% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `project_id` | `projeto_id` | bigint | sim | 16% | `projetos.id` |  |
| `project_code` | `codigo_projeto` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `business_unit_id` | `unidade_negocio_id` | bigint | sim | 24% | `unidades_negocio.id` |  |
| `cost_center_id` | `centro_custo_id` | bigint | sim | 24% | `centros_custo.id` |  |
| `currency` | `codigo_moeda` | varchar(10) | sim | 4% |  |  |
| `exchange_rate` | `taxa_cambio` | numeric(18,6) | sim | 4% |  |  |
| `exchange_rate_id` | `taxa_cambio_id` | bigint | sim | 4% | `taxas_cambio.id` |  |
| `exchange_rate_manual` | `taxa_cambio_manual` | boolean | sim | 8% |  |  |
| `total_amount_currency` | `montante_total_moeda` | numeric(15,2) | sim | 4% |  |  |
| `total_tax_currency` | `total_imposto_moeda` | numeric(15,2) | sim | 4% |  |  |

### `purchase_items` → `itens_compra` (model `ItemCompra`, `/api/compras/itens`)

Linhas reais: **90** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `parent_id` | `documento_origem_id` | varchar(10) | sim | 97% |  |  |
| `parent_type` | `tipo_documento_origem` | varchar(20) | sim | 97% |  | código normalizado ∈ {PEDIDO, COTACAO, ENCOMENDA, FATURA}; texto original em tipo_documento_origem_original |
| `parent_type` | `tipo_documento_origem_original` | varchar(20) | sim | 97% |  | texto exacto do legado |
| `product_id` | `produto_id` | bigint | sim | 91% | `produtos.id` |  |
| `quantity` | `quantidade` | numeric(12,3) | sim | 100% |  |  |
| `unit_price` | `preco_unitario` | numeric(15,2) | sim | 97% |  | tipos mistos: inteiro=83, decimal=4 |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `project_id` | `projeto_id` | bigint | sim | 12% | `projetos.id` |  |
| `project_code` | `codigo_projeto` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `received_qty` | `quantidade_recebida` | numeric(12,3) | sim | 36% |  |  |
| `invoiced_qty` | `quantidade_faturada` | numeric(12,3) | sim | 27% |  |  |
| `company_id` | `empresa_id` | bigint | sim | 12% | `empresas.id` |  |
| `order_id` | `encomenda_compra_id` | bigint | sim | 3% | `encomendas_compra.id` ⚠ 0.0% (3 órfãos) |  |
| `description` | `descricao` | text | sim | 9% |  |  |
| `total` | `total` | numeric(15,2) | sim | 3% |  |  |
| `task_id` | `tarefa_projeto_id` | bigint | sim | 8% | `tarefas_projeto.id` |  |
| `unit_price_currency` | `preco_unitario_moeda` | numeric(15,2) | sim | 4% |  |  |
| `total_currency` | `total_moeda` | numeric(15,2) | sim | 4% |  |  |
| `total_kz` | `total_kz` | numeric(15,2) | sim | 4% |  | tipos mistos: inteiro=2, decimal=2 |
| `fx_rec_por_faturar_qty` | `cambial_recebido_por_faturar_qtd` | numeric(12,3) | sim | 3% |  |  |
| `fx_rec_por_faturar_kz` | `cambial_recebido_por_faturar_kz` | numeric(15,2) | sim | 3% |  |  |
| `fx_fat_por_receber_qty` | `cambial_faturado_por_receber_qtd` | numeric(12,3) | sim | 3% |  |  |
| `fx_fat_por_receber_kz` | `cambial_faturado_por_receber_kz` | numeric(15,2) | sim | 3% |  |  |

### `purchase_catalog` → `catalogo_fornecedores` (model `CatalogoFornecedor`, `/api/compras/catalogo`)

Linhas reais: **70** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `product_id` | `produto_id` | bigint | sim | 100% | `produtos.id` |  |
| `code` | `codigo` | varchar(30) | sim | 100% |  |  |
| `name` | `nome` | varchar(150) | sim | 100% |  |  |
| `unit_price` | `preco_unitario` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=69, decimal=1 |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `purchase_contracts` → `contratos_fornecedores` (model `ContratoFornecedor`, `/api/compras/contratos`)

Linhas reais: **1** · fictícias descartadas: 15

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `supplier_id` | `fornecedor_id` | bigint | sim | 100% | `terceiros.id` |  |
| `order_id` | `encomenda_compra_id` | bigint | sim | 100% | `encomendas_compra.id` |  |
| `order_ids` | `encomendas_ids` | jsonb | sim | 100% |  |  |
| `reference` | `referencia` | varchar(20) | sim | 100% |  |  |
| `description` | `descricao` | text | sim | 100% |  |  |
| `start_date` | `data_inicio` | date | sim | 100% |  |  |
| `end_date` | `data_fim` | date | sim | 100% |  |  |
| `total_value` | `valor_total` | numeric(15,2) | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {ATIVO, EXPIRADO, CANCELADO}; texto original em estado_original |
| `status` | `estado_original` | varchar(10) | sim | 100% |  | texto exacto do legado |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `purchase_contract_milestones` → `marcos_contrato_fornecedor` (model `MarcoContratoFornecedor`, `/api/compras/contratos/marcos`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

## Módulo: Logística

### `warehouses` → `armazens` (model `Armazem`, `/api/logistica/armazens`)

Linhas reais: **6** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `name` | `nome` | varchar(30) | sim | 100% |  |  |
| `location` | `localizacao` | varchar(50) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `products` → `produtos` (model `Produto`, `/api/logistica/produtos`)

Linhas reais: **100** · fictícias descartadas: 24

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `code` | `codigo` | varchar(30) | sim | 100% |  |  |
| `name` | `nome` | varchar(150) | sim | 100% |  |  |
| `unit_price` | `preco_unitario` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=99, decimal=1 |
| `tax_rate` | `taxa_imposto` | numeric(9,4) | sim | 100% |  |  |
| `stock_qty` | `quantidade_stock` | numeric(12,3) | sim | 75% |  |  |
| `is_inventory` | `movimenta_stock` | boolean | sim | 100% |  | tipos mistos: boolean=77, inteiro=23 |
| `account_code` | `codigo_conta` | varchar(10) | sim | 78% |  |  |
| `account_purchase` | `conta_compra` | varchar(20) | sim | 32% |  | tipos mistos: string_inteiro=13, inteiro=19 |
| `account_inventory` | `conta_inventario` | varchar(10) | sim | 32% |  | tipos mistos: string_inteiro=13, inteiro=19 |
| `account_cost` | `conta_custo` | varchar(20) | sim | 36% |  | tipos mistos: string_inteiro=17, inteiro=19 |
| `category_id` | `categoria_produto_id` | bigint | sim | 65% | `categorias_produtos.id` |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `account_iva` | `conta_iva` | varchar(20) | sim | 68% |  | tipos mistos: string_inteiro=26, inteiro=42 |
| `account_iva_liquidado` | `conta_iva_liquidado` | varchar(20) | sim | 24% |  |  |
| `account_iva_dedutivel` | `conta_iva_dedutivel` | varchar(20) | sim | 11% |  |  |
| `account_shortage` | `conta_quebra` | varchar(10) | sim | 1% |  |  |
| `account_surplus` | `conta_sobra` | varchar(10) | sim | 1% |  |  |
| `image_base64` | `imagem_base64` | text | sim | 3% |  |  |
| `is_room` | `e_quarto` | boolean | sim | 48% |  |  |
| `is_asset` | `e_ativo_imobilizado` | boolean | sim | 23% |  |  |
| `account_asset` | `conta_ativo` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `pricePerHour` | `preco_por_hora` | numeric(15,2) | sim | 28% |  | tipos Dexie: {"undef":18} |
| `pricePerDay` | `preco_por_dia` | numeric(15,2) | sim | 28% |  | tipos Dexie: {"undef":18} |
| `minHours` | `horas_minimas` | numeric(12,3) | sim | 28% |  | tipos Dexie: {"undef":18} |
| `is_service` | `e_servico` | boolean | sim | 31% |  | tipos mistos: inteiro=24, boolean=7 |
| `is_blocked` | `bloqueado` | boolean | sim | 13% |  |  |
| `lav_group` | `lavandaria_grupo` | varchar(20) | sim | 3% |  |  |
| `lav_unit` | `lavandaria_unidade` | varchar(10) | sim | 1% |  |  |
| `lav_lead_days` | `lavandaria_dias_entrega` | integer | sim | 3% |  |  |
| `lav_requires_quote` | `lavandaria_requer_orcamento` | boolean | sim | 3% |  |  |
| `lav_active` | `lavandaria_ativa` | boolean | sim | 3% |  |  |
| `lav_price_piece` | `lavandaria_preco_peca` | numeric(15,2) | sim | 1% |  |  |
| `lav_price_kg` | `lavandaria_preco_kg` | numeric(15,2) | sim | 1% |  |  |
| `fe_unidade` | `unidade_fe` | varchar(10) | sim | 2% |  |  |
| `fe_tipo_operacao` | `tipo_operacao_fe` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `fe_isencao` | `codigo_isencao_fe` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |

### `product_categories` → `categorias_produtos` (model `CategoriaProduto`, `/api/logistica/categorias-produtos`)

Linhas reais: **23** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `name` | `nome` | varchar(100) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `warehouse_stock` → `stock_armazem` (model `StockArmazem`, `/api/logistica/stock`)

Linhas reais: **16** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `warehouse_id` | `armazem_id` | bigint | sim | 100% | `armazens.id` |  |
| `product_id` | `produto_id` | bigint | sim | 100% | `produtos.id` |  |
| `stock_qty` | `quantidade_stock` | numeric(12,3) | sim | 100% |  | tipos mistos: inteiro=12, decimal=4 |

### `inventory_movements` → `movimentos_inventario` (model `MovimentoInventario`, `/api/logistica/movimentos`)

Linhas reais: **102** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `product_id` | `produto_id` | bigint | sim | 100% | `produtos.id` |  |
| `warehouse_id` | `armazem_id` | bigint | sim | 100% | `armazens.id` |  |
| `type` | `tipo` | varchar(20) | sim | 96% |  | código normalizado ∈ {ENTRADA, SAIDA, TRANSFERENCIA, AJUSTE}; texto original em tipo_original |
| `type` | `tipo_original` | varchar(20) | sim | 96% |  | texto exacto do legado |
| `quantity` | `quantidade` | numeric(12,3) | sim | 100% |  | tipos mistos: inteiro=100, decimal=2 |
| `date` | `data` | timestamptz | sim | 100% |  | tipos mistos: string_datahora=79, string_data=23 |
| `third_party_id` | `terceiro_id` | bigint | sim | 82% | `terceiros.id` |  |
| `reference` | `referencia` | varchar(100) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `project_id` | `projeto_id` | bigint | sim | 0% | `projetos.id` |  |
| `project_code` | `codigo_projeto` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `unit_price` | `preco_unitario` | numeric(15,2) | sim | 69% |  | tipos mistos: inteiro=55, decimal=15 |

### `delivery_notes` → `guias_saida` (model `GuiaSaida`, `/api/logistica/guias-saida`)

Linhas reais: **11** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `doc_number` | `numero_documento` | varchar(30) | sim | 100% |  |  |
| `date` | `data` | date | sim | 100% |  |  |
| `type` | `tipo` | varchar(20) | sim | 100% |  | código normalizado ∈ {VENDA, BACK_TO_BACK, CONSUMO, VENDA_BALCAO}; texto original em tipo_original |
| `type` | `tipo_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `entity_id` | `terceiro_id` | bigint | sim | 73% | `terceiros.id` |  |
| `warehouse_id` | `armazem_id` | bigint | sim | 100% | `armazens.id` |  |
| `receiving_area` | `area_rececao` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `status` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {CONCLUIDO, FATURADA, ANULADA}; texto original em estado_original |
| `status` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `related_sale_id` | `venda_relacionada_id` | bigint | sim | 55% | `vendas.id` |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `is_posted` | `contabilizado` | boolean | sim | 91% |  |  |
| `project_id` | `projeto_id` | bigint | sim | 0% | `projetos.id` |  |
| `project_code` | `codigo_projeto` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |

### `delivery_items` → `itens_guia_saida` (model `ItemGuiaSaida`, `/api/logistica/guias-saida/itens`)

Linhas reais: **47** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `delivery_id` | `guia_id` | integer | sim | 100% |  |  |
| `product_id` | `produto_id` | bigint | sim | 100% | `produtos.id` |  |
| `quantity` | `quantidade` | numeric(12,3) | sim | 100% |  | tipos mistos: inteiro=45, decimal=2 |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `project_id` | `projeto_id` | bigint | sim | 0% | `projetos.id` |  |
| `project_code` | `codigo_projeto` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `fx_q1` | `cambial_q1` | numeric(12,3) | sim | 6% |  |  |
| `fx_v1` | `cambial_v1` | numeric(15,2) | sim | 6% |  | tipos mistos: decimal=1, inteiro=2 |
| `fx_q2` | `cambial_q2` | numeric(12,3) | sim | 6% |  |  |
| `fx_v2` | `cambial_v2` | numeric(15,2) | sim | 6% |  |  |
| `value_kz` | `valor_kz` | numeric(15,2) | sim | 6% |  | tipos mistos: decimal=1, inteiro=2 |
| `unit_cost_kz` | `custo_unitario_kz` | numeric(15,2) | sim | 6% |  | tipos mistos: decimal=1, inteiro=2 |

### `inventory_sessions` → `sessoes_inventario` (model `SessaoInventario`, `/api/logistica/inventario/sessoes`)

Linhas reais: **4** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `warehouse_id` | `armazem_id` | bigint | sim | 100% | `armazens.id` |  |
| `date` | `data` | date | sim | 100% |  |  |
| `description` | `descricao` | text | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {EM_CONTAGEM, REVISAO, CONCLUIDA, ANULADA}; texto original em estado_original |
| `status` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `type` | `tipo` | varchar(10) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `inventory_session_lines` → `linhas_sessao_inventario` (model `LinhaSessaoInventario`, `/api/logistica/inventario/linhas`)

Linhas reais: **6** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `session_id` | `sessao_inventario_id` | bigint | sim | 100% | `sessoes_inventario.id` |  |
| `product_id` | `produto_id` | bigint | sim | 100% | `produtos.id` |  |
| `system_quantity` | `quantidade_sistema` | numeric(12,3) | sim | 100% |  | tipos mistos: inteiro=4, decimal=2 |
| `counted_quantity` | `quantidade_contada` | numeric(12,3) | sim | 100% |  | tipos mistos: inteiro=4, decimal=2 |
| `difference` | `diferenca` | numeric(12,3) | sim | 100% |  | tipo forçado (inferido: numeric(15,2)) |
| `notes` | `observacoes` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `justification` | `justificacao` | text | sim | 33% |  |  |
| `custom_cost` | `custo_personalizado` | numeric(15,2) | sim | 33% |  |  |

## Módulo: RH

### `employees` → `colaboradores` (model `Colaborador`, `/api/rh/colaboradores`)

Linhas reais: **133** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `name` | `nome_completo` | varchar(100) | sim | 100% |  |  |
| `nif` | `nif` | varchar(30) | sim | 100% |  | tipos mistos: string=127, string_inteiro=6 |
| `inss` | `numero_inss` | varchar(20) | sim | 99% |  | tipos mistos: string_inteiro=127, string=5 |
| `role_id` | `cargo_funcao_id` | bigint | sim | 100% | `cargos_funcoes.id` ⚠ 98.5% (2 órfãos) |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `org_type_id` | `tipo_organizacao_id` | bigint | sim | 100% | `tipos_organizacao_rh.id` |  |
| `status` | `estado` | varchar(20) | sim | 89% |  | código normalizado ∈ {ACTIVO, INACTIVO, SUSPENSO}; texto original em estado_original |
| `status` | `estado_original` | varchar(20) | sim | 89% |  | texto exacto do legado |
| `work_days` | `dias_uteis_mes` | integer | sim | 89% |  |  |
| `is_retired` | `reformado` | boolean | sim | 62% |  |  |
| `business_unit_id` | `unidade_negocio_id` | bigint | sim | 5% | `unidades_negocio.id` |  |
| `cost_center_id` | `centro_custo_id` | bigint | sim | 4% | `centros_custo.id` |  |
| `is_avencado` | `avencado` | boolean | sim | 15% |  |  |
| `sexo` | `sexo` | varchar(10) | sim | 11% |  |  |
| `data_nascimento` | `data_nascimento` | date | sim | 12% |  |  |
| `estado_civil` | `estado_civil` | varchar(20) | sim | 12% |  | código normalizado ∈ {SOLTEIRO, CASADO, DIVORCIADO, VIUVO, UNIAO_FACTO}; texto original em estado_civil_original |
| `estado_civil` | `estado_civil_original` | varchar(20) | sim | 12% |  | texto exacto do legado |
| `nacionalidade` | `nacionalidade` | varchar(20) | sim | 14% |  |  |
| `naturalidade` | `naturalidade` | varchar(20) | sim | 2% |  |  |
| `provincia_naturalidade` | `provincia_naturalidade` | varchar(20) | sim | 2% |  |  |
| `documento_identificacao` | `documento_identificacao` | varchar(30) | sim | 11% |  |  |
| `documento_validade` | `documento_validade` | date | sim | 11% |  |  |
| `data_admissao` | `data_admissao` | date | sim | 11% |  |  |
| `endereco` | `endereco` | text | sim | 11% |  |  |
| `bairro` | `bairro` | varchar(20) | sim | 1% |  |  |
| `municipio` | `municipio` | varchar(20) | sim | 1% |  |  |
| `provincia` | `provincia` | varchar(10) | sim | 1% |  |  |
| `telefone` | `telefone` | varchar(50) | sim | 11% |  | tipos mistos: string=5, string_inteiro=10 |
| `telefone_alternativo` | `telefone_alternativo` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `email` | `email` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `emergencia_nome` | `emergencia_nome` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `emergencia_telefone` | `emergencia_telefone` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `emergencia_parentesco` | `emergencia_parentesco` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `habilitacao_maxima` | `habilitacao_maxima` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `org_unit_id` | `unidade_organica_id` | bigint | sim | 19% | `unidades_organicas.id` |  |
| `org_position_id` | `posto_trabalho_id` | bigint | sim | 14% | `postos_trabalho.id` |  |
| `manager_employee_id` | `colaborador_gestor_id` | bigint | sim | 11% | `colaboradores.id` |  |

### `roles` → `cargos_funcoes` (model `CargoFuncao`, `/api/rh/cargos`)

Linhas reais: **76** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `name` | `nome` | varchar(100) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `description` | `descricao` | text | sim | 4% |  |  |

### `org_types` → `tipos_organizacao_rh` (model `TipoOrganizacaoRH`, `/api/rh/tipos-organizacao`)

Linhas reais: **28** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` ⚠ 92.9% (2 órfãos) |  |
| `name` | `nome` | varchar(20) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `infotypes` → `infotipos_salariais` (model `InfotipoSalarial`, `/api/rh/infotipos`)

Linhas reais: **175** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `type` | `tipo` | varchar(20) | sim | 100% |  |  |
| `name` | `nome` | varchar(50) | sim | 100% |  |  |
| `inss` | `sujeito_inss` | boolean | sim | 100% |  |  |
| `irt` | `irt` | varchar(30) | sim | 100% |  | tipos mistos: boolean=149, string=26 |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |

### `contracts` → `contratos_trabalho` (model `ContratoTrabalho`, `/api/rh/contratos`)

Linhas reais: **89** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `employee_id` | `colaborador_id` | bigint | sim | 100% | `colaboradores.id` |  |
| `remunerations` | `remuneracoes` | jsonb | sim | 100% |  |  |
| `contract_days_month` | `dias_contrato_mes` | integer | sim | 100% |  |  |
| `start_date` | `data_inicio` | date | sim | 100% |  |  |
| `end_date` | `data_fim` | date | sim | 100% |  |  |
| `status` | `estado` | varchar(10) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `hours_per_day` | `horas_por_dia` | numeric(12,3) | sim | 100% |  |  |
| `currency` | `codigo_moeda` | varchar(10) | sim | 7% |  |  |
| `produtividade` | `produtividade` | jsonb | sim | 7% |  |  |

### `payroll_periods` → `periodos_processamento_salarial` (model `PeriodoProcessamentoSalarial`, `/api/rh/periodos`)

Linhas reais: **47** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `month_year` | `mes_ano` | varchar(20) | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {ABERTO, FECHADO, VALIDADO}; texto original em estado_original |
| `status` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `is_posted` | `contabilizado` | boolean | sim | 96% |  |  |

### `payroll_entries` → `linhas_folha_salarial` (model `LinhaFolhaSalarial`, `/api/rh/folha-linhas`)

Linhas reais: **1114** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `period_id` | `periodo_processamento_salarial_id` | bigint | sim | 100% | `periodos_processamento_salarial.id` |  |
| `employee_id` | `colaborador_id` | bigint | sim | 100% | `colaboradores.id` |  |
| `infotype_id` | `infotipo_salarial_id` | bigint | sim | 100% | `infotipos_salariais.id` |  |
| `value` | `valor` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=956, decimal=158 |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `company_id` | `empresa_id` | bigint | sim | 100% | `empresas.id` |  |
| `worked_days` | `dias_trabalhados` | numeric(12,3) | sim | 48% |  | tipos mistos: inteiro=533, decimal=3; tipos Dexie: {"undef":80} |
| `hours` | `horas` | numeric(12,3) | sim | 1% |  | tipos mistos: decimal=11, inteiro=1 |
| `origem_efectividade` | `origem_efetividade` | boolean | sim | 1% |  |  |
| `origem_produtividade` | `origem_produtividade` | boolean | sim | 0% |  |  |
| `prod_period_id` | `periodo_produtividade_id` | bigint | sim | 0% | `periodos_produtividade_rh.id` |  |

### `accounting_mapos` → `mapeamentos_contabeis_rh` (model `MapeamentoContabilRH`, `/api/rh/mapas-contabeis`)

Linhas reais: **225** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `infotype_id` | `infotipo_salarial_id` | bigint | sim | 100% | `infotipos_salariais.id` |  |
| `org_type_id` | `tipo_organizacao_id` | bigint | sim | 100% | `tipos_organizacao_rh.id` ⚠ 80.4% (44 órfãos) | -1 no legado = coluna 'Avençado' -> NULL + avencado=true |
| `org_type_id` | `avencado` | boolean | não | 100% |  | derivada: org_type_id = -1 |
| `account_number` | `numero_conta` | varchar(10) | sim | 43% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `system_accounting_mapos` → `mapeamentos_contabeis_sistema_rh` (model `MapeamentoContabilSistemaRH`, `/api/rh/mapas-sistema`)

Linhas reais: **112** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `code` | `codigo` | varchar(30) | sim | 100% |  |  |
| `org_type_id` | `tipo_organizacao_id` | bigint | sim | 100% | `tipos_organizacao_rh.id` ⚠ 90.9% (10 órfãos) | tipos Dexie: {"nan":6}; -1 no legado = coluna 'Avençado' -> NULL + avencado=true |
| `org_type_id` | `avencado` | boolean | não | 100% |  | derivada: org_type_id = -1 |
| `account_number` | `numero_conta` | varchar(10) | sim | 96% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `banks` → `bancos` (model `Banco`, `/api/rh/bancos`)

Linhas reais: **13** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `name` | `nome` | varchar(50) | sim | 100% |  |  |
| `code` | `codigo` | varchar(10) | sim | 69% |  | tipos mistos: string_inteiro=2, string=7 |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `nif` | `nif` | varchar(10) | sim | 8% |  |  |
| `address` | `endereco` | text | sim | 31% |  |  |
| `account_code` | `codigo_conta` | varchar(20) | sim | 15% |  |  |

### `employee_bank_details` → `coordenadas_bancarias_colaboradores` (model `CoordenadaBancariaColaborador`, `/api/rh/colaboradores/coordenadas-bancarias`)

Linhas reais: **44** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `employee_id` | `colaborador_id` | bigint | sim | 100% | `colaboradores.id` |  |
| `bank_id` | `banco_id` | bigint | sim | 100% | `bancos.id` |  |
| `iban` | `iban` | varchar(50) | sim | 100% |  | tipos mistos: string_inteiro=8, string=36 |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `payment_letters` → `cartas_pagamento_bancario` (model `CartaPagamentoBancario`, `/api/rh/cartas-pagamento`)

Linhas reais: **0** · fictícias descartadas: 16 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `payment_letter_items` → `itens_carta_pagamento` (model `ItemCartaPagamento`, `/api/rh/cartas-pagamento/itens`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `rh_dependents` → `dependentes_colaboradores` (model `DependenteColaborador`, `/api/rh/colaboradores/dependentes`)

Linhas reais: **1** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `rh_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `employee_id` | `colaborador_id` | bigint | sim | 100% | `colaboradores.id` |  |
| `ordem` | `ordem` | integer | sim | 100% |  |  |
| `nome` | `nome` | varchar(10) | sim | 100% |  |  |
| `parentesco` | `parentesco` | varchar(20) | sim | 100% |  | código normalizado ∈ {FILHO, CONJUGE, PAI, MAE, OUTRO}; texto original em parentesco_original |
| `parentesco` | `parentesco_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `data_nascimento` | `data_nascimento` | date | sim | 100% |  |  |
| `sexo` | `sexo` | varchar(10) | sim | 100% |  |  |
| `dependente_fiscal` | `dependente_fiscal` | boolean | sim | 100% |  |  |
| `updated_at` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `origem` | `origem` | varchar(30) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `rh_education` → `habilitacoes_colaboradores` (model `HabilitacaoColaborador`, `/api/rh/colaboradores/habilitacoes`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `rh_vacations` → `plano_ferias_colaboradores` (model `PlanoFeriasColaborador`, `/api/rh/ferias`)

Linhas reais: **4** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `rh_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `employee_id` | `colaborador_id` | bigint | sim | 100% | `colaboradores.id` |  |
| `ano` | `ano` | integer | sim | 100% |  |  |
| `data_inicio` | `data_inicio` | date | sim | 100% |  |  |
| `data_fim` | `data_fim` | date | sim | 100% |  |  |
| `dias` | `dias` | integer | sim | 100% |  |  |
| `direito` | `direito` | integer | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {PEDIDO, PLANEADO, APROVADO, GOZADO, CANCELADO}; texto original em estado_original |
| `status` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `observacoes` | `observacoes` | text | sim | 100% |  |  |
| `portal_request_id` | `pedido_portal_colaborador_id` | bigint | sim | 100% | `pedidos_portal_colaborador.id` |  |
| `created_at` | `criado_em` | timestamptz | sim | 100% |  |  |
| `updated_at` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `rh_absences` → `ausencias_faltas_colaboradores` (model `AusenciaFaltaColaborador`, `/api/rh/ausencias`)

Linhas reais: **34** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `rh_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `employee_id` | `colaborador_id` | bigint | sim | 100% | `colaboradores.id` |  |
| `tipo` | `tipo` | varchar(40) | sim | 0% |  | sem valores reais: tipo a confirmar no código legado; tipo forçado (inferido: text) |
| `data_inicio` | `data_inicio` | date | sim | 100% |  |  |
| `data_fim` | `data_fim` | date | sim | 100% |  |  |
| `dias_uteis` | `dias_uteis` | integer | sim | 100% |  |  |
| `horas_falta` | `horas_falta` | numeric(12,3) | sim | 100% |  |  |
| `ocorrencia` | `ocorrencia` | varchar(50) | sim | 100% |  |  |
| `estado` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {POR_JUSTIFICAR, PENDENTE_CHEFIA, PENDENTE_RH, APROVADO, RECUSADO, CANCELADO}; texto original em estado_original |
| `estado` | `estado_original` | varchar(30) | sim | 100% |  | texto exacto do legado |
| `detectada` | `detectada` | boolean | sim | 100% |  |  |
| `fecho_id` | `fecho_mensal_assiduidade_id` | bigint | sim | 18% | `fechos_mensais_assiduidade.id` |  |
| `mes` | `mes` | varchar(20) | sim | 100% |  |  |
| `criado_em` | `criado_em` | timestamptz | sim | 100% |  |  |
| `criado_por` | `criado_por` | varchar(10) | sim | 100% |  |  |
| `dias` | `dias` | integer | sim | 100% |  |  |
| `horas` | `horas` | numeric(12,3) | sim | 0% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `pendente_no_fecho` | `pendente_no_fecho` | boolean | sim | 18% |  |  |

### `rh_attendance` → `efectividade_assiduidade` (model `EfectividadeAssiduidade`, `/api/rh/assiduidade`)

Linhas reais: **190** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `data` | `data` | date | sim | 100% |  |  |
| `employee_id` | `colaborador_id` | bigint | sim | 100% | `colaboradores.id` |  |
| `entrada` | `entrada` | varchar(10) | sim | 1% |  |  |
| `saida` | `saida` | varchar(10) | sim | 1% |  |  |
| `horas` | `horas` | numeric(12,3) | sim | 100% |  | tipos mistos: inteiro=24, decimal=166 |
| `rh_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `origem` | `origem` | varchar(20) | sim | 100% |  | código normalizado ∈ {MANUAL, FICHEIRO, RELOGIO}; texto original em origem_original |
| `origem` | `origem_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `fonte` | `fonte` | varchar(50) | sim | 98% |  |  |
| `observacoes` | `observacoes` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `actualizado_em` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `actualizado_por` | `atualizado_por` | varchar(10) | sim | 100% |  |  |
| `criado_em` | `criado_em` | timestamptz | sim | 100% |  |  |
| `criado_por` | `criado_por` | varchar(10) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `rh_attendance_config` → `configuracoes_assiduidade` (model `ConfigAssiduidade`, `/api/rh/assiduidade/config`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `rh_attendance_closures` → `fechos_mensais_assiduidade` (model `FechoMensalAssiduidade`, `/api/rh/assiduidade/fechos`)

Linhas reais: **2** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `rh_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `mes` | `mes` | varchar(20) | sim | 100% |  |  |
| `estado` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {FECHADO, REABERTO}; texto original em estado_original |
| `estado` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `dias_uteis` | `dias_uteis` | integer | sim | 100% |  |  |
| `linhas` | `linhas` | jsonb | sim | 100% |  |  |
| `totais` | `totais` | jsonb | sim | 100% |  |  |
| `fechado_em` | `fechado_em` | timestamptz | sim | 100% |  |  |
| `fechado_por` | `fechado_por` | varchar(10) | sim | 100% |  |  |
| `lancado_em` | `lancado_em` | timestamptz | sim | 50% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `lancado_por` | `lancado_por` | varchar(10) | sim | 50% |  |  |
| `period_id` | `periodo_processamento_salarial_id` | bigint | sim | 50% | `periodos_processamento_salarial.id` |  |
| `apurado_ate` | `apurado_ate` | date | sim | 50% |  |  |
| `ausencias_geradas` | `ausencias_geradas` | integer | sim | 50% |  |  |

### `rh_prod_items` → `itens_produtividade_rh` (model `ItemProdutividadeRH`, `/api/rh/produtividade/itens`)

Linhas reais: **1** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `rh_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `codigo` | `codigo` | varchar(10) | sim | 100% |  |  |
| `descricao` | `descricao` | text | sim | 100% |  |  |
| `metrica` | `metrica` | varchar(20) | sim | 100% |  | código normalizado ∈ {QUANTIDADE, HORAS, OBJECTIVO, PONTOS, TAREFAS}; texto original em metrica_original |
| `metrica` | `metrica_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `unidade` | `unidade` | varchar(10) | sim | 100% |  |  |
| `preco_unitario` | `preco_unitario` | numeric(15,4) | sim | 100% |  | tipo forçado (inferido: numeric(15,2)) |
| `infotype_id` | `infotipo_salarial_id` | bigint | sim | 100% | `infotipos_salariais.id` |  |
| `minimo` | `minimo` | numeric(15,3) | sim | 0% |  | sem valores reais: tipo a confirmar no código legado; tipo forçado (inferido: text) |
| `maximo` | `maximo` | numeric(15,3) | sim | 0% |  | sem valores reais: tipo a confirmar no código legado; tipo forçado (inferido: text) |
| `activo` | `ativo` | boolean | sim | 100% |  |  |
| `actualizado_em` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `actualizado_por` | `atualizado_por` | varchar(10) | sim | 100% |  |  |
| `criado_em` | `criado_em` | timestamptz | sim | 100% |  |  |
| `criado_por` | `criado_por` | varchar(10) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `rh_prod_periods` → `periodos_produtividade_rh` (model `PeriodoProdutividadeRH`, `/api/rh/produtividade/periodos`)

Linhas reais: **1** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `rh_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `mes` | `mes` | varchar(20) | sim | 100% |  |  |
| `data_inicio` | `data_inicio` | date | sim | 100% |  |  |
| `data_fim` | `data_fim` | date | sim | 100% |  |  |
| `observacoes` | `observacoes` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `actualizado_em` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `actualizado_por` | `atualizado_por` | varchar(10) | sim | 100% |  |  |
| `estado` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {ABERTO, FECHADO}; texto original em estado_original |
| `estado` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `criado_em` | `criado_em` | timestamptz | sim | 100% |  |  |
| `criado_por` | `criado_por` | varchar(10) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `fechado_em` | `fechado_em` | timestamptz | sim | 100% |  |  |
| `fechado_por` | `fechado_por` | varchar(10) | sim | 100% |  |  |
| `total_fecho` | `total_fecho` | numeric(15,2) | sim | 100% |  |  |
| `registos_fecho` | `registos_fecho` | integer | sim | 100% |  |  |
| `lancado_em` | `lancado_em` | timestamptz | sim | 100% |  |  |
| `lancado_por` | `lancado_por` | varchar(10) | sim | 100% |  |  |
| `period_id` | `periodo_processamento_salarial_id` | bigint | sim | 100% | `periodos_processamento_salarial.id` |  |
| `reaberto_em` | `reaberto_em` | timestamptz | sim | 100% |  |  |
| `reaberto_por` | `reaberto_por` | varchar(10) | sim | 100% |  |  |
| `motivo_reabertura` | `motivo_reabertura` | text | sim | 100% |  |  |

### `rh_productivity` → `registos_produtividade_rh` (model `RegistoProdutividadeRH`, `/api/rh/produtividade/registos`)

Linhas reais: **6** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `rh_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `prod_period_id` | `periodo_produtividade_id` | bigint | sim | 100% | `periodos_produtividade_rh.id` |  |
| `mes` | `mes` | varchar(20) | sim | 100% |  |  |
| `employee_id` | `colaborador_id` | bigint | sim | 100% | `colaboradores.id` |  |
| `item_id` | `item_produtividade_id` | bigint | sim | 100% | `itens_produtividade_rh.id` |  |
| `data` | `data` | date | sim | 0% |  | sem valores reais: tipo a confirmar no código legado; tipo forçado (inferido: text) |
| `quantidade` | `quantidade` | numeric(12,3) | sim | 100% |  |  |
| `preco_unitario` | `preco_unitario` | numeric(15,4) | sim | 100% |  | tipo forçado (inferido: numeric(15,2)) |
| `valor` | `valor` | numeric(15,2) | sim | 100% |  |  |
| `quantidade_considerada` | `quantidade_considerada` | numeric(12,3) | sim | 100% |  |  |
| `observacoes` | `observacoes` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `origem` | `origem` | varchar(10) | sim | 100% |  |  |
| `criado_em` | `criado_em` | timestamptz | sim | 100% |  |  |
| `criado_por` | `criado_por` | varchar(10) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `rh_evaluations` → `avaliacoes_desempenho_rh` (model `AvaliacaoDesempenhoRH`, `/api/rh/avaliacoes`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `rh_evaluation_items` → `criterios_avaliacao_rh` (model `CriterioAvaliacaoRH`, `/api/rh/avaliacoes/criterios`)

Linhas reais: **26** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `rh_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `ambito` | `ambito` | varchar(20) | sim | 100% |  | código normalizado ∈ {COMUM, ESPECIFICO}; texto original em ambito_original |
| `ambito` | `ambito_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `employee_id` | `colaborador_id` | bigint | sim | 8% | `colaboradores.id` |  |
| `tipo` | `tipo` | varchar(20) | sim | 100% |  | código normalizado ∈ {CRITERIO, OBJECTIVO}; texto original em tipo_original |
| `tipo` | `tipo_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `chave` | `chave` | varchar(30) | sim | 92% |  |  |
| `nome` | `nome` | varchar(50) | sim | 100% |  |  |
| `descricao` | `descricao` | text | sim | 8% |  |  |
| `peso` | `peso` | numeric(9,4) | sim | 100% |  |  |
| `ordem` | `ordem` | integer | sim | 100% |  |  |
| `activo` | `ativo` | boolean | sim | 100% |  |  |
| `created_at` | `criado_em` | timestamptz | sim | 100% |  |  |
| `updated_at` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `natureza` | `natureza` | varchar(20) | sim | 4% |  |  |
| `meta` | `meta` | numeric(15,3) | sim | 4% |  | tipo forçado (inferido: integer) |
| `unidade` | `unidade` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `sentido` | `sentido` | varchar(10) | sim | 8% |  |  |

### `rh_eval_cycles` → `ciclos_avaliacao_360` (model `CicloAvaliacao360`, `/api/rh/avaliacoes-360/ciclos`)

Linhas reais: **2** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `nome` | `nome` | varchar(100) | sim | 100% |  |  |
| `ano` | `ano` | integer | sim | 100% |  |  |
| `periodo` | `periodo` | varchar(10) | sim | 100% |  |  |
| `data_inicio` | `data_inicio` | date | sim | 100% |  |  |
| `data_fim` | `data_fim` | date | sim | 100% |  |  |
| `estado` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {RASCUNHO, ABERTO, FECHADO}; texto original em estado_original |
| `estado` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `prazos` | `prazos` | jsonb | sim | 100% |  |  |
| `pesos` | `pesos` | jsonb | sim | 100% |  |  |
| `minimo_anonimato` | `minimo_anonimato` | integer | sim | 100% |  |  |
| `max_pares` | `max_pares` | integer | sim | 100% |  |  |
| `feedback` | `feedback` | jsonb | sim | 100% |  |  |
| `bonificacao` | `bonificacao` | jsonb | sim | 100% |  |  |
| `comunicado` | `comunicado` | jsonb | sim | 100% |  |  |
| `rh_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `actualizado_por` | `atualizado_por` | varchar(10) | sim | 100% |  |  |
| `actualizado_em` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `criado_por` | `criado_por` | varchar(10) | sim | 100% |  |  |
| `criado_em` | `criado_em` | timestamptz | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `participantes` | `participantes` | jsonb | sim | 50% |  |  |
| `criterios` | `criterios` | jsonb | sim | 50% |  |  |
| `aberto_em` | `aberto_em` | timestamptz | sim | 50% |  |  |
| `aberto_por` | `aberto_por` | varchar(10) | sim | 50% |  |  |

### `rh_eval_360_part` → `participantes_avaliacao_360` (model `ParticipanteAvaliacao360`, `/api/rh/avaliacoes-360/participantes`)

Linhas reais: **1** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `uid` | `uid` | varchar(100) | sim | 100% |  |  |
| `rh_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `ciclo_id` | `ciclo_avaliacao_id` | bigint | sim | 100% | `ciclos_avaliacao_360.id` |  |
| `avaliador_employee_id` | `colaborador_avaliador_id` | bigint | sim | 100% | `colaboradores.id` |  |
| `avaliado_employee_id` | `colaborador_avaliado_id` | bigint | sim | 100% | `colaboradores.id` |  |
| `grupo` | `grupo` | varchar(20) | sim | 100% |  |  |

### `rh_eval_360_resp` → `respostas_avaliacao_360` (model `RespostaAvaliacao360`, `/api/rh/avaliacoes-360/respostas`)

Linhas reais: **1** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `uid` | `uid` | varchar(100) | sim | 100% |  |  |
| `rh_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `ciclo_id` | `ciclo_avaliacao_id` | bigint | sim | 100% | `ciclos_avaliacao_360.id` |  |
| `avaliado_employee_id` | `colaborador_avaliado_id` | bigint | sim | 100% | `colaboradores.id` |  |
| `grupo` | `grupo` | varchar(20) | sim | 100% |  |  |
| `notas` | `notas` | jsonb | sim | 100% |  |  |
| `comentario` | `comentario` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |

### `rh_eval_feedback` → `feedbacks_avaliacao_360` (model `FeedbackAvaliacao360`, `/api/rh/avaliacoes-360/feedbacks`)

Linhas reais: **1** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `rh_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `ciclo_id` | `ciclo_avaliacao_id` | bigint | sim | 100% | `ciclos_avaliacao_360.id` |  |
| `employee_id` | `colaborador_id` | bigint | sim | 100% | `colaboradores.id` |  |
| `chefia_employee_id` | `colaborador_chefia_id` | bigint | sim | 100% | `colaboradores.id` |  |
| `periodo_ref` | `periodo_referencia` | varchar(20) | sim | 100% |  |  |
| `data` | `data` | date | sim | 100% |  |  |
| `objectivos` | `objetivos` | jsonb | sim | 100% |  |  |
| `positivos` | `positivos` | text | sim | 100% |  |  |
| `melhorar` | `melhorar` | text | sim | 100% |  |  |
| `acordos` | `acordos` | text | sim | 100% |  | tipo forçado (inferido: varchar(50)) |
| `registado_por` | `registado_por` | varchar(10) | sim | 100% |  |  |
| `registado_em` | `registado_em` | timestamptz | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `rh_eval_bonus` → `bonificacoes_avaliacao_rh` (model `BonificacaoAvaliacaoRH`, `/api/rh/avaliacoes/bonificacoes`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `rh_eval_ack` → `confirmacoes_avaliacao_rh` (model `ConfirmacaoAvaliacaoRH`, `/api/rh/avaliacoes/confirmacoes`)

Linhas reais: **1** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `rh_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `ciclo_id` | `ciclo_avaliacao_id` | bigint | sim | 100% | `ciclos_avaliacao_360.id` |  |
| `employee_id` | `colaborador_id` | bigint | sim | 100% | `colaboradores.id` |  |
| `em` | `em` | timestamptz | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `rh_portal_requests` → `pedidos_portal_colaborador` (model `PedidoPortalColaborador`, `/api/rh/portal/pedidos`)

Linhas reais: **9** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `rh_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `employee_id` | `colaborador_id` | bigint | sim | 100% | `colaboradores.id` |  |
| `tipo` | `tipo` | varchar(20) | sim | 100% |  | código normalizado ∈ {FERIAS, AUSENCIA, DOCUMENTO, AGREGADO}; texto original em tipo_original |
| `tipo` | `tipo_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `dados` | `dados` | jsonb | sim | 100% |  |  |
| `etapas` | `etapas` | jsonb | sim | 100% |  |  |
| `estado` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {PENDENTE_CHEFIA, PENDENTE_RH, APROVADO, EMITIDO, RECUSADO, CANCELADO}; texto original em estado_original |
| `estado` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `criado_por` | `criado_por` | varchar(10) | sim | 100% |  |  |
| `criado_em` | `criado_em` | timestamptz | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `vacation_id` | `plano_ferias_colaborador_id` | bigint | sim | 44% | `plano_ferias_colaboradores.id` |  |
| `decidido_em` | `decidido_em` | timestamptz | sim | 56% |  |  |
| `documento` | `documento` | jsonb | sim | 22% |  |  |

### `rh_self_evaluations` → `autoavaliacoes_colaborador` (model `AutoavaliacaoColaborador`, `/api/rh/portal/autoavaliacoes`)

Linhas reais: **1** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `rh_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `employee_id` | `colaborador_id` | bigint | sim | 100% | `colaboradores.id` |  |
| `ano` | `ano` | integer | sim | 100% |  |  |
| `periodo` | `periodo` | varchar(10) | sim | 100% |  |  |
| `criterios` | `criterios` | jsonb | sim | 100% |  |  |
| `objectivos` | `objetivos` | jsonb | sim | 100% |  |  |
| `realizacoes` | `realizacoes` | text | sim | 100% |  |  |
| `dificuldades` | `dificuldades` | text | sim | 100% |  |  |
| `formacao` | `formacao` | varchar(10) | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {RASCUNHO, SUBMETIDA}; texto original em estado_original |
| `status` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `submetida_em` | `submetida_em` | timestamptz | sim | 100% |  |  |
| `actualizado_em` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `criado_em` | `criado_em` | timestamptz | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `rh_upward_participation` → `participacoes_ascendentes_rh` (model `ParticipacaoAscendenteRH`, `/api/rh/avaliacoes/ascendentes`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `rh_upward_responses` → `respostas_ascendentes_rh` (model `RespostaAscendenteRH`, `/api/rh/avaliacoes/ascendentes-respostas`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `rh_doc_templates` → `modelos_documentos_rh` (model `ModeloDocumentoRH`, `/api/rh/modelos-documentos`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

## Módulo: Compras

### `pa_settings` → `configuracoes_deliberacao_compras` (model `ConfigDeliberacaoCompra`, `/api/compras/deliberacao/escaloes`)

Linhas reais: **2** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `pa_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `niveis` | `niveis` | jsonb | sim | 100% |  |  |
| `actualizado_por` | `atualizado_por` | varchar(10) | sim | 100% |  |  |
| `actualizado_em` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

## Módulo: RH

### `org_units` → `unidades_organicas` (model `UnidadeOrganica`, `/api/rh/estrutura/unidades`)

Linhas reais: **16** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `org_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `codigo` | `codigo` | varchar(10) | sim | 81% |  |  |
| `nome` | `nome` | varchar(100) | sim | 100% |  |  |
| `tipo` | `tipo` | varchar(20) | sim | 100% |  | código normalizado ∈ {ORGAO_SOCIAL, DIRECCAO_GERAL, DIRECCAO, DEPARTAMENTO, GABINETE, SECCAO, EQUIPA, OUTRO}; texto original em tipo_original |
| `tipo` | `tipo_original` | varchar(30) | sim | 100% |  | texto exacto do legado |
| `pai_id` | `unidade_organica_pai_id` | bigint | sim | 88% | `unidades_organicas.id` |  |
| `responsavel_employee_id` | `colaborador_responsavel_id` | bigint | sim | 44% | `colaboradores.id` |  |
| `utilizador_responsavel` | `utilizador_responsavel` | varchar(100) | sim | 13% |  | tipo forçado (inferido: varchar(10)) |
| `utilizadores` | `utilizadores` | jsonb | sim | 100% |  |  |
| `missao` | `missao` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `atribuicoes` | `atribuicoes` | text | sim | 25% |  |  |
| `cost_center_id` | `centro_custo_id` | bigint | sim | 13% | `centros_custo.id` |  |
| `business_unit_id` | `unidade_negocio_id` | bigint | sim | 13% | `unidades_negocio.id` |  |
| `ordem` | `ordem` | integer | sim | 100% |  |  |
| `activo` | `ativo` | boolean | sim | 100% |  |  |
| `apoio` | `apoio` | integer | sim | 100% |  |  |
| `cor` | `cor` | varchar(20) | sim | 44% |  |  |
| `actualizado_por` | `atualizado_por` | varchar(10) | sim | 100% |  |  |
| `actualizado_em` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `criado_por` | `criado_por` | varchar(10) | sim | 100% |  |  |
| `criado_em` | `criado_em` | timestamptz | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `org_positions` → `postos_trabalho` (model `PostoTrabalho`, `/api/rh/estrutura/postos`)

Linhas reais: **9** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `org_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `unit_id` | `unidade_organica_id` | bigint | sim | 100% | `unidades_organicas.id` |  |
| `role_id` | `cargo_funcao_id` | bigint | sim | 56% | `cargos_funcoes.id` |  |
| `titulo` | `titulo` | varchar(30) | sim | 78% |  |  |
| `vagas` | `vagas` | integer | sim | 100% |  |  |
| `reporta_a_position_id` | `posto_superior_id` | bigint | sim | 56% | `postos_trabalho.id` |  |
| `responsabilidades` | `responsabilidades` | text | sim | 11% |  |  |
| `chefia` | `chefia` | integer | sim | 100% |  |  |
| `ordem` | `ordem` | integer | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

## Módulo: Tesouraria

### `treasury_documents` → `documentos_tesouraria` (model `DocumentoTesouraria`, `/api/tesouraria/documentos`)

Linhas reais: **5781** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `type` | `tipo` | varchar(20) | sim | 100% |  | código normalizado ∈ {PAGAMENTO, RECEBIMENTO}; texto original em tipo_original |
| `type` | `tipo_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `doc_date` | `data_documento` | date | sim | 100% |  |  |
| `account_fin` | `conta_financeira` | varchar(20) | sim | 100% |  | tipos mistos: string_inteiro=5777, string=4 |
| `description` | `descricao` | text | sim | 100% |  |  |
| `total_value` | `valor_total` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=4901, decimal=880 |
| `status` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {PENDENTE, INTEGRADO, ANULADO}; texto original em estado_original |
| `status` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `reference` | `referencia` | varchar(100) | sim | 100% |  | tipos mistos: string=4037, string_inteiro=1742 |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `project_id` | `projeto_id` | bigint | sim | 0% | `projetos.id` |  |
| `project_code` | `codigo_projeto` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `is_imported` | `importado` | boolean | sim | 60% |  |  |
| `doc_url` | `url_documento` | varchar(150) | sim | 42% |  |  |
| `currency` | `codigo_moeda` | varchar(10) | sim | 0% |  |  |
| `exchange_rate` | `taxa_cambio` | numeric(18,6) | sim | 0% |  |  |
| `exchange_rate_id` | `taxa_cambio_id` | bigint | sim | 0% | `taxas_cambio.id` |  |
| `exchange_rate_manual` | `taxa_cambio_manual` | boolean | sim | 1% |  |  |
| `total_value_currency` | `valor_total_moeda` | numeric(15,2) | sim | 0% |  |  |
| `payroll_period_id` | `periodo_processamento_salarial_id` | bigint | sim | 0% | `periodos_processamento_salarial.id` |  |

### `treasury_items` → `itens_documento_tesouraria` (model `ItemDocumentoTesouraria`, `/api/tesouraria/itens`)

Linhas reais: **6252** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `account_code` | `codigo_conta` | varchar(20) | sim | 100% |  | tipos mistos: string_inteiro=6249, string=3 |
| `third_party_id` | `terceiro_id` | bigint | sim | 28% | `terceiros.id` |  |
| `doc_number` | `numero_documento` | varchar(100) | sim | 99% |  | tipos mistos: string=3076, string_inteiro=3136 |
| `demo_note_id` | `nota_demonstracao_id` | bigint | sim | 76% | `notas_demonstracao_resultados.id` |  |
| `cashflow_note_id` | `nota_fluxo_caixa_id` | bigint | sim | 93% | `notas_fluxo_caixa.id` |  |
| `description` | `descricao` | text | sim | 100% |  |  |
| `value` | `valor` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=5250, decimal=1002 |
| `type_dc` | `tipo_dc` | varchar(10) | sim | 100% |  |  |
| `doc_id` | `documento_tesouraria_id` | bigint | sim | 100% | `documentos_tesouraria.id` ⚠ 99.8% (15 órfãos) |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `project_id` | `projeto_id` | bigint | sim | 0% | `projetos.id` |  |
| `project_code` | `codigo_projeto` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `orig_doc_date` | `data_documento_original` | varchar(50) | sim | 97% |  | tipos mistos: string_data=6042, string=45, string_datahora=1; tipo forçado (inferido: timestamptz) |
| `nif_imported` | `nif_importado` | varchar(30) | sim | 20% |  | tipos mistos: string_inteiro=995, string=245 |
| `business_unit_id` | `unidade_negocio_id` | bigint | sim | 1% | `unidades_negocio.id` |  |
| `cost_center_id` | `centro_custo_id` | bigint | sim | 0% | `centros_custo.id` |  |
| `currency` | `codigo_moeda` | varchar(10) | sim | 0% |  |  |
| `value_currency` | `valor_moeda` | numeric(15,2) | sim | 0% |  |  |
| `exchange_rate` | `taxa_cambio` | numeric(18,6) | sim | 0% |  |  |
| `exchange_rate_id` | `taxa_cambio_id` | bigint | sim | 0% | `taxas_cambio.id` |  |
| `exchange_rate_manual` | `taxa_cambio_manual` | boolean | sim | 1% |  |  |
| `fx_doc_currency` | `cambial_moeda_documento` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `fx_bal_cur` | `cambial_saldo_moeda` | numeric(15,2) | sim | 0% |  |  |
| `fx_bal_kz` | `cambial_saldo_kz` | numeric(15,2) | sim | 0% |  |  |
| `value_input` | `valor_introduzido` | numeric(15,2) | sim | 0% |  |  |

### `bank_statement_lines` → `linhas_extrato_bancario` (model `LinhaExtratoBancario`, `/api/tesouraria/extratos`)

Linhas reais: **2821** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `account_code` | `codigo_conta` | varchar(20) | sim | 100% |  |  |
| `date` | `data` | date | sim | 100% |  |  |
| `reference` | `referencia` | varchar(50) | sim | 100% |  | tipos mistos: string=614, string_inteiro=2207 |
| `description` | `descricao` | text | sim | 98% |  |  |
| `value` | `valor` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=2391, decimal=430 |
| `type_dc` | `tipo_dc` | varchar(10) | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {PENDENTE, CONCILIADO, ANULADO}; texto original em estado_original |
| `status` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `reconciliation_id` | `reconciliacao_codigo` | varchar(30) | sim | 79% |  |  |
| `batch_id` | `lote_codigo` | varchar(30) | sim | 94% |  |  |

### `reconciliations` → `reconciliacoes_bancarias` (model `ReconciliacaoBancaria`, `/api/tesouraria/reconciliacoes`)

Linhas reais: **2469** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `reconciliation_id` | `reconciliacao_codigo` | varchar(50) | sim | 100% |  |  |
| `date` | `data` | timestamptz | sim | 100% |  | tipos mistos: string_data=1104, string_datahora=1365 |
| `total_value` | `valor_total` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=1829, decimal=640 |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `status` | `estado` | varchar(30) | sim | 1% |  |  |
| `import_id` | `importacao_codigo` | varchar(30) | sim | 1% |  |  |
| `type` | `tipo` | varchar(21) | sim | 0% |  | código normalizado ∈ {ATUALIZACAO_LOTE}; texto original em tipo_original |
| `type` | `tipo_original` | varchar(20) | sim | 0% |  | texto exacto do legado |
| `details` | `detalhes` | text | sim | 0% |  |  |

### `reconciliation_matches` → `correspondencias_reconciliacao` (model `CorrespondenciaReconciliacao`, `/api/tesouraria/reconciliacoes/correspondencias`)

Linhas reais: **1788** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `reconciliation_id` | `reconciliacao_codigo` | varchar(30) | sim | 100% |  |  |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `internal_id` | `lancamento_contabil_id` | bigint | sim | 91% | `lancamentos_contabeis.id` ⚠ 80.2% (323 órfãos) |  |
| `external_id` | `linha_extrato_bancario_id` | bigint | sim | 98% | `linhas_extrato_bancario.id` ⚠ 96.7% (58 órfãos) |  |
| `match_type` | `tipo_correspondencia` | varchar(20) | sim | 100% |  | código normalizado ∈ {AUTOMATICA, MANUAL}; texto original em tipo_correspondencia_original |
| `match_type` | `tipo_correspondencia_original` | varchar(10) | sim | 100% |  | texto exacto do legado |
| `value` | `valor` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=1512, decimal=276 |
| `date` | `data` | timestamptz | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `payment_methods` → `meios_pagamento` (model `MeioPagamento`, `/api/tesouraria/meios-pagamento`)

Linhas reais: **3** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `name` | `nome` | varchar(50) | sim | 100% |  |  |
| `account_code` | `codigo_conta` | varchar(20) | sim | 100% |  |  |
| `iban` | `iban` | varchar(50) | sim | 33% |  |  |
| `swift` | `swift` | varchar(20) | sim | 33% |  |  |
| `is_active` | `ativo` | boolean | sim | 100% |  |  |
| `is_default` | `predefinido` | boolean | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `currency` | `codigo_moeda` | varchar(10) | sim | 67% |  |  |

### `cash_audits` → `conferencias_caixa` (model `ConferenciaCaixa`, `/api/tesouraria/conferencias-caixa`)

Linhas reais: **1** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `account_code` | `codigo_conta` | varchar(10) | sim | 100% |  |  |
| `account_name` | `nome_conta` | varchar(30) | sim | 100% |  |  |
| `audit_date` | `data_conferencia` | timestamptz | sim | 100% |  |  |
| `operator_name` | `nome_operador` | varchar(20) | sim | 100% |  |  |
| `denominations` | `denominacoes` | text | sim | 100% |  |  |
| `total_physical` | `total_fisico` | numeric(15,2) | sim | 100% |  |  |
| `total_system` | `total_sistema` | numeric(15,2) | sim | 100% |  |  |
| `external_balance` | `saldo_externo` | numeric(15,2) | sim | 100% |  |  |
| `difference` | `diferenca` | numeric(15,2) | sim | 100% |  |  |
| `justification` | `justificacao` | text | sim | 100% |  |  |
| `regularization_account` | `conta_regularizacao` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `regularization_account_name` | `nome_conta_regularizacao` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `journal_entry_ref` | `referencia_lancamento` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `status` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {RASCUNHO, FINALIZADO}; texto original em estado_original |
| `status` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `created_at` | `criado_em` | timestamptz | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `updated_at` | `atualizado_em` | timestamptz | sim | 100% |  |  |

### `cash_sessions` → `sessoes_caixa` (model `SessaoCaixa`, `/api/tesouraria/sessoes-caixa`)

Linhas reais: **12** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `account_code` | `codigo_conta` | varchar(10) | sim | 100% |  |  |
| `operator` | `operador` | varchar(10) | sim | 100% |  |  |
| `open_date` | `data_abertura` | date | sim | 100% |  |  |
| `close_date` | `data_fecho` | date | sim | 92% |  |  |
| `opening_balance` | `saldo_abertura` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=10, decimal=2 |
| `closing_balance` | `saldo_fecho` | numeric(15,2) | sim | 92% |  | tipos mistos: decimal=5, inteiro=6 |
| `physical_balance` | `saldo_fisico` | numeric(15,2) | sim | 92% |  | tipos mistos: decimal=5, inteiro=6 |
| `status` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {ABERTA, FECHADA, CONTABILIZADA}; texto original em estado_original |
| `status` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `currency` | `codigo_moeda` | varchar(10) | sim | 25% |  |  |

### `cash_lines` → `movimentos_caixa` (model `MovimentoCaixa`, `/api/tesouraria/movimentos-caixa`)

Linhas reais: **186** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `session_id` | `sessao_caixa_id` | bigint | sim | 100% | `sessoes_caixa.id` |  |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `type` | `tipo` | varchar(20) | sim | 100% |  | código normalizado ∈ {REC, PAG}; texto original em tipo_original |
| `type` | `tipo_original` | varchar(10) | sim | 100% |  | texto exacto do legado |
| `doc_date` | `data_documento` | date | sim | 100% |  |  |
| `doc_number` | `numero_documento` | varchar(50) | sim | 99% |  | tipos mistos: string=183, string_inteiro=2 |
| `reference` | `referencia` | varchar(100) | sim | 99% |  | tipos mistos: string=183, string_inteiro=2 |
| `third_party_id` | `terceiro_id` | bigint | sim | 95% | `terceiros.id` |  |
| `product_id` | `produto_id` | bigint | sim | 4% | `produtos.id` |  |
| `account_debit` | `conta_debito` | varchar(10) | sim | 100% |  |  |
| `account_credit` | `conta_credito` | varchar(10) | sim | 100% |  |  |
| `description` | `descricao` | text | sim | 100% |  |  |
| `value` | `valor` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=168, decimal=18 |
| `bu_id` | `unidade_negocio_id` | bigint | sim | 5% | `unidades_negocio.id` |  |
| `cc_id` | `centro_custo_id` | bigint | sim | 5% | `centros_custo.id` |  |
| `source_type` | `tipo_origem` | varchar(20) | sim | 100% |  | código normalizado ∈ {CONTABILIDADE, IMPORTACAO, MANUAL, FATURA_COMPRA, POS}; texto original em tipo_origem_original |
| `source_type` | `tipo_origem_original` | varchar(30) | sim | 100% |  | texto exacto do legado |
| `source_id` | `origem_id` | integer | sim | 2% |  |  |
| `is_posted` | `contabilizado` | boolean | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `currency` | `codigo_moeda` | varchar(10) | sim | 1% |  |  |
| `exchange_rate` | `taxa_cambio` | numeric(18,6) | sim | 1% |  |  |
| `exchange_rate_id` | `taxa_cambio_id` | bigint | sim | 1% | `taxas_cambio.id` |  |
| `value_kz` | `valor_kz` | numeric(15,2) | sim | 9% |  |  |
| `demo_note_id` | `nota_demonstracao_id` | bigint | sim | 7% | `notas_demonstracao_resultados.id` |  |
| `cashflow_note_id` | `nota_fluxo_caixa_id` | bigint | sim | 3% | `notas_fluxo_caixa.id` |  |
| `doc_url` | `url_documento` | varchar(150) | sim | 7% |  |  |

## Módulo: POS

### `pos_terminals` → `terminais_pos` (model `TerminalPOS`, `/api/pos/terminais`)

Linhas reais: **8** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `pos_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `code` | `codigo` | varchar(10) | sim | 100% |  |  |
| `name` | `nome` | varchar(30) | sim | 100% |  |  |
| `type` | `tipo` | varchar(20) | sim | 100% |  | código normalizado ∈ {LOJA, RESTAURANTE, LAVANDARIA, HOTELARIA}; texto original em tipo_original |
| `type` | `tipo_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `business_unit_id` | `unidade_negocio_id` | bigint | sim | 63% | `unidades_negocio.id` |  |
| `cost_center_id` | `centro_custo_id` | bigint | sim | 63% | `centros_custo.id` |  |
| `warehouse_id` | `armazem_id` | bigint | sim | 0% | `armazens.id` |  |
| `default_customer_id` | `cliente_padrao_id` | bigint | sim | 0% | `terceiros.id` |  |
| `default_float` | `fundo_maneio_padrao` | numeric(15,2) | sim | 100% |  |  |
| `payment_methods` | `meios_pagamento` | jsonb | sim | 100% |  |  |
| `counters` | `contadores` | jsonb | sim | 100% |  |  |
| `session_counters` | `contadores_sessao` | jsonb | sim | 100% |  |  |
| `z_counters` | `contadores_z` | jsonb | sim | 100% |  |  |
| `is_active` | `ativo` | boolean | sim | 100% |  |  |
| `legacy_id` | `id_legado` | varchar(30) | sim | 75% |  |  |
| `created_at` | `criado_em` | timestamptz | sim | 100% |  |  |
| `created_by` | `criado_por` | varchar(10) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `updated_at` | `atualizado_em` | timestamptz | sim | 63% |  |  |
| `updated_by` | `atualizado_por` | varchar(10) | sim | 63% |  |  |
| `lav_os_counters` | `lavandaria_contadores_os` | jsonb | sim | 13% |  |  |
| `lav_rc_counters` | `lavandaria_contadores_rc` | jsonb | sim | 13% |  |  |
| `lav_ft_counters` | `lavandaria_contadores_ft` | jsonb | sim | 13% |  |  |
| `hotel_checkin_time` | `hotel_hora_entrada` | varchar(10) | sim | 13% |  |  |
| `hotel_checkout_time` | `hotel_hora_saida` | varchar(10) | sim | 13% |  |  |
| `hotel_late_tolerance_min` | `hotel_tolerancia_atraso_min` | integer | sim | 13% |  |  |
| `hotel_hour_block` | `hotel_bloco_horas` | boolean | sim | 13% |  |  |
| `hotel_hour_block_from` | `hotel_bloco_horas_de` | varchar(10) | sim | 13% |  |  |
| `hotel_hour_block_to` | `hotel_bloco_horas_ate` | varchar(10) | sim | 13% |  |  |

### `pos_sessions` → `sessoes_pos` (model `SessaoPOS`, `/api/pos/sessoes`)

Linhas reais: **4** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `pos_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `terminal_id` | `terminal_pos_id` | bigint | sim | 100% | `terminais_pos.id` |  |
| `terminal_code` | `codigo_terminal` | varchar(10) | sim | 100% |  |  |
| `terminal_name` | `nome_terminal` | varchar(30) | sim | 100% |  |  |
| `session_code` | `codigo_sessao` | varchar(30) | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  |  |
| `opened_at` | `aberto_em` | timestamptz | sim | 100% |  |  |
| `opening_float` | `fundo_maneio_abertura` | numeric(15,2) | sim | 100% |  |  |
| `operator_id` | `operador_id` | integer | sim | 100% |  |  |
| `operator_name` | `nome_operador` | varchar(20) | sim | 100% |  |  |
| `posting_status` | `estado_contabilizacao` | varchar(20) | sim | 100% |  |  |
| `settlement_status` | `estado_liquidacao` | varchar(20) | sim | 100% |  |  |
| `deviation_status` | `estado_desvio` | varchar(20) | sim | 100% |  | código normalizado ∈ {NAO_APLICAVEL, SEM_DESVIO, DELIBERADO, PENDENTE}; texto original em estado_desvio_original |
| `deviation_status` | `estado_desvio_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `closed_at` | `fechado_em` | timestamptz | sim | 50% |  |  |
| `closed_by` | `fechado_por` | varchar(10) | sim | 50% |  |  |
| `z_number` | `numero_z` | varchar(30) | sim | 50% |  |  |
| `sales_count` | `numero_vendas` | integer | sim | 50% |  |  |
| `total_sales` | `total_vendas` | numeric(15,2) | sim | 50% |  |  |
| `totals_by_method` | `totais_por_metodo` | jsonb | sim | 50% |  |  |
| `transfers` | `transferencias` | jsonb | sim | 50% |  |  |
| `cash_sales` | `vendas_numerario` | numeric(15,2) | sim | 50% |  |  |
| `cash_expected` | `numerario_esperado` | numeric(15,2) | sim | 50% |  |  |
| `cash_counted` | `numerario_contado` | numeric(15,2) | sim | 50% |  |  |
| `cash_counts` | `contagens_numerario` | jsonb | sim | 50% |  |  |
| `deviation` | `desvio` | numeric(15,2) | sim | 50% |  | tipo forçado (inferido: integer) |
| `tpa_closes` | `fechos_tpa` | jsonb | sim | 50% |  |  |
| `justification` | `justificacao` | text | sim | 25% |  |  |
| `posting_lans` | `lans_contabilizacao` | jsonb | sim | 50% |  |  |
| `posting_journal_id` | `diario_contabilizacao_id` | bigint | sim | 50% | `diarios_contabeis.id` |  |
| `posted_at` | `contabilizado_em` | timestamptz | sim | 50% |  |  |
| `posted_by` | `contabilizado_por` | varchar(10) | sim | 50% |  |  |
| `deliberation` | `deliberacao` | jsonb | sim | 25% |  |  |

### `pos_settlements` → `liquidacoes_pos` (model `LiquidacaoPOS`, `/api/pos/liquidacoes`)

Linhas reais: **6** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `pos_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `status` | `estado` | varchar(20) | sim | 100% |  |  |
| `created_at` | `criado_em` | timestamptz | sim | 100% |  |  |
| `created_by` | `criado_por` | varchar(10) | sim | 100% |  |  |
| `session_id` | `sessao_pos_id` | bigint | sim | 100% | `sessoes_pos.id` |  |
| `z_number` | `numero_z` | varchar(30) | sim | 100% |  |  |
| `item_key` | `chave_item` | varchar(60) | sim | 100% |  | tipo forçado (inferido: varchar(30)) |
| `kind` | `natureza_registo` | varchar(20) | sim | 100% |  |  |
| `pm_id` | `meio_pagamento_codigo` | varchar(40) | sim | 100% |  | tipo forçado (inferido: varchar(10)) |
| `date` | `data` | date | sim | 100% |  |  |
| `amount_gross` | `montante_bruto` | numeric(15,2) | sim | 100% |  |  |
| `commission` | `comissao` | numeric(15,2) | sim | 100% |  |  |
| `amount_net` | `montante_liquido` | numeric(15,2) | sim | 100% |  |  |
| `target` | `alvo` | varchar(20) | sim | 100% |  |  |
| `target_account` | `conta_destino` | varchar(20) | sim | 100% |  |  |
| `transit_account` | `conta_transitoria` | varchar(10) | sim | 100% |  |  |
| `cash_session_id` | `sessao_caixa_id` | bigint | sim | 33% | `sessoes_caixa.id` |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `cash_line_id` | `movimento_caixa_id` | bigint | sim | 33% | `movimentos_caixa.id` |  |
| `commission_account` | `conta_comissao` | varchar(10) | sim | 17% |  |  |
| `treasury_doc_id` | `documento_tesouraria_id` | bigint | sim | 67% | `documentos_tesouraria.id` |  |
| `batch_ref` | `referencia_lote` | varchar(10) | sim | 17% |  |  |
| `doc_number` | `numero_documento` | varchar(50) | sim | 50% |  |  |
| `reference` | `referencia` | varchar(100) | sim | 50% |  | tipos mistos: string_inteiro=2, string=1; tipo forçado (inferido: varchar(20)) |

### `pos_settings` → `configuracoes_pos` (model `ConfiguracaoPOS`, `/api/pos/config`)

Linhas reais: **1** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `pos_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `account_surplus` | `conta_sobra` | varchar(10) | sim | 100% |  |  |
| `account_shortage` | `conta_quebra` | varchar(10) | sim | 100% |  |  |
| `account_operator` | `conta_operador` | varchar(10) | sim | 100% |  |  |
| `deviation_tolerance` | `tolerancia_desvio` | numeric(15,2) | sim | 100% |  | tipo forçado (inferido: integer) |
| `journal_code` | `codigo_diario` | varchar(10) | sim | 100% |  |  |
| `updated_at` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `updated_by` | `atualizado_por` | varchar(10) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `hotel_stays` → `estadias_hotel` (model `EstadiaHotel`, `/api/pos/hotelaria/estadias`)

Linhas reais: **8** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `hotel_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `terminal_id` | `terminal_pos_id` | bigint | sim | 100% | `terminais_pos.id` |  |
| `terminal_code` | `codigo_terminal` | varchar(10) | sim | 100% |  |  |
| `session_id` | `sessao_pos_id` | bigint | sim | 100% | `sessoes_pos.id` |  |
| `room_product_id` | `produto_quarto_id` | bigint | sim | 100% | `produtos.id` |  |
| `room_name` | `nome_quarto` | varchar(50) | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  |  |
| `guest_customer_id` | `cliente_hospede_id` | bigint | sim | 100% | `terceiros.id` |  |
| `guest_name` | `nome_hospede` | varchar(50) | sim | 100% |  |  |
| `guests_count` | `numero_hospedes` | integer | sim | 100% |  |  |
| `mode` | `modo` | varchar(10) | sim | 100% |  |  |
| `checkin_at` | `entrada_em` | timestamptz | sim | 100% |  |  |
| `planned_checkout_at` | `saida_prevista_em` | timestamptz | sim | 100% |  |  |
| `quantity` | `quantidade` | numeric(12,3) | sim | 100% |  |  |
| `unit_price` | `preco_unitario` | numeric(15,2) | sim | 100% |  |  |
| `tax_rate` | `taxa_imposto` | numeric(9,4) | sim | 100% |  |  |
| `notes` | `observacoes` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `items` | `itens` | jsonb | sim | 100% |  |  |
| `history` | `historico_alteracoes` | jsonb | sim | 100% |  |  |
| `created_at` | `criado_em` | timestamptz | sim | 100% |  |  |
| `created_by` | `criado_por` | varchar(10) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `updated_at` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `updated_by` | `atualizado_por` | varchar(10) | sim | 100% |  |  |
| `checkout_at` | `saida_em` | timestamptz | sim | 100% |  |  |
| `final_quantity` | `quantidade_final` | numeric(12,3) | sim | 100% |  |  |
| `late_choice` | `opcao_atraso` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `discount_pct` | `percentagem_desconto` | numeric(9,4) | sim | 100% |  |  |
| `sale_id` | `venda_id` | bigint | sim | 100% | `vendas.id` |  |
| `sale_number` | `numero_venda` | varchar(50) | sim | 100% |  |  |
| `closed_session_id` | `sessao_fecho_id` | bigint | sim | 100% | `sessoes_pos.id` |  |
| `closed_at` | `fechado_em` | timestamptz | sim | 100% |  |  |
| `closed_by` | `fechado_por` | varchar(10) | sim | 100% |  |  |

### `lav_orders` → `pedidos_lavandaria` (model `PedidoLavandaria`, `/api/pos/lavandaria/pedidos`)

Linhas reais: **5** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `lav_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `order_number` | `numero_encomenda` | varchar(30) | sim | 100% |  |  |
| `terminal_id` | `terminal_pos_id` | bigint | sim | 100% | `terminais_pos.id` |  |
| `terminal_code` | `codigo_terminal` | varchar(10) | sim | 100% |  |  |
| `customer_id` | `cliente_id` | bigint | sim | 100% | `terceiros.id` |  |
| `received_at` | `recebido_em` | timestamptz | sim | 100% |  |  |
| `received_by` | `recebido_por` | varchar(10) | sim | 100% |  |  |
| `reception_session_id` | `sessao_rececao_id` | bigint | sim | 100% | `sessoes_pos.id` |  |
| `invoice_mode` | `modo_faturacao` | varchar(20) | sim | 100% |  |  |
| `urgent` | `urgente` | boolean | sim | 100% |  |  |
| `promised_date` | `data_prometida` | timestamptz | sim | 100% |  |  |
| `notes` | `observacoes` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `pickup` | `recolha` | jsonb | sim | 100% |  |  |
| `delivery` | `entrega` | jsonb | sim | 100% |  |  |
| `invoice_ids` | `faturas_ids` | jsonb | sim | 100% |  |  |
| `extras` | `extras` | jsonb | sim | 100% |  |  |
| `history` | `historico_alteracoes` | jsonb | sim | 100% |  |  |
| `items` | `itens` | jsonb | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `assigned_employee_id` | `colaborador_atribuido_id` | bigint | sim | 100% | `colaboradores.id` |  |
| `assigned_name` | `nome_atribuido` | varchar(30) | sim | 100% |  |  |
| `assigned_at` | `atribuido_em` | timestamptz | sim | 100% |  |  |
| `assigned_by` | `atribuido_por` | varchar(10) | sim | 100% |  |  |
| `assignment_note` | `nota_atribuicao` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `assignments` | `atribuicoes` | jsonb | sim | 100% |  |  |
| `delivered_at` | `entregue_em` | timestamptz | sim | 40% |  |  |

### `lav_payments` → `pagamentos_lavandaria` (model `PagamentoLavandaria`, `/api/pos/lavandaria/pagamentos`)

Linhas reais: **5** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `lav_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `order_id` | `pedido_lavandaria_id` | bigint | sim | 100% | `pedidos_lavandaria.id` |  |
| `order_number` | `numero_encomenda` | varchar(30) | sim | 100% |  |  |
| `session_id` | `sessao_pos_id` | bigint | sim | 100% | `sessoes_pos.id` |  |
| `terminal_id` | `terminal_pos_id` | bigint | sim | 100% | `terminais_pos.id` |  |
| `customer_id` | `cliente_id` | bigint | sim | 100% | `terceiros.id` |  |
| `date` | `data` | date | sim | 100% |  |  |
| `amount` | `montante` | numeric(15,2) | sim | 100% |  |  |
| `change` | `troco` | numeric(15,2) | sim | 100% |  |  |
| `pos_payments` | `pos_pagamentos` | jsonb | sim | 100% |  |  |
| `receipt_number` | `numero_recibo` | varchar(50) | sim | 100% |  |  |
| `kind` | `natureza_registo` | varchar(20) | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  |  |
| `created_at` | `criado_em` | timestamptz | sim | 100% |  |  |
| `created_by` | `criado_por` | varchar(10) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `lav_claims` → `reclamacoes_lavandaria` (model `ReclamacaoLavandaria`, `/api/pos/lavandaria/reclamacoes`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `lav_settings` → `configuracoes_lavandaria` (model `ConfiguracaoLavandaria`, `/api/pos/lavandaria/config`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `lav_pieces` → `pecas_lavandaria` (model `PecaLavandaria`, `/api/pos/lavandaria/pecas`)

Linhas reais: **2** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `lav_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `code` | `codigo` | varchar(10) | sim | 100% |  |  |
| `created_at` | `criado_em` | timestamptz | sim | 100% |  |  |
| `created_by` | `criado_por` | varchar(10) | sim | 100% |  |  |
| `name` | `nome` | varchar(30) | sim | 100% |  |  |
| `fabric` | `tecido` | varchar(100) | sim | 100% |  | tipo forçado (inferido: varchar(20)) |
| `color` | `cor` | varchar(50) | sim | 100% |  | tipo forçado (inferido: varchar(10)) |
| `unit` | `unidade` | varchar(10) | sim | 100% |  |  |
| `is_active` | `ativo` | boolean | sim | 100% |  |  |
| `updated_at` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `updated_by` | `atualizado_por` | varchar(10) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `price` | `preco` | numeric(15,2) | sim | 100% |  |  |
| `service_prices` | `precos_servico` | jsonb | sim | 50% |  |  |

## Módulo: Activos

### `fixed_assets` → `ativos_imobilizados` (model `AtivoImobilizado`, `/api/activos/bens`)

Linhas reais: **174** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `code` | `codigo` | varchar(30) | sim | 100% |  |  |
| `description` | `descricao` | text | sim | 100% |  |  |
| `category_id` | `categoria_ativo_id` | bigint | sim | 100% | `categorias_ativos.id` ⚠ 99.4% (1 órfãos) |  |
| `cost_center_id` | `centro_custo_id` | bigint | sim | 10% | `centros_custo.id` |  |
| `acquisition_value` | `valor_aquisicao` | numeric(15,2) | sim | 100% |  | tipos mistos: decimal=91, inteiro=83 |
| `useful_life` | `vida_util` | integer | sim | 100% |  |  |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `acquisition_date` | `data_aquisicao` | date | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {ACTIVO, INACTIVO, ABATIDO}; texto original em estado_original |
| `status` | `estado_original` | varchar(10) | sim | 100% |  | texto exacto do legado |
| `journal_line_id` | `lancamento_contabil_id` | bigint | sim | 98% | `lancamentos_contabeis.id` ⚠ 97.1% (5 órfãos) |  |
| `supplier_id` | `fornecedor_id` | bigint | sim | 20% | `terceiros.id` |  |
| `residual_value` | `valor_residual` | numeric(15,2) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `accumulated_depreciation` | `amortizacao_acumulada` | numeric(15,2) | sim | 100% |  | tipos mistos: decimal=127, inteiro=47 |
| `remaining_life` | `vida_util_restante` | integer | sim | 95% |  |  |
| `accumulated_dep_initial` | `amortizacao_acumulada_inicial` | numeric(15,2) | sim | 95% |  | tipos mistos: inteiro=94, decimal=72 |
| `fixed_quota` | `quota_fixa` | numeric(15,2) | sim | 24% |  | tipos mistos: inteiro=10, decimal=32 |
| `accumulated_end_year` | `acumulado_fim_ano` | integer | sim | 58% |  | tipo forçado (inferido: numeric(15,2)) |
| `business_unit_id` | `unidade_negocio_id` | bigint | sim | 1% | `unidades_negocio.id` |  |

### `asset_categories` → `categorias_ativos` (model `CategoriaAtivo`, `/api/activos/categorias`)

Linhas reais: **11** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `name` | `nome` | varchar(100) | sim | 100% |  |  |
| `annual_rate` | `taxa_anual` | numeric(9,4) | sim | 100% |  | tipos mistos: inteiro=10, decimal=1 |
| `default_useful_life` | `vida_util_padrao` | integer | sim | 100% |  |  |
| `account_expense` | `conta_gasto` | varchar(10) | sim | 100% |  |  |
| `account_accumulated` | `conta_amortizacao_acumulada` | varchar(10) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `account_sale` | `conta_venda` | varchar(10) | sim | 82% |  |  |
| `account_loss` | `conta_perda` | varchar(10) | sim | 82% |  |  |

### `asset_depreciations` → `amortizacoes_ativos` (model `AmortizacaoAtivo`, `/api/activos/amortizacoes`)

Linhas reais: **1448** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `asset_id` | `ativo_imobilizado_id` | bigint | sim | 100% | `ativos_imobilizados.id` |  |
| `period_id` | `periodo_codigo` | varchar(20) | sim | 100% |  |  |
| `is_posted` | `contabilizado` | boolean | sim | 100% |  |  |
| `value` | `valor` | numeric(15,2) | sim | 100% |  | tipos mistos: decimal=1377, inteiro=71 |
| `date` | `data` | date | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `asset_disposals` → `abates_vendas_ativos` (model `AbateVendaAtivo`, `/api/activos/abates`)

Linhas reais: **0** · fictícias descartadas: 16 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `asset_maintenance_plans` → `planos_manutencao_ativos` (model `PlanoManutencaoAtivo`, `/api/activos/planos-manutencao`)

Linhas reais: **0** · fictícias descartadas: 16 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `asset_maintenance_records` → `registos_manutencao_ativos` (model `RegistoManutencaoAtivo`, `/api/activos/manutencoes`)

Linhas reais: **1** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `asset_id` | `ativo_imobilizado_id` | bigint | sim | 100% | `ativos_imobilizados.id` |  |
| `type` | `tipo` | varchar(20) | sim | 100% |  | código normalizado ∈ {PREVENTIVA, CORRECTIVA}; texto original em tipo_original |
| `type` | `tipo_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `date` | `data` | date | sim | 100% |  |  |
| `description` | `descricao` | text | sim | 100% |  |  |
| `cost` | `custo` | numeric(15,2) | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {PLANEADA, EM_CURSO, CONCLUIDA}; texto original em estado_original |
| `status` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `resolution` | `resolucao` | text | sim | 100% |  |  |
| `execution_date` | `data_execucao` | date | sim | 100% |  |  |

### `asset_movements` → `transferencias_centros_custo_ativos` (model `TransferenciaCentroCustoAtivo`, `/api/activos/transferencias`)

Linhas reais: **1** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `asset_id` | `ativo_imobilizado_id` | bigint | sim | 100% | `ativos_imobilizados.id` ⚠ 0.0% (1 órfãos) |  |
| `from_cc_id` | `centro_custo_origem_id` | bigint | sim | 100% | `centros_custo.id` |  |
| `to_cc_id` | `centro_custo_destino_id` | bigint | sim | 100% | `centros_custo.id` |  |
| `date` | `data` | date | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `project_id` | `projeto_id` | bigint | sim | 0% | `projetos.id` |  |
| `project_code` | `codigo_projeto` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |

### `maintenance_requests` → `pedidos_manutencao_equipamentos` (model `PedidoManutencaoEquipamento`, `/api/activos/pedidos-manutencao`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

## Módulo: Projectos

### `projects` → `projetos` (model `Projeto`, `/api/projectos`)

Linhas reais: **5** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `code` | `codigo` | varchar(30) | sim | 100% |  |  |
| `name` | `nome` | varchar(200) | sim | 100% |  |  |
| `status` | `estado` | varchar(10) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `type` | `tipo` | varchar(20) | sim | 100% |  |  |
| `business_unit_id` | `unidade_negocio_id` | bigint | sim | 20% | `unidades_negocio.id` |  |
| `cost_center_id` | `centro_custo_id` | bigint | sim | 20% | `centros_custo.id` |  |
| `customer_id` | `cliente_id` | bigint | sim | 80% | `terceiros.id` |  |
| `sales_order_id` | `encomenda_venda_id` | bigint | sim | 80% | `vendas.id` |  |

### `project_milestones` → `marcos_projeto` (model `MarcoProjeto`, `/api/projectos/marcos`)

Linhas reais: **15** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `project_id` | `projeto_id` | bigint | sim | 100% | `projetos.id` |  |
| `name` | `nome` | varchar(50) | sim | 100% |  |  |
| `date` | `data` | date | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `project_tasks` → `tarefas_projeto` (model `TarefaProjeto`, `/api/projectos/tarefas`)

Linhas reais: **51** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `project_id` | `projeto_id` | bigint | sim | 100% | `projetos.id` |  |
| `parent_task_id` | `tarefa_pai_id` | bigint | sim | 53% | `tarefas_projeto.id` |  |
| `code` | `codigo` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `name` | `nome` | varchar(100) | sim | 100% |  |  |
| `start_date` | `data_inicio` | date | sim | 100% |  |  |
| `end_date` | `data_fim` | date | sim | 78% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {PENDENTE, EM_CURSO, CONCLUIDA, BLOQUEADA}; texto original em estado_original |
| `status` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `milestone_id` | `marco_projeto_id` | bigint | sim | 82% | `marcos_projeto.id` |  |
| `assigned_to_id` | `atribuido_a_id` | integer | sim | 76% |  |  |
| `execution_pct` | `percentagem_execucao` | numeric(9,4) | sim | 96% |  |  |
| `contract_value` | `valor_contrato` | numeric(15,2) | sim | 10% |  |  |
| `ordem` | `ordem` | integer | sim | 6% |  |  |

### `project_teams` → `equipas_projeto` (model `EquipaProjeto`, `/api/projectos/equipas`)

Linhas reais: **4** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `project_id` | `projeto_id` | bigint | sim | 100% | `projetos.id` |  |
| `name` | `nome` | varchar(30) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `project_team_members` → `membros_equipa_projeto` (model `MembroEquipaProjeto`, `/api/projectos/equipas/membros`)

Linhas reais: **29** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `team_id` | `equipa_projeto_id` | bigint | sim | 100% | `equipas_projeto.id` |  |
| `employee_id` | `colaborador_id` | bigint | sim | 79% | `colaboradores.id` |  |
| `third_party_id` | `terceiro_id` | bigint | sim | 21% | `terceiros.id` |  |
| `external_name` | `nome_externo` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `role` | `papel` | varchar(100) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `allocated_hours` | `horas_alocadas` | numeric(12,3) | sim | 79% |  | tipos mistos: inteiro=22, decimal=1 |
| `contract_value` | `valor_contrato` | numeric(15,2) | sim | 3% |  |  |
| `org_node_id` | `no_organigrama_projeto_id` | bigint | sim | 72% | `nos_organigrama_projeto.id` |  |

### `project_requisitions` → `requisicoes_material_projeto` (model `RequisicaoMaterialProjeto`, `/api/projectos/requisicoes`)

Linhas reais: **7** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `project_id` | `projeto_id` | bigint | sim | 100% | `projetos.id` |  |
| `requester_name` | `nome_requerente` | varchar(20) | sim | 100% |  |  |
| `date` | `data` | date | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `expected_date` | `data_prevista` | date | sim | 43% |  |  |

### `project_requisition_lines` → `linhas_requisicao_projeto` (model `LinhaRequisicaoProjeto`, `/api/projectos/requisicoes/linhas`)

Linhas reais: **10** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `requisition_id` | `requisicao_material_projeto_id` | bigint | sim | 100% | `requisicoes_material_projeto.id` |  |
| `task_id` | `tarefa_projeto_id` | bigint | sim | 90% | `tarefas_projeto.id` |  |
| `rubric` | `rubrica` | varchar(20) | sim | 100% |  |  |
| `description` | `descricao` | text | sim | 100% |  |  |
| `quantity` | `quantidade` | numeric(12,3) | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `project_budget_lines` → `linhas_orcamento_projeto` (model `LinhaOrcamentoProjeto`, `/api/projectos/orcamento`)

Linhas reais: **13** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `project_id` | `projeto_id` | bigint | sim | 100% | `projetos.id` |  |
| `task_id` | `tarefa_projeto_id` | bigint | sim | 92% | `tarefas_projeto.id` |  |
| `rubric` | `rubrica` | varchar(20) | sim | 100% |  |  |
| `amount` | `montante` | numeric(15,2) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `account_number` | `numero_conta` | varchar(10) | sim | 46% |  |  |

### `project_ledger` → `razao_analitico_projetos` (model `RazaoAnaliticoProjeto`, `/api/projectos/razao`)

Linhas reais: **30** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `project_id` | `projeto_id` | bigint | sim | 100% | `projetos.id` |  |
| `task_id` | `tarefa_projeto_id` | bigint | sim | 13% | `tarefas_projeto.id` |  |
| `rubric` | `rubrica` | varchar(30) | sim | 100% |  |  |
| `source_module` | `modulo_origem` | varchar(30) | sim | 100% |  | tipo forçado (inferido: varchar(10)) |
| `source_doc_type` | `tipo_documento_origem` | varchar(27) | sim | 100% |  | tipo forçado (inferido: varchar(30)); código normalizado ∈ {AUTO_INTERNO, REGISTO_OBRA, FATURA, FATURA_RECIBO, PROCESSAMENTO_SALARIAL}; texto original em tipo_documento_origem_original |
| `source_doc_type` | `tipo_documento_origem_original` | varchar(30) | sim | 100% |  | texto exacto do legado |
| `nature` | `natureza` | varchar(20) | sim | 100% |  | código normalizado ∈ {CUSTO, CUSTO_REAL, PROVEITO}; texto original em natureza_original |
| `nature` | `natureza_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `date` | `data` | date | sim | 100% |  |  |
| `value` | `valor` | numeric(15,2) | sim | 83% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `amount` | `montante` | numeric(15,2) | sim | 53% |  |  |
| `source_doc_id` | `documento_origem_id` | varchar(60) | sim | 17% |  | tipo forçado (inferido: varchar(30)) |
| `journal_line_id` | `lancamento_contabil_id` | bigint | sim | 0% | `lancamentos_contabeis.id` |  |
| `description` | `descricao` | text | sim | 27% |  |  |

### `project_timesheets` → `folhas_horas_projeto` (model `FolhaHorasProjeto`, `/api/projectos/timesheets`)

Linhas reais: **2** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `project_id` | `projeto_id` | bigint | sim | 100% | `projetos.id` |  |
| `task_id` | `tarefa_projeto_id` | bigint | sim | 100% | `tarefas_projeto.id` |  |
| `employee_id` | `colaborador_id` | bigint | sim | 100% | `colaboradores.id` |  |
| `date` | `data` | date | sim | 100% |  |  |
| `hours` | `horas` | numeric(12,3) | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `project_billings` → `faturacao_projetos` (model `FaturacaoProjeto`, `/api/projectos/faturacao`)

Linhas reais: **0** · fictícias descartadas: 16 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `project_asset_allocations` → `afetacoes_ativos_projeto` (model `AfetacaoAtivoProjeto`, `/api/projectos/ativos`)

Linhas reais: **0** · fictícias descartadas: 16 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `project_change_orders` → `aditamentos_alteracoes_projeto` (model `AditamentoAlteracaoProjeto`, `/api/projectos/alteracoes`)

Linhas reais: **0** · fictícias descartadas: 16 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `project_documents` → `documentos_projeto` (model `DocumentoProjeto`, `/api/projectos/documentos`)

Linhas reais: **0** · fictícias descartadas: 16 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `project_activity_log` → `logs_atividades_projeto` (model `LogAtividadeProjeto`, `/api/projectos/logs`)

Linhas reais: **0** · fictícias descartadas: 16 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `project_settings` → `configuracoes_projetos` (model `ConfiguracaoProjeto`, `/api/projectos/config`)

Linhas reais: **1** · fictícias descartadas: 16

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `project_id` | `projeto_id` | bigint | sim | 100% | `projetos.id` |  |
| `key` | `chave` | varchar(20) | sim | 100% |  |  |
| `value` | `valor` | text | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `project_reviews` → `revisoes_mensais_projeto` (model `RevisaoMensalProjeto`, `/api/projectos/revisoes`)

Linhas reais: **20** · fictícias descartadas: 15

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `project_id` | `projeto_id` | bigint | sim | 100% | `projetos.id` |  |
| `month` | `mes` | integer | sim | 100% |  |  |
| `year` | `ano` | integer | sim | 100% |  |  |
| `status` | `estado` | varchar(20) | sim | 100% |  |  |
| `created_at` | `criado_em` | date | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `project_review_lines` → `linhas_revisao_projeto` (model `LinhaRevisaoProjeto`, `/api/projectos/revisoes/linhas`)

Linhas reais: **55** · fictícias descartadas: 15

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `review_id` | `revisao_mensal_projeto_id` | bigint | sim | 100% | `revisoes_mensais_projeto.id` |  |
| `type` | `tipo` | varchar(20) | sim | 100% |  | código normalizado ∈ {MAO_OBRA, SUBEMPREITADA}; texto original em tipo_original |
| `type` | `tipo_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `third_party_id` | `terceiro_id` | bigint | sim | 15% | `terceiros.id` |  |
| `task_id` | `tarefa_projeto_id` | bigint | sim | 15% | `tarefas_projeto.id` |  |
| `previous_pct` | `percentagem_anterior` | numeric(9,4) | sim | 15% |  |  |
| `current_pct` | `percentagem_atual` | numeric(9,4) | sim | 15% |  |  |
| `calculated_value` | `valor_calculado` | numeric(15,2) | sim | 100% |  |  |
| `generated_doc_id` | `documento_gerado_id` | varchar(50) | sim | 60% |  | tipo forçado (inferido: varchar(10)) |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `employee_id` | `colaborador_id` | bigint | sim | 85% | `colaboradores.id` |  |

### `project_org_nodes` → `nos_organigrama_projeto` (model `NoOrganigramaProjeto`, `/api/projectos/organigrama`)

Linhas reais: **30** · fictícias descartadas: 2

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `project_id` | `projeto_id` | bigint | sim | 100% | `projetos.id` |  |
| `parent_id` | `no_pai_id` | bigint | sim | 93% | `nos_organigrama_projeto.id` |  |
| `titulo` | `titulo` | varchar(50) | sim | 100% |  |  |
| `area` | `area` | varchar(100) | sim | 27% |  | tipo forçado (inferido: varchar(30)) |
| `descricao` | `descricao` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `vagas` | `vagas` | integer | sim | 100% |  |  |
| `responsavel_member_id` | `membro_responsavel_id` | bigint | sim | 0% | `membros_equipa_projeto.id` |  |
| `ordem` | `ordem` | integer | sim | 100% |  |  |
| `cor` | `cor` | varchar(20) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `apoio` | `apoio` | integer | sim | 13% |  |  |
| `tarefas` | `tarefas` | jsonb | sim | 17% |  |  |
| `disposicao` | `disposicao` | varchar(30) | sim | 10% |  | tipo forçado (inferido: varchar(10)) |

## Módulo: Orçamento

### `orc_rubrics` → `rubricas_orcamentais` (model `RubricaOrcamental`, `/api/orcamento/rubricas`)

Linhas reais: **75** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `orc_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `tipo` | `tipo` | varchar(20) | sim | 100% |  | código normalizado ∈ {EXPLORACAO, TESOURARIA}; texto original em tipo_original |
| `tipo` | `tipo_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `codigo` | `codigo` | varchar(10) | sim | 100% |  |  |
| `nome` | `nome` | varchar(100) | sim | 100% |  |  |
| `natureza` | `natureza` | varchar(20) | sim | 100% |  | código normalizado ∈ {PROVEITO, CUSTO, RECEBIMENTO, PAGAMENTO}; texto original em natureza_original |
| `natureza` | `natureza_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `grupo` | `grupo` | varchar(50) | sim | 100% |  |  |
| `contas` | `contas` | jsonb | sim | 100% |  |  |
| `ordem` | `ordem` | integer | sim | 100% |  |  |
| `activo` | `ativo` | boolean | sim | 100% |  |  |
| `descricao` | `descricao` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `actualizado_em` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `criado_em` | `criado_em` | timestamptz | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `orc_budgets` → `orcamentos_anuais` (model `OrcamentoAnual`, `/api/orcamento/orcamentos`)

Linhas reais: **8** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `orc_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `ano` | `ano` | integer | sim | 100% |  |  |
| `tipo` | `tipo` | varchar(20) | sim | 100% |  | código normalizado ∈ {EXPLORACAO, TESOURARIA}; texto original em tipo_original |
| `tipo` | `tipo_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `business_unit_id` | `unidade_negocio_id` | bigint | sim | 50% | `unidades_negocio.id` |  |
| `cost_center_id` | `centro_custo_id` | bigint | sim | 0% | `centros_custo.id` |  |
| `nome` | `nome` | varchar(100) | sim | 100% |  |  |
| `descricao` | `descricao` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `versao` | `versao` | integer | sim | 100% |  |  |
| `versao_origem_id` | `versao_origem_id` | bigint | sim | 0% | `orcamentos_anuais.id` |  |
| `status` | `estado` | varchar(20) | sim | 100% |  | código normalizado ∈ {RASCUNHO, SUBMETIDO, APROVADO, SUBSTITUIDO}; texto original em estado_original |
| `status` | `estado_original` | varchar(20) | sim | 100% |  | texto exacto do legado |
| `criado_por` | `criado_por` | varchar(10) | sim | 100% |  |  |
| `criado_em` | `criado_em` | timestamptz | sim | 100% |  |  |
| `rejeicoes` | `rejeicoes` | jsonb | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `actualizado_por` | `atualizado_por` | varchar(10) | sim | 63% |  |  |
| `actualizado_em` | `atualizado_em` | timestamptz | sim | 63% |  |  |
| `abordagem` | `abordagem` | varchar(20) | sim | 63% |  |  |
| `dimensao_filhos` | `dimensao_filhos` | varchar(10) | sim | 13% |  |  |
| `project_id` | `projeto_id` | bigint | sim | 0% | `projetos.id` |  |
| `metodo` | `metodo` | varchar(20) | sim | 50% |  |  |
| `origem` | `origem` | varchar(30) | sim | 0% |  | sem valores reais: tipo a confirmar no código legado; tipo forçado (inferido: text) |
| `crescimento_proveitos_pct` | `crescimento_proveitos_pct` | numeric(9,4) | sim | 50% |  |  |
| `crescimento_custos_pct` | `crescimento_custos_pct` | numeric(9,4) | sim | 50% |  |  |
| `inflacao_pct` | `inflacao_pct` | numeric(9,4) | sim | 50% |  |  |
| `responsavel` | `responsavel` | varchar(100) | sim | 0% |  | sem valores reais: tipo a confirmar no código legado; tipo forçado (inferido: text) |
| `pai_id` | `orcamento_pai_id` | bigint | sim | 50% | `orcamentos_anuais.id` |  |

### `orc_lines` → `linhas_orcamento` (model `LinhaOrcamento`, `/api/orcamento/linhas`)

Linhas reais: **6** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `orc_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `budget_id` | `orcamento_anual_id` | bigint | sim | 100% | `orcamentos_anuais.id` |  |
| `rubric_id` | `rubrica_orcamental_id` | bigint | sim | 100% | `rubricas_orcamentais.id` |  |
| `valores` | `valores` | jsonb | sim | 100% |  |  |
| `total` | `total` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=5, decimal=1 |
| `notas` | `notas` | text | sim | 67% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `orc_forecasts` → `previsoes_orcamentais` (model `PrevisaoOrcamental`, `/api/orcamento/previsoes`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `orc_forecast_lines` → `linhas_previsao_orcamental` (model `LinhaPrevisaoOrcamental`, `/api/orcamento/previsoes/linhas`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `orc_scenarios` → `cenarios_orcamentais` (model `CenarioOrcamental`, `/api/orcamento/cenarios`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `orc_excess_requests` → `pedidos_extrapolacao_orcamento` (model `PedidoExtrapolacaoOrcamento`, `/api/orcamento/extrapolacoes`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `orc_alert_log` → `logs_alertas_orcamentais` (model `LogAlertaOrcamental`, `/api/orcamento/alertas`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

## Módulo: Acréscimos

### `ad_settings` → `configuracoes_acrescimos_diferimentos` (model `ConfigAcrescimoDiferimento`, `/api/acrescimos-diferimentos/config`)

Linhas reais: **2** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `ad_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `contas` | `contas` | jsonb | sim | 100% |  |  |
| `journal_id` | `diario_id` | bigint | sim | 100% | `diarios_contabeis.id` |  |
| `prazo_documento_dias` | `prazo_documento_dias` | integer | sim | 100% |  |  |
| `actualizado_por` | `atualizado_por` | varchar(10) | sim | 100% |  |  |
| `actualizado_em` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `ad_items` → `itens_acrescimos_diferimentos` (model `ItemAcrescimoDiferimento`, `/api/acrescimos-diferimentos/itens`)

Linhas reais: **2** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `ad_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `tipo` | `tipo` | varchar(20) | sim | 100% |  |  |
| `natureza` | `natureza` | varchar(30) | sim | 100% |  | tipo forçado (inferido: varchar(10)) |
| `descricao` | `descricao` | text | sim | 100% |  |  |
| `valor` | `valor` | numeric(15,2) | sim | 100% |  |  |
| `conta_resultado` | `conta_resultado` | varchar(10) | sim | 100% |  |  |
| `conta_balanco` | `conta_balanco` | varchar(10) | sim | 100% |  |  |
| `data_inicio` | `data_inicio` | date | sim | 100% |  |  |
| `data_fim` | `data_fim` | date | sim | 100% |  |  |
| `reparticao` | `reparticao` | varchar(10) | sim | 100% |  | tipo forçado (inferido: varchar(10)) |
| `data_documento` | `data_documento` | date | sim | 100% |  |  |
| `data_limite` | `data_limite` | date | sim | 0% |  | sem valores reais: tipo a confirmar no código legado; tipo forçado (inferido: text) |
| `documento_em_balanco` | `documento_em_balanco` | boolean | sim | 100% |  |  |
| `third_party_id` | `terceiro_id` | bigint | sim | 100% | `terceiros.id` |  |
| `business_unit_id` | `unidade_negocio_id` | bigint | sim | 50% | `unidades_negocio.id` |  |
| `cost_center_id` | `centro_custo_id` | bigint | sim | 50% | `centros_custo.id` |  |
| `project_id` | `projeto_id` | bigint | sim | 0% | `projetos.id` |  |
| `origem` | `origem` | jsonb | sim | 100% |  |  |
| `notas` | `notas` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `actualizado_por` | `atualizado_por` | varchar(10) | sim | 100% |  |  |
| `actualizado_em` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `estado` | `estado` | varchar(20) | sim | 100% |  |  |
| `criado_por` | `criado_por` | varchar(10) | sim | 100% |  |  |
| `criado_em` | `criado_em` | timestamptz | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `ad_postings` → `periodos_lancamento_acrescimos` (model `PeriodoLancamentoAcrescimo`, `/api/acrescimos-diferimentos/lancamentos`)

Linhas reais: **8** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `ad_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `item_id` | `item_acrescimo_diferimento_id` | bigint | sim | 100% | `itens_acrescimos_diferimentos.id` |  |
| `periodo` | `periodo` | varchar(20) | sim | 100% |  |  |
| `tipo` | `tipo` | varchar(30) | sim | 100% |  |  |
| `valor` | `valor` | numeric(15,2) | sim | 100% |  | tipos mistos: inteiro=6, decimal=2 |
| `journal_id` | `diario_id` | bigint | sim | 100% | `diarios_contabeis.id` |  |
| `lan_number` | `numero_lan` | varchar(20) | sim | 100% |  |  |
| `doc_number` | `numero_documento` | varchar(30) | sim | 100% |  |  |
| `doc_date` | `data_documento` | date | sim | 100% |  |  |
| `estado` | `estado` | varchar(20) | sim | 100% |  |  |
| `por` | `por` | varchar(100) | sim | 100% |  | tipo forçado (inferido: varchar(10)) |
| `em` | `em` | timestamptz | sim | 100% |  |  |
| `diferenca` | `diferenca` | numeric(15,2) | sim | 0% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

## Módulo: CRM

### `crm_settings` → `configuracoes_crm` (model `ConfiguracaoCRM`, `/api/crm/config`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._

### `crm_pipelines` → `funis_vendas_crm` (model `FunilVendasCRM`, `/api/crm/funis`)

Linhas reais: **2** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `crm_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `nome` | `nome` | varchar(10) | sim | 100% |  |  |
| `ordem` | `ordem` | integer | sim | 100% |  |  |
| `activo` | `ativo` | boolean | sim | 100% |  |  |
| `etapas` | `etapas` | jsonb | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `crm_accounts` → `contas_crm` (model `ContaCRM`, `/api/crm/contas`)

Linhas reais: **5** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `crm_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `tipo` | `tipo` | varchar(20) | sim | 100% |  |  |
| `third_party_id` | `terceiro_id` | bigint | sim | 100% | `terceiros.id` |  |
| `nome` | `nome` | varchar(50) | sim | 100% |  |  |
| `nif` | `nif` | varchar(20) | sim | 100% |  |  |
| `email` | `email` | varchar(50) | sim | 40% |  |  |
| `telefone` | `telefone` | varchar(30) | sim | 40% |  | tipos mistos: string=1, string_inteiro=1 |
| `morada` | `morada` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `origem` | `origem` | varchar(22) | sim | 100% |  | código normalizado ∈ {RECOMENDACAO, CLIENTE_EXISTENTE, SITE, CAMPANHA, OUTRO}; texto original em origem_original |
| `origem` | `origem_original` | varchar(30) | sim | 100% |  | texto exacto do legado |
| `responsavel` | `responsavel` | varchar(100) | sim | 100% |  | tipo forçado (inferido: varchar(10)) |
| `criado_por` | `criado_por` | varchar(10) | sim | 100% |  |  |
| `criado_em` | `criado_em` | timestamptz | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `sector` | `setor` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `website` | `website` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `notas` | `notas` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `actualizado_em` | `atualizado_em` | timestamptz | sim | 60% |  |  |

### `crm_contacts` → `contactos_crm` (model `ContactoCRM`, `/api/crm/contactos`)

Linhas reais: **4** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `crm_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `account_id` | `conta_crm_id` | bigint | sim | 100% | `contas_crm.id` |  |
| `nome` | `nome` | varchar(30) | sim | 100% |  |  |
| `cargo` | `cargo` | varchar(100) | sim | 50% |  | tipo forçado (inferido: varchar(10)) |
| `email` | `email` | varchar(50) | sim | 75% |  |  |
| `telefone` | `telefone` | varchar(30) | sim | 75% |  | tipos mistos: string=2, string_inteiro=1 |
| `principal` | `principal` | boolean | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `crm_opportunities` → `oportunidades_venda_crm` (model `OportunidadeVendaCRM`, `/api/crm/oportunidades`)

Linhas reais: **5** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `crm_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `pipeline_id` | `funil_vendas_crm_id` | bigint | sim | 100% | `funis_vendas_crm.id` |  |
| `account_id` | `conta_crm_id` | bigint | sim | 100% | `contas_crm.id` |  |
| `contact_id` | `contacto_crm_id` | bigint | sim | 80% | `contactos_crm.id` |  |
| `titulo` | `titulo` | varchar(255) | sim | 100% |  |  |
| `valor` | `valor` | numeric(15,2) | sim | 100% |  |  |
| `probabilidade` | `probabilidade` | numeric(9,4) | sim | 60% |  |  |
| `data_fecho_prevista` | `data_fecho_prevista` | date | sim | 100% |  |  |
| `responsavel` | `responsavel` | varchar(100) | sim | 100% |  | tipo forçado (inferido: varchar(10)) |
| `origem` | `origem` | varchar(22) | sim | 100% |  | código normalizado ∈ {RECOMENDACAO, CLIENTE_EXISTENTE, SITE, CAMPANHA, OUTRO}; texto original em origem_original |
| `origem` | `origem_original` | varchar(30) | sim | 100% |  | texto exacto do legado |
| `notas` | `notas` | text | sim | 20% |  |  |
| `itens` | `itens` | jsonb | sim | 100% |  |  |
| `actualizado_em` | `atualizado_em` | timestamptz | sim | 100% |  |  |
| `etapa_id` | `etapa_codigo` | varchar(20) | sim | 100% |  |  |
| `estado` | `estado` | varchar(20) | sim | 100% |  |  |
| `historico` | `historico` | jsonb | sim | 100% |  |  |
| `criada_em` | `criado_em` | timestamptz | sim | 100% |  |  |
| `etapa_desde` | `etapa_desde` | timestamptz | sim | 100% |  |  |
| `vendas` | `vendas` | jsonb | sim | 100% |  |  |
| `criado_por` | `criado_por` | varchar(10) | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `fechada_em` | `fechado_em` | timestamptz | sim | 80% |  |  |
| `motivo_perda` | `motivo_perda` | text | sim | 20% |  |  |
| `concorrente` | `concorrente` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `notas_perda` | `notas_perda` | text | sim | 0% |  | sem valores reais: tipo a confirmar no código legado |
| `ultima_actividade_em` | `ultima_atividade_em` | timestamptz | sim | 60% |  |  |

### `crm_activities` → `atividades_comerciais_crm` (model `AtividadeComercialCRM`, `/api/crm/atividades`)

Linhas reais: **45** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `crm_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `opportunity_id` | `oportunidade_crm_id` | bigint | sim | 93% | `oportunidades_venda_crm.id` |  |
| `account_id` | `conta_crm_id` | bigint | sim | 100% | `contas_crm.id` |  |
| `contact_id` | `contacto_crm_id` | bigint | sim | 64% | `contactos_crm.id` |  |
| `tipo` | `tipo` | varchar(20) | sim | 100% |  |  |
| `titulo` | `titulo` | varchar(100) | sim | 100% |  |  |
| `descricao` | `descricao` | text | sim | 100% |  |  |
| `data_prevista` | `data_prevista` | date | sim | 100% |  |  |
| `concluida` | `concluida` | boolean | sim | 100% |  |  |
| `responsavel` | `responsavel` | varchar(100) | sim | 89% |  | tipo forçado (inferido: varchar(10)) |
| `automatica` | `automatica` | boolean | sim | 100% |  |  |
| `modelo_id` | `modelo_email_crm_id` | bigint | sim | 20% | `modelos_email_crm.id` |  |
| `criado_por` | `criado_por` | varchar(20) | sim | 100% |  |  |
| `criado_em` | `criado_em` | timestamptz | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |
| `concluida_em` | `concluida_em` | timestamptz | sim | 91% |  |  |
| `resultado` | `resultado` | text | sim | 87% |  | tipo forçado (inferido: varchar(50)) |
| `concluida_por` | `concluida_por` | varchar(10) | sim | 2% |  |  |

### `crm_templates` → `modelos_email_crm` (model `ModeloEmailCRM`, `/api/crm/modelos`)

Linhas reais: **6** · fictícias descartadas: 0

| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |
| :--- | :--- | :--- | :---: | ---: | :--- | :--- |
| `crm_company_id` | `empresa_id` | bigint | não | 100% | `empresas.id` |  |
| `nome` | `nome` | varchar(30) | sim | 100% |  |  |
| `assunto` | `assunto` | varchar(255) | sim | 100% |  | tipo forçado (inferido: varchar(100)) |
| `corpo` | `corpo` | text | sim | 100% |  |  |
| `id` | `id` | bigint | não | 100% |  | PK preservada do backup |

### `crm_sequences` → `sequencias_campanhas_crm` (model `SequenciaCampanhaCRM`, `/api/crm/sequencias`)

Linhas reais: **0** · fictícias descartadas: 0 · ⚠ **sem dados reais — esquema a derivar do código legado**

_Sem colunas reais no backup._
