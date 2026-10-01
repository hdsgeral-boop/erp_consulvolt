import { accoesOrcamento, corMonitor, desvioFavoravel, doze, percentagensValidas, repartirAnual, repartirPorPesos, sinal, totalAnual, totaisMensais } from './regras';

const todas = () => true;
const so = (...chaves: string[]) => (...pedidas: string[]) => pedidas.some((p) => chaves.includes(p));
const somaC = (v: number[]) => v.reduce((t, x) => t + Math.round(x * 100), 0);

describe('somas mensais e anuais', () => {
  it('normaliza para 12 meses e soma ao cêntimo', () => {
    expect(doze([1, '2.5', null])).toEqual([1, 2.5, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]);
    expect(totalAnual(Array(12).fill(0.1))).toBe(1.2);
    expect(totalAnual([10000, 1000, 30000, 0, 400000, 777777, 88888, 88888, 99999, 9999, 0, 99999])).toBe(1606550);
    expect(totalAnual([-0.04, 0.61])).toBe(0.57);
  });

  it('totais por mês e resultado com sinal (entradas − saídas)', () => {
    const linhas = [
      { valores: Array(12).fill(100), natureza: 'PROVEITO' as const },
      { valores: Array(12).fill(30.1), natureza: 'CUSTO' as const },
    ];
    const brutos = totaisMensais(linhas);
    expect(brutos[0]).toBe(130.1);
    expect(brutos[12]).toBe(1561.2);
    const resultado = totaisMensais(linhas, true);
    expect(resultado[0]).toBe(69.9);
    expect(resultado[12]).toBe(838.8);
    expect(sinal('RECEBIMENTO')).toBe(1);
    expect(sinal('PAGAMENTO')).toBe(-1);
  });
});

describe('repartição', () => {
  it('total anual em 12 meses iguais; Dezembro absorve o resto', () => {
    const r = repartirAnual(1000);
    expect(r.slice(0, 11).every((v) => v === 83.33)).toBe(true);
    expect(r[11]).toBe(83.37);
    expect(somaC(r)).toBe(100000);
    expect(somaC(repartirAnual(0.05))).toBe(5);
  });

  it('por pesos (sazonalidade/percentagens), com a soma exacta', () => {
    const r = repartirPorPesos(100, [1, 1, 1]);
    expect(r).toEqual([33.33, 33.33, 33.34]);
    expect(somaC(repartirPorPesos(1234.56, [10, 20, 70]))).toBe(123456);
    expect(repartirPorPesos(12, [0, 0])).toHaveLength(12);
  });

  it('percentagens do top-down manual somam 100', () => {
    expect(percentagensValidas([33.33, 33.33, 33.34])).toBe(true);
    expect(percentagensValidas([50, 49.98])).toBe(false);
    expect(percentagensValidas([])).toBe(false);
    expect(percentagensValidas([120, -20])).toBe(false);
  });
});

describe('ciclo de aprovação', () => {
  const base = { responsavel: null, submetido_por: null, orcamento_pai_id: null };

  it('rascunho: editar, submeter e eliminar; aprovado: só nova versão', () => {
    expect(accoesOrcamento({ ...base, estado: 'RASCUNHO' }, todas, 'ana')).toMatchObject({ editarValores: true, submeter: true, eliminar: true, aprovar: false, novaVersao: false, hierarquia: true });
    expect(accoesOrcamento({ ...base, estado: 'APROVADO' }, todas, 'ana')).toMatchObject({ editarValores: false, submeter: false, novaVersao: true, eliminar: false });
  });

  it('quem submeteu não aprova, mas pode devolver', () => {
    expect(accoesOrcamento({ ...base, estado: 'SUBMETIDO', submetido_por: 'ana' }, todas, 'ana')).toMatchObject({ aprovar: false, devolver: true });
    expect(accoesOrcamento({ ...base, estado: 'SUBMETIDO', submetido_por: 'rui' }, todas, 'ana')).toMatchObject({ aprovar: true });
  });

  it('o responsável do contributo edita e submete com orc_contributo', () => {
    const contributo = { ...base, estado: 'RASCUNHO', responsavel: 'ana', orcamento_pai_id: 1 };
    expect(accoesOrcamento(contributo, so('orc_contributo'), 'ana')).toMatchObject({ editarValores: true, submeter: true, eliminar: false, hierarquia: false });
    expect(accoesOrcamento(contributo, so('orc_contributo'), 'rui')).toMatchObject({ editarValores: false, submeter: false });
  });
});

describe('desvios e monitor', () => {
  it('favorável acima nas entradas e abaixo nas saídas', () => {
    expect(desvioFavoravel('PROVEITO', 100, 120)).toBe(true);
    expect(desvioFavoravel('CUSTO', 100, 120)).toBe(false);
    expect(desvioFavoravel('PAGAMENTO', 100, 80)).toBe(true);
  });

  it('cores do monitor', () => {
    expect(corMonitor('EXCEDIDO')).toBe('red');
    expect(corMonitor('AVISO')).toBe('orange');
    expect(corMonitor('OK')).toBe('green');
  });
});
