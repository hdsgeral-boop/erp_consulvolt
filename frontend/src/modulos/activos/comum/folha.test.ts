import { describe, expect, it } from 'vitest';
import { lerLinhasFolha } from './regras';

describe('importação .xlsx de activos', () => {
  it('mapeia os cabeçalhos do modelo Excel e mantém os números', () => {
    const r = lerLinhasFolha([{ 'Código': '', 'Descrição': 'Viatura', 'Valor de aquisição': 4500000, 'Vida útil (meses)': 48, 'Data de aquisição': '2026-01-15', Outra: 'x' }]);
    expect(r.linhas).toEqual([{ descricao: 'Viatura', valor_aquisicao: 4500000, vida_util: 48, data_aquisicao: '2026-01-15' }]);
    expect(r.ignoradas).toEqual(['Outra']);
  });
});
