import { diferenca, doze, somaValores, totalAnual } from './regras';

/** Afinação da Fase 5 (ADR-064): o Orçamento passou a devolver os valores monetários como texto decimal. */
describe('valores monetários em texto decimal', () => {
  it('normaliza e soma ao cêntimo sem concatenar texto', () => {
    expect(doze(['100.10', '0.20'])).toEqual([100.1, 0.2, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]);
    expect(totalAnual(Array(12).fill('0.10'))).toBe(1.2);
    expect(somaValores(['0.10', '0.20', '-0.05'])).toBe(0.25);
    expect(somaValores(null)).toBe(0);
  });

  it('diferença ao cêntimo (variação do cenário)', () => {
    expect(diferenca('1000.30', '999.90')).toBe(0.4);
    expect(diferenca('0.10', 0.3)).toBe(-0.2);
  });
});
