import type { DocumentoVenda } from '../api';

/**
 * Indicadores de vendas (paridade: renderRelatoriosVendasTab do legado) calculados no cliente a partir dos documentos
 * do período. Contam as facturas (FT) e facturas-recibo (FR) menos as notas de crédito (NC); os anulados não contam.
 */
export interface KpisVendas {
  liquido: number;
  imposto: number;
  bruto: number;
  notasCredito: number;
  aReceber: number;
  documentos: number;
  porMes: { mes: string; liquido: number; bruto: number }[];
  topClientes: { cliente_id: number; nome: string; bruto: number; documentos: number }[];
  pendentes: DocumentoVenda[];
}

const r2 = (v: number) => Math.round((v + Number.EPSILON) * 100) / 100;
const n = (v: string | null | undefined) => (v ? Number(v) || 0 : 0);

export function calcularKpis(documentos: DocumentoVenda[], topN = 10): KpisVendas {
  const validos = documentos.filter((d) => d.estado !== 'ANULADO' && ['FT', 'FR', 'NC'].includes(d.tipo_documento));
  const meses = new Map<string, { liquido: number; bruto: number }>();
  const clientes = new Map<number, { nome: string; bruto: number; documentos: number }>();
  let liquido = 0;
  let imposto = 0;
  let bruto = 0;
  let notasCredito = 0;
  let aReceber = 0;

  for (const d of validos) {
    const sinal = d.tipo_documento === 'NC' ? -1 : 1;
    liquido += sinal * n(d.total_liquido);
    imposto += sinal * n(d.total_imposto);
    bruto += sinal * n(d.total_bruto);
    if (sinal < 0) notasCredito += n(d.total_bruto);
    if (d.tipo_documento === 'FT') aReceber += n(d.valor_pendente);

    const mes = (d.data_emissao ?? '').slice(0, 7);
    const m = meses.get(mes) ?? { liquido: 0, bruto: 0 };
    m.liquido += sinal * n(d.total_liquido);
    m.bruto += sinal * n(d.total_bruto);
    meses.set(mes, m);

    if (sinal > 0) {
      const c = clientes.get(d.cliente_id) ?? { nome: d.cliente?.nome?.trim() || `#${d.cliente_id}`, bruto: 0, documentos: 0 };
      c.bruto += n(d.total_bruto);
      c.documentos += 1;
      clientes.set(d.cliente_id, c);
    }
  }

  return {
    liquido: r2(liquido),
    imposto: r2(imposto),
    bruto: r2(bruto),
    notasCredito: r2(notasCredito),
    aReceber: r2(aReceber),
    documentos: validos.length,
    porMes: [...meses.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([mes, v]) => ({ mes, liquido: r2(v.liquido), bruto: r2(v.bruto) })),
    topClientes: [...clientes.entries()]
      .map(([cliente_id, c]) => ({ cliente_id, nome: c.nome, bruto: r2(c.bruto), documentos: c.documentos }))
      .sort((a, b) => b.bruto - a.bruto)
      .slice(0, topN),
    pendentes: validos
      .filter((d) => d.tipo_documento === 'FT' && n(d.valor_pendente) > 0)
      .sort((a, b) => (b.data_emissao ?? '').localeCompare(a.data_emissao ?? ''))
      .slice(0, 10),
  };
}
