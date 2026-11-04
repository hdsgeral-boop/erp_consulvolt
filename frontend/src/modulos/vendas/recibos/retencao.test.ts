import { describe, expect, it } from 'vitest';
import { baseEfectiva, calcularRetencao, RETENCAO_VAZIA, resumirRecibo, retencaoParaPedido } from './retencao';

const ret = (p: Partial<typeof RETENCAO_VAZIA> = {}) => ({ ...RETENCAO_VAZIA, aplicar: true, percentagem: 6.5, ...p });

describe('retenção na fonte — cálculos', () => {
  it('calcula base × % com arredondamento ao cêntimo', () => {
    expect(calcularRetencao(1000, 6.5)).toBe(65);
    expect(calcularRetencao(1140, 6.5)).toBe(74.1);
    expect(calcularRetencao(12.34, 10)).toBe(1.23);
    expect(calcularRetencao(0.1, 6.5)).toBe(0.01);
    expect(calcularRetencao(100, null)).toBe(0);
  });

  it('a base por omissão é o total e nunca excede o total', () => {
    expect(baseEfectiva(1140, null)).toBe(1140);
    expect(baseEfectiva(1140, 1000)).toBe(1000);
    expect(baseEfectiva(1140, 5000)).toBe(1140);
    expect(baseEfectiva(1140, -3)).toBe(0);
  });

  it('sem retenção: líquido = total', () => {
    expect(resumirRecibo(1140, RETENCAO_VAZIA)).toMatchObject({ retido: 0, liquido: 1140, erro: null });
  });

  it('com retenção: bruto − retenção = líquido', () => {
    expect(resumirRecibo(1140, ret({ base: 1000 }))).toMatchObject({ base: 1000, calculado: 65, retido: 65, liquido: 1075, editado: false, erro: null });
    expect(resumirRecibo(1140, ret())).toMatchObject({ retido: 74.1, liquido: 1065.9 });
  });

  it('valor editado difere do calculado e fica assinalado', () => {
    expect(resumirRecibo(1000, ret({ valor: 63.4 }))).toMatchObject({ calculado: 65, retido: 63.4, liquido: 936.6, editado: true, erro: null });
  });

  it('valida percentagem, base, excesso sobre a base e líquido nulo', () => {
    expect(resumirRecibo(1000, ret({ percentagem: 120 })).erro).toMatch(/entre 0 e 100/);
    expect(resumirRecibo(1000, ret({ base: 2000 })).erro).toMatch(/base de incidência/);
    expect(resumirRecibo(1000, ret({ base: 500, valor: 600 })).erro).toMatch(/exceder a base/);
    expect(resumirRecibo(1000, ret({ percentagem: 100 })).erro).toMatch(/não entraria dinheiro/);
  });

  it('o pedido leva o bloco só com retenção positiva', () => {
    expect(retencaoParaPedido(RETENCAO_VAZIA, 1000)).toBeUndefined();
    expect(retencaoParaPedido(ret({ percentagem: 0 }), 1000)).toBeUndefined();
    expect(retencaoParaPedido(ret({ tipoId: 3, base: 1000, referencia: ' GR-7 ', data: '2026-11-01' }), 1140)).toEqual({
      tipo_retencao_fonte_id: 3, percentagem: 6.5, base_incidencia: 1000, valor_retido: 65, referencia_comprovativo: 'GR-7', data_comprovativo: '2026-11-01',
    });
  });
});
