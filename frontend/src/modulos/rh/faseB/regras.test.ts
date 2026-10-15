import { describe, expect, it } from 'vitest';
import { antiguidadeAnos, horasEntre, horasPrevistas, mesMais, normalizarLinhaApuramento, normalizarTotais, planoEmprestimo, textoHorario, valorCompensacao } from './regras';

describe('Fase B — horário do contrato', () => {
  it('1 período desconta o intervalo fixo; 2 períodos somam os dois períodos', () => {
    expect(horasPrevistas({ entrada: '08:00', saida: '17:00', periodos: 1, intervalo_min: 60 })).toBe(8);
    expect(horasPrevistas({ entrada: '08:00', saida: '17:30', periodos: 2, saida_intervalo: '12:00', entrada_intervalo: '13:00' })).toBe(8.5);
    expect(horasPrevistas({ entrada: '08:00', saida: '09:00', periodos: 1, intervalo_min: 60 })).toBeNull();
    expect(horasPrevistas({ entrada: '', saida: '17:00', periodos: 1 })).toBeNull();
  });

  it('turno que passa da meia-noite soma 24 h', () => {
    expect(horasEntre('22:00', '06:00')).toBe(8);
    expect(horasEntre('25:00', '06:00')).toBeNull();
  });

  it('texto do horário', () => {
    expect(textoHorario({ entrada: '08:00', saida: '17:30', periodos: 2, saida_intervalo: '12:00', entrada_intervalo: '13:00' })).toBe('08:00–12:00 e 13:00–17:30');
    expect(textoHorario({ entrada: '08:00', saida: '17:00', periodos: 1, intervalo_min: 60 })).toBe('08:00–17:00 (−60 min)');
  });
});

describe('Fase B — plano de amortização (sistema francês)', () => {
  it('sem juros: capital ÷ prestações e a última fecha o saldo', () => {
    const p = planoEmprestimo(100, 3, 0, '2026-11');
    expect(p.prestacao).toBe('33.33');
    expect(p.linhas.map((l) => l.total)).toEqual(['33.33', '33.33', '33.34']);
    expect(p.linhas.map((l) => l.mes_ano)).toEqual(['2026-11', '2026-12', '2027-01']);
    expect(p.linhas[2].saldo).toBe('0.00');
  });

  it('com juros de 12 % ao ano igual ao servidor', () => {
    const p = planoEmprestimo(100000, 12, 12, '2026-01');
    expect(p.prestacao).toBe('8884.88');
    expect(p.linhas[0]).toMatchObject({ juro: '1000.00', capital: '7884.88' });
    expect(p.linhas.reduce((s, l) => s + Number(l.capital) * 100, 0)).toBe(10000000);
    expect(p.linhas[11].saldo).toBe('0.00');
  });

  it('mês seguinte atravessa o ano', () => {
    expect(mesMais('2026-12', 1)).toBe('2027-01');
    expect(mesMais('2026-01', 13)).toBe('2027-02');
  });
});

describe('Fase B — Lei 12/23', () => {
  it('antiguidade conta a fracção de 3 meses ou mais (art. 311.º)', () => {
    expect(antiguidadeAnos('2018-01-10', '2026-03-09')).toBe(8);
    expect(antiguidadeAnos('2018-01-10', '2026-04-10')).toBe(9);
    expect(antiguidadeAnos(null, '2026-04-10')).toBe(0);
  });

  it('compensação dos arts. 307.º, 308.º e 310.º', () => {
    expect(valorCompensacao('ART307', 300000, 6)).toBe('900000.00');
    expect(valorCompensacao('ART308', 300000, 9)).toBe('2100000.00');
    expect(valorCompensacao('ART310', 300000, 2)).toBe('900000.00');
    expect(valorCompensacao('', 300000, 9)).toBe('0.00');
  });
});

describe('Fase B — apuramento', () => {
  it('aceita as linhas do servidor (snake_case) e dos fechos do legado (camelCase)', () => {
    const l = normalizarLinhaApuramento({ colaborador_id: 7, nome: 'Ana', dias_uteis: 21, horas_extra: 1, dias_atraso: 2, avisos: ['x'] });
    expect(l).toMatchObject({ employeeId: 7, diasUteis: 21, horasExtra: 1, diasAtraso: 2, avisos: ['x'] });
    expect(normalizarLinhaApuramento({ employee_id: 3, diasUteis: 20 })).toMatchObject({ employeeId: 3, diasUteis: 20, avisos: [] });
    expect(normalizarTotais({ horas_extra: 2, horas_falta: 3, dias_falta: 1 })).toEqual({ diasFalta: 1, horasExtra: 2, horasFalta: 3 });
  });
});
