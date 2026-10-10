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
