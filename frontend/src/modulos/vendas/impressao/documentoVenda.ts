import { MEIOS_PAGAMENTO, TIPOS_DOCUMENTO, type DocumentoVenda } from '../api';
import { dataDoc, type DadosDocumentoComercial } from './documentoComercial';

/** QR de consulta AGT já obtido (imagem em data URL e endereço de consulta). */
export interface QrDocumento {
  imagem: string;
  url: string | null;
}

const SEM_VALOR_FISCAL = ['OR', 'PF', 'NE'];
const GUIAS = ['GR', 'GD'];

/**
 * Documento de venda (FT, FR, NC, OR, PF, NE, GR, GD) → dados do documento comercial impresso, com os mesmos
 * elementos do ecrã de detalhe: cliente, datas, linhas, resumo do IVA, totais e, nos documentos do regime de
 * facturação electrónica, a série AGT, o excerto do hash e o QR de consulta (quando existe).
 */
export function dadosDocumentoVenda(d: DocumentoVenda, qr?: QrDocumento | null): DadosDocumentoComercial {
  const tipo = d.tipo_documento;
  const fe = d.faturacao_eletronica;
  const guia = GUIAS.includes(tipo);
  const semValor = SEM_VALOR_FISCAL.includes(tipo);
  const meio = MEIOS_PAGAMENTO.find((m) => m.value === d.meio_pagamento)?.label ?? d.meio_pagamento;
  const serieAgt = fe?.regime && fe.serie ? `${fe.serie}${fe.numero ? ` / ${fe.numero}` : ''}` : null;

  return {
    tipo: TIPOS_DOCUMENTO[tipo] ?? tipo,
    numero: d.numero_documento,
    via: 'Original',
    estado: d.estado === 'ANULADO' ? 'ANULADO' : null,
    avisos: [semValor && 'Este documento não serve de factura', guia && 'Documento de transporte'],
    entidade: { rotulo: 'Cliente', nome: d.cliente?.nome ?? 'Consumidor final', nif: d.cliente?.nif ?? '999999999', morada: d.cliente?.endereco ?? null },
    meta: [
      ['Data', dataDoc(d.data_emissao)],
      [tipo === 'FT' ? 'Vencimento' : 'Válido até', dataDoc(tipo === 'FT' ? d.data_vencimento : d.valido_ate)],
      ['Moeda', d.codigo_moeda || 'AOA'],
      ['Série AGT', serieAgt],
      ['Meio de pagamento', tipo === 'FR' ? meio : null],
      ['Estado', d.estado && d.estado !== 'ANULADO' && !semValor && !guia ? d.estado : null],
    ],
    semPrecos: false,
    itens: (d.linhas ?? []).map((l) => {
      const base = l.valor ?? l.total;
      return {
        descricao: l.descricao ?? `Produto #${l.produto_id}`,
        notas: l.observacoes ?? null,
        quantidade: l.quantidade,
        preco: l.preco_unitario,
        taxa: l.taxa_imposto,
        base,
        iva: l.valor !== null && l.total !== null ? (Number(l.total) - Number(l.valor)).toFixed(2) : undefined,
        total: base,
      };
    }),
    totais: { subtotal: d.total_liquido, imposto: d.total_imposto, total: d.total_bruto },
    pagamentos: tipo === 'FR' && meio ? [{ descricao: meio, valor: d.total_bruto }] : [],
    moeda: d.codigo_moeda || 'AOA',
    blocos: [
      ['Motivo da nota de crédito', d.motivo_nota_credito],
      ['Condições de pagamento', typeof d.condicoes_pagamento === 'string' ? d.condicoes_pagamento : null],
      ['Observações', d.observacoes],
    ],
    fiscal:
      fe?.regime && (qr || serieAgt)
        ? {
            qr: qr?.imagem ?? null,
            titulo: 'Documento emitido no regime de facturação electrónica',
            linhas: [serieAgt ? `Série ${serieAgt}` : null, qr ? 'Leia o QR code para consultar o documento no portal da AGT.' : null],
            url: qr?.url ?? null,
          }
        : null,
    hash: fe?.hash ? `Hash: ${fe.hash}` : null,
    legal: [
      semValor ? null : 'Documento processado por computador.',
      ['FT', 'FR', 'GR'].includes(tipo) ? 'Os bens/serviços foram colocados à disposição do adquirente na data e local do documento.' : null,
    ],
    assinaturas: guia ? ['Expedidor', 'Recebi (nome, data e assinatura)'] : undefined,
  };
}
