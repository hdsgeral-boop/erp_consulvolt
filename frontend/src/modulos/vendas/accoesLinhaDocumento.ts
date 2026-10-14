import type { AccaoLinha } from '@/componentes/vistas';
import { CONTABILIZAVEIS, CONVERSOES, FISCAIS, type DocumentoVenda } from './api';

export type GrupoLista = 'faturas' | 'orcamentos' | 'encomendas' | 'guias';

/** O que cada botão faz (ligado pelo ecrã aos endpoints). */
export interface AccoesDocumento {
  /** Janela «Fluxo Completo do Processo» (showDocumentWorkflow). */
  verProcesso: () => void;
  /** Documento AGT: página de detalhe (sem isto, usa verProcesso). */
  verDocumentoAgt?: () => void;
  imprimir: () => unknown;
  copiar: () => unknown;
  contabilizar: () => unknown;
  descontabilizar: () => unknown;
  converter: () => void;
  pedidoCompra: () => unknown;
  liquidar: () => void;
  limparEstado: () => unknown;
  /** «Anular» (chave histórica «eliminar»): o ecrã pede o motivo e chama POST /anular — os documentos não se apagam. */
  eliminar: () => unknown;
}

/**
 * Razão do bloqueio de «Anular» (legado: deleteSale, js/ui_sales.js:1962-2000; legado novo: AnularDocs.anularVenda — os
 * documentos não se apagam, anulam-se com motivo), ou null quando é permitido.
 */
export function bloqueioEliminar(d: Pick<DocumentoVenda, 'tipo_documento' | 'estado' | 'contabilizado' | 'valor_pago'>): string | null {
  if (FISCAIS.includes(d.tipo_documento)) return 'Documento fiscal: não se anula, corrige-se com Nota de Crédito';
  if (d.estado === 'ANULADO') return 'Documento já anulado';
  if (d.contabilizado || Number(d.valor_pago ?? 0) > 0.009) return 'Anulação Bloqueada: documento com liquidações ou contabilizado';
  if (d.estado === 'CONCLUIDO' && d.tipo_documento !== 'GD') return 'Anulação Bloqueada: documento já convertido';
  return null;
}

/**
 * Acções por linha da Faturação, pela ordem, com os ícones e as regras do legado (renderFaturaçãoTab, js/ui_sales.js:
 * 685-707): Ver Processo, Documento AGT, Imprimir, Copiar Documento, Contabilizar / Descontabilizar (facturas e guias),
 * Converter (orçamentos, encomendas, guias), Converter em Pedido de Compra (encomendas), Liquidar (factura contabilizada
 * por pagar), Forçar limpeza de estado e Anular (bloqueado quando fiscal, contabilizado ou com liquidações; pede motivo). As mesmas
 * acções continuam no detalhe; o servidor valida cada uma.
 */
export function accoesLinhaDocumento(d: DocumentoVenda, grupo: GrupoLista, pode: (...p: string[]) => boolean, fazer: AccoesDocumento): AccaoLinha[] {
  const anulado = d.estado === 'ANULADO';
  const fatOuGuia = grupo === 'faturas' || grupo === 'guias';
  const contabilizavel = fatOuGuia && CONTABILIZAVEIS.includes(d.tipo_documento);
  const regime = !!d.faturacao_eletronica?.regime;
  const lista: (AccaoLinha | false)[] = [
    { chave: 'processo', icone: 'fa-project-diagram', titulo: 'Ver Processo', onClick: fazer.verProcesso },
    regime && { chave: 'agt', icone: 'fa-file-code', titulo: 'Documento AGT (facturação electrónica)', cor: d.faturacao_eletronica?.estado === 'PRONTO' ? '#047857' : '#b45309', onClick: fazer.verDocumentoAgt ?? fazer.verProcesso },
    { chave: 'imprimir', icone: 'fa-print', titulo: 'Imprimir', onClick: fazer.imprimir },
    pode('vendas_fat_emitir') && !['NC', 'GD'].includes(d.tipo_documento) && { chave: 'copiar', icone: 'fa-copy', titulo: 'Copiar Documento', onClick: fazer.copiar },
    contabilizavel && !d.contabilizado && !anulado && pode('vendas_fat_contabilizar') && {
      chave: 'contabilizar', icone: 'fa-book', titulo: 'Contabilizar', variante: 'primario', confirmar: `Contabilizar ${d.numero_documento}?`, onClick: fazer.contabilizar,
    },
    contabilizavel && d.contabilizado && pode('vendas_fat_descontab') && { chave: 'descontabilizar', icone: 'fa-undo', titulo: 'Descontabilizar', variante: 'perigo', onClick: fazer.descontabilizar },
    contabilizavel && d.contabilizado && { chave: 'contabilizado', icone: 'fa-check-circle', titulo: 'Contabilizado', variante: 'sucesso', desactivado: `Contabilizado${d.numero_lan_contabilizacao ? ` (${d.numero_lan_contabilizacao})` : ''}` },
    grupo !== 'faturas' && !anulado && (CONVERSOES[d.tipo_documento] ?? []).length > 0 && pode('vendas_fat_emitir') && {
      chave: 'converter', icone: 'fa-exchange-alt', titulo: 'Converter', variante: 'sucesso', cheio: true, desactivado: d.estado === 'CONCLUIDO' ? 'Documento já totalmente convertido' : false, onClick: fazer.converter,
    },
    grupo === 'encomendas' && !anulado && pode('compras_gerar_pedidos', 'compras_ped_criar') && {
      chave: 'pedido', icone: 'fa-shopping-cart', titulo: 'Converter em Pedido de Compra', variante: 'aviso', cheio: true,
      confirmar: `Deseja converter a encomenda ${d.numero_documento} num Pedido de Compra (Back-to-Back)?`, onClick: fazer.pedidoCompra,
    },
    grupo === 'faturas' && d.tipo_documento === 'FT' && d.contabilizado && !anulado && d.estado !== 'PAGO' && pode('vendas_recibos') && {
      chave: 'liquidar', icone: 'fa-university', titulo: 'Liquidar', variante: 'primario', cheio: true, onClick: fazer.liquidar,
    },
    grupo === 'faturas' && d.tipo_documento === 'FT' && !anulado && pode('vendas_fat_emitir') && {
      chave: 'limpar', icone: 'fa-broom', titulo: 'Forçar Limpeza de Estado (usar se o documento estiver bloqueado)', cor: '#f59e0b',
      confirmar: { titulo: `Forçar a limpeza de estado de ${d.numero_documento}?`, texto: 'O pendente e o estado são recalculados a partir dos recibos e notas de crédito válidos (nada é apagado).', textoOk: 'Recalcular' },
      onClick: fazer.limparEstado,
    },
    pode('vendas_fat_del') && {
      // a confirmação é o próprio pedido do motivo (ecrã)
      chave: 'eliminar', icone: 'fa-ban', titulo: 'Anular', variante: 'perigo', cheio: true, desactivado: bloqueioEliminar(d) ?? false,
      onClick: fazer.eliminar,
    },
  ];
  return lista.filter(Boolean) as AccaoLinha[];
}
