import dayjs from 'dayjs';
import { accoesItem, contaBalancoPadrao, diferencaPlano, fimPorMeses, mesApi, somaQuotas, totaisSeleccao } from './regras';
import type { LinhaProposta } from './tipos';

const todas = () => true;
const nenhuma = () => false;

describe('acções dos registos', () => {
  it('acréscimo activo: regularizar e terminar; sem lançamentos pode eliminar', () => {
    expect(accoesItem({ tipo: 'ACRESCIMO', estado: 'ACTIVO', tem_lancamentos: false }, todas)).toEqual({
      editar: true, edicaoParcial: false, eliminar: true, regularizar: true, terminar: true, desfazer: false,
    });
  });

  it('diferimento não se regulariza; com lançamentos a edição é parcial e não se elimina', () => {
    expect(accoesItem({ tipo: 'DIFERIMENTO', estado: 'ACTIVO', tem_lancamentos: true }, todas)).toMatchObject({ regularizar: false, edicaoParcial: true, eliminar: false, terminar: true });
  });

  it('pedidos por contabilizar podem ser desfeitos; fechados não se editam', () => {
    expect(accoesItem({ tipo: 'ACRESCIMO', estado: 'A_REGULARIZAR' }, todas)).toMatchObject({ desfazer: true, regularizar: true, terminar: false });
    expect(accoesItem({ tipo: 'DIFERIMENTO', estado: 'A_TERMINAR' }, todas)).toMatchObject({ desfazer: true, terminar: false });
    expect(accoesItem({ tipo: 'ACRESCIMO', estado: 'CONCLUIDO', tem_lancamentos: true }, todas)).toMatchObject({ editar: false, eliminar: false, regularizar: false });
    expect(accoesItem({ tipo: 'ACRESCIMO', estado: 'ACTIVO' }, nenhuma)).toEqual({ editar: false, edicaoParcial: false, eliminar: false, regularizar: false, terminar: false, desfazer: false });
  });
});

describe('plano e proposta', () => {
  it('conta 37 por omissão segundo o tipo e a natureza', () => {
    const def = { contas: { ACRESCIMO_CUSTO: '3754', ACRESCIMO_PROVEITO: '3731', DIFERIMENTO_CUSTO: '3743', DIFERIMENTO_PROVEITO: null } };
    expect(contaBalancoPadrao(def, 'DIFERIMENTO', 'CUSTO')).toBe('3743');
    expect(contaBalancoPadrao(def, 'ACRESCIMO', 'PROVEITO')).toBe('3731');
    expect(contaBalancoPadrao(def, 'DIFERIMENTO', 'PROVEITO')).toBeUndefined();
    expect(contaBalancoPadrao(undefined, 'DIFERIMENTO', 'CUSTO')).toBeUndefined();
  });

  it('soma das quotas e diferença face ao valor, ao cêntimo', () => {
    const quotas = [{ valor: '33.33' }, { valor: '33.33' }, { valor: '33.34' }];
    expect(somaQuotas(quotas)).toBe('100.00');
    expect(diferencaPlano('100.00', quotas)).toBe('0.00');
    expect(diferencaPlano(100.05, quotas)).toBe('0.05');
  });

  it('totais da selecção da proposta', () => {
    const l = (chave: string, valor: string) => ({ chave, valor } as LinhaProposta);
    const linhas = [l('1|REC|2026-09', '0.10'), l('1|REC|2026-10', '0.20'), l('2|INI|2026-09', '5.00')];
    expect(totaisSeleccao(linhas, ['1|REC|2026-09', '1|REC|2026-10'])).toEqual({ n: 2, total: '0.30' });
    expect(totaisSeleccao(linhas, [])).toEqual({ n: 0, total: '0.00' });
  });

  it('datas dos modelos e mês da API', () => {
    expect(fimPorMeses(dayjs('2026-01-01'), 12).format('YYYY-MM-DD')).toBe('2026-12-31');
    expect(fimPorMeses(dayjs('2026-09-14'), 3).format('YYYY-MM-DD')).toBe('2026-12-13');
    expect(fimPorMeses(dayjs('2026-03-01'), 0).format('YYYY-MM-DD')).toBe('2026-03-31');
    expect(mesApi(dayjs('2026-09-30'))).toBe('2026-09');
  });
});

describe('cartões da lista (indicadoresAD)', () => {
  it('conta os em curso e soma o reconhecido e o por reconhecer', async () => {
    const { indicadoresAD } = await import('./regras');
    expect(
      indicadoresAD([
        { tipo: 'ACRESCIMO', valor: '1000.00', reconhecido: '250.50' },
        { tipo: 'DIFERIMENTO', valor: '1200.00', reconhecido: '200.00' },
        { tipo: 'DIFERIMENTO', valor: '300.00' },
      ]),
    ).toEqual({ acrescimos: 1, diferimentos: 2, acrescimosReconhecido: '250.50', diferimentosPorReconhecer: '1300.00' });
  });
});
