/** Recibo de cliente (ReciboVendaResource). */
export interface ReciboVenda {
  id: number;
  numero_recibo: string;
  data: string;
  cliente_id: number;
  cliente?: { id: number; nome: string; nif: string | null } | null;
  montante_total: string;
  meio_pagamento: string | null;
  codigo_conta: string | null;
  referencia_pagamento: string | null;
  estado: string;
  contabilizado: boolean;
  numero_lan_contabilizacao: string | null;
  venda_origem_id: number | null;
  anulado_em: string | null;
  motivo_anulacao: string | null;
  alocacoes?: { venda_id: number; numero_documento: string | null; montante: string }[];
}

export const MEIOS_RECIBO = [
  { value: 'NUMERARIO', label: 'Numerário' },
  { value: 'TPA', label: 'TPA (Multicaixa)' },
  { value: 'TRANSFERENCIA', label: 'Transferência' },
  { value: 'CONTA_CORRENTE', label: 'Conta corrente' },
];

export function rotuloMeio(meio: string | null | undefined): string {
  if (!meio) return '—';
  return MEIOS_RECIBO.find((m) => m.value === meio.toUpperCase())?.label ?? meio;
}

/** Acções do recibo (ReciboVendaController / ServicoRecibosVenda). */
export function accoesRecibo(r: Pick<ReciboVenda, 'estado' | 'contabilizado'>, pode: (...c: string[]) => boolean) {
  const anulado = r.estado === 'ANULADO';
  return {
    contabilizar: !anulado && !r.contabilizado && pode('vendas_fat_contabilizar'),
    descontabilizar: r.contabilizado && pode('vendas_fat_unpost'),
    anular: !anulado && pode('vendas_recibos'),
  };
}
