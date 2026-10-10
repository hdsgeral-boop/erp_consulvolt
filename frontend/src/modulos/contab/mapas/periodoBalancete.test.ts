import dayjs from 'dayjs';
import { describe, expect, it } from 'vitest';
import { periodoPorOmissao } from './periodoBalancete';

describe('periodoPorOmissao (Balancete)', () => {
  it('vai de 1 de Janeiro a 31 de Dezembro do ano corrente, como o legado', () => {
    const [inicio, fim] = periodoPorOmissao(dayjs('2026-10-09'));
    expect(inicio.format('YYYY-MM-DD')).toBe('2026-01-01');
    expect(fim.format('YYYY-MM-DD')).toBe('2026-12-31');
  });

  it('não termina na data de hoje (lançamentos com data futura no ano entram)', () => {
    const [, fim] = periodoPorOmissao(dayjs('2026-03-15'));
    expect(fim.isAfter(dayjs('2026-03-15'), 'day')).toBe(true);
  });
});
