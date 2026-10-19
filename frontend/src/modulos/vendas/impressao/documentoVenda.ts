import { MEIOS_PAGAMENTO, TIPOS_DOCUMENTO, type DocumentoVenda } from '../api';
import { formatarKz } from '@/utilitarios/formatacao';
import { valoresPrestacoes } from '../condicoes';
import { dataDoc, type DadosDocumentoComercial } from './documentoComercial';

/**
 * A-03: plano de prestações impresso (como o legado: descrição, data, percentagem e valor apurado sobre o total do
 * documento, na moeda do documento). Nulo a pronto pagamento.
 */
export function textoPlano(d: Pick<DocumentoVenda, 'modo_pagamento' | 'plano_pagamentos' | 'total_bruto' | 'moeda'>): string | null {
  const plano = d.plano_pagamentos ?? [];
  if (!d.modo_pagamento || d.modo_pagamento === 'PRONTO' || !plano.length) return null;
  const total = Number(d.moeda?.total_bruto ?? d.total_bruto) || 0;
  const valores = valoresPrestacoes(plano.map((p) => ({ descricao: p.descricao ?? '', percentagem: Number(p.percentagem), dias: null, data: p.data ?? null })), total);
  const sufixo = d.moeda ? d.moeda.codigo : 'Kz';
  const linhas = plano.map((p, i) => `${p.descricao || `${i + 1}ª prestação`} — ${p.data ? dataDoc(p.data) : 'data a definir'} — ${Number(p.percentagem)} % — ${formatarKz(valores[i])} ${sufixo}`);
  return [d.modo_pagamento === 'MARCOS' ? 'Pagamento por marcos' : 'Pagamento a prazo', ...linhas].join('\n');
}

/** Contravalor em Kz de um documento em moeda estrangeira (os valores oficiais, AGT/SAF-T, são em Kz). */
export function textoContravalor(d: Pick<DocumentoVenda, 'moeda' | 'total_liquido' | 'total_imposto' | 'total_bruto'>): string | null {
  if (!d.moeda) return null;
  return `Total: ${formatarKz(d.total_bruto)} Kz (líquido ${formatarKz(d.total_liquido)} Kz; IVA ${formatarKz(d.total_imposto)} Kz) ao câmbio de 1 ${d.moeda.codigo} = ${formatarKz(d.moeda.taxa_cambio)} Kz.`;
}

/** QR de consulta AGT já obtido (imagem em data URL e endereço de consulta). */
export interface QrDocumento {
  imagem: string;
  url: string | null;
}

const SEM_VALOR_FISCAL = ['OR', 'PF', 'NE'];
const GUIAS = ['GR', 'GD'];

/**
 * Quadro «Meios de pagamento» impresso (legado: buildDocData, js/ui_sales.js:4645-4653): o meio da factura-recibo e as
 * contas indicadas no documento («Adicionar Conta» — nome, conta, IBAN/SWIFT), salvo nos documentos sem valor fiscal,
 * nas guias e quando se escolheu «Ocultar quadro de Meios de Pagamento na impressão».
 */
export function pagamentosImpressos(d: Pick<DocumentoVenda, 'tipo_documento' | 'total_bruto' | 'contas_pagamento' | 'ocultar_meios_pagamento'>, meio?: string | null): { descricao: string; valor?: string }[] {
  if (d.ocultar_meios_pagamento || SEM_VALOR_FISCAL.includes(d.tipo_documento) || GUIAS.includes(d.tipo_documento)) return [];
  const linhas: { descricao: string; valor?: string }[] = d.tipo_documento === 'FR' && meio ? [{ descricao: meio, valor: d.total_bruto }] : [];
  for (const c of d.contas_pagamento ?? []) {
    const banco = [c.iban && `IBAN ${c.iban}`, c.swift && `SWIFT ${c.swift}`].filter(Boolean).join(' · ');
    linhas.push({ descricao: [c.nome ?? c.codigo_conta ?? 'Conta', banco].filter(Boolean).join(' — ') });
  }
  return linhas;
}

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
      ['Câmbio', d.moeda?.taxa_cambio ? `1 ${d.moeda.codigo} = ${formatarKz(d.moeda.taxa_cambio)} Kz` : null],
      ['Série AGT', serieAgt],
      ['Meio de pagamento', tipo === 'FR' ? meio : null],
      ['Estado', d.estado && d.estado !== 'ANULADO' && !semValor && !guia ? d.estado : null],
    ],
    semPrecos: false,
    itens: (d.linhas ?? []).map((l) => {
      // moeda estrangeira: preços e valores na moeda do documento (o contravalor em Kz vai no bloco próprio)
      if (d.moeda && l.preco_unitario_moeda != null) {
        return { descricao: l.descricao ?? `Produto #${l.produto_id}`, notas: l.observacoes ?? null, quantidade: l.quantidade, preco: l.preco_unitario_moeda,
          taxa: l.taxa_imposto, base: l.total_moeda, total: l.total_moeda };
      }
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
    totais: d.moeda
      ? { subtotal: d.moeda.total_liquido, imposto: d.moeda.total_imposto, total: d.moeda.total_bruto }
      : { subtotal: d.total_liquido, imposto: d.total_imposto, total: d.total_bruto },
    pagamentos: pagamentosImpressos(d, meio),
    moeda: d.codigo_moeda || 'AOA',
    blocos: [
      ['Motivo da nota de crédito', d.motivo_nota_credito],
      ['Correcção', fe?.documento_rejeitado ? `Documento de correcção do documento ${fe.documento_rejeitado}, recusado pela AGT.` : null],
      ['Plano de pagamentos', textoPlano(d)],
      ['Contravalor em Kz', textoContravalor(d)],
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
    // Decreto Presidencial n.º 71/25: o documento identifica o software validado pela AGT, o código hash e o n.º de
    // validação. Forma usada: «<4 caracteres do Hash>-Processado por programa validado n.º <n.º>» (redacção inferida da
    // prática dos programas certificados; a confirmar na homologação — docs/integracoes/agt_faturacao_eletronica.md)
    hash: fe?.hash ? (fe.software?.certificacao ? `${fe.hash}-Processado por programa validado n.º ${fe.software.certificacao}` : `Hash: ${fe.hash}`) : null,
    legal: [
      semValor ? null : 'Documento processado por computador.',
      ['FT', 'FR', 'GR'].includes(tipo) ? 'Os bens/serviços foram colocados à disposição do adquirente na data e local do documento.' : null,
    ],
    assinaturas: guia ? ['Expedidor', 'Recebi (nome, data e assinatura)'] : undefined,
  };
}
