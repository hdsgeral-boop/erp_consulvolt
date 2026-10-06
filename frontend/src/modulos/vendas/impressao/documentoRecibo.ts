import type { PedidoImpressao } from '@/componentes/impressao';
import { formatarKz } from '@/utilitarios/formatacao';
import { rotuloMeio, type ReciboVenda } from '../recibos/tipos';
import { CSS_DOCUMENTO_COMERCIAL, dataDoc, pedidoDocumentoComercial, type DadosDocumentoComercial } from './documentoComercial';

/** Recibo de cliente → documento comercial impresso (facturas liquidadas ou adiantamento, montante, meio de pagamento). */
export function dadosRecibo(r: ReciboVenda): DadosDocumentoComercial {
  const meio = rotuloMeio(r.meio_pagamento);
  const adiantamento = r.tipo_recibo === 'ADIANTAMENTO';
  const alocacoes = r.alocacoes ?? [];
  const linhas: (string | null)[][] = alocacoes.map((a) => [a.numero_documento ?? `#${a.venda_id}`, formatarKz(a.montante)]);
  // Adiantamento sem facturas (ainda) alocadas: a linha descreve o adiantamento em vez de «Sem linhas».
  if (adiantamento && !linhas.length) linhas.push([`Adiantamento${r.referencia ? ` — ${r.referencia}` : ''}`, formatarKz(r.montante_total)]);
  return {
    tipo: adiantamento ? 'Recibo de adiantamento' : 'Recibo',
    numero: r.numero_recibo,
    via: 'Original',
    estado: r.estado === 'ANULADO' ? `ANULADO${r.motivo_anulacao ? ` — ${r.motivo_anulacao}` : ''}` : null,
    entidade: { rotulo: 'Cliente', nome: r.cliente?.nome ?? `#${r.cliente_id}`, nif: r.cliente?.nif },
    meta: [
      ['Data', dataDoc(r.data)],
      ['Meio de pagamento', meio === '—' ? null : meio],
      ['Referência do pagamento', r.referencia_pagamento],
      ['Conta', r.codigo_conta],
      ['Contabilização', r.numero_lan_contabilizacao],
      ...(adiantamento ? ([['Saldo por alocar', `${formatarKz(r.saldo_adiantamento ?? 0)} Kz`]] as [string, string][]) : []),
    ],
    colunas: [{ titulo: adiantamento && !alocacoes.length ? 'Descrição' : 'Documento liquidado' }, { titulo: 'Montante (Kz)', alinhar: 'direita' }],
    linhas,
    totais: { total: r.montante_total, rotuloTotal: 'Total recebido' },
    pagamentos: meio !== '—' ? [{ descricao: meio, valor: r.montante_total }] : [],
    impostos: false,
    legal: ['Documento processado por computador.', adiantamento ? 'Adiantamento a deduzir nas facturas a emitir/alocar.' : null],
    assinaturas: ['O Cliente', 'A Empresa'],
  };
}

/** CSS das vias: um documento comercial em meia folha (A5) precisa de espaços mais curtos. */
export const CSS_VIAS_DOCUMENTO = `
.imp-via .dc-topo { margin: 0 0 2.5mm; gap: 4mm; }
.imp-via .dc-meio { margin-top: 2.5mm; }
.imp-via .dc-legal { margin-top: 2mm; }
.imp-via .dc-assin { margin-top: 9mm; }
.imp-via .dc-documento { display: flex; flex-direction: column; min-height: 100%; }
.imp-via .imp-via-corpo { display: flex; flex-direction: column; }
.imp-via .imp-via-corpo > .dc-documento { flex: 1 1 auto; }
.imp-via .dc-documento > .dc-assin { margin-top: auto; padding-top: 9mm; }
body[data-orientacao="paisagem"] .imp-via .dc-topo { grid-template-columns: 1fr; gap: 2mm; }
body[data-orientacao="paisagem"] .imp-via .dc-meio { grid-template-columns: 1fr; gap: 2mm; }
`;

/**
 * Pedido de impressão de um documento comercial em DUAS VIAS na mesma folha (recibos de venda, adiantamentos, notas
 * de recebimento/pagamento da tesouraria, recibos da lavandaria): «Original» e «Duplicado», cada uma com o cabeçalho da
 * empresa, separadas por linha de corte. Vertical por omissão (uma via por cima da outra); em horizontal, lado a lado.
 */
export function pedidoDuasVias(d: DadosDocumentoComercial, rotulos: string[] = ['Original', 'Duplicado']): PedidoImpressao {
  const base = pedidoDocumentoComercial({ ...d, via: null });
  return { ...base, subtitulo: null, vias: rotulos, cssExtra: `${CSS_DOCUMENTO_COMERCIAL}${CSS_VIAS_DOCUMENTO}`, papel: 'A4', orientacao: 'retrato' };
}

/** Recibo de cliente em duas vias. */
export const pedidoRecibo = (r: ReciboVenda): PedidoImpressao => pedidoDuasVias(dadosRecibo(r));
