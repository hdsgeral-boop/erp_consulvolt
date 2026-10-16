import type { CampoFormulario } from '@/componentes/formularios';

/** Lógica das Tabelas Auxiliares da Contabilidade (renderTabelasAuxiliares, js/ui_aux.js:98-491), sem UI para testar. */

export type ChaveSeparadorAux =
  | 'diarios'
  | 'terceiros'
  | 'notas-demonstracao'
  | 'notas-fluxo-caixa'
  | 'centros-custo'
  | 'unidades-negocio'
  | 'plano'
  | 'reciclagem';

export interface SeparadorAux {
  chave: ChaveSeparadorAux;
  rotulo: string;
  icone?: string;
  /** cor do texto (Reciclagem a vermelho, como no legado) */
  cor?: string;
}

/** Ordem do legado (Diários, Terceiros, Notas de Demonstrações, Notas de Fluxo de Caixa, Centros de Custo, Unidades de
 * Negócio, Reciclagem) + «Plano de contas» do sistema novo antes da Reciclagem. */
export const SEPARADORES_AUX: SeparadorAux[] = [
  { chave: 'diarios', rotulo: 'Diários' },
  { chave: 'terceiros', rotulo: 'Terceiros' },
  { chave: 'notas-demonstracao', rotulo: 'Notas de Demonstrações' },
  { chave: 'notas-fluxo-caixa', rotulo: 'Notas de Fluxo de Caixa' },
  { chave: 'centros-custo', rotulo: 'Centros de Custo' },
  { chave: 'unidades-negocio', rotulo: 'Unidades de Negócio', icone: 'building' },
  { chave: 'plano', rotulo: 'Plano de contas', icone: 'book' },
  { chave: 'reciclagem', rotulo: 'Reciclagem', icone: 'trash-restore', cor: '#ef4444' },
];

/** Separador activo a partir de `?separador=` (desconhecido ou sem permissão → o primeiro visível). */
export function separadorAuxActivo(pesquisa: string, visiveis: ChaveSeparadorAux[]): ChaveSeparadorAux {
  const pedido = new URLSearchParams(pesquisa).get('separador') as ChaveSeparadorAux | null;
  return pedido && visiveis.includes(pedido) ? pedido : (visiveis[0] ?? 'diarios');
}

/** Textos do formulário rápido de cada tabela simples (js/ui_aux.js:139-465). */
export const TEXTOS_TABELA: Record<string, { novo: string; registados: string; adicionar: string; exCodigo: string; exDescricao: string }> = {
  diarios: { novo: 'Novo Diário', registados: 'Diários Registados', adicionar: 'Adicionar Diário', exCodigo: 'Ex: 01', exDescricao: 'Ex: Salários' },
  'notas-demonstracao': { novo: 'Nova Nota', registados: 'Notas às Demonstrações Registadas', adicionar: 'Adicionar Nota', exCodigo: 'Ex: N01', exDescricao: 'Ex: Fornecedores Locais' },
  'notas-fluxo-caixa': { novo: 'Nova Nota', registados: 'Notas de Fluxo Registadas', adicionar: 'Adicionar Nota', exCodigo: 'Ex: F01', exDescricao: 'Ex: Recebimentos de Clientes' },
  'centros-custo': { novo: 'Novo Centro de Custo', registados: 'Centros de Custo Registados', adicionar: 'Adicionar Centro de Custo', exCodigo: 'Ex: CC-01', exDescricao: 'Ex: Administração' },
};

interface TerceiroTipo {
  tipo?: string | null;
  e_cliente?: boolean;
  e_fornecedor?: boolean;
}

/** Coluna «Tipo» dos terceiros: o tipo gravado (CLIENTE, FORNECEDOR, COLABORADOR, ESTADO…) ou os papéis da ficha. */
export function rotuloTipoTerceiro(t: TerceiroTipo): string {
  const papeis = [t.e_cliente && 'CLIENTE', t.e_fornecedor && 'FORNECEDOR'].filter(Boolean) as string[];
  if (papeis.length) return papeis.join(' / ');
  return t.tipo?.trim().toUpperCase() || '—';
}

/** Papel a usar ao gravar a ficha (a API só aceita CLIENTE ou FORNECEDOR). */
export function papelDoTerceiro(t: TerceiroTipo): 'CLIENTE' | 'FORNECEDOR' {
  if (t.e_fornecedor && !t.e_cliente) return 'FORNECEDOR';
  if (!t.e_cliente && t.tipo?.toUpperCase() === 'FORNECEDOR') return 'FORNECEDOR';
  return 'CLIENTE';
}

// ---------------------------------------------------------------------------------------------------------------
// Vistas «Grade | Linhas» dos cadastros (legado: js/shared/formularios_linhas_config.js — diarios, centros_custo,
// terceiros, plano_contas). A grade e as linhas usam as mesmas funções puras para montar o corpo do pedido.
// ---------------------------------------------------------------------------------------------------------------


const txt = (v: unknown) => (typeof v === 'string' ? v.trim() : v === null || v === undefined ? '' : String(v).trim());

/** Chave da preferência Grade/Linhas de cada tabela simples (as do legado; as notas não tinham linhas no legado). */
export const CHAVE_LINHAS_TABELA: Record<string, string> = {
  diarios: 'contab.diarios',
  'notas-demonstracao': 'contab.notas_demonstracao',
  'notas-fluxo-caixa': 'contab.notas_fluxo_caixa',
  'centros-custo': 'contab.centros_custo',
};

/** Valores do formulário (grade ou linha) → corpo de POST/PUT /contabilidade/tabelas/{tabela}. */
export function dadosRegistoAux(v: Record<string, unknown>): { codigo: string; descricao: string } {
  return { codigo: txt(v.codigo), descricao: txt(v.descricao) };
}

/** Colunas da vista Linhas das tabelas simples (Código · Descrição, como no legado). */
export function camposRegistoAux(textos?: { exCodigo?: string; exDescricao?: string }): CampoFormulario[] {
  return [
    { nome: 'codigo', rotulo: 'Código', obrigatorio: true, largura: 130, maxLength: 20, placeholder: textos?.exCodigo || undefined, aliases: ['Codigo', 'Cód.'] },
    { nome: 'descricao', rotulo: 'Descrição', obrigatorio: true, largura: 300, maxLength: 255, placeholder: textos?.exDescricao || undefined, aliases: ['Descricao', 'Descriccação', 'Designação'] },
  ];
}

/** Código de conta: só algarismos, letras e pontos (a mesma regra da grade). */
export const PADRAO_CODIGO_CONTA = /^[0-9A-Za-z.]+$/;

export interface ValoresConta {
  codigo?: string;
  descricao?: string;
  tipo?: 'M' | 'T' | string;
  codigo_moeda?: string | null;
  natureza_conta?: string | null;
}

/** Valores da conta (grade ou linha) → corpo de POST/PUT /contabilidade/plano-contas. */
export function dadosConta(v: ValoresConta): Record<string, unknown> {
  const moeda = txt(v.codigo_moeda).toUpperCase();
  const natureza = txt(v.natureza_conta);
  return {
    codigo: txt(v.codigo),
    descricao: txt(v.descricao),
    tipo: txt(v.tipo).toUpperCase() === 'T' ? 'T' : 'M',
    // vazio → null (o mesmo que o '' da grade antiga, convertido em null pelo servidor)
    codigo_moeda: moeda || null,
    natureza_conta: natureza || null,
  };
}

export const OPCOES_TIPO_CONTA = [
  { value: 'M', label: 'Movimento (aceita lançamentos)' },
  { value: 'T', label: 'Totalizadora' },
];

/** Colunas da vista Linhas do plano de contas (legado `plano_contas`: código, descrição, tipo M/T, moeda). */
export const CAMPOS_CONTA: CampoFormulario[] = [
  {
    nome: 'codigo', rotulo: 'Código da conta', obrigatorio: true, largura: 140, maxLength: 20, aliases: ['Código', 'Codigo', 'Conta'],
    validar: (v) => (txt(v) && !PADRAO_CODIGO_CONTA.test(txt(v)) ? 'Código da conta: só algarismos, letras e pontos.' : null),
  },
  { nome: 'descricao', rotulo: 'Descrição', obrigatorio: true, largura: 300, maxLength: 255, aliases: ['Descricao'] },
  { nome: 'tipo', rotulo: 'Tipo (M/T)', tipo: 'select', obrigatorio: true, largura: 170, omissao: 'M', opcoes: OPCOES_TIPO_CONTA, aliases: ['Tipo'] },
  { nome: 'codigo_moeda', rotulo: 'Moeda', largura: 100, maxLength: 10, placeholder: 'AOA', aliases: ['Moeda (só classe 4)'] },
  { nome: 'natureza_conta', rotulo: 'Natureza', largura: 180, maxLength: 255, emLinhas: 'opcional' },
];
