import { describe, expect, it } from 'vitest';
import { construirDocumento } from '@/componentes/impressao';
import type { ReciboVenda } from '../recibos/tipos';
import { dadosRecibo, pedidoDuasVias, pedidoRecibo } from './documentoRecibo';
import { htmlDocumentoComercial } from './documentoComercial';

const recibo: ReciboVenda = {
  id: 1, numero_recibo: 'RC 2026/7', data: '2026-09-30', cliente_id: 1, cliente: { id: 1, nome: 'Cliente Fictício', nif: '5000000999' }, montante_total: '12500.00',
  meio_pagamento: 'TRANSFERENCIA', codigo_conta: '4311', referencia_pagamento: 'TRF-001', estado: 'EMITIDO', contabilizado: true, numero_lan_contabilizacao: 'RC-15',
  venda_origem_id: null, anulado_em: null, motivo_anulacao: null, alocacoes: [{ venda_id: 7, numero_documento: 'FT A/2026/12', montante: '12500.00' }],
};

describe('recibos em duas vias', () => {
  it('pedido do recibo: original e duplicado na mesma folha, A4 vertical por omissão', () => {
    const p = pedidoRecibo(recibo);
    expect(p).toMatchObject({ titulo: 'Recibo RC 2026/7', vias: ['Original', 'Duplicado'], papel: 'A4', orientacao: 'retrato', subtitulo: null });
    const html = construirDocumento({ ...p, conteudo: String(p.conteudo), identidade: { nome: 'Empresa Fictícia, Lda', nif: '5000000000' } });
    expect(html.match(/class="imp-via"/g)).toHaveLength(2);
    expect(html).toContain('<span class="imp-via-rotulo">Original</span>');
    expect(html).toContain('<span class="imp-via-rotulo">Duplicado</span>');
    expect(html.match(/FT A\/2026\/12/g)).toHaveLength(2);
    expect(html).toContain('Doze mil e quinhentos kwanzas');
    expect(html).toContain('O Cliente');
  });

  it('recibo de adiantamento sem facturas: descreve o adiantamento e o saldo por alocar (sem «Sem linhas»)', () => {
    const html = htmlDocumentoComercial(dadosRecibo({ ...recibo, tipo_recibo: 'ADIANTAMENTO', referencia: 'Sinal da obra', saldo_adiantamento: '12500.00', alocacoes: [] }));
    expect(html).toContain('Adiantamento — Sinal da obra');
    expect(html).toContain('Saldo por alocar');
    expect(html).not.toContain('Sem linhas');
  });

  it('qualquer documento comercial pode sair em vias com rótulos próprios', () => {
    const p = pedidoDuasVias({ tipo: 'Nota de recebimento', numero: 'NR 1', totais: { total: '10' } }, ['Original', 'Cópia']);
    expect(p.vias).toEqual(['Original', 'Cópia']);
    expect(String(p.cssExtra)).toContain('.imp-via .dc-assin');
  });
});
