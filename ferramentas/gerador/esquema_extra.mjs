// Regras de esquema que não se deduzem do backup: chaves únicas, cascatas, eliminação lógica,
// tenant derivado, pivôs, colunas novas do desenho (ex.: estorno) e CHECKs de negócio.
//
// TODAS as chaves únicas são verificadas pelo gerador contra os dados reais do backup (após normalização):
// se o backup as violar, o gerador falha — a chave tem de sair daqui e o caso vai para os relatórios
// de validação (ADR-015). Nomes de tabelas e colunas: já em português (destino).

// Tabelas globais (sem empresa_id / sem isolamento multi-empresa).
// pedidos_manutencao_equipamentos = pedidos de "Manutenção de dados" (js/manutencao.js): podem ser globais (empresa_id NULL).
export const GLOBAIS = new Set(['utilizadores', 'perfis_utilizador', 'moedas', 'configuracoes_sistema', 'taxas_cambio', 'pedidos_manutencao_equipamentos']);

// Tabelas criadas manualmente na Fase 1 (o gerador não as recria; FKs para elas são permitidas).
export const FASE1 = new Set(['empresas', 'utilizadores', 'perfis_utilizador', 'logs_auditoria']);

// Tabelas-filho sem empresa no legado: o ETL deriva empresa_id do pai (coluna -> tabela pai).
export const EMPRESA_DERIVADA = {
  linhas_folha_salarial: ['periodo_processamento_salarial_id', 'periodos_processamento_salarial'],
  itens_venda: ['venda_id', 'vendas'],
  itens_recibo_venda: ['recibo_venda_id', 'recibos_venda'],
  itens_documento_tesouraria: ['documento_tesouraria_id', 'documentos_tesouraria'],
  linhas_sessao_inventario: ['sessao_inventario_id', 'sessoes_inventario'],
  membros_consolidacao: ['grupo_consolidacao_id', 'grupos_consolidacao'],
  // itens_compra e itens_guia_saida: derivada do documento-pai polimórfico (ver POLIMORFICOS)
};

// Tabelas com dados mestre: eliminação lógica (eliminado_em); únicos passam a índices parciais (WHERE eliminado_em IS NULL).
export const ELIMINACAO_LOGICA = new Set([
  'terceiros', 'produtos', 'categorias_produtos', 'armazens', 'plano_contas', 'diarios_contabeis', 'colaboradores',
  'cargos_funcoes', 'infotipos_salariais', 'tipos_organizacao_rh', 'bancos', 'meios_pagamento', 'centros_custo',
  'unidades_negocio', 'projetos', 'ativos_imobilizados', 'categorias_ativos', 'terminais_pos', 'contas_crm',
  'contactos_crm', 'rubricas_orcamentais', 'unidades_organicas', 'postos_trabalho', 'moedas', 'catalogo_fornecedores',
]);

// Chaves únicas (verificadas contra o backup). [tabela, [colunas], condição WHERE opcional (índice parcial)]
export const UNICOS = [
  ['plano_contas', ['empresa_id', 'codigo']],
  ['diarios_contabeis', ['empresa_id', 'codigo']],
  ['produtos', ['empresa_id', 'codigo']],
  ['categorias_produtos', ['empresa_id', 'nome']],
  ['stock_armazem', ['armazem_id', 'produto_id']],
  ['colaboradores', ['empresa_id', 'nif']],
  ['tipos_organizacao_rh', ['empresa_id', 'nome']],
  ['periodos_processamento_salarial', ['empresa_id', 'mes_ano']],
  ['coordenadas_bancarias_colaboradores', ['empresa_id', 'colaborador_id']],
  ['efectividade_assiduidade', ['empresa_id', 'colaborador_id', 'data']],   // um registo por colaborador e dia
  ['fechos_mensais_assiduidade', ['empresa_id', 'mes']],                   // estado actual do mês (o histórico fica na auditoria)
  ['periodos_produtividade_rh', ['empresa_id', 'mes']],
  ['modelos_documentos_rh', ['empresa_id', 'codigo']],
  ['avaliacoes_desempenho_rh', ['empresa_id', 'colaborador_id', 'ano', 'periodo']],   // o legado só verificava na aplicação
  ['ciclos_avaliacao_360', ['empresa_id', 'ano', 'periodo']],
  ['autoavaliacoes_colaborador', ['empresa_id', 'colaborador_id', 'ano', 'periodo']],
  ['confirmacoes_avaliacao_rh', ['ciclo_avaliacao_id', 'colaborador_id']],
  ['participantes_avaliacao_360', ['ciclo_avaliacao_id', 'colaborador_avaliador_id', 'colaborador_avaliado_id']],
  ['participacoes_ascendentes_rh', ['empresa_id', 'colaborador_id', 'colaborador_alvo_id', 'ano', 'periodo']],
  ['ciclos_avaliacao_360', ['empresa_id'], "estado = 'ABERTO'"],
  ['sessoes_pos', ['terminal_pos_id'], "estado = 'ABERTA'"],   // uma sessão aberta por terminal (o legado: ler-depois-inserir)
  ['liquidacoes_pos', ['sessao_pos_id', 'chave_item'], "estado = 'REGISTADO'"],
  ['estadias_hotel', ['produto_quarto_id'], "estado = 'ABERTA'"],
  ['configuracoes_sistema', ['chave']],   // chave única (o cadeado closed_year_<empresa>_<ano>, ADR-056)
  ['amortizacoes_ativos', ['empresa_id', 'ativo_imobilizado_id', 'periodo_codigo']],   // índice [company_id+asset_id+period_id] do legado (db_v2.js:50)
  ['configuracoes_projetos', ['empresa_id', 'projeto_id', 'chave'], 'projeto_id IS NOT NULL'],
  ['configuracoes_projetos', ['empresa_id', 'chave'], 'projeto_id IS NULL'],
  ['contas_crm', ['empresa_id', 'terceiro_id'], 'terceiro_id IS NOT NULL'],   // uma conta CRM por cliente
  ['periodos_lancamento_acrescimos', ['item_acrescimo_diferimento_id', 'tipo', 'periodo'], "estado = 'CONTABILIZADO'"],   // sem lançamentos em duplicado   // um check-in aberto por quarto (o legado: ler-depois-inserir)   // cada item da prestação de contas liquida-se uma vez   // um só ciclo aberto   // um IBAN por colaborador (upsert do legado)
  // Numeração única obrigatória só nos documentos fiscais (AGT). Orçamentos/proformas do legado repetem números
  // (o legado tratava "Orçamento" e "Orcamento" como tipos distintos) -> relatório de validação.
  ['vendas', ['empresa_id', 'tipo_documento', 'numero_documento'], "tipo_documento IN ('FT','FR','NC','ND')"],
  // Unicidade dos restantes números de documento (análise de regras 2026-10-02, M15). O legado repete OR/PF sem série.
  ['vendas', ['empresa_id', 'tipo_documento', 'numero_documento'], "tipo_documento IN ('NE','GR','GD')"],
  ['vendas', ['empresa_id', 'tipo_documento', 'numero_documento'], "tipo_documento IN ('OR','PF') AND serie_faturacao_eletronica_id IS NOT NULL"],
  ['documentos_tesouraria', ['empresa_id', 'numero_documento'], 'numero_documento IS NOT NULL'],
  ['pedidos_compra', ['empresa_id', 'numero_pedido'], 'numero_pedido IS NOT NULL'],
  ['cotacoes_compra', ['empresa_id', 'numero_proposta'], 'numero_proposta IS NOT NULL'],
  ['rececoes_compra', ['empresa_id', 'numero_rececao'], 'numero_rececao IS NOT NULL'],
  ['recibos_venda', ['empresa_id', 'numero_recibo']],
  ['encomendas_compra', ['empresa_id', 'numero_encomenda']],
  ['guias_saida', ['empresa_id', 'numero_documento']],
  ['centros_custo', ['empresa_id', 'codigo']],
  ['unidades_negocio', ['empresa_id', 'codigo']],
  ['moedas', ['codigo']],
  ['taxas_cambio', ['empresa_id', 'codigo_moeda', 'data_taxa']],
  ['ativos_imobilizados', ['empresa_id', 'codigo']],
  ['projetos', ['empresa_id', 'codigo']],
  ['terminais_pos', ['empresa_id', 'codigo']],
  ['rubricas_orcamentais', ['empresa_id', 'codigo']],
  ['unidades_organicas', ['empresa_id', 'codigo']],
  ['coordenadas_bancarias_colaboradores', ['empresa_id', 'colaborador_id', 'iban']],
  // Configuração: uma linha por empresa
  ['configuracoes_contabeis_vendas', ['empresa_id', 'chave']],   // uma linha por chave (clientes_default, iva_vendas, …)
  ['configuracoes_pos', ['empresa_id']],
  ['configuracoes_deliberacao_compras', ['empresa_id']],
  ['configuracoes_contabeis_compras', ['empresa_id', 'chave']],
  ['resultados_folha_salarial', ['periodo_processamento_salarial_id', 'colaborador_id']],
  ['contratos_fornecedores_encomendas', ['encomenda_compra_id']],   // cada encomenda num só contrato (pcRetirarDeOutrosContratos)
  ['configuracoes_contabeis_tesouraria', ['empresa_id', 'chave']],
  ['configuracoes_contabeis_logistica', ['empresa_id', 'chave']],
  ['configuracoes_faturacao_eletronica', ['empresa_id']],
  // Séries de numeração (AGT e não fiscais): código único por empresa, tipo e ano
  ['series_faturacao_eletronica', ['empresa_id', 'tipo', 'ano', 'codigo']],
  // Ronda 2 (ADR-068): tabelas novas
  ['mesas_pos', ['terminal_pos_id', 'nome']],
  ['contas_mesa_pos', ['mesa_pos_id'], "estado = 'ABERTA'"],   // uma conta aberta por mesa (bloqueio entre postos)
  ['configuracoes_rh', ['empresa_id', 'chave']],
  ['tokens_bi', ['hash_token']],
  ['preferencias_utilizador', ['utilizador_id', 'tipo', 'nome']],
];

// Chaves candidatas que o backup VIOLA: ficam como índice normal + relatório de validação (ADR-015).
export const INDICES_COM_DUPLICADOS_CONHECIDOS = [
  ['terceiros', ['empresa_id', 'nif'], '408 terceiros com o mesmo NIF e tipo'],
  ['cargos_funcoes', ['empresa_id', 'nome'], '10 cargos com o mesmo nome'],
  ['infotipos_salariais', ['empresa_id', 'nome'], '2 infotipos "Dias de Trabalho" duplicados'],
  ['notas_demonstracao_resultados', ['empresa_id', 'codigo'], '12 notas DEMO com o mesmo código'],
  ['notas_fluxo_caixa', ['empresa_id', 'codigo'], '6 notas de fluxo com o mesmo código'],
  ['mapeamentos_contabeis_rh', ['empresa_id', 'infotipo_salarial_id', 'tipo_organizacao_id'], '4 mapeamentos RH duplicados (conta ambígua na integração salarial)'],
  ['faturas_compra', ['empresa_id', 'fornecedor_id', 'numero_fatura'], '2 facturas de fornecedor registadas em duplicado'],
];

// Índices de consulta frequentes (além dos índices automáticos de todas as FKs e de empresa_id).
export const INDICES = [
  ['lancamentos_contabeis', ['empresa_id', 'codigo_conta', 'data_documento']],
  ['lancamentos_contabeis', ['empresa_id', 'diario_id', 'numero_lan']],
  ['lancamentos_contabeis', ['empresa_id', 'numero_documento']],
  ['lancamentos_contabeis', ['empresa_id', 'reconciliacao_codigo']],
  ['lancamentos_contabeis', ['empresa_id', 'data_documento']],
  ['lancamentos_estornados', ['empresa_id', 'lancamento_original_id']],
  ['plano_contas', ['empresa_id', 'tipo']],
  ['vendas', ['empresa_id', 'data_emissao']],
  ['vendas', ['empresa_id', 'estado']],
  ['documentos_tesouraria', ['empresa_id', 'data_documento']],
  ['linhas_extrato_bancario', ['empresa_id', 'codigo_conta', 'data']],
  ['linhas_extrato_bancario', ['empresa_id', 'reconciliacao_codigo']],
  ['movimentos_inventario', ['empresa_id', 'produto_id', 'data']],
  ['linhas_folha_salarial', ['empresa_id', 'periodo_processamento_salarial_id', 'colaborador_id']],
  ['amortizacoes_ativos', ['empresa_id', 'ativo_imobilizado_id', 'periodo_codigo']],
  ['terceiros', ['empresa_id', 'codigo_conta']],
  ['mesas_pos', ['empresa_id', 'terminal_pos_id']],
  ['contas_mesa_pos', ['empresa_id', 'terminal_pos_id', 'estado']],
  ['rascunhos_reconciliacao', ['empresa_id', 'codigo_conta']],
  ['utilizacoes_assistente_ia', ['empresa_id', 'criado_em']],
];

// FKs em cascata (linhas/itens apagados com o cabeçalho). Todas as outras: RESTRICT.
export const CASCATA = new Set([
  'itens_venda.venda_id', 'itens_recibo_venda.recibo_venda_id', 'itens_documento_tesouraria.documento_tesouraria_id',
  'linhas_sessao_inventario.sessao_inventario_id', 'linhas_folha_salarial.periodo_processamento_salarial_id',
  'movimentos_caixa.sessao_caixa_id', 'linhas_requisicao_projeto.requisicao_material_projeto_id',
  'linhas_revisao_projeto.revisao_mensal_projeto_id', 'linhas_orcamento.orcamento_anual_id',
  'membros_equipa_projeto.equipa_projeto_id', 'membros_consolidacao.grupo_consolidacao_id',
  'coordenadas_bancarias_colaboradores.colaborador_id', 'dependentes_colaboradores.colaborador_id',
  // Ronda 2 (ADR-068): as mesas pertencem ao terminal; as preferências ao utilizador
  'mesas_pos.terminal_pos_id', 'preferencias_utilizador.utilizador_id',
]);

// FKs polimórficas do legado substituídas por uma FK real por tipo (CHECK: exactamente uma preenchida).
export const POLIMORFICOS = {
  // delivery_items do legado guarda linhas de guias de saída E de recepções de compra no mesmo delivery_id,
  // sem discriminador (ui_warehouse.js:888 vs ui_compras_v2.js:2183). O ETL resolve o pai de cada linha.
  itens_guia_saida: {
    legado: { id: 'guia_id', tipo: null },
    alvos: { GUIA_SAIDA: ['guia_saida_id', 'guias_saida'], RECECAO_COMPRA: ['rececao_compra_id', 'rececoes_compra'] },
  },
  itens_compra: {
    legado: { id: 'documento_origem_id', tipo: 'tipo_documento_origem' },
    alvos: { PEDIDO: ['pedido_compra_id', 'pedidos_compra'], COTACAO: ['cotacao_compra_id', 'cotacoes_compra'],
      ENCOMENDA: ['encomenda_compra_id', 'encomendas_compra'], FATURA: ['fatura_compra_id', 'faturas_compra'] },
  },
};

// Colunas com listas de ids no legado -> tabelas pivô. A coluna original fica como <coluna>_legado (texto).
export const PIVOS = [
  { tabela: 'vendas_estadias_hotel', de: ['vendas', 'estadias_hotel_ids'], a: ['estadias_hotel', 'estadia_hotel_id'], chave: 'venda_id' },
  { tabela: 'vendas_documentos_relacionados', de: ['vendas', 'documentos_relacionados'], a: ['vendas', 'venda_relacionada_id'], chave: 'venda_id' },
  { tabela: 'pedidos_lavandaria_faturas', de: ['pedidos_lavandaria', 'faturas_ids'], a: ['vendas', 'venda_id'], chave: 'pedido_lavandaria_id' },
  { tabela: 'contratos_fornecedores_encomendas', de: ['contratos_fornecedores', 'encomendas_ids'], a: ['encomendas_compra', 'encomenda_compra_id'], chave: 'contrato_fornecedor_id' },
];

// Colunas novas do desenho (não existem no legado).
export const COLUNAS_NOVAS = {
  // ADR-055: fotografia do Relatório e Contas concluído (legado: annual_reports.snapshot/completed_em/completed_por, js/relatorio_contas.js:1179-1181)
  relatorios_anuais_contas: [
    ['fotografia', 'jsonb', null, 'Números do relatório no momento da conclusão'],
    ['concluido_em', 'timestamptz', null, 'Data/hora da conclusão'],
    ['concluido_por', 'varchar(100)', null, 'Utilizador que concluiu'],
  ],
  // ADR-016: estorno com rasto, linha a linha
  lancamentos_contabeis: [
    ['estorno_de_id', 'bigint', 'lancamentos_contabeis', 'Linha original que esta linha estorna'],
    ['estornado_por_id', 'bigint', 'lancamentos_contabeis', 'Linha de estorno que anulou esta linha'],
    ['estornado_em', 'timestamptz', null, 'Data/hora do estorno'],
  ],
  // ADR-005: sessões POS antigas só existiam no localStorage
  vendas: [
    ['sessao_pos_legado_codigo', 'varchar(50)', null, "Código 'POS_SESS_<epoch>' do legado (sem FK)"],
    // ADR-029: ligação explícita à contabilização (o legado só ligava por numero_documento, ambíguo entre módulos)
    ['numero_lan_contabilizacao', 'varchar(30)', null, 'N.º do lançamento contabilístico gerado pela contabilização do documento'],
    // ADR-030: assinatura SAF-T(AO) calculada na emissão (o legado gerava um hash falso na exportação)
    ['saft_hash', 'text', null, 'Assinatura RSA-SHA1 (base64) de "data;data entrada;n.º;total bruto;hash anterior"'],
    ['saft_hash_controlo', 'varchar(10)', null, 'Versão da chave usada na assinatura (HashControl); 0 = não assinado'],
    // ADR-043: stock nas vendas (FT/FR/GR baixam; GD e NC de devolução repõem)
    ['armazem_id', 'bigint', 'armazens', 'Armazém de onde sai (ou para onde volta) a mercadoria'],
    ['devolucao_mercadoria', 'boolean', null, 'NC: a mercadoria volta ao stock (as NC de correcção de preço não mexem no stock)'],
    // ADR-068 (decisão 10): arredondamento AGT do POS separado do desconto comercial
    ['arredondamento_agt', 'numeric(15,2)', null, 'POS: arredondamento AGT (valor cobrado − desconto − total do documento), separado do desconto'],
  ],
  itens_venda: [
    ['custo_unitario_kz', 'numeric(18,6)', null, 'Custo médio da saída/entrada de stock da linha (base do CMV)'],
    ['quantidade_stock', 'numeric(12,3)', null, 'Quantidade que movimentou stock (0 numa FT gerada de uma GR)'],
    ['quantidade_devolvida', 'numeric(12,3)', null, 'GR: quantidade já devolvida por guias de devolução'],
    // ADR-068 (decisão 15): devolução (GD/NC) sobre stock negativo — diferença de valorização das unidades a descoberto
    ['acerto_cmv_kz', 'numeric(15,2)', null, 'GD/NC com devolução sobre stock negativo: acerto do CMV (custo da devolução − custo médio das unidades a descoberto)'],
  ],
  // ADR-029: recibos com rasto de anulação, série e ligação à factura-recibo que os originou
  recibos_venda: [
    ['estado', 'varchar(20)', null, 'EMITIDO ou ANULADO (nulo nos recibos do legado = EMITIDO)'],
    ['venda_origem_id', 'bigint', 'vendas', 'Factura-recibo que gerou automaticamente o recibo'],
    ['serie_faturacao_eletronica_id', 'bigint', 'series_faturacao_eletronica', 'Série de numeração do recibo'],
    ['numero_lan_contabilizacao', 'varchar(30)', null, 'N.º do lançamento contabilístico do recibo'],
    ['anulado_em', 'timestamptz', null, 'Data/hora da anulação'],
    ['motivo_anulacao', 'text', null, 'Motivo da anulação'],
    // ADR-068 (M-18): recibo de adiantamento de cliente (sem factura), alocado depois
    ['tipo_recibo', 'varchar(20)', null, 'NORMAL (nulo) ou ADIANTAMENTO (recibo sem factura, alocado depois)'],
  ],
  itens_recibo_venda: [
    ['numero_lan_contabilizacao', 'varchar(30)', null, 'Adiantamento: lançamento da alocação à factura (D adiantamentos / C cliente)'],
    ['data_alocacao', 'date', null, 'Adiantamento: data da alocação à factura'],
  ],
  // ADR-068 (decisão 18): a venda de um activo liquida IVA
  abates_vendas_ativos: [
    ['taxa_iva', 'numeric(5,2)', null, 'Venda: taxa de IVA liquidado (0, 5, 7 ou 14 %)'],
    ['valor_iva', 'numeric(15,2)', null, 'Venda: IVA liquidado (valor × taxa)'],
    ['conta_iva', 'varchar(20)', null, 'Venda: conta do IVA liquidado creditada'],
  ],
  // ADR-032 (Tesouraria): numeração, rasto de integração/anulação e ligação EXPLÍCITA ao documento liquidado
  // (o legado ligava só pelo texto doc_number; o "pago" das vendas contava até pagamentos por integrar)
  documentos_tesouraria: [
    ['numero_documento', 'varchar(50)', null, 'N.º "PAG|REC <série>/<n>" (a referência continua texto livre)'],
    ['numero_lan_contabilizacao', 'varchar(30)', null, 'N.º do lançamento da integração'],
    ['integrado_em', 'timestamptz', null, 'Data/hora da integração'], ['integrado_por', 'varchar(100)', null, 'Utilizador que integrou'],
    ['anulado_em', 'timestamptz', null, 'Data/hora da anulação'], ['motivo_anulacao', 'text', null, 'Motivo da anulação'],
  ],
  sessoes_caixa: [
    ['numeros_lan_contabilizacao', 'jsonb', null, 'Lançamentos da contabilização (um por data de movimento + diferença de fecho)'],
    ['fechado_por', 'varchar(100)', null, 'Utilizador que fechou'], ['contabilizado_em', 'timestamptz', null, 'Data/hora da contabilização'],
  ],
  movimentos_caixa: [
    ['venda_id', 'bigint', 'vendas', 'Factura de venda recebida por este movimento'],
    ['fatura_compra_id', 'bigint', 'faturas_compra', 'Factura de fornecedor paga por este movimento'],
  ],
  itens_documento_tesouraria: [
    ['venda_id', 'bigint', 'vendas', 'Factura de venda liquidada por esta linha'],
    ['fatura_compra_id', 'bigint', 'faturas_compra', 'Factura de fornecedor liquidada por esta linha'],
    ['valor_kz_documento', 'numeric(15,2)', null, 'Multi-moeda: valor da linha em Kz ao câmbio do documento (a diferença para o valor histórico é diferença de câmbio)'],
  ],
  // ADR-031 (Compras): numeração, rasto de anulação/contabilização e ligação linha a linha
  // (o legado casava encomenda/recepção/factura por product_id e perdia linhas repetidas)
  // ADR-036 (Salários): ciclo de vida com rasto e ligação ao lançamento (o legado não guardava resultados nem n.º)
  periodos_processamento_salarial: [
    ['fechado_em', 'timestamptz', null, 'Encerramento do cálculo (fotografia dos resultados)'], ['fechado_por', 'varchar(100)', null, 'Quem encerrou'],
    ['validado_em', 'timestamptz', null, 'Validação'], ['validado_por', 'varchar(100)', null, 'Quem validou'],
    ['numero_lan_contabilizacao', 'varchar(30)', null, 'N.º do lançamento da integração no diário SAL'],
    ['modo_calculo', 'varchar(10)', null, 'ATUAL (regras corrigidas) ou LEGADO (reprodução do motor antigo, períodos migrados)'],
  ],
  // ADR-037 (RH parte 1b): carta de pagamento = ordem bancária gravada de um período validado, paga por documento de tesouraria
  cartas_pagamento_bancario: [
    ['periodo_processamento_salarial_id', 'bigint', 'periodos_processamento_salarial', 'Processamento salarial pago por esta carta'],
    ['documento_tesouraria_id', 'bigint', 'documentos_tesouraria', 'Pagamento (PAG) gerado a partir da carta'],
    ['grupo', 'varchar(20)', null, 'COLABORADORES, AVENCADOS ou TODOS'],
    ['criado_por', 'varchar(100)', null, 'Quem emitiu a carta'],
  ],
  // ADR-038 (RH parte 2): o fecho guarda a configuração usada, para o recálculo ao lançar ser reprodutível
  fechos_mensais_assiduidade: [
    ['configuracao', 'jsonb', null, 'Configuração da assiduidade usada no apuramento (fotografia)'],
  ],
  // ADR-042 (Logística parte 1): movimento com sentido, valor e ligação ao documento (o legado só tinha texto em «referência»)
  movimentos_inventario: [
    ['sentido', 'varchar(1)', null, 'E (entrada) ou S (saída) — os ajustes e transferências também têm sentido'],
    ['valor', 'numeric(15,2)', null, 'Quantidade × custo unitário (Kz)'],
    ['custo_medio_apos', 'numeric(18,6)', null, 'Custo médio ponderado do produto depois do movimento'],
    ['documento_tipo', 'varchar(30)', null, 'Origem: RECECAO, VENDA, GUIA, TRANSFERENCIA, INVENTARIO, AJUSTE, MIGRACAO…'],
    ['documento_id', 'bigint', null, 'Id do documento de origem'],
    ['armazem_contraparte_id', 'bigint', 'armazens', 'Transferências: o outro armazém'],
    ['criado_por', 'varchar(100)', null, 'Quem registou'],
  ],
  armazens: [
    ['codigo', 'varchar(20)', null, 'Código do armazém'], ['predefinido', 'boolean', null, 'Armazém por omissão (o legado usava o primeiro)'],
  ],
  produtos: [
    ['custo_medio', 'numeric(18,6)', null, 'Custo médio ponderado (Kz), actualizado nas entradas de stock'],
    ['stock_minimo', 'numeric(12,3)', null, 'Stock mínimo (alerta de ruptura; o legado usava ≤ 5 fixo)'],
  ],
  sessoes_inventario: [
    ['aprovado_por', 'varchar(100)', null, 'Quem aprovou a regularização'], ['aprovado_em', 'timestamptz', null, 'Aprovação'],
    ['numero_lan_contabilizacao', 'varchar(30)', null, 'Lançamento da regularização (diário SQ)'],
    ['motivo_anulacao', 'text', null, 'Motivo da anulação (o legado apagava a sessão)'], ['iniciado_por', 'varchar(100)', null, 'Quem abriu a contagem'],
  ],
  linhas_sessao_inventario: [
    ['custo_unitario', 'numeric(18,6)', null, 'Custo usado na valorização da diferença'], ['valor_diferenca', 'numeric(15,2)', null, 'Diferença × custo (Kz)'],
  ],
  // ADR-035 (Compras parte 2): contratos com rasto de cancelamento; marcos ligados à factura (estado deixa de ser manual)
  contratos_fornecedores: [
    ['cancelado_em', 'timestamptz', null, 'Data/hora do cancelamento'], ['motivo_cancelamento', 'text', null, 'Motivo do cancelamento'],
  ],
  marcos_contrato_fornecedor: [['fatura_compra_id', 'bigint', 'faturas_compra', 'Factura do fornecedor que factura o marco']],
  pedidos_compra: [
    ['numero_pedido', 'varchar(50)', null, 'N.º do pedido "PC <série>/<n>" (o legado só mostrava o id)'],
    ['anulado_em', 'timestamptz', null, 'Data/hora da anulação'], ['motivo_anulacao', 'text', null, 'Motivo da anulação'],
  ],
  cotacoes_compra: [['numero_proposta', 'varchar(50)', null, 'N.º interno "PP <série>/<n>" (a referência é a do fornecedor)']],
  encomendas_compra: [
    ['data_entrega_prevista', 'date', null, 'Prazo de entrega da proposta adjudicada'],
    ['anulado_em', 'timestamptz', null, 'Data/hora da anulação'], ['motivo_anulacao', 'text', null, 'Motivo da anulação'],
  ],
  rececoes_compra: [
    ['numero_rececao', 'varchar(50)', null, 'N.º interno "RCP <série>/<n>" (numero_entrega é a guia do fornecedor)'],
    ['numero_lan_contabilizacao', 'varchar(30)', null, 'N.º do lançamento da validação (entrada em armazém)'],
    ['validado_em', 'timestamptz', null, 'Data/hora da validação no armazém'], ['validado_por', 'varchar(100)', null, 'Utilizador que validou'],
    ['anulado_em', 'timestamptz', null, 'Data/hora da anulação'], ['motivo_anulacao', 'text', null, 'Motivo da anulação'],
  ],
  faturas_compra: [
    ['data_vencimento', 'date', null, 'Data de vencimento'],
    ['numero_lan_contabilizacao', 'varchar(30)', null, 'N.º do lançamento contabilístico da factura'],
    ['anulado_em', 'timestamptz', null, 'Data/hora da anulação'], ['motivo_anulacao', 'text', null, 'Motivo da anulação'],
  ],
  itens_compra: [
    ['item_encomenda_id', 'bigint', 'itens_compra', 'Linha da encomenda que esta linha de factura factura'],
    ['valor_recebido_kz', 'numeric(15,2)', null, 'Encomenda: valor em Kz acumulado das quantidades recebidas'],
    ['valor_transitoria_kz', 'numeric(15,2)', null, 'Factura: valor debitado na conta transitória de compras (328)'],
    ['imposto_kz', 'numeric(15,2)', null, 'IVA da linha em Kz (o legado guardava tax_kz nas linhas embutidas da factura)'],
    ['imposto_moeda', 'numeric(15,2)', null, 'IVA da linha na moeda do documento'],
  ],
  itens_guia_saida: [['item_compra_id', 'bigint', 'itens_compra', 'Recepção: linha da encomenda recebida']],
  guias_saida: [
    ['observacoes', 'text', null, 'Observações'], ['criado_por', 'varchar(100)', null, 'Quem emitiu'],
    ['numero_lan_contabilizacao', 'varchar(30)', null, 'Lançamento do consumo (D custo / C inventário)'],
    ['anulado_em', 'timestamptz', null, 'Anulação (o legado apagava a guia)'], ['motivo_anulacao', 'text', null, 'Motivo da anulação'],
  ],
  // ADR-005: org_type_id = -1 significa "Avençado"
  mapeamentos_contabeis_rh: [['avencado', 'boolean', null, "Coluna 'Avençado' do mapeamento (legado: org_type_id = -1)"]],
  mapeamentos_contabeis_sistema_rh: [['avencado', 'boolean', null, "Coluna 'Avençado' do mapeamento (legado: org_type_id = -1)"]],
};

// CHECKs de negócio (além dos domínios das enumerações normalizadas, gerados automaticamente).
// [tabela, nome, expressão SQL, predicado JS de verificação sobre a linha já mapeada]
export const CHECKS = [
  ['lancamentos_contabeis', 'ck_lancamentos_tipo_dc', "tipo_dc IN ('D','C')", (l) => ['D', 'C'].includes(l.tipo_dc)],
  ['lancamentos_contabeis', 'ck_lancamentos_valor', 'valor >= 0', (l) => l.valor === null || Number(l.valor) >= 0],
  ['itens_documento_tesouraria', 'ck_itens_tesouraria_tipo_dc', "tipo_dc IN ('D','C')", (l) => ['D', 'C'].includes(l.tipo_dc)],
  ['linhas_extrato_bancario', 'ck_extrato_tipo_dc', "tipo_dc IN ('D','C')", (l) => ['D', 'C'].includes(l.tipo_dc)],
  ['lancamentos_estornados', 'ck_estornados_tipo_dc', "tipo_dc IN ('D','C')", (l) => ['D', 'C'].includes(l.tipo_dc)],
  ['plano_contas', 'ck_plano_contas_tipo', "tipo IN ('M','T')", (l) => l.tipo === null || ['M', 'T'].includes(l.tipo)],
  ['contas_mesa_pos', 'ck_contas_mesa_pos_estado', "estado IN ('ABERTA','FECHADA','ANULADA')", (l) => ['ABERTA', 'FECHADA', 'ANULADA'].includes(l.estado)],
];

// Tabelas novas do desenho (ETL / infraestrutura).
export const TABELAS_NOVAS = {
  resultados_folha_salarial: {
    modulo: 'RH', model: 'ResultadoFolhaSalarial',
    colunas: [
      ['periodo_processamento_salarial_id', 'bigint', 'Período'], ['colaborador_id', 'bigint', 'Colaborador'],
      ['tipo_organizacao_id', 'bigint', 'Tipo de organização (mapeamento contabilístico)'], ['unidade_negocio_id', 'bigint'], ['centro_custo_id', 'bigint'],
      ['avencado', 'boolean'], ['reformado', 'boolean'], ['dias_contrato', 'numeric(6,2)'], ['dias_trabalhados', 'numeric(6,2)'],
      ['bruto', 'numeric(15,2)'], ['base_inss', 'numeric(15,2)'], ['inss_trabalhador', 'numeric(15,2)'], ['inss_patronal', 'numeric(15,2)'],
      ['isencoes', 'numeric(15,2)', 'Isenções de IRT (subsídios até 30 000 Kz) e faltas'], ['base_irt', 'numeric(15,2)'], ['irt', 'numeric(15,2)'],
      ['descontos', 'numeric(15,2)'], ['liquido', 'numeric(15,2)'], ['rubricas', 'jsonb', 'Detalhe por rubrica calculada'],
      ['avisos', 'jsonb'], ['modo_calculo', 'varchar(10)', 'ATUAL ou LEGADO'],
    ],
    indices: [['colaborador_id']],
  },
  configuracoes_contabeis_tesouraria: {
    modulo: 'Tesouraria', model: 'ConfigContabilTesouraria',
    colunas: [['chave', 'varchar(150)', 'Chave da conta (ver ServicoConfigTesouraria::CHAVES)'], ['codigo_conta', 'varchar(20)', 'Conta do plano']],
  },
  configuracoes_contabeis_logistica: {
    modulo: 'Logistica', model: 'ConfigContabilLogistica',
    colunas: [['chave', 'varchar(150)', 'Chave da conta (ver ServicoConfigLogistica::CHAVES)'], ['codigo_conta', 'varchar(20)', 'Conta do plano']],
  },
  configuracoes_contabeis_compras: {
    modulo: 'Compras', model: 'ConfigContabilCompra',
    colunas: [['chave', 'varchar(150)', 'Chave da conta (ver ServicoConfigCompras::CHAVES)'], ['codigo_conta', 'varchar(20)', 'Conta do plano']],
  },
  ocorrencias_migracao: {
    modulo: 'Sistema', model: 'OcorrenciaMigracao', global: true,
    colunas: [
      ['execucao', 'varchar(40)', 'Identificador da execução do ETL'],
      ['tabela_legado', 'varchar(100)'], ['id_legado', 'varchar(100)'], ['tabela_destino', 'varchar(100)'],
      ['coluna', 'varchar(100)'], ['regra', 'varchar(40)', 'QUARENTENA, ANULAR_FK, DIARIO_RECUPERACAO, CODIGO_LEGADO, SEMANTICA, NORMALIZACAO, ...'],
      ['gravidade', 'varchar(10)', 'INFO, AVISO, ERRO'], ['valor_original', 'text'], ['valor_final', 'text'],
      ['descricao', 'text'], ['dados_originais', 'jsonb'], ['empresa_legado_id', 'bigint'],
    ],
    indices: [['execucao', 'tabela_legado'], ['regra'], ['empresa_legado_id']],
  },
  quarentena_migracao: {
    modulo: 'Sistema', model: 'QuarentenaMigracao', global: true,
    colunas: [
      ['execucao', 'varchar(40)'], ['tabela_legado', 'varchar(100)'], ['id_legado', 'varchar(100)'],
      ['motivo', 'text'], ['dados_originais', 'jsonb'], ['empresa_legado_id', 'bigint'],
      ['resolvido_em', 'timestamptz'], ['resolvido_por', 'varchar(100)'], ['resolucao', 'text'],
    ],
    indices: [['tabela_legado', 'id_legado']],
  },

  // ── Ronda 2 (ADR-068) ────────────────────────────────────────────────────────────────────────────────────────
  // Coluna: [nome, tipo, nota, { fk: 'tabela', padrao: valor por omissão, nulo: false }] (4.º elemento opcional).
  // M-15 (decisão 14): mesas do POS restaurante e contas por mesa no servidor (o legado usava o localStorage)
  mesas_pos: {
    modulo: 'POS', model: 'MesaPOS',
    colunas: [
      ['terminal_pos_id', 'bigint', 'Terminal RESTAURANTE', { fk: 'terminais_pos', nulo: false }],
      ['nome', 'varchar(60)', 'Nome da mesa (ex.: Mesa 1, Terraço 2, Take-Away)', { nulo: false }],
      ['ordem', 'integer', 'Ordem de apresentação', { padrao: 0 }], ['ativo', 'boolean', null, { padrao: true }],
    ],
  },
  contas_mesa_pos: {
    modulo: 'POS', model: 'ContaMesaPOS',
    colunas: [
      ['mesa_pos_id', 'bigint', 'Mesa', { fk: 'mesas_pos', nulo: false }],
      ['terminal_pos_id', 'bigint', 'Terminal', { fk: 'terminais_pos', nulo: false }],
      ['sessao_pos_id', 'bigint', 'Sessão em que foi cobrada', { fk: 'sessoes_pos' }],
      ['estado', 'varchar(12)', 'ABERTA, FECHADA (cobrada) ou ANULADA (libertada sem venda)', { padrao: 'ABERTA', nulo: false }],
      ['linhas', 'jsonb', '[{produto_id, quantidade, preco_unitario?}] — o preço só quando alterado', { nulo: false }],
      ['percentagem_desconto', 'numeric(9,4)', null, { padrao: 0 }],
      ['cliente_id', 'bigint', 'Cliente', { fk: 'terceiros' }],
      ['observacoes', 'text'], ['operador', 'varchar(150)', 'Quem abriu/actualizou a conta'],
      ['versao', 'integer', 'Bloqueio optimista entre postos', { padrao: 1 }],
      ['venda_id', 'bigint', 'Factura-recibo emitida ao cobrar', { fk: 'vendas' }],
      ['aberta_em', 'timestamptz'], ['fechada_em', 'timestamptz'],
    ],
  },
  // Decisões 5 e 7: configuração de RH por empresa (chave → valor JSON; ver ServicoConfiguracaoRH::PADRAO)
  configuracoes_rh: {
    modulo: 'RH', model: 'ConfiguracaoRH',
    colunas: [
      ['chave', 'varchar(100)', 'Chave (ver ServicoConfiguracaoRH::PADRAO)', { nulo: false }],
      ['valor', 'jsonb', 'Valor'], ['atualizado_por', 'varchar(100)', 'Quem alterou'],
    ],
  },
  // M-08: rascunhos da reconciliação bancária (o legado guardava o trabalho em curso no localStorage)
  rascunhos_reconciliacao: {
    modulo: 'Tesouraria', model: 'RascunhoReconciliacao',
    colunas: [
      ['codigo_conta', 'varchar(20)', 'Conta bancária (43) reconciliada', { nulo: false }],
      ['periodo_inicio', 'date', 'Início do período trabalhado'], ['periodo_fim', 'date', 'Fim do período trabalhado'],
      ['grupos', 'jsonb', 'Grupos emparelhados por confirmar: [{extrato: [ids], lancamentos: [ids]}]', { nulo: false }],
      ['observacoes', 'text', 'Notas do autor'], ['criado_por', 'varchar(100)', 'Utilizador que gravou o rascunho'],
    ],
  },
  // Câmbios do BAI automáticos: pré-visualização por validar e registo das obtenções (globais, como taxas_cambio)
  cambios_bai_pendentes: {
    modulo: 'Sistema', model: 'CambioBAIPendente', global: true,
    colunas: [
      ['execucao_cambio_bai_id', 'bigint', 'Obtenção que gerou a linha'],
      ['data_cotacao', 'date', 'Data a que o câmbio fica registado (dia da obtenção)'],
      ['codigo_moeda', 'varchar(3)'], ['nome_moeda', 'varchar(255)'],
      ['taxa_compra', 'numeric(18,6)', 'Divisas: compra'], ['taxa_venda', 'numeric(18,6)', 'Divisas: venda'],
      ['taxa_media', 'numeric(18,6)', 'Média (compra + venda) / 2 — valor a gravar'],
      ['ultima_taxa', 'numeric(18,6)', 'Último câmbio registado no momento da obtenção'], ['ultima_data', 'date'], ['ultima_fonte', 'varchar(50)'],
      ['variacao', 'numeric(9,2)', 'Variação % face ao último registado'], ['alerta', 'boolean', 'Variação acima do limiar (5 %)'],
      ['estado', 'varchar(20)', 'PENDENTE, VALIDADO, REJEITADO ou SUBSTITUIDO'],
      ['decidido_por_id', 'bigint', 'Utilizador que validou/rejeitou'], ['decidido_por', 'varchar(100)'], ['decidido_em', 'timestamptz'],
      ['motivo', 'text', 'Motivo da rejeição / resultado da validação'],
    ],
    indices: [['estado'], ['execucao_cambio_bai_id']],
  },
  execucoes_cambios_bai: {
    modulo: 'Sistema', model: 'ExecucaoCambioBAI', global: true,
    colunas: [
      ['origem', 'varchar(20)', 'AGENDADA ou MANUAL'], ['estado', 'varchar(20)', 'SUCESSO ou FALHA'],
      ['codigo_erro', 'varchar(50)'], ['mensagem', 'text'], ['moedas', 'integer', 'Moedas deixadas por validar'],
      ['utilizador_id', 'bigint', 'Quem pediu (obtenção manual)'], ['iniciado_em', 'timestamptz'], ['concluido_em', 'timestamptz'],
    ],
    indices: [['iniciado_em']],
  },
  // Power BI (decisão 25, M-02): tokens de leitura do feed OData; guarda-se só o hash SHA-256
  tokens_bi: {
    modulo: 'Sistema', model: 'TokenBI',
    colunas: [
      ['nome', 'varchar(255)', 'Descrição (ex.: Power BI da Direcção Financeira)'],
      ['prefixo', 'varchar(20)', 'Início do token, para o identificar sem o revelar'],
      ['hash_token', 'varchar(64)', 'SHA-256 do token (o valor nunca é guardado)'],
      ['conjuntos', 'jsonb', 'Conjuntos permitidos (NULL = todos os da lista branca)'],
      ['criado_por_id', 'bigint'], ['criado_por', 'varchar(100)'], ['expira_em', 'timestamptz'], ['ultimo_uso_em', 'timestamptz'],
      ['utilizacoes', 'bigint'], ['revogado_em', 'timestamptz'], ['revogado_por', 'varchar(100)'],
    ],
  },
  // Assistente IA (decisão 26, M-03): metadados de cada pedido (auditoria e custo) — nunca o texto nem o ficheiro
  utilizacoes_assistente_ia: {
    modulo: 'Contabilidade', model: 'UtilizacaoAssistenteIA',
    colunas: [
      ['utilizador_id', 'bigint'], ['nome_utilizador', 'varchar(100)'],
      ['motor', 'varchar(20)', 'IA (Claude) ou REGRAS (motor interno)'], ['modelo', 'varchar(60)'],
      ['estado', 'varchar(20)', 'SUCESSO, SEM_PROPOSTA, RECUSA ou FALHA'], ['codigo_erro', 'varchar(60)'],
      ['propostas', 'integer'], ['caracteres_texto', 'integer'], ['tipo_ficheiro', 'varchar(60)'], ['tamanho_ficheiro_kb', 'integer'],
      ['tokens_entrada', 'integer'], ['tokens_saida', 'integer'], ['custo_estimado_usd', 'numeric(12,6)'], ['duracao_ms', 'integer'],
    ],
  },
  // M-19: preferências do utilizador no servidor (favoritos, ordem dos módulos, visões do cubo, interface)
  preferencias_utilizador: {
    modulo: 'Sistema', model: 'PreferenciaUtilizador', global: true,
    colunas: [
      ['utilizador_id', 'bigint', 'Utilizador', { fk: 'utilizadores', nulo: false }],
      ['empresa_id', 'bigint', 'NULL = válida em todas as empresas'],
      ['tipo', 'varchar(40)', 'favoritos, ordem_modulos, visoes_cubo, interface'], ['nome', 'varchar(150)'], ['valor', 'jsonb'],
    ],
  },
};
