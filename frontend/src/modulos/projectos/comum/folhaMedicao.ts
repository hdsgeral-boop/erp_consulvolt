import type { LinhaFolhaMedicao } from './tipos';

/**
 * Folha de medição por pessoa (pedido de 2026-10-16, base em projMetasRegistarEmMassa, js/projectos_metas.js:462-628):
 * cada tarefa medível aparece uma vez por pessoa que a executa — o responsável directo ou, numa tarefa da área do
 * organigrama, cada membro dessa área — e a medição grava-se com o executante (membro_id).
 */
export interface LinhaPessoa {
  /** `tarefa:membro` (membro 0 = sem pessoa atribuída). */
  chave: string;
  linha: LinhaFolhaMedicao;
  membro_id: number | null;
}

export const chaveLinha = (tarefa: number, membro: number | null) => `${tarefa}:${membro ?? 0}`;

/** Linhas da folha, uma por (tarefa, pessoa); com `pessoa`, só as dessa pessoa. */
export function linhasPorPessoa(linhas: LinhaFolhaMedicao[], pessoa?: number | null): LinhaPessoa[] {
  return linhas.flatMap((l) => {
    const pessoas: (number | null)[] = l.pessoas?.length ? l.pessoas : [null];
    return pessoas
      .filter((m) => pessoa === undefined || pessoa === null || m === pessoa)
      .map((m) => ({ chave: chaveLinha(l.id, m), linha: l, membro_id: m }));
  });
}

/** Grupos por pessoa, pelo nome (pt), com «Sem pessoa atribuída» no fim. */
export function agruparPorPessoa(linhas: LinhaPessoa[], nome: (id: number) => string): { membro_id: number | null; nome: string; linhas: LinhaPessoa[] }[] {
  const mapa = new Map<number | null, LinhaPessoa[]>();
  linhas.forEach((l) => mapa.set(l.membro_id, [...(mapa.get(l.membro_id) ?? []), l]));
  return [...mapa.entries()]
    .map(([id, ls]) => ({ membro_id: id, nome: id === null ? 'Sem pessoa atribuída' : nome(id), linhas: ls }))
    .sort((a, b) => (a.membro_id === null ? 1 : b.membro_id === null ? -1 : a.nome.localeCompare(b.nome, 'pt')));
}

/** Corpo do POST /medicoes/lote: só as linhas com quantidade ≠ 0, com o executante escolhido (ou o da linha). */
export function linhasALancar(
  linhas: LinhaPessoa[],
  qtd: Record<string, number | null | undefined>,
  notas: Record<string, string | undefined>,
  executante: Record<string, number | null | undefined>,
): { tarefa_id: number; quantidade: number; nota: string | null; membro_id: number | null }[] {
  return linhas
    .filter((l) => Number(qtd[l.chave] ?? 0) !== 0)
    .map((l) => ({
      tarefa_id: l.linha.id,
      quantidade: Number(qtd[l.chave]),
      nota: notas[l.chave]?.trim() || null,
      membro_id: executante[l.chave] !== undefined ? (executante[l.chave] ?? null) : l.membro_id,
    }));
}

/** Total por pessoa das medições já registadas (histórico da tarefa). */
export function medidoPorPessoa(linhas: LinhaFolhaMedicao[]): Map<number | null, Record<string, number>> {
  const saida = new Map<number | null, Record<string, number>>();
  linhas.forEach((l) => l.medicoes.forEach((m) => {
    const k = m.membro_id ?? null;
    const r = saida.get(k) ?? {};
    r[l.unidade] = Math.round(((r[l.unidade] ?? 0) + Number(m.q)) * 1000) / 1000;
    saida.set(k, r);
  }));
  return saida;
}
