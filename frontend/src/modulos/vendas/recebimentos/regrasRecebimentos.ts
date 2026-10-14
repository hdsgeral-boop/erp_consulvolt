import { ErroApi } from '@/api/tipos';

/** Pré-visualização de GET /vendas/documentos/{id}/recebimentos (ServicoCancelamentoRecebimentos::previsualizar). */
export interface RecebimentosFatura {
  venda: { id: number; numero_documento: string; tipo_documento: string; estado: string | null; total_bruto: string; valor_pago: string };
  recibos: { id: number; numero: string; data: string | null; montante_total: string; montante_factura: string; contabilizado: boolean; outras_facturas: string[] }[];
  tesouraria: { id: number; numero: string; data: string | null; valor_total: string; valor_factura: string; estado: string; integrado: boolean; outras_facturas: string[] }[];
  caixa: { id: number; sessao_caixa_id: number; data: string | null; valor: string }[];
  tem_recebimentos: boolean;
  com_estorno: boolean;
  bloqueios: string[];
  pode_cancelar: boolean;
  permissoes_em_falta: string[];
}

/**
 * A factura tem de ter o recebimento cancelado antes da nota de crédito? (exigirRecebimentoCancelado do legado novo:
 * PAGO, PARCIAL ou pago > 0,01). A emissão directa de NC no servidor só recusa a factura PAGA; a conversão recusa
 * qualquer pagamento — aqui segue-se a regra mais larga, para o diálogo aparecer antes do erro.
 */
export function exigeCancelarRecebimento(d: { tipo_documento: string; estado?: string | null; valor_pago?: string | number | null }): boolean {
  if (!['FT', 'FR'].includes(d.tipo_documento) || d.estado === 'ANULADO') return false;
  return d.estado === 'PAGO' || Number(d.valor_pago ?? 0) > 0.01;
}

/** Erro NC_FATURA_PAGA devolvido pelo servidor com a indicação de que o recebimento se pode cancelar → id da factura. */
export function facturaDoErroNcPaga(e: unknown): number | null {
  if (!(e instanceof ErroApi) || e.codigo !== 'NC_FATURA_PAGA') return null;
  const id = Number(e.erros?.venda_id);
  return e.erros?.cancelar_recebimentos && Number.isInteger(id) && id > 0 ? id : null;
}

/** Linhas do que vai ser cancelado, para o diálogo de confirmação. */
export function linhasACancelar(p: Pick<RecebimentosFatura, 'recibos' | 'tesouraria'>, formatar: (v: string) => string = (v) => v): string[] {
  return [
    ...p.recibos.map((r) => `Recibo ${r.numero} — ${formatar(r.montante_factura)} desta factura${r.contabilizado ? ' (contabilizado: é estornado)' : ''}`),
    ...p.tesouraria.map((t) => `Tesouraria ${t.numero} — ${formatar(t.valor_factura)} desta factura${t.integrado ? ' (integrado: é estornado)' : ''}`),
  ];
}

/** Outras facturas que voltam a pendentes porque o recibo / documento de tesouraria é anulado por inteiro. */
export function outrasFacturasAfectadas(p: Pick<RecebimentosFatura, 'recibos' | 'tesouraria'>): string[] {
  return [...new Set([...p.recibos.flatMap((r) => r.outras_facturas), ...p.tesouraria.flatMap((t) => t.outras_facturas)])];
}
