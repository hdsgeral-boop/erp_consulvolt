/**
 * Regras de apresentação da Logística iguais às do sistema anterior (verificacao_visual/RELATORIO.md, secção 2).
 * Só apresentação: as regras de negócio estão no servidor (ParidadeStockController, ServicoArmazens…).
 */
import type { EstadoEtapa } from '@/componentes/fluxos';

/** Situação da recepção vista do armazém (GET /logistica/paridade/rececoes). */
export type SituacaoRececao = 'PENDENTE' | 'VALIDADO' | 'ANULADO';

/** Linha de GET /logistica/paridade/rececoes. */
export interface RececaoArmazem {
  id: number;
  data: string | null;
  numero_entrega: string | null;
  numero_rececao: string | null;
  encomenda_compra_id: number | null;
  numero_encomenda: string | null;
  fornecedor_id: number | null;
  fornecedor: string | null;
  armazem_id: number | null;
  armazem: string | null;
  estado: string | null;
  validado: boolean | null;
  contabilizado: boolean | null;
  valor_total_kz: string | null;
  situacao: SituacaoRececao;
}

/** Filtro de situação (o legado listava todas por omissão — 2.2.3). */
export const FILTRO_SITUACAO: { value: SituacaoRececao | 'TODAS'; label: string }[] = [
  { value: 'TODAS', label: 'Todas' },
  { value: 'PENDENTE', label: 'Por validar' },
  { value: 'VALIDADO', label: 'Validadas (em stock)' },
  { value: 'ANULADO', label: 'Anuladas' },
];

/** Rótulo e cor da situação (legado: badge-warning «PENDENTE» / badge-success «VALIDADO»). */
export const SITUACOES_RECECAO: Record<SituacaoRececao, { rotulo: string; cor: string }> = {
  PENDENTE: { rotulo: 'Pendente', cor: 'warning' },
  VALIDADO: { rotulo: 'Validado', cor: 'success' },
  ANULADO: { rotulo: 'Anulado', cor: 'error' },
};

/** «#ORD-4205» como no legado (`#${order.order_number || 'N/D'}`); sem número, o id interno. */
export function referenciaEncomenda(r: Pick<RececaoArmazem, 'numero_encomenda' | 'encomenda_compra_id'>): string {
  if (r.numero_encomenda) return `#${r.numero_encomenda}`;
  return r.encomenda_compra_id ? `#${r.encomenda_compra_id}` : 'N/D';
}

/** Etapas do fluxo de uma recepção: encomenda → recepção → validação em stock → contabilização. */
export const ETAPAS_FLUXO_RECECAO = [
  { id: 'enc', nome: 'Encomenda', icone: 'shopping-cart' },
  { id: 'rec', nome: 'Receção', icone: 'truck-loading' },
  { id: 'val', nome: 'Validação (stock)', icone: 'warehouse' },
  { id: 'cont', nome: 'Contabilização', icone: 'book' },
] as const;

export function estadosFluxoRececao(r: Pick<RececaoArmazem, 'situacao' | 'contabilizado' | 'numero_encomenda' | 'encomenda_compra_id' | 'numero_entrega' | 'armazem'>) {
  const anulada = r.situacao === 'ANULADO';
  const validada = r.situacao === 'VALIDADO';
  const e = (estado: EstadoEtapa, resumo?: string | null) => ({ estado, resumo: resumo ?? null });
  const estados: Record<string, { estado: EstadoEtapa; resumo: string | null }> = {
    enc: e(r.encomenda_compra_id ? 'concluida' : 'fazer', r.numero_encomenda ?? null),
    rec: e(anulada ? 'bloqueada' : 'concluida', anulada ? 'Anulada' : r.numero_entrega),
    val: e(anulada ? 'bloqueada' : validada ? 'concluida' : 'curso', validada ? r.armazem : anulada ? null : 'Por validar'),
    cont: e(r.contabilizado ? 'concluida' : anulada ? 'bloqueada' : validada ? 'curso' : 'fazer', r.contabilizado ? 'Contabilizada' : null),
  };
  const concluidas = Object.values(estados).filter((x) => x.estado === 'concluida').length;
  const global: { estado: EstadoEtapa; rotulo: string } = anulada
    ? { estado: 'bloqueada', rotulo: 'Anulada' }
    : concluidas === ETAPAS_FLUXO_RECECAO.length
      ? { estado: 'concluida', rotulo: 'Concluído' }
      : { estado: 'curso', rotulo: 'Em curso' };
  return { estados, concluidas, global };
}

/** Linha de GET /logistica/paridade/movimentos. */
export interface MovimentoArmazem {
  id: number;
  produto_id: number;
  armazem_id: number | null;
  armazem: string | null;
  armazem_contraparte_id: number | null;
  armazem_contraparte: string | null;
  tipo: string;
  sentido: 'E' | 'S' | null;
  quantidade: string;
  data: string;
  referencia: string | null;
  preco_unitario: string | null;
  valor: string | null;
  custo_medio_apos: string | null;
  documento_tipo: string | null;
  documento_id: number | null;
  terceiro_id: number | null;
  terceiro: string | null;
  criado_por: string | null;
}

/** Acerto de saldo inicial gravado pela migração (ETL). */
export const eMigracao = (m: Pick<MovimentoArmazem, 'documento_tipo'>) => m.documento_tipo === 'MIGRACAO';

/** Sentido efectivo (o legado deduz do tipo quando falta). */
export const sentidoDe = (m: Pick<MovimentoArmazem, 'sentido' | 'tipo'>): 'E' | 'S' => m.sentido ?? (m.tipo === 'SAIDA' ? 'S' : 'E');

/**
 * Tipo como no legado (2.4.4): «Saída», «Entrada»; ajustes e transferências com o sentido entre parênteses
 * («Ajuste (saída)»); os acertos da migração como «Saldo inicial (migração)» (2.4.2).
 */
export function rotuloTipoMovimento(m: Pick<MovimentoArmazem, 'tipo' | 'sentido' | 'documento_tipo'>): string {
  if (eMigracao(m)) return 'Saldo inicial (migração)';
  const s = sentidoDe(m) === 'S' ? 'saída' : 'entrada';
  switch (m.tipo) {
    case 'ENTRADA':
      return 'Entrada';
    case 'SAIDA':
      return 'Saída';
    case 'AJUSTE':
      return `Ajuste (${s})`;
    case 'TRANSFERENCIA':
      return `Transferência (${s})`;
    default:
      return m.tipo;
  }
}

/** Estado do stock como no legado (renderStockTab): > 10 «OK», > 0 «Baixo», senão «Esgotado». */
export function statusStock(quantidade: number | string | null | undefined): { rotulo: 'OK' | 'Baixo' | 'Esgotado'; cor: string } {
  const q = Number(quantidade ?? 0);
  if (q > 10) return { rotulo: 'OK', cor: 'success' };
  if (q > 0) return { rotulo: 'Baixo', cor: 'warning' };
  return { rotulo: 'Esgotado', cor: 'error' };
}
