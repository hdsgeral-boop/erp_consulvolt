import { enviar, obter } from '@/api/cliente';

export type OrigemProcesso = 'FABRICA' | 'EDITADO' | 'PROPRIO';

export interface EtapaProcesso { nome: string; quem: string; descricao: string; controlos: string; resultado: string }
export interface CruzamentoProcesso { com: string; onde: string; o_que: string }

export interface Processo {
  chave: string;
  origem: OrigemProcesso;
  editado: boolean;
  tem_painel: boolean;
  fluxo_id: string | null;
  modulo: string | null;
  ordem: number;
  titulo: string;
  objetivo: string;
  intervenientes: string[];
  etapas: EtapaProcesso[];
  cruza: CruzamentoProcesso[];
}

export interface ListaProcessos { processos: Processo[]; ocultos: { chave: string; titulo: string }[] }

export interface ModuloCobertura { id: string; nome: string; esperados: string[]; descritos: string[]; em_falta: string[]; coberto: boolean; sem_processo: boolean }
export interface Cobertura {
  modulos: ModuloCobertura[];
  painel_sem_narrativa: string[];
  narrativa_sem_painel: { chave: string; titulo: string; origem: OrigemProcesso }[];
  modulos_sem_processo: ModuloCobertura[];
  total: { modulos: number; paineis: number; narrativas: number };
}

export const TEXTOS = ['apresentacao', 'objetivo', 'principios', 'notas'] as const;
export type ChaveTexto = (typeof TEXTOS)[number];
export const OPCOES = ['incluir_diagrama', 'incluir_legenda', 'incluir_matriz', 'incluir_controlos', 'incluir_cruzamentos', 'incluir_cobertura', 'conferir_funcoes'] as const;
export type ChaveOpcao = (typeof OPCOES)[number];

export type DefinicoesManual = {
  titulo: string; versao: string; data_documento: string | null; aprovado_em: string | null; elaborado_por: string; aprovado_por: string;
  processos_excluidos: string[]; guardado?: boolean;
  textos_ajuda?: Record<ChaveTexto, { rot: string; ajuda: string; exemplo: string }>;
} & Record<ChaveTexto, string> & Record<ChaveOpcao, boolean>;

export interface Interveniente { nome: string; processos: string[]; partes: string[]; na_funcoes: boolean | null }
export interface Ligacao { a: string; b: string; pontos: { de: string; para: string; onde: string; o_que: string }[] }
export interface Cruzamentos {
  pares: { de: string; para: string; onde: string; o_que: string }[];
  externos: { de: string; para: string; onde: string; o_que: string; fora: true }[];
  ligacoes: Ligacao[];
  grau: Record<string, number>;
  isolados: string[];
  central: string | null;
}
export interface Recolha {
  opcoes: DefinicoesManual;
  processos: Processo[];
  intervenientes: Interveniente[];
  controlos: { processo: string; etapa: string; quem: string; controlos: string; n: number }[];
  cruzamentos: Cruzamentos;
  cobertura: Cobertura | null;
  totais: { processos: number; etapas: number; controlos: number; intervenientes: number; editados: number; proprios: number; ligacoes: number };
  avisos: string[];
}

export const CHAVE_PROCESSOS = ['procedimentos', 'lista'] as const;
export const CHAVE_COBERTURA = ['procedimentos', 'cobertura'] as const;
export const CHAVE_DEFINICOES = ['manuais', 'procedimentos'] as const;

export const listarProcessos = () => obter<ListaProcessos>('/procedimentos');
export const obterCobertura = () => obter<Cobertura>('/procedimentos/cobertura');
export const criarProcesso = async (titulo: string) => (await enviar<Processo>('post', '/procedimentos', { titulo })).dados;
export const gravarProcesso = async (chave: string, dados: Pick<Processo, 'titulo' | 'objetivo' | 'intervenientes' | 'etapas' | 'cruza'>) =>
  (await enviar<Processo>('put', `/procedimentos/${encodeURIComponent(chave)}`, dados)).dados;
export const reporProcesso = (chave: string) => enviar<null>('delete', `/procedimentos/${encodeURIComponent(chave)}`);
export const ocultarProcesso = (chave: string, ocultar: boolean) => enviar<null>('post', `/procedimentos/${encodeURIComponent(chave)}/${ocultar ? 'ocultar' : 'mostrar'}`);

export const obterDefinicoes = () => obter<DefinicoesManual>('/manuais/procedimentos');
export const gravarDefinicoes = async (d: Partial<DefinicoesManual>) => (await enviar<DefinicoesManual>('put', '/manuais/procedimentos', d)).dados;
export const recolherManual = async (d: Partial<DefinicoesManual>) => (await enviar<Recolha>('post', '/manuais/procedimentos/recolha', d)).dados;
