import type { Pode } from '@/modulos/compras/comum/regras';
import type { GuiaSaida, LinhaInventario, SessaoInventario } from './tipos';

/** Guias geridas pelo sistema novo (GuiaSaida::eGerida): consumo interno e vendas ao balcão do POS de armazém. */
export function guiaGerida(g: Pick<GuiaSaida, 'tipo' | 'tipo_original'>): boolean {
  return g.tipo === 'CONSUMO' || g.tipo === 'VENDA_BALCAO' || (g.tipo === 'VENDA' && g.tipo_original === 'VENDA_BALCAO');
}

/** Acções das guias de saída (ServicoGuiasSaida). */
export function accoesGuia(g: GuiaSaida, pode: Pode) {
  const anulada = g.estado === 'ANULADA';
  const gerida = guiaGerida(g);
  return {
    contabilizar: gerida && !anulada && !g.contabilizado && pode('armazem_guias_contab'),
    descontabilizar: !!g.contabilizado && pode('armazem_guias_contab'),
    anular: gerida && !anulada && !g.contabilizado && pode('armazem_guias_anular'),
  };
}

/** Acções das sessões de inventário (ServicoInventario::exigirEstado). */
export function accoesInventario(s: SessaoInventario, pode: Pode) {
  return {
    contar: s.estado === 'EM_CONTAGEM' && pode('inventario_count'),
    rever: s.estado === 'REVISAO' && pode('inventario_rever'),
    voltarContagem: s.estado === 'REVISAO' && pode('inventario_rever'),
    aprovar: s.estado === 'REVISAO' && pode('inventario_manage'),
    reabrir: s.estado === 'CONCLUIDA' && pode('inventario_manage'),
    anular: (s.estado === 'EM_CONTAGEM' || s.estado === 'REVISAO') && pode('inventario_manage'),
  };
}

export interface PrevisaoRegularizacao {
  sobras: { linhas: number; quantidade: number; valor: number };
  quebras: { linhas: number; quantidade: number; valor: number };
  semDiferenca: number;
  /** Diferenças sem justificação (o legado exigia justificar as diferenças antes de aprovar). */
  porJustificar: number;
}

const r2 = (v: number) => Math.round((v + Number.EPSILON) * 100) / 100;
const r3 = (v: number) => Math.round(v * 1000) / 1000;

/**
 * Pré-visualização da regularização de um inventário em revisão: diferença (contado − sistema) × custo
 * (o personalizado da revisão ou, na falta dele, o custo médio). Estimativa — quem regulariza é o servidor.
 */
export function previsualizarRegularizacao(linhas: Pick<LinhaInventario, 'quantidade_sistema' | 'quantidade_contada' | 'diferenca' | 'custo_personalizado' | 'custo_medio' | 'justificacao'>[]): PrevisaoRegularizacao {
  const res: PrevisaoRegularizacao = { sobras: { linhas: 0, quantidade: 0, valor: 0 }, quebras: { linhas: 0, quantidade: 0, valor: 0 }, semDiferenca: 0, porJustificar: 0 };
  for (const l of linhas) {
    const dif = l.diferenca !== null && l.diferenca !== undefined ? Number(l.diferenca) : Number(l.quantidade_contada ?? 0) - Number(l.quantidade_sistema ?? 0);
    if (!dif) {
      res.semDiferenca++;
      continue;
    }
    const custo = l.custo_personalizado !== null && l.custo_personalizado !== undefined && l.custo_personalizado !== '' ? Number(l.custo_personalizado) : Number(l.custo_medio ?? 0);
    const alvo = dif > 0 ? res.sobras : res.quebras;
    alvo.linhas++;
    alvo.quantidade = r3(alvo.quantidade + Math.abs(dif));
    alvo.valor = r2(alvo.valor + r2(Math.abs(dif) * custo));
    if (!l.justificacao?.trim()) res.porJustificar++;
  }
  return res;
}
