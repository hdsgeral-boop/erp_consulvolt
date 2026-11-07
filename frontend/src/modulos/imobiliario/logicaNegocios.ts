import type { FaseNegocio, FichaNegocio, IsencaoNegocio, Negocio, TipoNegocio } from './apiNegocios';

/** Rótulos e cores das fases (tokens do legado). A fase vem sempre em texto: a cor só reforça. */
export const ROTULO_FASE: Readonly<Record<FaseNegocio, string>> = {
  NEGOCIACAO: 'Em negociação', PROMESSA: 'Promessa assinada', ESCRITURA: 'Escritura feita', REGISTADO: 'Registado na Conservatória', CONCLUIDO: 'Concluído', CANCELADO: 'Cancelado',
};
export const COR_FASE: Readonly<Record<FaseNegocio, string>> = { NEGOCIACAO: 'default', PROMESSA: 'orange', ESCRITURA: 'blue', REGISTADO: 'purple', CONCLUIDO: 'green', CANCELADO: 'red' };
export const ROTULO_TIPO: Readonly<Record<TipoNegocio, string>> = { COMPRA: 'Compra', VENDA: 'Venda' };
export const FASES_DA_BARRA: readonly FaseNegocio[] = ['NEGOCIACAO', 'PROMESSA', 'ESCRITURA', 'REGISTADO', 'CONCLUIDO'];
const ORDEM: Readonly<Record<FaseNegocio, number>> = { NEGOCIACAO: 1, PROMESSA: 2, ESCRITURA: 3, REGISTADO: 4, CONCLUIDO: 5, CANCELADO: 0 };
export const ordemDaFase = (f: FaseNegocio): number => ORDEM[f];
export const fechado = (n: Pick<Negocio, 'estado'>): boolean => n.estado === 'CONCLUIDO' || n.estado === 'CANCELADO';

export const ROTULO_EVENTO: Readonly<Record<string, string>> = {
  CRIADO: 'Criado', APROVADO: 'Aprovado', APROVACAO_RETIRADA: 'Aprovação retirada', FASE: 'Mudou de fase', CANCELADO: 'Cancelado', ENCARGOS: 'Encargos gravados', PAGAMENTO: 'Pagamento registado',
  PAGAMENTO_ANULADO: 'Pagamento anulado', CONTRATOS: 'Contratos tratados', CONCLUIDO: 'Concluído', ABATIDO: 'Bem abatido e alienação lançada',
};

export const formatarMoeda = (v: number | string | null | undefined): string => {
  const n = Number(v);
  return v === null || v === undefined || !Number.isFinite(n) ? '—' : `${new Intl.NumberFormat('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n)} Kz`;
};

export interface TaxasTransmissao { ip_transmissao: number; selo_transmissao: number; escritura_fixo: number }
export interface ImpostosPrevia { base: number; baseEVpt: boolean; ip: number; selo: number; escritura: number; total: number }

/** Dinheiro em cêntimos inteiros (sem float nos totais). */
const cent = (v: number | string | null | undefined): bigint => BigInt(Math.round((Number(v) || 0) * 100));
/** `cêntimos × taxa %` com «meio para cima» ao cêntimo (a taxa com 4 casas, em inteiros). */
const percentagem = (c: bigint, taxa: number): bigint => {
  const num = c * BigInt(Math.round(taxa * 10000));
  const den = 1_000_000n;
  return (num * 2n + den) / (2n * den);
};

/**
 * Pré-visualização dos impostos da transmissão (a mesma conta do servidor, que manda): base = o MAIOR entre o preço e o valor patrimonial; Imposto Predial com a isenção
 * (TOTAL zera, METADE reparte ao meio); selo; escritura fixa. A isenção só toca no Imposto Predial.
 */
export function previaImpostos(preco: number | null | undefined, vpt: number | null | undefined, t: TaxasTransmissao, isencao: IsencaoNegocio = 'NAO'): ImpostosPrevia {
  const p = cent(preco);
  const v = cent(vpt);
  const base = v > p ? v : p;
  let ip = percentagem(base, t.ip_transmissao);
  if (isencao === 'TOTAL') ip = 0n;
  else if (isencao === 'METADE') ip = (ip + 1n) / 2n;
  const selo = percentagem(base, t.selo_transmissao);
  const escritura = cent(t.escritura_fixo);
  const k = (x: bigint) => Number(x) / 100;
  return { base: k(base), baseEVpt: v > p, ip: k(ip), selo: k(selo), escritura: k(escritura), total: k(ip + selo + escritura) };
}

export interface AccoesNegocio {
  editar: boolean; custos: boolean; aprovar: boolean; avancar: boolean; tratarContratos: boolean; cancelar: boolean; concluir: boolean; abater: boolean; pagamento: boolean; eliminar: boolean;
}

/**
 * O que cada utilizador pode fazer a um negócio (o servidor repete todas as regras e recusa o resto): editar e encargos enquanto aberto; aprovar uma vez; avançar com as faltas
 * resolvidas; cancelar antes da escritura; concluir depois da escritura; abater só a venda concluída com bem por abater; pagamentos só com o negócio aprovado e não cancelado.
 * Quem aprova não regista pagamentos e quem conclui a venda não abate (pares de segregação do catálogo), por isso os botões dependem de permissões distintas.
 */
export function accoesDoNegocio(
  n: Pick<FichaNegocio, 'estado' | 'tipo' | 'aprovado' | 'fases_seguintes' | 'contratos_em_vigor' | 'ativo_id' | 'abatido_em' | 'pagamentos'>,
  pode: (...p: string[]) => boolean,
): AccoesNegocio {
  const aberto = !fechado(n);
  const ordem = ordemDaFase(n.estado);
  return {
    editar: aberto && pode('imo_neg_editar'),
    custos: aberto && pode('imo_neg_editar'),
    aprovar: aberto && !n.aprovado && pode('imo_neg_aprovar'),
    avancar: aberto && n.fases_seguintes.length > 0 && pode('imo_neg_fase'),
    tratarContratos: aberto && n.tipo === 'VENDA' && n.contratos_em_vigor.length > 0 && pode('imo_neg_fase'),
    cancelar: aberto && ordem < 3 && pode('imo_neg_fase'),
    concluir: aberto && ordem >= 3 && pode('imo_neg_concluir'),
    abater: n.tipo === 'VENDA' && n.estado === 'CONCLUIDO' && n.ativo_id !== null && n.abatido_em === null && pode('imo_neg_abater'),
    pagamento: n.aprovado && n.estado !== 'CANCELADO' && pode('imo_neg_pagamento'),
    eliminar: (n.estado === 'CANCELADO' || ordem < 3) && n.estado !== 'CONCLUIDO' && n.pagamentos.length === 0 && pode('imo_neg_editar'),
  };
}

/** Texto do resultado da venda: «mais-valia» ou «menos-valia» (nunca só a cor). */
export const rotuloResultado = (v: number | string | null | undefined): string => (Number(v) >= 0 ? 'Mais-valia' : 'Menos-valia');
