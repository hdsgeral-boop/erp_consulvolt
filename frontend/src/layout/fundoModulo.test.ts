import { describe, expect, it } from 'vitest';
import { classeFundoModulo } from './fundoModulo';

describe('fundo por módulo (legado setModuleBackground)', () => {
  it('usa a imagem própria dos ecrãs mapeados e a do sistema nos restantes', () => {
    expect(classeFundoModulo('/rh/colaboradores')).toBe('erp-fundo-rh');
    expect(classeFundoModulo('/contab/lancamentos')).toBe('erp-fundo-contabilidade');
    expect(classeFundoModulo('/stock/pos_armazem')).toBe('erp-fundo-armazem');
    expect(classeFundoModulo('/teso/teso_gestao_pagamentos')).toBe('erp-fundo-vendas');
    expect(classeFundoModulo('/activos/activos')).toBe('erp-fundo-activos');
    expect(classeFundoModulo('/stock/armazem_stock')).toBe('erp-fundo-sistema');
    expect(classeFundoModulo('/rh/colaboradores/novo')).toBe('erp-fundo-rh');
    expect(classeFundoModulo('/')).toBe('erp-fundo-sistema');
    expect(classeFundoModulo('/compras/compras_pedidos')).toBe('erp-fundo-armazem');
  });
});
