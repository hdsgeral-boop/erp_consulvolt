/**
 * M-20: arrastar tarefas na WBS (projMoverTarefa / projLigarArrastoWBS do legado) — decide o pedido de
 * POST /projetos/{p}/tarefas/{t}/mover a partir do alvo da largada:
 *   - sobre um milestone: a tarefa passa para esse milestone (tipo MARCO, no fim);
 *   - sobre uma tarefa: metade de cima = ANTES, metade de baixo = DEPOIS; com Shift (ou largada no meio da linha) = DENTRO
 *     (subtarefa).
 */
export type TipoMover = 'ANTES' | 'DEPOIS' | 'DENTRO' | 'MARCO';
export interface PedidoMover { tipo: TipoMover; alvo_id?: number; marco_projeto_id?: number | null }

export type AlvoArrasto = { tarefa: number } | { marco: number | null };

export function pedidoMover(origem: number, alvo: AlvoArrasto, y: number, altura: number, shift: boolean): PedidoMover | null {
  if ('marco' in alvo) return { tipo: 'MARCO', marco_projeto_id: alvo.marco };
  if (alvo.tarefa === origem) return null;
  if (shift) return { tipo: 'DENTRO', alvo_id: alvo.tarefa };
  const r = altura > 0 ? y / altura : 0;
  if (r > 0.35 && r < 0.65) return { tipo: 'DENTRO', alvo_id: alvo.tarefa };
  return { tipo: r <= 0.35 ? 'ANTES' : 'DEPOIS', alvo_id: alvo.tarefa };
}

/** Texto do resultado esperado, para o aviso no ecrã. */
export function descricaoMover(p: PedidoMover, nomeAlvo: string): string {
  return { ANTES: `antes de «${nomeAlvo}»`, DEPOIS: `depois de «${nomeAlvo}»`, DENTRO: `como subtarefa de «${nomeAlvo}»`, MARCO: `para o milestone «${nomeAlvo}»` }[p.tipo];
}

/**
 * #10: arrastar uma fase na WBS (projLigarArrastoWBS / projMudarNivel, projectos_hierarquia.js:682-809) — decide o pedido
 * de POST /projetos/{p}/marcos/{id}/nivel a partir do alvo da largada:
 *   - sobre uma tarefa: a fase passa a subtarefa dessa tarefa (SUB);
 *   - sobre outra fase (ou «Sem milestone»): a fase passa a tarefa principal dessa fase (RAIZ).
 * As tarefas da fase passam a subtarefas da nova tarefa. Largar sobre a própria fase não faz nada.
 */
export interface PedidoNivelFase { destino: 'SUB' | 'RAIZ'; alvo_id: number | null; marco_projeto_id: number | null; encaixar: boolean }

export function pedidoNivelFase(faseId: number, alvo: AlvoArrasto): PedidoNivelFase | null {
  if ('tarefa' in alvo) return { destino: 'SUB', alvo_id: alvo.tarefa, marco_projeto_id: null, encaixar: true };
  if (alvo.marco === faseId) return null;
  return { destino: 'RAIZ', alvo_id: null, marco_projeto_id: alvo.marco, encaixar: true };
}

/** Texto da confirmação ao largar uma fase. */
export function descricaoNivelFase(p: PedidoNivelFase, nomeFase: string, nomeAlvo: string): string {
  return p.destino === 'SUB'
    ? `A fase «${nomeFase}» passa a subtarefa de «${nomeAlvo}» e as tarefas dela passam a subtarefas da nova tarefa.`
    : `A fase «${nomeFase}» passa a tarefa principal ${p.marco_projeto_id ? `da fase «${nomeAlvo}»` : 'sem fase'} e as tarefas dela passam a subtarefas da nova tarefa.`;
}
