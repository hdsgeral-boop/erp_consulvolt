import { describe, expect, it } from 'vitest';
import { corIndice, FORMULAS } from './Financeiro';

describe('Financeiro do projecto', () => {
  it('semáforo dos índices CPI/SPI', () => {
    expect(corIndice(1.05)).toBe('#16a34a');
    expect(corIndice(0.95)).toBe('#d97706');
    expect(corIndice(0.7)).toBe('#dc2626');
    expect(corIndice(null)).toBe('#64748b');
  });

  it('documenta a fórmula de cada indicador de valor acrescentado', () => {
    for (const k of ['PV', 'EV', 'AC', 'CPI', 'SPI', 'CV', 'SV', 'EAC', 'ETC', 'VAC', 'TCPI']) expect(FORMULAS[k]).toContain('=');
  });
});
