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
