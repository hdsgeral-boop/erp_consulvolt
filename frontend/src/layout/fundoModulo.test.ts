import { describe, expect, it } from 'vitest';
import { classeFundoModulo } from './fundoModulo';

describe('fundo por módulo (legado setModuleBackground)', () => {
  it('usa a imagem própria dos ecrãs mapeados e a do sistema nos restantes', () => {
    expect(classeFundoModulo('/m/rh/colaboradores')).toBe('erp-fundo-rh');
    expect(classeFundoModulo('/m/contab/lancamentos')).toBe('erp-fundo-contabilidade');
    expect(classeFundoModulo('/m/stock/pos_armazem')).toBe('erp-fundo-armazem');
    expect(classeFundoModulo('/m/teso/teso_gestao_pagamentos')).toBe('erp-fundo-vendas');
    expect(classeFundoModulo('/m/activos/activos')).toBe('erp-fundo-activos');
    expect(classeFundoModulo('/m/stock/armazem_stock')).toBe('erp-fundo-sistema');
    expect(classeFundoModulo('/m/rh/colaboradores/novo')).toBe('erp-fundo-rh');
    expect(classeFundoModulo('/')).toBe('erp-fundo-sistema');
    expect(classeFundoModulo('/m/compras/compras_pedidos')).toBe('erp-fundo-armazem');
  });
});
