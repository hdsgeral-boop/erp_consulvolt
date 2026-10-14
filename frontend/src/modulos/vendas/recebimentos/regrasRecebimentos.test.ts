import { describe, expect, it } from 'vitest';
import { ErroApi } from '@/api/tipos';
import { exigeCancelarRecebimento, facturaDoErroNcPaga, linhasACancelar, outrasFacturasAfectadas } from './regrasRecebimentos';

describe('Cancelar o recebimento antes da NC (exigirRecebimentoCancelado do legado novo)', () => {
  it('factura paga ou com pagamentos exige o cancelamento; pendente, anulada ou não-factura não', () => {
    expect(exigeCancelarRecebimento({ tipo_documento: 'FT', estado: 'PAGO', valor_pago: '2280.00' })).toBe(true);
    expect(exigeCancelarRecebimento({ tipo_documento: 'FT', estado: 'PARCIAL', valor_pago: '100.00' })).toBe(true);
    expect(exigeCancelarRecebimento({ tipo_documento: 'FT', estado: 'PENDENTE', valor_pago: '0.00' })).toBe(false);
    expect(exigeCancelarRecebimento({ tipo_documento: 'FT', estado: 'ANULADO', valor_pago: '50' })).toBe(false);
    expect(exigeCancelarRecebimento({ tipo_documento: 'FR', estado: 'PAGO', valor_pago: '10' })).toBe(true);
    expect(exigeCancelarRecebimento({ tipo_documento: 'OR', estado: 'PAGO', valor_pago: '10' })).toBe(false);
  });

  it('reconhece o erro NC_FATURA_PAGA com a indicação do servidor', () => {
    expect(facturaDoErroNcPaga(new ErroApi('paga', 422, 'NC_FATURA_PAGA', { venda_id: 42, cancelar_recebimentos: true }))).toBe(42);
    expect(facturaDoErroNcPaga(new ErroApi('paga', 422, 'NC_FATURA_PAGA'))).toBeNull();
    expect(facturaDoErroNcPaga(new ErroApi('outro', 422, 'NC_EXCEDE_SALDO', { venda_id: 42, cancelar_recebimentos: true }))).toBeNull();
    expect(facturaDoErroNcPaga(new Error('x'))).toBeNull();
  });

  it('descreve o que vai ser cancelado e as outras facturas afectadas', () => {
    const p = {
      recibos: [{ id: 1, numero: 'RE A2026/3', data: null, montante_total: '1200', montante_factura: '1000.00', contabilizado: true, outras_facturas: ['FT A2026/9'] }],
      tesouraria: [{ id: 2, numero: 'REC A2026/1', data: null, valor_total: '500', valor_factura: '500.00', estado: 'PENDENTE', integrado: false, outras_facturas: ['FT A2026/9', 'FT A2026/10'] }],
    };
    expect(linhasACancelar(p)).toEqual([
      'Recibo RE A2026/3 — 1000.00 desta factura (contabilizado: é estornado)',
      'Tesouraria REC A2026/1 — 500.00 desta factura',
    ]);
    expect(outrasFacturasAfectadas(p)).toEqual(['FT A2026/9', 'FT A2026/10']);
  });
});
