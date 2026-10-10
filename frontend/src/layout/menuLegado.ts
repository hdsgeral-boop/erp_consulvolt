/**
 * Estrutura do menu lateral do sistema anterior (payroll_system_web/index_arrumado.html, `.sidebar-nav`): itens gerais soltos
 * no topo, grupo «Menu Geral» com os módulos (e sub-grupos) pela ordem e com os rótulos EXACTOS do legado, e grupo «Sistema»
 * com as configurações. Ícones: nomes Font Awesome 5 do legado (desenhados em SVG por IconeFa).
 *
 * O menu mostra só o que GET /sistema/menu devolve (permissões); esta estrutura apenas ordena, agrupa e dá os rótulos.
 * Regras para os ecrãs do catálogo que não estão aqui (exclusivos do sistema novo, ex.: Hotelaria):
 *   - entram no fim do grupo «casa» do módulo (CASA_MODULO), com o nome do catálogo;
 *   - um módulo inteiro desconhecido entra no fim do «Menu Geral», com o nome do catálogo;
 *   - ESCONDIDOS: ecrãs que no legado não são entradas de menu (separadores/mapas); continuam acessíveis pela rota, pela
 *     página de entrada (ex.: Relatórios e Recibos → mapas), pela pesquisa Ctrl+K e pelos favoritos.
 */

export interface EcraLegado {
  tipo: 'ecra';
  modulo: string;
  ecra: string;
  rotulo: string;
  icone?: string;
  /** query string fixa (sem dados pessoais), ex.: «tipo=PAGAMENTO» — o mesmo ecrã aberto já filtrado */
  query?: string;
  /** item grande do topo (Início, Dashboard Global, …) */
  topo?: boolean;
}

export interface GrupoLegado {
  tipo: 'grupo';
  chave: string;
  rotulo: string;
  icone?: string;
  /** cor do ícone (ex.: «Menu Geral» azul) */
  cor?: string;
  filhos: NoLegado[];
}

export type NoLegado = EcraLegado | GrupoLegado;

const e = (modulo: string, ecra: string, rotulo: string, icone?: string, extra: Partial<EcraLegado> = {}): EcraLegado => ({ tipo: 'ecra', modulo, ecra, rotulo, icone, ...extra });
const g = (chave: string, rotulo: string, icone: string | undefined, filhos: NoLegado[], cor?: string): GrupoLegado => ({ tipo: 'grupo', chave: `grupo:${chave}`, rotulo, icone, cor, filhos });

/** Itens soltos no topo (grandes, como no legado). O Início é fixo e não depende do menu. */
export const TOPO_LEGADO: EcraLegado[] = [
  e('geral', 'dashboard', 'Dashboard Global', 'chart-line', { topo: true }),
  e('geral', 'relatorios_gestao', 'Relatórios de Gestão', 'chart-pie', { topo: true }),
  e('geral', 'fluxo_processos', 'Fluxo de Processos', 'project-diagram', { topo: true }),
];

/** Grupo «Menu Geral» (módulos pela ordem do legado). */
export const MENU_GERAL_LEGADO: GrupoLegado = g('menu-geral', 'Menu Geral', 'layer-group', [
  g('logistica', 'Logística', 'warehouse', [
    g('logistica-armazem', 'Gestão de Armazém', 'boxes', [
      e('stock', 'armazem_stock', 'Níveis de Stock'),
      e('stock', 'armazem_rececoes', 'Validar Entradas'),
      e('stock', 'armazem_guias', 'Guias de Saída'),
      e('stock', 'armazem_movimentos', 'Histórico'),
      e('stock', 'armazem_armazens', 'Config. Armazém'),
    ]),
    e('stock', 'pos_armazem', 'POS de Armazém', 'cash-register'),
    g('logistica-inventario', 'Inventário de Stock', 'clipboard-check', [
      e('stock', 'inventario_sessoes', 'Sessões de Inventário', 'tasks'),
      e('stock', 'inventario_contagem', 'Efetuar Contagem', 'list-ol'),
      e('stock', 'inventario_revisao', 'Revisão e Regularização', 'balance-scale'),
    ]),
  ]),
  g('vendas', 'Vendas', 'shopping-cart', [
    g('vendas-pos', 'Ponto de Venda (POS)', 'cash-register', [
      e('pos', 'pos', 'Frente de Caixa', 'desktop'),
      e('pos', 'pos_relatorios', 'Relatórios POS', 'chart-pie'),
      e('pos', 'pos_terminais', 'Terminais POS', 'laptop-house'),
      e('pos', 'pos_config_print', 'Config. Impressão', 'print'),
    ]),
    e('vendas', 'vendas_produtos', 'Produtos/Serviços', 'box'),
    e('vendas', 'vendas_faturacao', 'Faturação', 'file-invoice-dollar'),
    e('vendas', 'vendas_relatorios', 'Relatórios Vendas', 'chart-line'),
  ]),
  g('compras', 'Compras', 'truck-loading', [
    e('compras', 'compras_pedidos', 'Pedidos Internos', 'clipboard-list'),
    e('compras', 'compras_prospeccao', 'Prospeção', 'search-dollar'),
    e('compras', 'compras_encomendas', 'Encomendas', 'shopping-cart'),
    e('compras', 'compras_rececoes', 'Receções', 'dolly'),
    e('compras', 'compras_faturacao', 'Faturação', 'file-invoice-dollar'),
    e('compras', 'compras_fornecedores', 'Fornecedores', 'users'),
    e('compras', 'compras_encomendas_clientes', 'Encomendas Clientes', 'file-export'),
    e('compras', 'compras_contratos', 'Contratos', 'file-contract'),
  ]),
  g('rh', 'Salários e RH', 'users', [
    e('rh', 'colaboradores', 'Colaboradores', 'user-friends'),
    e('rh', 'rh_portal', 'Portal do Colaborador', 'id-badge'),
    e('rh', 'rh_portal_gestao', 'Pedidos do Portal', 'inbox'),
    e('rh', 'rh_ferias', 'Programa de Férias', 'umbrella-beach'),
    e('rh', 'rh_assiduidade', 'Efectividade (Biometria)', 'fingerprint'),
    e('rh', 'rh_produtividade', 'Produtividade', 'chart-line'),
    e('rh', 'rh_avaliacao', 'Avaliação de Desempenho', 'star-half-alt'),
    e('rh', 'rh_avaliacao_ciclo', 'Acompanhamento 360º', 'tasks'),
    e('rh', 'rh_avaliacao_config', 'Configuração da Avaliação', 'sliders-h'),
    e('rh', 'contratos', 'Contratos', 'file-contract'),
    e('rh', 'funcoes', 'Funções', 'briefcase'),
    e('rh', 'infotipos', 'Infotipos', 'tags'),
    e('rh', 'calcular', 'Calcular', 'calculator'),
    e('rh', 'processamento', 'Processamentos', 'file-invoice-dollar'),
    e('rh', 'bancario', 'Coordenadas Bancárias', 'university'),
    e('rh', 'relatorios', 'Relatórios e Recibos', 'chart-pie'),
  ]),
  g('teso', 'Tesouraria', 'university', [
    g('teso-operacoes', 'Operações', 'money-bill-wave', [
      e('teso', 'teso_gestao_pagamentos', 'Pagamentos', undefined, { query: 'tipo=PAGAMENTO' }),
      e('teso', 'teso_gestao_pagamentos', 'Recebimentos', undefined, { query: 'tipo=RECEBIMENTO' }),
      e('teso', 'teso_folha_caixa', 'Folha de Caixa'),
    ]),
    g('teso-contabil', 'Contábil', 'file-invoice-dollar', [
      e('teso', 'teso_contab_integracao', 'Integração'),
      e('teso', 'teso_contab_historico', 'Anulação'),
    ]),
    g('teso-gestao', 'Gestão', 'chart-pie', [
      e('teso', 'teso_gestao_mapas', 'Extratos', 'file-invoice'),
      e('teso', 'teso_gestao_conciliacao', 'Reconciliação', 'balance-scale'),
      e('teso', 'teso_meios_pagamento', 'Meios de Pagamento', 'credit-card'),
      e('teso', 'teso_gestao_conferencia', 'Conferência de Caixa', 'cash-register'),
    ]),
  ]),
  g('contab', 'Contabilidade', 'book', [
    e('contab', 'lancamentos', 'Lançamentos (Diários)', 'book-open'),
    e('acrescimos', 'ad_registos', 'Acréscimos e Diferimentos', 'exchange-alt'),
    e('acrescimos', 'ad_propostas', 'Acrésc./Difer. — Proposta Mensal', 'calendar-check'),
    e('acrescimos', 'ad_recolher', 'Acrésc./Difer. — Recolher Documentos', 'inbox'),
    e('contab', 'encerramento', 'Encerramento', 'door-closed'),
    e('contab', 'relatorios_contabeis', 'Mapas e Relatórios', 'chart-line'),
    e('contab', 'relatorio_contas', 'Relatório e Contas', 'file-contract'),
    e('contab', 'contab_rotinas', 'Rotinas (inclui Imposto de Selo)', 'magic'),
    e('contab', 'consolidacao', 'Consolidação de Empresas', 'sitemap'),
    e('contab', 'tabelas_aux', 'Terceiros e Tabelas', 'address-book'),
    e('contab', 'contabilidade', 'Mapeamento Contábil', 'project-diagram'),
  ]),
  g('crm', 'CRM', 'handshake', [
    e('crm', 'crm_pipeline', 'Pipeline de Vendas', 'columns'),
    e('crm', 'crm_agenda', 'Agenda Comercial', 'calendar-check'),
    e('crm', 'crm_contas', 'Contas e Contactos', 'building'),
    e('crm', 'crm_previsao', 'Previsão e Indicadores', 'chart-line'),
    e('crm', 'crm_campanhas', 'Campanhas de Email', 'mail-bulk'),
    e('crm', 'crm_config', 'Configuração do CRM', 'sliders-h'),
  ]),
  g('estrutura', 'Estrutura Orgânica', 'sitemap', [
    e('estrutura', 'est_estrutura', 'Unidades e Cargos', 'building'),
    e('estrutura', 'est_organigrama', 'Organigrama', 'sitemap'),
    e('estrutura', 'est_mapa', 'Mapa de Pessoal', 'table'),
  ]),
  g('orcamento', 'Gestão Orçamental', 'file-invoice-dollar', [
    e('orcamento', 'orc_orcamentos', 'Orçamentos', 'table'),
    e('orcamento', 'orc_controlo', 'Controlo Orçamental', 'balance-scale'),
    e('orcamento', 'orc_previsoes', 'Previsões (12 meses)', 'chart-line'),
    e('orcamento', 'orc_cenarios', 'Cenários (what-if)', 'random'),
    e('orcamento', 'orc_alertas', 'Alertas e Aprovações', 'bell'),
    e('orcamento', 'orc_rubricas', 'Rubricas Orçamentais', 'tags'),
  ]),
  g('projectos', 'Projectos & Obras', 'hard-hat', [
    e('projectos', 'projectos_carteira', 'Carteira de Projectos', 'briefcase'),
    e('projectos', 'projectos_extracto', 'Extracto Analítico', 'chart-line'),
    e('projectos', 'projectos_gantt', 'Gantt Global', 'stream'),
  ]),
  g('activos', 'Activos', 'couch', [
    e('activos', 'activos', 'Cadastro e Gestão', 'list'),
    e('activos', 'activos_categorias', 'Categorias', 'tags'),
    e('activos', 'activos_manutencao', 'Manutenções', 'tools'),
    e('activos', 'activos_amortizacoes', 'Amortizações', 'percent'),
    e('activos', 'activos_abates', 'Abates e Vendas', 'trash-alt'),
    e('activos', 'activos_pendentes', 'Aquisições Pendentes', 'hourglass-start'),
    e('activos', 'activos_mapa', 'Mapa das Amortizações e Integrações', 'table'),
  ]),
], '#3b82f6');

/** Grupo «Sistema» (configurações; «Migração de Dados» fica fora do sub-grupo, como no legado). */
export const SISTEMA_LEGADO: GrupoLegado = g('sistema', 'Sistema', 'cogs', [
  g('sistema-config', 'Configurações', 'cog', [
    e('config', 'config_geral', 'Geral / Backup', 'save'),
    e('config', 'config_empresas', 'Gestão de Empresas', 'building'),
    e('config', 'config_plano', 'Plano de Contas', 'list-alt'),
    e('config', 'config_moedas', 'Moedas e Câmbios', 'coins'),
    e('config', 'config_utilizadores', 'Utilizadores', 'users'),
    e('config', 'config_perfis', 'Perfis e Permissões', 'user-shield'),
    e('config', 'config_logs', 'Logs de Navegação', 'history'),
    e('config', 'config_manutencao', 'Manutenção de dados', 'shield-alt'),
  ]),
  e('config', 'config_migracao', 'Migração de Dados', 'file-import'),
]);

/**
 * Ecrãs do catálogo que no legado NÃO são entradas de menu (separadores ou mapas abertos a partir de outro ecrã).
 * As rotas continuam a existir.
 */
export const ESCONDIDOS = new Set<string>([
  'rh/rh_rel_remuneracoes', 'rh/rh_rel_irt', 'rh/rh_rel_inss', 'rh/rh_rel_pagamentos', 'rh/rh_rel_banco', 'rh/rh_rel_recibos',
  'contab/contab_mapa_extrato', 'contab/contab_mapa_balancete', 'contab/contab_mapa_balanco', 'contab/contab_mapa_dr',
  'contab/contab_mapa_fluxo', 'contab/contab_mapa_evolucao',
  'contab/imposto_selo',
]);

/** Grupo onde entram os ecrãs de cada módulo que não estão na estrutura (exclusivos do sistema novo). 'topo' = itens soltos. */
export const CASA_MODULO: Readonly<Record<string, string>> = {
  geral: 'topo',
  stock: 'grupo:logistica',
  pos: 'grupo:vendas-pos',
  vendas: 'grupo:vendas',
  compras: 'grupo:compras',
  rh: 'grupo:rh',
  teso: 'grupo:teso',
  contab: 'grupo:contab',
  acrescimos: 'grupo:contab',
  crm: 'grupo:crm',
  estrutura: 'grupo:estrutura',
  orcamento: 'grupo:orcamento',
  projectos: 'grupo:projectos',
  activos: 'grupo:activos',
  config: 'grupo:sistema-config',
};

/** Nome curto do módulo, como no menu do legado (pesquisa Ctrl+K, favoritos). */
export const NOME_MODULO_LEGADO: Readonly<Record<string, string>> = {
  geral: 'Geral',
  stock: 'Logística',
  pos: 'Ponto de Venda (POS)',
  vendas: 'Vendas',
  compras: 'Compras',
  rh: 'Salários e RH',
  teso: 'Tesouraria',
  contab: 'Contabilidade',
  acrescimos: 'Contabilidade',
  crm: 'CRM',
  estrutura: 'Estrutura Orgânica',
  orcamento: 'Gestão Orçamental',
  projectos: 'Projectos & Obras',
  activos: 'Activos',
  config: 'Sistema',
};

/** Rótulos para a pesquisa e os favoritos quando o ecrã tem mais de uma entrada no menu (ex.: Pagamentos / Recebimentos). */
const ROTULOS_EXTRA: Readonly<Record<string, string>> = {
  'teso/teso_gestao_pagamentos': 'Pagamentos e Recebimentos',
};

/** Todos os ecrãs da estrutura, pela ordem do menu. */
export function ecrasDaEstrutura(): EcraLegado[] {
  const saida: EcraLegado[] = [...TOPO_LEGADO];
  const percorrer = (n: NoLegado) => (n.tipo === 'ecra' ? saida.push(n) : n.filhos.forEach(percorrer));
  percorrer(MENU_GERAL_LEGADO);
  percorrer(SISTEMA_LEGADO);
  return saida;
}

let rotulos: Map<string, string> | null = null;

/** Rótulo do legado de um ecrã (ou o nome do catálogo, se o ecrã não existe no menu do legado). */
export function rotuloEcra(modulo: string, ecra: string, nomeCatalogo: string): string {
  if (!rotulos) {
    rotulos = new Map();
    for (const x of ecrasDaEstrutura()) if (!rotulos.has(`${x.modulo}/${x.ecra}`)) rotulos.set(`${x.modulo}/${x.ecra}`, x.rotulo);
    for (const [k, v] of Object.entries(ROTULOS_EXTRA)) rotulos.set(k, v);
  }
  return rotulos.get(`${modulo}/${ecra}`) ?? nomeCatalogo;
}

/** Nome do módulo no menu do legado (ou o nome do catálogo). */
export function nomeModulo(modulo: string, nomeCatalogo: string): string {
  return NOME_MODULO_LEGADO[modulo] ?? nomeCatalogo;
}
