import { formatarKz } from '@/utilitarios/formatacao';
import { rotuloMeio, type ReciboVenda } from '../recibos/tipos';
import { dataDoc, type DadosDocumentoComercial } from './documentoComercial';

/** Recibo de cliente → documento comercial impresso (facturas liquidadas, montante, meio de pagamento). */
export function dadosRecibo(r: ReciboVenda): DadosDocumentoComercial {
  const meio = rotuloMeio(r.meio_pagamento);
  return {
    tipo: 'Recibo',
    numero: r.numero_recibo,
    via: 'Original',
    estado: r.estado === 'ANULADO' ? `ANULADO${r.motivo_anulacao ? ` — ${r.motivo_anulacao}` : ''}` : null,
    entidade: { rotulo: 'Cliente', nome: r.cliente?.nome ?? `#${r.cliente_id}`, nif: r.cliente?.nif },
    meta: [
      ['Data', dataDoc(r.data)],
      ['Meio de pagamento', meio === '—' ? null : meio],
      ['Referência', r.referencia_pagamento],
      ['Conta', r.codigo_conta],
    ],
    colunas: [{ titulo: 'Documento liquidado' }, { titulo: 'Montante (Kz)', alinhar: 'direita' }],
    linhas: (r.alocacoes ?? []).map((a) => [a.numero_documento ?? `#${a.venda_id}`, formatarKz(a.montante)]),
    totais: { total: r.montante_total, rotuloTotal: 'Total recebido' },
    pagamentos: meio !== '—' ? [{ descricao: meio, valor: r.montante_total }] : [],
    impostos: false,
    legal: ['Documento processado por computador.'],
  };
}
