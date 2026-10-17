import { parDivergente, temDiferenca } from './Consolidacao';

describe('Consolidação — eliminações e divergências (legado js/consolidacao.js:799 e 1030)', () => {
  it('diferença relevante a partir de 0,01 (em módulo)', () => {
    expect(temDiferenca('0.00')).toBe(false);
    expect(temDiferenca('0.009')).toBe(false);
    expect(temDiferenca('-0.01')).toBe(true);
    expect(temDiferenca(null)).toBe(false);
  });

  it('um par diverge se saldos, A→B ou B→A não fecham (o botão do cartão fica a vermelho)', () => {
    expect(parDivergente({ dif_saldo: '0.00', dif_ab: '0.00', dif_ba: '0.00' })).toBe(false);
    expect(parDivergente({ dif_saldo: '0.00', dif_ab: '0.00', dif_ba: '5.00' })).toBe(true);
  });
});
