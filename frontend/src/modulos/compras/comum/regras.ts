import type { ContratoCompra, EncomendaCompra, FaturaCompra, PedidoCompra, PropostaCompra, RececaoCompra } from './tipos';

/**
 * Que acções mostrar em cada documento de Compras. Espelham as validações do servidor
 * (ServicoProcessoCompras, ServicoDeliberacaoCompras, ServicoRececoesCompra, ServicoFaturasCompra,
 * ServicoContabilizacaoCompras, ServicoContratosFornecedores) — o servidor volta sempre a validar.
 */
export type Pode = (...chaves: string[]) => boolean;

export const TAREFAS_APROVAR = ['compras_ped_aprovar', 'compras_ped_aprovar_n2', 'compras_ped_aprovar_n3', 'compras_ped_aprovar_n4'];

/** Etapa de deliberação pendente (a única que pode ser decidida). */
export function etapaPendente(p: Pick<PedidoCompra, 'deliberacao'>) {
  return p.deliberacao?.etapas?.find((e) => e.estado === 'PENDENTE') ?? null;
}

export function accoesPedido(p: PedidoCompra, pode: Pode) {
  const etapa = etapaPendente(p);
  // Pedidos migrados sem etapas: o servidor inicia a deliberação na 1.ª decisão (nível 1).
  const tarefa = etapa && etapa.tipo !== 'LEGADO' ? etapa.tarefa ?? 'compras_ped_aprovar' : 'compras_ped_aprovar';
  return {
    decidir: p.estado === 'PENDENTE' && (etapa?.tipo === 'LEGADO' ? pode(...TAREFAS_APROVAR) : pode(tarefa)),
    anular: ['PENDENTE', 'APROVADO', 'REJEITADO'].includes(p.estado) && pode('compras_ped_eliminar'),
    registarProposta: p.estado === 'APROVADO' && pode('compras_new_proposal'),
  };
}

export function accoesProposta(c: PropostaCompra, pode: Pode) {
  return {
    propor: c.estado === 'PROPOSTA' && pode('compras_evaluate'),
    cancelarProposta: c.estado === 'PROPOSTA_ADJUDICACAO' && pode('compras_evaluate'),
    adjudicar: c.estado === 'PROPOSTA_ADJUDICACAO' && pode('compras_adjudicate'),
    anular: ['PROPOSTA', 'PROPOSTA_ADJUDICACAO'].includes(c.estado) && pode('compras_prop_eliminar'),
  };
}

export function accoesEncomenda(e: EncomendaCompra, pode: Pode) {
  const anulada = e.estado === 'ANULADA';
  return {
    registarRececao: !anulada && e.estado !== 'RECEBIDO' && pode('compras_rec_registar'),
    registarFatura: !anulada && pode('compras_fact_registar'),
    anular: !anulada && pode('compras_enc_eliminar'),
  };
}

export function accoesRececao(r: RececaoCompra, pode: Pode) {
  const anulada = r.estado === 'ANULADO';
  return {
    validar: r.estado === 'RECEBIDO' && !r.validado && pode('armazem_validar'),
    reverter: !!r.validado && pode('armazem_rec_anular'),
    anular: !anulada && !r.validado && pode('armazem_rec_anular'),
  };
}

export function accoesFatura(f: FaturaCompra, pode: Pode) {
  const anulada = f.estado === 'ANULADA';
  const paga = f.estado === 'PAGO' || f.estado === 'PARCIAL';
  return {
    contabilizar: !anulada && !f.contabilizado && pode('compras_fact_contabilizar'),
    descontabilizar: !!f.contabilizado && !paga && pode('compras_descontab'),
    anular: !anulada && !f.contabilizado && !paga && pode('compras_fact_eliminar'),
  };
}

export function accoesContrato(c: ContratoCompra, pode: Pode) {
  const cancelado = c.estado === 'CANCELADO';
  return {
    editar: !cancelado && pode('compras_contratos_gerir'),
    /** Só contratos activos aceitam novas encomendas. */
    associar: c.estado === 'ATIVO' && pode('compras_contratos_gerir', 'compras_enc_criar'),
    desassociar: !cancelado && pode('compras_contratos_gerir', 'compras_enc_criar'),
    marcos: !cancelado && pode('compras_contratos_gerir'),
    cancelar: !cancelado && pode('compras_contratos_cancelar'),
  };
}
