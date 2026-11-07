import { describe, expect, it } from 'vitest';
import { accoesDoNegocio, fechado, formatarMoeda, ordemDaFase, previaImpostos, rotuloResultado } from './logicaNegocios';

const TAXAS = { ip_transmissao: 2, selo_transmissao: 0.3, escritura_fixo: 2000 };
const todas = () => true;
const base = { estado: 'NEGOCIACAO' as const, tipo: 'COMPRA' as const, aprovado: false, fases_seguintes: ['PROMESSA', 'ESCRITURA', 'REGISTADO'] as ('PROMESSA' | 'ESCRITURA' | 'REGISTADO')[], contratos_em_vigor: [], ativo_id: null, abatido_em: null, pagamentos: [] };

describe('impostos da transmissão (as mesmas fixtures do servidor, calculadas à mão)', () => {
  it('a base é o maior entre o preço e o valor patrimonial: 30 000 000 e 35 000 000 → IP 700 000, selo 105 000, escritura 2 000', () => {
    const i = previaImpostos(30000000, 35000000, TAXAS);
    expect(i).toEqual({ base: 35000000, baseEVpt: true, ip: 700000, selo: 105000, escritura: 2000, total: 807000 });
    expect(previaImpostos(40000000, 0, TAXAS)).toMatchObject({ base: 40000000, baseEVpt: false, ip: 800000, selo: 120000, total: 922000 });
  });

  it('a isenção só toca no imposto predial: total zera, metade reparte ao meio', () => {
    expect(previaImpostos(30000000, 35000000, TAXAS, 'TOTAL')).toMatchObject({ ip: 0, selo: 105000, total: 107000 });
    expect(previaImpostos(30000000, 35000000, TAXAS, 'METADE')).toMatchObject({ ip: 350000, selo: 105000, total: 457000 });
  });

  it('arredonda meio para cima ao cêntimo: 12 345,67 → IP 246,91, selo 37,04, total 2 283,95', () => {
    expect(previaImpostos(12345.67, 0, TAXAS)).toMatchObject({ ip: 246.91, selo: 37.04, total: 2283.95 });
    expect(previaImpostos(0, 0, TAXAS)).toMatchObject({ ip: 0, selo: 0, total: 2000 });
  });
});

describe('fases, resultado e moeda', () => {
  it('a ordem das fases e o fecho', () => {
    expect(['NEGOCIACAO', 'PROMESSA', 'ESCRITURA', 'REGISTADO', 'CONCLUIDO', 'CANCELADO'].map((f) => ordemDaFase(f as never))).toEqual([1, 2, 3, 4, 5, 0]);
    expect(fechado({ estado: 'CONCLUIDO' })).toBe(true);
    expect(fechado({ estado: 'CANCELADO' })).toBe(true);
    expect(fechado({ estado: 'REGISTADO' })).toBe(false);
  });

  it('o resultado diz sempre mais-valia ou menos-valia em texto', () => {
    expect(rotuloResultado(14000000)).toBe('Mais-valia');
    expect(rotuloResultado(0)).toBe('Mais-valia');
    expect(rotuloResultado('-4000000.00')).toBe('Menos-valia');
    expect(formatarMoeda(12345.5).replace(/\s/g, ' ')).toBe('12 345,50 Kz');
    expect(formatarMoeda(1234.5)).toBe('1234,50 Kz');   // o pt-PT só agrupa a partir de 5 dígitos
    expect(formatarMoeda(null)).toBe('—');
  });
});

describe('acções do negócio por estado e permissão (segregação)', () => {
  it('antes de aprovado: editar, encargos, aprovar, cancelar; ainda sem pagamento nem conclusão', () => {
    const a = accoesDoNegocio(base, todas);
    expect(a).toMatchObject({ editar: true, custos: true, aprovar: true, avancar: true, cancelar: true, concluir: false, abater: false, pagamento: false, eliminar: true });
  });

  it('aprovado: pagamentos e já não aprovar; com pagamentos não se elimina', () => {
    expect(accoesDoNegocio({ ...base, aprovado: true }, todas)).toMatchObject({ aprovar: false, pagamento: true });
    expect(accoesDoNegocio({ ...base, aprovado: true, pagamentos: [{ id: 1 }] as never }, todas).eliminar).toBe(false);
  });

  it('quem aprova não regista pagamentos: cada botão depende da sua tarefa', () => {
    const soAprova = (...p: string[]) => p.some((x) => x === 'imo_neg_aprovar');
    expect(accoesDoNegocio({ ...base, aprovado: true }, soAprova)).toMatchObject({ aprovar: false, pagamento: false });
    expect(accoesDoNegocio(base, soAprova)).toMatchObject({ aprovar: true, pagamento: false, editar: false, avancar: false });
    const soPaga = (...p: string[]) => p.some((x) => x === 'imo_neg_pagamento');
    expect(accoesDoNegocio({ ...base, aprovado: true }, soPaga)).toMatchObject({ aprovar: false, pagamento: true });
  });

  it('depois da escritura já não se cancela e conclui-se; quem conclui a venda não abate', () => {
    const e = { ...base, estado: 'ESCRITURA' as const, aprovado: true, fases_seguintes: ['REGISTADO' as const] };
    expect(accoesDoNegocio(e, todas)).toMatchObject({ cancelar: false, concluir: true, avancar: true });
    const soConclui = (...p: string[]) => p.some((x) => x === 'imo_neg_concluir');
    expect(accoesDoNegocio(e, soConclui)).toMatchObject({ concluir: true, avancar: false });
    const venda = { ...base, tipo: 'VENDA' as const, estado: 'CONCLUIDO' as const, aprovado: true, fases_seguintes: [], ativo_id: 9 };
    expect(accoesDoNegocio(venda, todas)).toMatchObject({ abater: true, concluir: false, editar: false, cancelar: false });
    expect(accoesDoNegocio(venda, soConclui).abater).toBe(false);
    expect(accoesDoNegocio({ ...venda, abatido_em: '2026-03-21T10:00:00+01:00' }, todas).abater).toBe(false);
    expect(accoesDoNegocio({ ...venda, ativo_id: null }, todas).abater).toBe(false);
  });

  it('a venda com contratos em vigor mostra a decisão sobre os contratos', () => {
    const v = { ...base, tipo: 'VENDA' as const, contratos_em_vigor: [{ id: 1 }] as never };
    expect(accoesDoNegocio(v, todas).tratarContratos).toBe(true);
    expect(accoesDoNegocio({ ...v, tipo: 'COMPRA' as const }, todas).tratarContratos).toBe(false);
    expect(accoesDoNegocio({ ...base, estado: 'CANCELADO' as const }, todas)).toMatchObject({ editar: false, pagamento: false, eliminar: true });
  });
});
