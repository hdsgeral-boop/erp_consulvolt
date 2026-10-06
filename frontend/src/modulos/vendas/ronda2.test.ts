import { describe, expect, it } from 'vitest';
import { desvioCambio, erroPlano, estimarTotais, planoMarcos, planoPrazo, repartir, valoresPrestacoes } from './condicoes';
import { textoContravalor, textoPlano } from './impressao/documentoVenda';
import { percentagemPaga } from './ListaDocumentos';
import { separadorFaturacao } from './Faturacao';
import { accoesRecibo } from './recibos/tipos';

describe('condições de pagamento (A-03)', () => {
  it('reparte as prestações a prazo e calcula as datas a partir do documento', () => {
    expect(repartir(3)).toEqual([33.33, 33.33, 33.34]);
    const p = planoPrazo('2026-10-06', [60, 30, 30]);
    expect(p.map((x) => [x.dias, x.data, x.percentagem])).toEqual([[30, '2026-11-05', 50], [60, '2026-12-05', 50]]);
    expect(p[0].descricao).toBe('1ª prestação (30 dias)');
    expect(erroPlano('PRAZO', p)).toBeNull();
  });

  it('usa os modelos de marcos do legado e valida a soma de 100 %', () => {
    const m = planoMarcos('2026-10-06', 2);
    expect(m.map((x) => x.percentagem)).toEqual([30, 40, 30]);
    expect(erroPlano('MARCOS', m)).toBeNull();
    expect(erroPlano('MARCOS', m.slice(0, 2))).toMatch(/somam 70/);
    expect(erroPlano('PRAZO', [{ descricao: '', percentagem: 100, dias: null, data: null }])).toMatch(/data/);
    expect(erroPlano('PRONTO', [])).toBeNull();
  });

  it('valores das prestações somam o total ao cêntimo', () => {
    const v = valoresPrestacoes(planoMarcos('2026-10-06', 2), 1000.01);
    expect(v).toEqual([300, 400, 300.01]);
  });

  it('estima totais na moeda e o contravalor em Kz com IVA por excesso', () => {
    const t = estimarTotais([{ quantidade: 3, preco: 10, taxa: 14 }], 900.5);
    expect(t).toMatchObject({ liquido: 30, imposto: 4.2, total: 34.2, liquidoKz: 27015, impostoKz: 3782.1, totalKz: 30797.1 });
    expect(estimarTotais([{ quantidade: 1, preco: 10.01, taxa: 14 }]).imposto).toBe(1.41);
    expect(desvioCambio(1000, 900)).toBe(11.11);
  });
});

describe('impressão e listas (ronda 2)', () => {
  it('imprime o plano de prestações e o contravalor em Kz', () => {
    const d = {
      modo_pagamento: 'MARCOS' as const, total_bruto: '1140.00', moeda: null,
      plano_pagamentos: [{ percentagem: 50, data: '2026-10-06', descricao: 'Adjudicação' }, { percentagem: 50, data: null, descricao: 'Conclusão' }],
    };
    expect(textoPlano(d)).toBe('Pagamento por marcos\nAdjudicação — 06/10/2026 — 50 % — 570,00 Kz\nConclusão — data a definir — 50 % — 570,00 Kz');
    expect(textoPlano({ ...d, modo_pagamento: 'PRONTO' })).toBeNull();
    expect(textoContravalor({ moeda: { codigo: 'USD', taxa_cambio: '900.5', taxa_cambio_manual: false, total_liquido: '30', total_imposto: '4.2', total_bruto: '34.2' },
      total_liquido: '27015.00', total_imposto: '3782.10', total_bruto: '30797.10' })).toMatch(/30\s797,10 Kz .* 1 USD = 900,50 Kz/);
  });

  it('percentagem paga, separadores e acções do adiantamento', () => {
    expect(percentagemPaga({ tipo_documento: 'FT', total_bruto: '1000', valor_pago: '250' })).toBe(25);
    expect(percentagemPaga({ tipo_documento: 'NC', total_bruto: '1000', valor_pago: '0' })).toBe(0);
    expect(separadorFaturacao('/m/vendas/vendas_faturacao/guias')).toBe('guias');
    expect(separadorFaturacao('/m/vendas/vendas_faturacao/orcamentos')).toBe('orcamentos');
    const ad = { estado: 'EMITIDO', contabilizado: true, tipo_recibo: 'ADIANTAMENTO' as const, saldo_adiantamento: '100.00', alocacoes: [] };
    expect(accoesRecibo(ad, () => true)).toMatchObject({ alocar: true, descontabilizar: true });
    expect(accoesRecibo({ ...ad, alocacoes: [{ venda_id: 1, numero_documento: 'FT 1', montante: '10' }] }, () => true)).toMatchObject({ descontabilizar: false, anular: false });
  });
});
