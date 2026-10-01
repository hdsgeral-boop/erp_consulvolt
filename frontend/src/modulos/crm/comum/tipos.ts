/** Tipos da API do CRM (ADR-054) e regras puras do frontend (testáveis). */

export type TipoEtapa = 'ABERTA' | 'GANHA' | 'PERDIDA';

export interface TarefaEtapa {
  tipo?: string | null;
  titulo?: string | null;
  dias?: number | null;
  modelo_email_crm_id?: number | null;
}

export interface Etapa {
  id: string;
  nome: string;
  tipo: TipoEtapa;
  cor?: string | null;
  probabilidade?: number | null;
  dias_estagnacao?: number | null;
  tarefas?: TarefaEtapa[];
}

export interface Funil {
  id: number;
  nome: string;
  ordem: number | null;
  ativo: boolean;
  etapas: Etapa[];
}

export interface ConfiguracaoCRM {
  motivos_perda: string[];
  origens: string[];
  dias_sem_atividade: number;
  prazo_pagamento_dias: number;
  tipos_atividade: Record<string, string>;
  marcadores: string[];
}

export interface ItemOportunidade {
  produto_id?: number | null;
  descricao?: string | null;
  quantidade?: number | string | null;
  preco?: number | string | null;
  taxa?: number | string | null;
}

export interface Oportunidade {
  id: number;
  funil_vendas_crm_id: number;
  conta_crm_id: number;
  contacto_crm_id: number | null;
  titulo: string;
  valor: string;
  probabilidade: string | number | null;
  data_fecho_prevista: string | null;
  responsavel: string | null;
  origem: string | null;
  origem_original: string | null;
  notas: string | null;
  itens: ItemOportunidade[] | null;
  etapa_codigo: string;
  estado: 'ABERTA' | 'GANHA' | 'PERDIDA';
  historico: { por: string; entrou_em: string; etapa_codigo: string; motivo?: string | null }[] | null;
  etapa_desde: string | null;
  vendas: { venda_id: number; tipo_documento: string; numero_documento: string; total: string; em: string }[] | null;
  fechado_em: string | null;
  motivo_perda: string | null;
  concorrente: string | null;
  notas_perda: string | null;
  ultima_atividade_em: string | null;
  criado_em: string | null;
  conta_crm?: { id: number; nome: string; tipo: string; terceiro_id: number | null } | null;
  contacto_crm?: { id: number; nome: string } | null;
}

export interface Actividade {
  id: number;
  oportunidade_crm_id: number | null;
  conta_crm_id: number | null;
  contacto_crm_id: number | null;
  tipo: string;
  titulo: string | null;
  descricao: string | null;
  data_prevista: string | null;
  concluida: boolean | null;
  responsavel: string | null;
  automatica: boolean | null;
  modelo_email_crm_id: number | null;
  concluida_em: string | null;
  resultado: string | null;
  conta_crm?: { id: number; nome: string } | null;
  oportunidade_crm?: { id: number; titulo: string } | null;
  vencida?: boolean;
}

export interface Saude {
  nivel: 'OK' | 'ATENCAO' | 'RISCO' | 'GANHA' | 'PERDIDA' | string;
  motivos: string[];
  proxima: Actividade | null;
  dias_etapa: number | null;
}

export interface Cartao {
  oportunidade: Oportunidade;
  probabilidade_efectiva: string;
  valor_ponderado: string;
  saude: Saude;
  atraso_financeiro: { em_atraso: string; n_atrasadas: number; max_dias_atraso: number } | null;
}

export interface Quadro {
  funil: Funil;
  etapas: { etapa: Etapa; n: number; valor: string; ponderado: string; cartoes: Cartao[] }[];
  resumo: { abertas: number; valor: string; ponderado: string; em_risco: number; atencao: number };
}

export interface ContaCRM {
  id: number;
  tipo: 'PROSPECT' | 'CLIENTE';
  terceiro_id: number | null;
  nome: string;
  nif: string | null;
  email: string | null;
  telefone: string | null;
  morada: string | null;
  origem: string | null;
  origem_original: string | null;
  responsavel: string | null;
  setor: string | null;
  website: string | null;
  notas: string | null;
  oportunidades_abertas?: number;
  valor_aberto?: string;
  ganhas?: number;
  financeiro?: { em_aberto: string; em_atraso: string; max_dias_atraso: number; n_atrasadas: number } | null;
}

export interface Contacto {
  id: number;
  conta_crm_id: number;
  nome: string;
  cargo: string | null;
  email: string | null;
  telefone: string | null;
  principal: boolean | null;
}

export interface ModeloEmail {
  id: number;
  nome: string;
  assunto: string;
  corpo: string | null;
}

export interface Sequencia {
  id: number;
  nome: string;
  funil_vendas_crm_id: number;
  etapa_codigo: string;
  ativo: boolean;
  passos: { dias?: number | null; modelo_email_crm_id?: number | null }[];
}

export interface MensagemEmail {
  para: string;
  assunto: string;
  corpo: string;
  modelo_email_crm_id: number | null;
  conta_crm_id: number | null;
  contacto_crm_id: number | null;
  oportunidade_crm_id: number | null;
}

export interface Envio {
  estado: string;
  resultado: string;
  mailto?: string;
}

export const DOCUMENTOS_CONVERSAO: { tipo: string; rotulo: string }[] = [
  { tipo: 'OR', rotulo: 'Orçamento' },
  { tipo: 'PF', rotulo: 'Factura pró-forma' },
  { tipo: 'NE', rotulo: 'Nota de encomenda' },
  { tipo: 'FT', rotulo: 'Factura' },
  { tipo: 'FR', rotulo: 'Factura-recibo' },
];

export const NIVEIS_SAUDE: Record<string, { cor: string; rotulo: string }> = {
  OK: { cor: 'green', rotulo: 'Em dia' },
  ATENCAO: { cor: 'orange', rotulo: 'Atenção' },
  RISCO: { cor: 'red', rotulo: 'Em risco' },
  GANHA: { cor: 'green', rotulo: 'Ganha' },
  PERDIDA: { cor: 'default', rotulo: 'Perdida' },
};

/** Mover uma oportunidade para uma etapa: precisa de motivo se a etapa de destino é de perda. */
export function exigeMotivo(etapa: Etapa | undefined): boolean {
  return etapa?.tipo === 'PERDIDA';
}

/** Uma oportunidade só se move para outra etapa (largar na mesma coluna não faz nada). */
export function podeMover(origem: string, destino: string): boolean {
  return !!destino && origem !== destino;
}

const cent = (v: unknown) => {
  const n = Number(v ?? 0);
  return Number.isFinite(n) ? Math.round(n * 100) : 0;
};

/** Valor estimado das linhas (Σ quantidade × preço × (1 + taxa)), em cêntimos → texto com 2 casas. */
export function totalItens(itens: ItemOportunidade[] | null | undefined, comImposto = false): string {
  let total = 0;
  for (const i of itens ?? []) {
    const base = Math.round(Number(i.quantidade ?? 0) * cent(i.preco));
    total += comImposto ? Math.round(base * (1 + Number(i.taxa ?? 0) / 100)) : base;
  }
  return (total / 100).toFixed(2);
}

/** Soma de valores (texto decimal) em cêntimos. */
export function somar(valores: (string | number | null | undefined)[]): string {
  return (valores.reduce<number>((s, v) => s + cent(v), 0) / 100).toFixed(2);
}

/** Filtra cartões do quadro por texto (título, conta) e responsável, no cliente. */
export function filtrarCartoes(cartoes: Cartao[], texto: string, soRisco = false): Cartao[] {
  const t = texto.trim().toLowerCase();
  return cartoes.filter(
    (c) =>
      (!soRisco || c.saude.nivel === 'RISCO' || c.saude.nivel === 'ATENCAO') &&
      (!t || c.oportunidade.titulo.toLowerCase().includes(t) || (c.oportunidade.conta_crm?.nome ?? '').toLowerCase().includes(t)),
  );
}

/** Abre o mailto devolvido pelo servidor (o envio real é feito no programa de email do utilizador). */
export function abrirMailto(envio: Envio | null | undefined): boolean {
  if (!envio?.mailto) return false;
  window.location.href = envio.mailto;
  return true;
}

/** Etapas de um funil por tipo (para validar um funil antes de gravar: precisa de ganha e perdida). */
export function validarFunil(etapas: Pick<Etapa, 'nome' | 'tipo'>[]): string[] {
  const erros: string[] = [];
  if (etapas.length < 3) erros.push('O funil precisa de pelo menos 3 etapas.');
  if (!etapas.some((e) => e.tipo === 'GANHA')) erros.push('Falta uma etapa de ganho.');
  if (!etapas.some((e) => e.tipo === 'PERDIDA')) erros.push('Falta uma etapa de perda.');
  if (!etapas.some((e) => e.tipo === 'ABERTA')) erros.push('Falta pelo menos uma etapa aberta.');
  if (etapas.some((e) => !e.nome?.trim())) erros.push('Todas as etapas precisam de nome.');
  return erros;
}

/** Marcadores {{x}} usados num modelo que não existem na lista do servidor. */
export function marcadoresDesconhecidos(texto: string, conhecidos: string[]): string[] {
  const usados = [...texto.matchAll(/\{\{\s*(\w+)\s*\}\}/g)].map((m) => m[1]);
  return [...new Set(usados.filter((u) => !conhecidos.includes(u)))];
}
