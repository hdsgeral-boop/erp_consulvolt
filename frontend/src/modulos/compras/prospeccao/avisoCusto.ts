/**
 * Aviso da proposta de fornecedor face ao valor da encomenda do cliente que originou o pedido: o custo proposto
 * (convertido para Kz) a partir de 70 % do preço de venda da encomenda é sinalizado, e acima de 100 % é alerta.
 */
export const LIMIAR_AVISO = 0.7;

export type NivelCusto = 'ok' | 'aviso' | 'acima';

export interface AvaliacaoLinha {
  produto_id: number;
  /** Preço unitário proposto em Kz (null sem preço ou sem câmbio para moeda estrangeira). */
  custoKz: number | null;
  referenciaKz: number;
  /** custo / referência (1 = 100 %). */
  razao: number | null;
  nivel: NivelCusto;
  documentos: string[];
}

export function nivelDaRazao(razao: number | null): NivelCusto {
  if (razao === null) return 'ok';
  if (razao > 1) return 'acima';
  return razao >= LIMIAR_AVISO ? 'aviso' : 'ok';
}

/** Câmbio a usar: 1 em AOA; em moeda estrangeira só o câmbio indicado (sem ele não se compara). */
export function cambioKz(moeda: string, taxa?: number | null): number | null {
  if (!moeda || moeda.toUpperCase() === 'AOA') return 1;
  return taxa && taxa > 0 ? taxa : null;
}

export function avaliarLinhas(
  linhas: { produto_id: number; preco_unitario?: number | null }[],
  referencias: Record<string, { preco_kz: string; documentos: string[] }> | undefined,
  cambio: number | null,
): AvaliacaoLinha[] {
  if (!referencias) return [];
  return linhas.flatMap((l) => {
    const ref = referencias[String(l.produto_id)];
    const referenciaKz = ref ? Number(ref.preco_kz) : 0;
    if (!ref || !(referenciaKz > 0)) return [];
    const custoKz = l.preco_unitario != null && cambio !== null ? Number(l.preco_unitario) * cambio : null;
    const razao = custoKz === null ? null : custoKz / referenciaKz;
    return [{ produto_id: l.produto_id, custoKz, referenciaKz, razao, nivel: nivelDaRazao(razao), documentos: ref.documentos }];
  });
}
