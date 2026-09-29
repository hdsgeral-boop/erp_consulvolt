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
  // Numeração única obrigatória só nos documentos fiscais (AGT). Orçamentos/proformas do legado repetem números
  // (o legado tratava "Orçamento" e "Orcamento" como tipos distintos) -> relatório de validação.
  ['vendas', ['empresa_id', 'tipo_documento', 'numero_documento'], "tipo_documento IN ('FT','FR','NC','ND')"],
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
  ['configuracoes_faturacao_eletronica', ['empresa_id']],
  // Séries de numeração (AGT e não fiscais): código único por empresa, tipo e ano
  ['series_faturacao_eletronica', ['empresa_id', 'tipo', 'ano', 'codigo']],
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
];

// FKs em cascata (linhas/itens apagados com o cabeçalho). Todas as outras: RESTRICT.
export const CASCATA = new Set([
  'itens_venda.venda_id', 'itens_recibo_venda.recibo_venda_id', 'itens_documento_tesouraria.documento_tesouraria_id',
  'linhas_sessao_inventario.sessao_inventario_id', 'linhas_folha_salarial.periodo_processamento_salarial_id',
  'movimentos_caixa.sessao_caixa_id', 'linhas_requisicao_projeto.requisicao_material_projeto_id',
  'linhas_revisao_projeto.revisao_mensal_projeto_id', 'linhas_orcamento.orcamento_anual_id',
  'membros_equipa_projeto.equipa_projeto_id', 'membros_consolidacao.grupo_consolidacao_id',
  'coordenadas_bancarias_colaboradores.colaborador_id', 'dependentes_colaboradores.colaborador_id',
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
  ],
  // ADR-029: recibos com rasto de anulação, série e ligação à factura-recibo que os originou
  recibos_venda: [
    ['estado', 'varchar(20)', null, 'EMITIDO ou ANULADO (nulo nos recibos do legado = EMITIDO)'],
    ['venda_origem_id', 'bigint', 'vendas', 'Factura-recibo que gerou automaticamente o recibo'],
    ['serie_faturacao_eletronica_id', 'bigint', 'series_faturacao_eletronica', 'Série de numeração do recibo'],
    ['numero_lan_contabilizacao', 'varchar(30)', null, 'N.º do lançamento contabilístico do recibo'],
    ['anulado_em', 'timestamptz', null, 'Data/hora da anulação'],
    ['motivo_anulacao', 'text', null, 'Motivo da anulação'],
  ],
  // ADR-031 (Compras): numeração, rasto de anulação/contabilização e ligação linha a linha
  // (o legado casava encomenda/recepção/factura por product_id e perdia linhas repetidas)
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
  produtos: [['custo_medio', 'numeric(18,6)', null, 'Custo médio ponderado (Kz), actualizado nas entradas de stock']],
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
];

// Tabelas novas do desenho (ETL / infraestrutura).
export const TABELAS_NOVAS = {
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
};
