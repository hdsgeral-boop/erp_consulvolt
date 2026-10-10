/**
 * Lógica pura do POS de armazém (paridade com js/ui_pos_armazem.js — RELATORIO 2.6): filtro dos artigos, cores e
 * etiquetas do stock nos cartões, total do picking e detecção de uma nova chamada de senha no monitor.
 */

/** Artigo do catálogo do balcão (GET /pos/armazem/artigos). */
export interface ArtigoPos {
  produto_id: number;
  codigo: string | null;
  nome: string | null;
  preco_unitario: string;
  quantidade: string;
  bloqueado: boolean;
}

/** Diferença entre o stock global do produto e a soma dos armazéns (GET /pos/armazem/acerto-stock). */
export interface DiferencaStock {
  produto_id: number;
  codigo: string | null;
  nome: string | null;
  global: string;
  armazens: string;
  local: string;
  diferenca: string;
  ajuste: string;
  limitado: boolean;
  fica: string;
}

export interface SenhaPos {
  id: number;
  codigo: string;
  numero: number;
  estado: 'AGUARDA' | 'CHAMADA';
  chamadas: number;
  chamada_em: string | null;
  criado_em: string | null;
}

export interface EstadoSenhas {
  data: string;
  armazem_id: number;
  actual: SenhaPos | null;
  fila: SenhaPos[];
  chamadas: SenhaPos[];
}

/** Legado: «Todos» (all), «Com Stock» (in), «Sem Stock» (out). */
export type FiltroStock = 'todos' | 'com' | 'sem';

export type ModoArtigos = 'grelha' | 'lista';

const texto = (v: string | null | undefined) => (v ?? '').toLocaleLowerCase('pt-PT');

/** Filtro «Todos / Com Stock / Sem Stock» + «Pesquisar…» (nome ou código, sem distinguir maiúsculas). */
export function filtrarArtigos(artigos: ArtigoPos[], filtro: FiltroStock, pesquisa: string): ArtigoPos[] {
  const q = texto(pesquisa.trim());
  return artigos.filter((a) => {
    const qtd = Number(a.quantidade) || 0;
    if (filtro === 'com' && qtd <= 0) return false;
    if (filtro === 'sem' && qtd > 0) return false;
    return !q || texto(a.nome).includes(q) || texto(a.codigo).includes(q);
  });
}

/** Cor da quantidade «STOCK DISP.» (legado: > 10 verde, > 0 laranja, 0 vermelho). */
export function corStock(quantidade: number): string {
  return quantidade > 10 ? '#059669' : quantidade > 0 ? '#d97706' : '#dc2626';
}

/** Etiqueta do cartão (legado: «disponivel» / «Baixo» / «Esgotado»). */
export function etiquetaStock(quantidade: number): { texto: string; tipo: 'sucesso' | 'aviso' | 'perigo' } {
  if (quantidade > 10) return { texto: 'Disponível', tipo: 'sucesso' };
  if (quantidade > 0) return { texto: 'Baixo', tipo: 'aviso' };
  return { texto: 'Esgotado', tipo: 'perigo' };
}

/** Quantidade como no legado («10 U», «2,5 U»). */
export function formatarUnidades(quantidade: number): string {
  return `${(Math.round(quantidade * 1000) / 1000).toLocaleString('pt-PT', { maximumFractionDigits: 3 })} U`;
}

/** TOTAL DO PICKING = Σ quantidade × preço unitário (legado: it.quantity * it.price). */
export function totalPicking(itens: { produto_id: number; quantidade: number }[], precos: ReadonlyMap<number, number>): number {
  return Math.round(itens.reduce((t, i) => t + i.quantidade * (precos.get(i.produto_id) ?? 0), 0) * 100) / 100;
}

/** Frase anunciada por voz (legado: «Senha número P 001. Por favor, dirigir-se ao Balcão de Logística.»). */
export function fraseChamada(codigo: string): string {
  return `Senha número ${codigo.replace('-', ' ')}. Por favor, dirigir-se ao Balcão de Logística.`;
}

/** Identificador de uma chamada (a mesma senha chamada de novo conta como nova chamada). */
export function chaveChamada(s: SenhaPos | null | undefined): string | null {
  return s ? `${s.id}:${s.chamadas}:${s.chamada_em ?? ''}` : null;
}

/**
 * Há uma chamada nova a anunciar? Na primeira leitura (anterior `undefined`) não se anuncia o que já estava no ecrã.
 */
export function chamadaNova(anterior: string | null | undefined, actual: string | null): boolean {
  return anterior !== undefined && actual !== null && actual !== anterior;
}
