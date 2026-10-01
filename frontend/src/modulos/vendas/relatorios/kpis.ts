/**
 * Indicadores de vendas (paridade: renderRelatoriosVendasTab do legado), calculados no servidor:
 * GET /api/vendas/relatorios/resumo?inicio=&fim= (permissão vendas_relatorios_view).
 * Contam as facturas (FT) e facturas-recibo (FR) menos as notas de crédito (NC); os anulados não contam.
 * Valores em Kz como texto decimal com 2 casas.
 */
export interface ResumoVendas {
  periodo: { inicio: string; fim: string };
  documentos: number;
  liquido: string;
  imposto: string;
  bruto: string;
  notas_credito: string;
  a_receber: string;
  por_mes: { mes: string; liquido: string; bruto: string; documentos: number }[];
  maiores_clientes: { cliente_id: number | null; nome: string; nif: string | null; bruto: string; documentos: number }[];
  pendentes: {
    id: number;
    numero_documento: string | null;
    data_emissao: string | null;
    data_vencimento: string | null;
    cliente: { id: number; nome: string | null } | null;
    total_bruto: string;
    valor_pendente: string;
  }[];
}

/** Resposta de GET /api/vendas/saft/validar (validação prévia, sem gerar o ficheiro). */
export interface ValidacaoSaft {
  nome: string;
  documentos: number;
  recibos: number;
  avisos: string[];
}

/** Texto decimal → cêntimos inteiros (sem erros de vírgula flutuante). */
export function emCentimos(valor: string | null | undefined): number {
  if (!valor) return 0;
  const m = /^(-)?(\d+)(?:\.(\d{1,2}))?$/.exec(valor.trim());
  if (!m) return Math.round((Number(valor) || 0) * 100);
  const c = Number(m[2]) * 100 + Number((m[3] ?? '0').padEnd(2, '0'));
  return m[1] ? -c : c;
}

/** Barras da facturação mensal: percentagem de cada mês face ao maior valor absoluto (com IVA). */
export function barrasMensais(porMes: ResumoVendas['por_mes']): { mes: string; bruto: string; percentagem: number; negativo: boolean }[] {
  const centimos = porMes.map((m) => emCentimos(m.bruto));
  const maximo = Math.max(1, ...centimos.map((c) => Math.abs(c)));
  return porMes.map((m, i) => ({ mes: m.mes, bruto: m.bruto, percentagem: Math.round((Math.abs(centimos[i]) / maximo) * 100), negativo: centimos[i] < 0 }));
}
