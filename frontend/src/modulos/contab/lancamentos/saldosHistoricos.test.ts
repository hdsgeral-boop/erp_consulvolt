import { describe, expect, it } from 'vitest';
import { aplicarLeitura, calcularResultadoLiquido, corpoGravacao, valoresDe } from './saldosHistoricos';

describe('saldos históricos (A-06)', () => {
  it('calcula o resultado líquido como o legado, em cêntimos exactos', () => {
    // (1000,10 + 0,20) − (300,05) + 0,10 − 0,15
    expect(calcularResultadoLiquido({ '22': 1000.1, '26': 0.2, '27': 300.05, '34': 0.1, '35': 0.15, '10': 99999 })).toBe('700.20');
    expect(calcularResultadoLiquido({})).toBe('0.00');
    expect(calcularResultadoLiquido({ '30': 50 })).toBe('-50.00');
  });

  it('lê os valores do servidor (vazios ficam vazios) sem o res_liq', () => {
    expect(valoresDe([{ codigo: '23', descricao: 'Prestações', valor: '1000.00' }, { codigo: '10', descricao: null, valor: null }, { codigo: 'res_liq', descricao: null, valor: '5.00' }])).toEqual({
      '23': 1000,
      '10': null,
    });
  });

  it('grava só os valores preenchidos, arredondados ao cêntimo, e nunca o res_liq', () => {
    expect(corpoGravacao({ '23': 10.005, '10': null, res_liq: 3, '24': undefined }, { '111': 0, tot2: 5 })).toEqual({ demo: { '23': 10.01 }, fluxo: { '111': 0, tot2: 5 } });
  });

  it('aplica a leitura do Excel às notas existentes e assinala os códigos desconhecidos', () => {
    const r = aplicarLeitura({ demo: { '23': 1, '10': null }, fluxo: { tot2: null } }, { demo: { '23': '10.00', '99': '1.00', res_liq: '7.00' }, fluxo: { TOT2: '5.50' }, ignoradas: 2 });
    expect(r.demo).toEqual({ '23': 10, '10': null });
    expect(r.fluxo).toEqual({ tot2: 5.5 });
    expect(r.desconhecidos).toEqual(['DEMO 99']);
    expect(r.aplicados).toBe(2);
  });
});
