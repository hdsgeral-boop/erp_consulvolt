import { describe, expect, it } from 'vitest';
import { descricaoMover, pedidoMover } from './comum/arrastar';
import { lerLinhasPosicoes } from './separadores/OrganigramaAccoes';

describe('projectos — ronda 2 (M-20)', () => {
  it('decide o destino ao largar uma tarefa na WBS', () => {
    expect(pedidoMover(1, { tarefa: 2 }, 5, 40, false)).toEqual({ tipo: 'ANTES', alvo_id: 2 });
    expect(pedidoMover(1, { tarefa: 2 }, 35, 40, false)).toEqual({ tipo: 'DEPOIS', alvo_id: 2 });
    expect(pedidoMover(1, { tarefa: 2 }, 20, 40, false)).toEqual({ tipo: 'DENTRO', alvo_id: 2 });
    expect(pedidoMover(1, { tarefa: 2 }, 5, 40, true)).toEqual({ tipo: 'DENTRO', alvo_id: 2 });
    expect(pedidoMover(1, { marco: 7 }, 0, 40, false)).toEqual({ tipo: 'MARCO', marco_projeto_id: 7 });
    expect(pedidoMover(1, { tarefa: 1 }, 5, 40, false)).toBeNull();
    expect(descricaoMover({ tipo: 'DENTRO', alvo_id: 2 }, 'Fundações')).toBe('como subtarefa de «Fundações»');
  });

  it('lê as posições do lote (Título;Área;Vagas)', () => {
    expect(lerLinhasPosicoes('Encarregado;Produção;1\n\nPedreiro;Produção\nTopógrafo\t\t2\n;sem título;3')).toEqual([
      { titulo: 'Encarregado', area: 'Produção', vagas: 1 },
      { titulo: 'Pedreiro', area: 'Produção', vagas: 1 },
      { titulo: 'Topógrafo', area: null, vagas: 2 },
    ]);
  });
});
