/** Alocação de um recebimento às facturas pendentes do cliente (recibo de vendas). */

export interface FacturaPendente {
  id: number;
  data_emissao: string;
  valor_pendente: string | null;
}

const centimos = (v: number) => Math.round(v * 100);

/**
 * Distribui o montante recebido pelas facturas, da mais antiga para a mais recente, sem exceder o pendente de cada uma.
 * Devolve { id → montante } só com valores positivos; o que sobrar (montante acima do total pendente) fica em `excedente`.
 */
export function distribuirMontante(facturas: FacturaPendente[], montante: number): { alocacoes: Record<number, number>; excedente: number } {
  let resto = centimos(Math.max(0, montante));
  const alocacoes: Record<number, number> = {};
  const ordenadas = [...facturas].sort((a, b) => a.data_emissao.localeCompare(b.data_emissao) || a.id - b.id);
  for (const f of ordenadas) {
    if (resto <= 0) break;
    const pendente = centimos(Number(f.valor_pendente ?? 0));
    const valor = Math.min(pendente, resto);
    if (valor > 0) {
      alocacoes[f.id] = valor / 100;
      resto -= valor;
    }
  }
  return { alocacoes, excedente: resto / 100 };
}

/** Alocações para o pedido (só montantes positivos, com 2 casas). */
export function alocacoesParaPedido(alocacoes: Record<number, number | null | undefined>): { venda_id: number; montante: number }[] {
  return Object.entries(alocacoes)
    .filter(([, v]) => (v ?? 0) > 0)
    .map(([id, v]) => ({ venda_id: Number(id), montante: centimos(v as number) / 100 }));
}

export function totalAlocado(alocacoes: Record<number, number | null | undefined>): number {
  return Object.values(alocacoes).reduce<number>((t, v) => t + centimos(v ?? 0), 0) / 100;
}
