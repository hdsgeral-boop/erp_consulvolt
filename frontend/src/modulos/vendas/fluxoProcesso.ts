import { CONTABILIZAVEIS, TIPOS_DOCUMENTO } from './api';

/** GET /vendas/documentos/{id}/fluxo — «Fluxo Completo do Processo» (showDocumentWorkflow, js/ui_sales.js:5758). */
export interface FluxoVenda {
  id: number;
  tipo_documento: string;
  numero_documento: string;
  data_emissao: string | null;
  estado: string | null;
  total_bruto: string | null;
  contabilizado: boolean;
  actual: boolean;
}

export interface FluxoRecibo {
  id: number;
  numero: string;
  data: string | null;
  valor: string;
  venda_id: number;
  numero_documento: string | null;
  estado: string | null;
}

export interface FluxoLancamento {
  referencia: string;
  numero_lan: string | null;
  linhas: { conta: string; debito: string | null; credito: string | null }[];
}

export interface FluxoAprovisionamento {
  tipo: string;
  id: number;
  documento: string;
  estado: string | null;
  data: string | null;
}

export interface FluxoProcesso {
  vendas: FluxoVenda[];
  recibos: FluxoRecibo[];
  lancamentos: FluxoLancamento[];
  aprovisionamento: FluxoAprovisionamento[];
}

/** «Factura FT 2026/1», como o legado (`${doc_type} ${doc_number}`). */
export const rotuloDocumentoFluxo = (v: Pick<FluxoVenda, 'tipo_documento' | 'numero_documento'>): string =>
  `${TIPOS_DOCUMENTO[v.tipo_documento] ?? v.tipo_documento} ${v.numero_documento}`;

/** Cor do estado no cartão: verde quando pago/concluído (badge-success do legado), vermelho se anulado, senão laranja. */
export function corEstadoFluxo(estado: string | null | undefined): 'success' | 'warning' | 'error' {
  const e = (estado ?? '').toUpperCase();
  if (e === 'PAGO' || e === 'CONCLUIDO') return 'success';
  if (e === 'ANULADO') return 'error';
  return 'warning';
}

export interface BotoesFluxo {
  contabilizar: boolean;
  descontabilizar: boolean;
  converter: boolean;
  emitirRecibo: boolean;
}

/**
 * Botões de «Ações de Fluxo Sugeridas» (rodapé de showDocumentWorkflow): Imprimir e Abrir no Módulo estão sempre;
 * Descontabilizar quando contabilizado (o legado só tinha este; aqui também Contabilizar quando ainda não está, como
 * na linha da lista); Converter/Faturar em orçamentos, proformas, encomendas e guias não concluídos; Emitir Recibo
 * numa factura por pagar (a FR é paga na emissão). Mesmas permissões da lista; o servidor valida cada acção.
 */
export function botoesFluxo(d: Pick<FluxoVenda, 'tipo_documento' | 'estado' | 'contabilizado'> | undefined, pode: (...p: string[]) => boolean): BotoesFluxo {
  if (!d) return { contabilizar: false, descontabilizar: false, converter: false, emitirRecibo: false };
  const anulado = d.estado === 'ANULADO';
  const contabilizavel = CONTABILIZAVEIS.includes(d.tipo_documento);
  return {
    contabilizar: contabilizavel && !d.contabilizado && !anulado && pode('vendas_fat_contabilizar'),
    descontabilizar: contabilizavel && d.contabilizado && pode('vendas_fat_descontab'),
    converter: ['OR', 'PF', 'NE', 'GR'].includes(d.tipo_documento) && d.estado !== 'CONCLUIDO' && !anulado && pode('vendas_fat_emitir'),
    emitirRecibo: d.tipo_documento === 'FT' && d.estado !== 'PAGO' && !anulado && pode('vendas_recibos'),
  };
}
