import { describe, expect, it } from 'vitest';
import { juntarGrupo } from './ReconciliacaoExtras';

describe('tesouraria — ronda 2', () => {
  it('rascunho de reconciliação acrescenta a selecção como novo grupo sem repetir linhas (M-08)', () => {
    const g1 = juntarGrupo([], [1, 2], [10]);
    expect(g1).toEqual([{ extrato: [1, 2], lancamentos: [10] }]);
    expect(juntarGrupo(g1, [2, 3], [10, 11])).toEqual([{ extrato: [1, 2], lancamentos: [10] }, { extrato: [3], lancamentos: [11] }]);
    expect(juntarGrupo(g1, [1], [10])).toBe(g1);
    expect(juntarGrupo(g1, [], [])).toBe(g1);
  });
});
