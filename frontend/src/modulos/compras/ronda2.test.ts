import { describe, expect, it } from 'vitest';
import { ecraCompras, SEPARADORES_COMPRAS } from './comum/BarraCompras';
import { linhaIvaEditavel, TAXAS_IVA_LEGAIS } from './comum/ModalIva';

describe('compras — ronda 2', () => {
  it('identifica o separador do módulo pelo caminho (barra do legado)', () => {
    expect(ecraCompras('/m/compras/compras_prospeccao/12')).toBe('compras_prospeccao');
    expect(ecraCompras('/m/vendas/vendas_faturacao')).toBeNull();
    expect(SEPARADORES_COMPRAS.map((s) => s.rotulo)).toEqual(['Pedidos', 'Prospecção', 'Encomendas', 'Recepções', 'Facturação', 'Fornecedores', 'Encomendas clientes', 'Contratos']);
  });

  it('IVA editável só nas taxas legais e nas linhas de encomenda por facturar (M-17)', () => {
    expect(TAXAS_IVA_LEGAIS).toEqual([14, 7, 5, 0]);
    expect(linhaIvaEditavel({ quantidade_faturada: '0.000' }, true)).toBe(true);
    expect(linhaIvaEditavel({ quantidade_faturada: '1.000' }, true)).toBe(false);
    expect(linhaIvaEditavel({ quantidade_faturada: '1.000' }, false)).toBe(true);
  });
});
