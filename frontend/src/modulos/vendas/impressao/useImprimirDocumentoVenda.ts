import { useCallback } from 'react';
import { http, obter } from '@/api/cliente';
import { useImpressao } from '@/componentes/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { accoesAgt, svgComoDataUrl } from '../agt/accoesDocumento';
import type { DocumentoVenda } from '../api';
import { pedidoDocumentoComercial } from './documentoComercial';
import { dadosDocumentoVenda, type QrDocumento } from './documentoVenda';
import { useMeioPagamentoPredefinido } from './meioPredefinido';

/**
 * «Imprimir» na linha da lista (legado: printInvoice(id)): lê o documento completo e imprime-o como no detalhe (A4
 * retrato, com o QR de consulta AGT quando o documento está selado no regime de facturação electrónica).
 */
export function useImprimirDocumentoVenda() {
  const { imprimir } = useImpressao();
  const { pode } = useSessao();
  const obterPredefinido = useMeioPagamentoPredefinido();
  return useCallback(
    async (id: number) => {
      try {
        const d = await obter<DocumentoVenda>(`/vendas/documentos/${id}`);
        let qr: QrDocumento | null = null;
        if (accoesAgt(d, pode).qr) {
          try {
            const r = await http.get<string>(`/vendas/documentos/${d.id}/qr`, { params: { formato: 'svg' } });
            qr = { imagem: svgComoDataUrl(String(r.data)), url: (r.headers['x-url-consulta'] as string | undefined) ?? null };
          } catch {
            qr = null;
          }
        }
        await imprimir(pedidoDocumentoComercial(dadosDocumentoVenda(d, qr, await obterPredefinido())));
      } catch (e) {
        notificarErro(e, 'Não foi possível imprimir o documento');
      }
    },
    [imprimir, pode, obterPredefinido],
  );
}
