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

// ───────────── #11 (Versão Actual): métricas, base «prémio» e explicação do cálculo (produtividade.js:36-160) ─────────────

export type BaseCalculo = 'UNITARIO' | 'PREMIO';

export interface DescricaoMetrica {
  nome: string;
  rotuloQuantidade: string;
  rotuloPreco: string;
  rotuloMinimo: string;
  rotuloMaximo: string;
  formula: string;
  exemplo: string;
  ajuda: string;
  basePremio: boolean;
}

/** Cada métrica diz por palavras o que se escreve, o que vale o preço e como se chega ao subsídio (METRICAS do legado). */
export const METRICAS: Record<string, DescricaoMetrica> = {
  QUANTIDADE: {
    nome: 'Quantidade produzida', rotuloQuantidade: 'Quantidade produzida no período', rotuloPreco: 'Valor por unidade',
    rotuloMinimo: 'Quantidade mínima para pagar', rotuloMaximo: 'Quantidade máxima paga',
    formula: 'Subsídio = quantidade produzida × valor por unidade', exemplo: '40 m³ × 2 000 Kz/m³ = 80 000 Kz',
    ajuda: 'Paga-se o que foi produzido, à peça. Escreve-se a quantidade do período e o sistema multiplica pelo valor da unidade.', basePremio: false,
  },
  HORAS: {
    nome: 'Horas produtivas', rotuloQuantidade: 'Horas produtivas no período', rotuloPreco: 'Valor por hora',
    rotuloMinimo: 'Horas mínimas para pagar', rotuloMaximo: 'Horas máximas pagas',
    formula: 'Subsídio = horas produtivas × valor por hora', exemplo: '120 h × 800 Kz/h = 96 000 Kz',
    ajuda: 'Paga-se o tempo efectivamente produtivo ou de máquina. Não confundir com horas extraordinárias, que se tratam na Efectividade.', basePremio: false,
  },
  OBJECTIVO: {
    nome: 'Cumprimento de objectivo (%)', rotuloQuantidade: 'Percentagem atingida do objectivo', rotuloPreco: 'Prémio a 100% do objectivo',
    rotuloMinimo: '% mínima para haver prémio', rotuloMaximo: '% máxima considerada (tecto)',
    formula: 'Subsídio = prémio a 100% × (% atingida ÷ 100)', exemplo: 'objectivo cumprido a 95% com prémio de 50 000 Kz → 50 000 × 0,95 = 47 500 Kz',
    ajuda: 'Define-se quanto vale o objectivo cumprido a 100% e paga-se na proporção do que foi atingido. Use a % mínima (ex.: 80%) para não pagar abaixo disso e o tecto (ex.: 120%) para limitar o excesso.',
    basePremio: true,
  },
  PONTOS: {
    nome: 'Pontos de desempenho/qualidade', rotuloQuantidade: 'Pontos obtidos na grelha', rotuloPreco: 'Valor por ponto',
    rotuloMinimo: 'Pontos mínimos para pagar', rotuloMaximo: 'Pontos máximos pagos',
    formula: 'Subsídio = pontos obtidos × valor por ponto', exemplo: '85 pontos × 300 Kz/ponto = 25 500 Kz',
    ajuda: 'Para grelhas de avaliação ou de qualidade (ex.: 0 a 100 pontos). Cada ponto vale um valor fixo. Ponha o tecto igual ao máximo da grelha.', basePremio: false,
  },
  TAREFAS: {
    nome: 'Tarefas / ordens concluídas', rotuloQuantidade: 'Tarefas concluídas no período', rotuloPreco: 'Valor por tarefa concluída',
    rotuloMinimo: 'Tarefas mínimas para pagar', rotuloMaximo: 'Tarefas máximas pagas',
    formula: 'Subsídio = tarefas concluídas × valor por tarefa', exemplo: '12 entregas × 2 000 Kz/entrega = 24 000 Kz',
    ajuda: 'Paga-se por trabalho concluído: ordem de serviço, visita, entrega ou contrato. Só contam as concluídas no período.', basePremio: false,
  },
};

export const BASES_CALCULO: Record<BaseCalculo, { nome: string; formula: string }> = {
  UNITARIO: { nome: 'Valor por unidade (quantidade × preço)', formula: 'quantidade × valor unitário' },
  PREMIO: { nome: 'Prémio proporcional à percentagem atingida', formula: 'prémio × (% atingida ÷ 100)' },
};

/** Descrição da métrica (as desconhecidas valem como QUANTIDADE). */
export const metricaDe = (m: string | null | undefined): DescricaoMetrica => METRICAS[m ?? ''] ?? METRICAS.QUANTIDADE;

/** Base efectiva: PREMIO só gravada assim e na métrica OBJECTIVO; os itens antigos valem como UNITARIO. */
export const baseDe = (i: Pick<ItemProdutividade, 'base_calculo' | 'metrica'> | null | undefined): BaseCalculo =>
  i?.base_calculo === 'PREMIO' && i.metrica === 'OBJECTIVO' ? 'PREMIO' : 'UNITARIO';

type ItemCalculo = Pick<ItemProdutividade, 'base_calculo' | 'metrica' | 'unidade'> & { minimo: string | number | null; maximo: string | number | null };

/** Quantidade considerada: 0 abaixo do mínimo; o tecto acima do máximo. */
export function quantidadeConsiderada(i: ItemCalculo, q: number): number {
  if (i.minimo !== null && i.minimo !== '' && q < Number(i.minimo)) return 0;
  if (i.maximo !== null && i.maximo !== '' && Number(i.maximo) > 0 && q > Number(i.maximo)) return Number(i.maximo);
  return q;
}

/** Valor ao cêntimo: UNITARIO = considerada × preço; PREMIO = preço × (considerada ÷ 100) (calcularValor, produtividade.js:149). */
export function valorProdutividade(i: ItemCalculo, q: number, preco: number): number {
  const qc = quantidadeConsiderada(i, q);
  return Math.round((baseDe(i) === 'PREMIO' ? preco * (qc / 100) : qc * preco) * 100) / 100;
}

const n = (v: number) => new Intl.NumberFormat('pt-PT', { maximumFractionDigits: 4 }).format(v);

/** A conta escrita por extenso, com os números do caso e os cortes de mínimo e tecto (explicarCalculo, produtividade.js:155). */
export function explicarCalculo(i: ItemCalculo, q: number, preco: number): { formula: string; conta: string; cortes: string[]; valor: number } {
  const un = i.unidade ?? '';
  const qc = quantidadeConsiderada(i, q);
  const cortes: string[] = [];
  if (i.minimo !== null && i.minimo !== '' && q < Number(i.minimo)) cortes.push(`abaixo do mínimo de ${n(Number(i.minimo))} ${un} — não há subsídio`.replace(/\s+/g, ' '));
  if (i.maximo !== null && i.maximo !== '' && Number(i.maximo) > 0 && q > Number(i.maximo)) cortes.push(`acima do tecto de ${n(Number(i.maximo))} ${un} — conta ${n(Number(i.maximo))}`.replace(/\s+/g, ' '));
  const premio = baseDe(i) === 'PREMIO';
  const valor = valorProdutividade(i, q, preco);
  const conta = `${premio ? `${n(preco)} Kz × (${n(qc)} ÷ 100)` : `${`${n(qc)} ${un}`.trim()} × ${n(preco)} Kz`} = ${new Intl.NumberFormat('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(valor)} Kz`;
  return { formula: premio ? METRICAS.OBJECTIVO.formula : metricaDe(i.metrica).formula, conta, cortes, valor };
}
