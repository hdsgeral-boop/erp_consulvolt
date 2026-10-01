import dayjs from 'dayjs';
import { dataApi, formatarData, formatarKz, formatarNumero } from './formatacao';

describe('formatação', () => {
  it('formata valores em Kz a partir do texto decimal da API', () => {
    expect(formatarKz('1234567.8')).toMatch(/1\s?234\s?567,80/);
    expect(formatarKz('0.00')).toBe('0,00');
    expect(formatarKz('-50.5', true)).toMatch(/-50,50 Kz/);
    expect(formatarKz(null)).toBe('—');
    expect(formatarKz('')).toBe('—');
  });

  it('formata quantidades sem casas desnecessárias', () => {
    expect(formatarNumero('2.000')).toBe('2');
    expect(formatarNumero('1.5')).toBe('1,5');
  });

  it('formata e converte datas', () => {
    expect(formatarData('2026-09-30')).toBe('30/09/2026');
    expect(formatarData(null)).toBe('—');
    expect(dataApi(dayjs('2026-01-05'))).toBe('2026-01-05');
    expect(dataApi(null)).toBeUndefined();
  });
});
