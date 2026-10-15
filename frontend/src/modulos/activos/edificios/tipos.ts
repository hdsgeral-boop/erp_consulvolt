/** Tipos das respostas de /api/ativos (Edifícios e Locais e inventário físico — Fase F, Versão Actual). Valores em Kz: texto decimal. */

export interface CartaoEdificio {
  id: number;
  codigo: string | null;
  descricao: string;
  estado: string;
  andares: number;
  portas: number;
  bens: number;
  valor_bens: string;
  inventarios: number;
}

export interface ResumoEdificios {
  edificios: CartaoEdificio[];
  sem_local: number;
  inventarios: number;
}

export interface BemNaPlanta {
  id: number;
  codigo: string | null;
  descricao: string;
  valor_aquisicao: string;
  categoria?: string | null;
  estado?: string;
}

export interface PortaPlanta {
  id: number;
  numero: string;
  nome: string | null;
  tipo: string | null;
  capacidade: number | null;
  ordem: number | null;
  andar_id: number;
  bens: BemNaPlanta[];
  n_bens: number;
  valor: string;
}

export interface AndarPlanta {
  id: number;
  numero: string | null;
  nome: string | null;
  notas: string | null;
  ordem: number | null;
  portas: PortaPlanta[];
  n_portas: number;
  n_bens: number;
  valor: string;
}

export interface Planta {
  edificio: { id: number; codigo: string | null; descricao: string; estado: string };
  andares: AndarPlanta[];
  sem_local: BemNaPlanta[];
  totais: { andares: number; portas: number; bens: number; valor: string };
}

export interface OpcoesLocais {
  edificios: { id: number; codigo: string | null; descricao: string }[];
  andares: { id: number; edificio_id: number; numero: string | null; nome: string | null; ordem: number | null }[];
  portas: { id: number; edificio_id: number; andar_id: number; numero: string; nome: string | null; tipo: string | null }[];
}

export type Ambito = 'EDIFICIO' | 'ANDAR' | 'PORTA';
export type Resultado = 'ENCONTRADO' | 'EM_FALTA' | 'DESLOCADO' | 'EXTRA';

export interface Inventario {
  id: number;
  numero: string;
  edificio_id: number;
  ambito: Ambito;
  ambito_texto: string;
  andar_id: number | null;
  porta_id: number | null;
  data: string | null;
  responsavel: string | null;
  notas: string | null;
  estado: 'ABERTO' | 'FECHADO' | 'ANULADO';
  esperados: number;
  valor_esperado: string;
  aplicado_em: string | null;
  fechado_em: string | null;
  fechado_por: string | null;
  motivo_anulacao: string | null;
  edificio?: { id: number; codigo: string | null; descricao: string } | null;
  previstos?: number;
  contados?: number;
  por_contar?: number;
  diferencas?: number;
}

export interface LinhaInventario {
  id: number;
  ativo_imobilizado_id: number;
  codigo: string | null;
  descricao: string | null;
  porta_id: number | null;
  valor: string;
  resultado: Resultado | null;
  porta_encontrada_id: number | null;
  notas: string | null;
  local_esperado: string | null;
  local_encontrado: string | null;
}

export interface FolhaInventario {
  inventario: Inventario;
  linhas: LinhaInventario[];
  portas: { id: number; texto: string }[];
}

export interface ComparacaoInventario extends FolhaInventario {
  resumo: Record<'encontrados' | 'em_falta' | 'deslocados' | 'extras', { n: number; valor: string }> & {
    por_contar: number;
    previstos: number;
    conformidade: number;
    a_aplicar: number;
  };
}
