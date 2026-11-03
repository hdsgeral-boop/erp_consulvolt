/**
 * Tipos da Fase K (especificação K2–K5): importação Excel de entregas, multas de entregáveis, delegação da chefia.
 * Os nomes dos campos são os do contrato; os campos que a especificação não fixa (nomes das pessoas, vigência) são
 * opcionais e o ecrã cai para o identificador quando faltam.
 */

import type { Decimal } from '../tarefas/tipos';

/* ---------------------------------------------------------------- K2 importação Excel */

export type EstadoLinhaImportacao = 'OK' | 'ERRO' | 'DUPLICADA';

export interface LinhaImportacaoEntrega {
  n: number;
  estado: EstadoLinhaImportacao;
  erros: string[];
  colaborador: { id: number; nome: string } | null;
  data: string | null;
  quantidade: Decimal | null;
}

export interface ResumoImportacaoEntregas {
  total: number;
  validas: number;
  erros: number;
  duplicadas: number;
}

export interface RelatorioImportacaoEntregas {
  linhas: LinhaImportacaoEntrega[];
  resumo: ResumoImportacaoEntregas;
}

/* ---------------------------------------------------------------- K3 multas */

export type EstadoMulta = 'PROPOSTA' | 'APLICADA' | 'DISPENSADA' | 'DESCONTADA';

export interface MultaEntregavel {
  id: number;
  entregavel_id: number;
  tarefa_unidade_id?: number;
  unidade_organica_id?: number;
  colaborador_id: number;
  periodo_de: string;
  periodo_ate: string;
  limite: string;
  dias_atraso: number;
  valor: Decimal;
  descricao: string | null;
  estado: EstadoMulta;
  decidido_por?: string | null;
  decidido_em?: string | null;
  motivo_decisao?: string | null;
  periodo_processamento_salarial_id?: number | null;
  descontada_em?: string | null;
  /** nomes para mostrar (o servidor pode devolvê-los planos ou aninhados) */
  entregavel_nome?: string | null;
  colaborador_nome?: string | null;
  unidade_nome?: string | null;
  entregavel?: { id?: number; nome?: string } | null;
  colaborador?: { id?: number; nome?: string } | null;
  unidade?: { id?: number; nome?: string } | null;
}

export interface FiltroMultas {
  estado?: EstadoMulta;
  unidade_id?: number;
  colaborador_id?: number;
  de?: string;
  ate?: string;
}

/** POST propor: contadores (número ou lista) e avisos (ex.: atrasos sem penalizado). */
export interface ResultadoProposta {
  criadas?: number | unknown[];
  ignoradas?: number | unknown[];
  avisos?: string[];
}

/** POST importar-multas-entregaveis (folha). */
export interface ResultadoMultasFolha {
  lancados: number;
  actualizados: number;
  ignorados: number;
  total: Decimal;
  avisos: string[];
}

/* ---------------------------------------------------------------- K5 delegações */

export type AmbitoDelegacao = 'PORTAL' | 'METODO' | 'ENTREGAVEIS';
export type EstadoDelegacao = 'ATIVA' | 'REVOGADA';

export interface DelegacaoChefia {
  id: number;
  delegante_colaborador_id: number;
  delegado_colaborador_id: number;
  delegante?: { id: number; nome: string } | null;
  delegante_nome?: string | null;
  delegado?: { id: number; nome: string } | null;
  delegado_nome?: string | null;
  data_inicio: string;
  data_fim: string;
  ambito: AmbitoDelegacao[];
  motivo: string | null;
  estado: EstadoDelegacao;
  revogada_por?: string | null;
  revogada_em?: string | null;
  criado_por?: string | null;
}

/** GET /rh/portal/delegacoes: as que dei e as que recebi. */
export interface MinhasDelegacoes {
  dadas: DelegacaoChefia[];
  recebidas: DelegacaoChefia[];
}

export interface DadosDelegacao {
  /** só na criação em nome de outro (estrutura) */
  delegante_colaborador_id?: number;
  delegado_colaborador_id: number;
  data_inicio: string;
  data_fim: string;
  ambito: AmbitoDelegacao[];
  motivo: string | null;
}

/** Marca de uma decisão tomada por delegação (etapas, deliberações, validações). */
export interface PorDelegadoDe {
  nome?: string | null;
  colaborador_id?: number | null;
}
