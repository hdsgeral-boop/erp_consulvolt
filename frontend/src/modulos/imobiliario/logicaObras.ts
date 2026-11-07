import type { EstadoBem, EstadoNecessidade, EstadoObra, FichaOrdem, LinhaComparacao, OrdemLinha, TipoObra } from './apiObras';

/** Rótulos e cores dos estados (tokens do legado). O estado vem sempre em texto: a cor só reforça. */
export const ROTULO_OBRA: Readonly<Record<EstadoObra, string>> = { ABERTA: 'Aberta', AGUARDA_MATERIAL: 'Aguarda material', EM_CURSO: 'Em curso', CONCLUIDA: 'Concluída', CANCELADA: 'Cancelada' };
export const COR_OBRA: Readonly<Record<EstadoObra, string>> = { ABERTA: 'default', AGUARDA_MATERIAL: 'orange', EM_CURSO: 'blue', CONCLUIDA: 'green', CANCELADA: 'red' };
export const ROTULO_TIPO_OBRA: Readonly<Record<TipoObra, string>> = { APETRECHAMENTO: 'Apetrechamento', PREVENTIVA: 'Manutenção preventiva', CORRECTIVA: 'Reparação', MELHORIA: 'Melhoria', VISTORIA: 'Vistoria' };
export const ROTULO_ITEM: Readonly<Record<EstadoNecessidade, string>> = { POR_PEDIR: 'Por pedir', REQUISITADO: 'Requisitado ao armazém', PEDIDO_COMPRA: 'Em pedido de compra', RECEBIDO: 'Recebido', CANCELADO: 'Cancelado' };
export const COR_ITEM: Readonly<Record<EstadoNecessidade, string>> = { POR_PEDIR: 'default', REQUISITADO: 'blue', PEDIDO_COMPRA: 'orange', RECEBIDO: 'green', CANCELADO: 'red' };
export const ROTULO_BEM: Readonly<Record<EstadoBem, string>> = { NOVO: 'Novo', BOM: 'Bom estado', USADO: 'Usado', DANIFICADO: 'Danificado', EM_FALTA: 'Em falta' };
export const ROTULO_DATA_PLANO: Readonly<Record<string, string>> = { EM_FALTA: 'Em falta', POR_GERAR: 'Por gerar', ...ROTULO_OBRA };
export const COR_DATA_PLANO: Readonly<Record<string, string>> = { EM_FALTA: 'red', POR_GERAR: 'default', ...COR_OBRA };
export const ROTULO_ORIGEM_BEM: Readonly<Record<string, string>> = { MANUAL: 'Registado à mão', INVENTARIO: 'Do imobilizado', COMPRA: 'Comprado', ARMAZEM: 'Do armazém' };

/** Quantidade do servidor (3 casas, ponto) para mostrar: `2.500` → `2,5`, `3.000` → `3`. */
export const formatarQuantidade = (v: string | number | null | undefined): string => {
  const n = Number(v ?? 0);
  return Number.isFinite(n) ? new Intl.NumberFormat('pt-PT', { maximumFractionDigits: 3 }).format(n) : '—';
};

export interface AccoesOrdem {
  editar: boolean; aprovar: boolean; pedirAprovacao: boolean; pedir: boolean; receber: boolean; iniciar: boolean; concluir: boolean; cancelar: boolean; debitar: boolean; desfazer: boolean; apagar: boolean;
}

/**
 * O que cada utilizador pode fazer a uma ordem (o servidor repete todas as regras e recusa o resto): editar, aprovar, pedir material e registar entregas só nas abertas; pedir só depois de
 * aprovada e com necessidades por pedir; debitar só concluída, aprovada, debitável e por debitar. Quem aprova não recebe (par de segregação), por isso os botões dependem de permissões distintas.
 */
export function accoesDaOrdem(o: Pick<FichaOrdem, 'estado' | 'aprovada' | 'por_pedir' | 'por_debitar' | 'debitado_em'> & Partial<Pick<FichaOrdem, 'necessidades_lista'>>, pode: (...p: string[]) => boolean): AccoesOrdem {
  const aberta = o.estado !== 'CONCLUIDA' && o.estado !== 'CANCELADA';
  const comPedido = (o.necessidades_lista ?? []).some((n) => n.estado === 'REQUISITADO' || n.estado === 'PEDIDO_COMPRA' || n.estado === 'RECEBIDO');
  return {
    editar: aberta && pode('imo_obra_editar'),
    aprovar: aberta && !o.aprovada && pode('imo_obra_aprovar'),
    pedirAprovacao: aberta && !o.aprovada && !pode('imo_obra_aprovar'),
    pedir: aberta && o.aprovada && o.por_pedir > 0 && pode('imo_obra_pedir'),
    receber: aberta && pode('imo_obra_receber') && (o.necessidades_lista ?? []).some((n) => n.estado === 'REQUISITADO' || n.estado === 'PEDIDO_COMPRA'),
    iniciar: o.estado === 'ABERTA' && pode('imo_obra_fechar'),
    concluir: aberta && pode('imo_obra_fechar'),
    cancelar: aberta && o.debitado_em === null && pode('imo_obra_fechar'),
    debitar: o.por_debitar && o.estado === 'CONCLUIDA' && o.aprovada && pode('imo_sub_debitar'),
    desfazer: o.debitado_em !== null && pode('imo_sub_debitar'),
    apagar: o.estado === 'ABERTA' && !comPedido && o.debitado_em === null && pode('imo_obra_editar'),
  };
}

/** A linha da comparação ficha × inventário precisa de ser tratada: falta algo. */
export const temFalta = (l: Pick<LinhaComparacao, 'falta'>): boolean => Number(l.falta) > 0;

/** Texto do título da comparação: «Faltam 2 artigos — 1 obrigatório» ou «A fracção tem tudo o que a ficha pede». */
export function tituloComparacao(faltam: number, obrigatorios: number): string {
  if (faltam === 0) return 'A fracção tem tudo o que a ficha pede';
  return `Faltam ${faltam} artigo(s)${obrigatorios > 0 ? ` — ${obrigatorios} obrigatório(s)` : ''}`;
}

/** O custo a mostrar de uma ordem: o real do material quando já há, senão a estimativa (nunca se somam). */
export function custoDaOrdem(o: Pick<FichaOrdem, 'custo_estimado' | 'custo_real'>): { valor: number | null; real: boolean } {
  const real = Number(o.custo_real?.total ?? 0);
  return real > 0 ? { valor: real, real: true } : { valor: o.custo_estimado, real: false };
}

/** Ordem em atraso: a data prevista passou e a ordem não está fechada. */
export const emAtraso = (o: Pick<OrdemLinha, 'data_prevista' | 'estado'>, hoje: string): boolean => o.data_prevista !== null && o.data_prevista < hoje && o.estado !== 'CONCLUIDA' && o.estado !== 'CANCELADA';

const MESES: Readonly<Record<string, number>> = { MENSAL: 1, BIMESTRAL: 2, TRIMESTRAL: 3, SEMESTRAL: 6, ANUAL: 12, BIANUAL: 24, MANUAL: 0 };

/**
 * Datas propostas por periodicidade (a mesma regra do servidor): contam-se sempre a partir do dia ORIGINAL — 31 de Janeiro + 1 mês dá 28 ou 29 de Fevereiro e o mês seguinte volta ao 31 —,
 * entre 1 e 60 datas; periodicidade manual ou desconhecida dá só a primeira; data que não existe dá lista vazia.
 */
export function proporDatas(primeira: string, periodicidade: string, quantas: number): string[] {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(primeira);
  if (!m) return [];
  const [a, mes, dia] = [Number(m[1]), Number(m[2]), Number(m[3])];
  const base = new Date(Date.UTC(a, mes - 1, dia));
  if (base.getUTCFullYear() !== a || base.getUTCMonth() !== mes - 1 || base.getUTCDate() !== dia) return [];
  const n = Math.max(1, Math.min(60, Math.round(quantas || 1)));
  const passo = MESES[periodicidade] ?? 0;
  if (passo === 0) return [primeira];
  const r: string[] = [];
  for (let i = 0; i < n; i++) {
    const alvo = new Date(Date.UTC(a, mes - 1 + i * passo, 1));
    const ultimo = new Date(Date.UTC(alvo.getUTCFullYear(), alvo.getUTCMonth() + 1, 0)).getUTCDate();
    r.push(`${alvo.getUTCFullYear()}-${String(alvo.getUTCMonth() + 1).padStart(2, '0')}-${String(Math.min(dia, ultimo)).padStart(2, '0')}`);
  }
  return r;
}
