/** Apresentação do Subsídio de produtividade (legado js/modules/rh/produtividade_ui.js). Os valores vêm do servidor. */
import type { DetalheProdutividade, ItemProdutividade, PeriodoProdutividade } from '../api';
import { somar } from '../comum/regras';

/** Período em que o ecrã abre: o aberto mais recente; senão o primeiro da lista (mais recente). */
export function periodoActualProdutividade(periodos: PeriodoProdutividade[]): PeriodoProdutividade | undefined {
  const ordenados = [...periodos].sort((a, b) => b.mes.localeCompare(a.mes));
  return ordenados.find((p) => p.estado !== 'FECHADO') ?? ordenados[0];
}

export interface LinhaResumoProdutividade {
  colaborador_id: number;
  nome: string;
  registos: number;
  itens: { rotulo: string; quantidade: string; valor: string }[];
  rubricas: number[];
  total: string;
}

/** «Resumo»: um colaborador por linha, com os itens (quantidade considerada → valor), as rubricas e o total. */
export function resumoProdutividade(p: DetalheProdutividade, item: (id: number) => ItemProdutividade | undefined): LinhaResumoProdutividade[] {
  const nome = (cid: number) => p.elegiveis.find((e) => e.colaborador_id === cid)?.nome ?? `#${cid}`;
  const por = new Map<number, typeof p.registos>();
  p.registos.forEach((r) => por.set(r.colaborador_id, [...(por.get(r.colaborador_id) ?? []), r]));
  return [...por.entries()].map(([cid, regs]) => {
    const porItem = new Map<number, typeof regs>();
    regs.forEach((r) => porItem.set(r.item_produtividade_id, [...(porItem.get(r.item_produtividade_id) ?? []), r]));
    const itens = [...porItem.entries()].map(([iid, rs]) => {
      const it = item(iid);
      return {
        rotulo: it ? `${it.codigo} ${it.descricao}` : `#${iid}`,
        quantidade: String(Math.round(rs.reduce((s, r) => s + Number(r.quantidade_considerada ?? r.quantidade), 0) * 10_000) / 10_000),
        valor: somar(rs.map((r) => r.valor)),
      };
    });
    const rubricas = [...new Set([...porItem.keys()].map((iid) => item(iid)?.infotipo_salarial_id).filter((x): x is number => typeof x === 'number'))];
    return { colaborador_id: cid, nome: nome(cid), registos: regs.length, itens, rubricas, total: somar(regs.map((r) => r.valor)) };
  }).sort((a, b) => a.nome.localeCompare(b.nome, 'pt'));
}

// ───────────── #11 (Versão Actual): métricas e explicação do cálculo — vêm do servidor (ServicoProdutividade) ─────────────
// O ecrã não refaz a conta: as descrições vêm de GET /rh/produtividade/metricas e a explicação (fórmula, conta, cortes e valor)
// de GET /rh/produtividade/explicar ou …/periodos/{id}/explicacao — o mesmo motor que grava o valor dos registos.

export type BaseCalculo = 'UNITARIO' | 'PREMIO';

/** Descrição de uma métrica (DESCRICAO_METRICAS do servidor). */
export interface DescricaoMetrica {
  nome: string;
  rotulo_quantidade: string;
  rotulo_preco: string;
  rotulo_minimo: string;
  rotulo_maximo: string;
  formula: string;
  exemplo: string;
  ajuda: string;
  base_premio: boolean;
  quantidade_exemplo: number;
  formula_unitario?: string;
  rotulo_preco_unitario?: string;
}

/** GET /rh/produtividade/metricas */
export interface MetricasProdutividade {
  metricas: Record<string, DescricaoMetrica>;
  bases: Record<BaseCalculo, { nome: string; formula: string }>;
}

/** GET /rh/produtividade/explicar — a conta de um caso, feita pelo motor do servidor. */
export interface ExplicacaoCalculo {
  metrica: string;
  base_calculo: BaseCalculo;
  formula: string;
  rotulo_preco: string;
  conta: string;
  cortes: string[];
  quantidade: number;
  quantidade_considerada: number;
  preco: number;
  valor: string;
}

/** Descrição da métrica para mostrar; sem métrica (itens antigos) mostra a de QUANTIDADE, como o servidor a trata. */
export const descricaoMetrica = (m: MetricasProdutividade | undefined, codigo: string | null | undefined): DescricaoMetrica | undefined =>
  m?.metricas[codigo ?? ''] ?? m?.metricas.QUANTIDADE;

/** Nome da métrica (o código enquanto as descrições não chegam). */
export const nomeMetrica = (m: MetricasProdutividade | undefined, codigo: string | null | undefined): string => descricaoMetrica(m, codigo)?.nome ?? codigo ?? '—';

/** A conta com os cortes de mínimo e tecto, numa linha (só formatação do que o servidor devolve). */
export const textoExplicacao = (e: Pick<ExplicacaoCalculo, 'conta' | 'cortes'>): string => `${e.conta}${e.cortes.length ? ` (${e.cortes.join('; ')})` : ''}`;

/** Valores do formulário do item que o servidor usa para simular a conta. */
export interface ValoresSimulacao {
  metrica?: string | null;
  base_calculo?: string | null;
  preco_unitario?: number | string | null;
  unidade?: string | null;
  minimo?: number | string | null;
  maximo?: number | string | null;
}

/** Parâmetros de GET /rh/produtividade/explicar para o item ainda por gravar (preço vazio vai como 0: devolve fórmula e rótulos). */
export function parametrosSimulacao(v: ValoresSimulacao): Record<string, string> {
  const p: Record<string, string> = { metrica: v.metrica || 'QUANTIDADE', base_calculo: v.base_calculo === 'PREMIO' ? 'PREMIO' : 'UNITARIO' };
  const preco = Number(v.preco_unitario);
  p.preco = Number.isFinite(preco) && preco > 0 ? String(preco) : '0';
  if (v.unidade) p.unidade = String(v.unidade).slice(0, 10);
  for (const k of ['minimo', 'maximo'] as const) {
    const n = v[k];
    if (n !== null && n !== undefined && n !== '' && Number.isFinite(Number(n)) && Number(n) >= 0) p[k] = String(n);
  }
  return p;
}
