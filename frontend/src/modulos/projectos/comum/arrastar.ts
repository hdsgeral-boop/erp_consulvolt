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
