import { describe, expect, it } from 'vitest';
import { camposMassa, filtrosLancamentos, intervaloPeriodo, REMOVER } from './filtros';
import { contravalorKz, emMoeda, equilibrarLinhas, linhasComMoeda } from './moeda';

describe('filtros da lista de lançamentos (A-05)', () => {
  it('ano e período dão o intervalo de datas (meses de 28 a 31 dias)', () => {
    expect(intervaloPeriodo(2024, 2)).toEqual({ inicio: '2024-02-01', fim: '2024-02-29' });
    expect(intervaloPeriodo(2026, 11)).toEqual({ inicio: '2026-11-01', fim: '2026-11-30' });
    expect(intervaloPeriodo(2026)).toEqual({ inicio: '2026-01-01', fim: '2026-12-31' });
    expect(intervaloPeriodo(undefined, 3)).toEqual({});
  });

  it('converte os filtros para a API, com «linhas sem» e textos aparados', () => {
    expect(filtrosLancamentos({ contas: ' 11, 21-25 ', numeroLan: '', classe9: true, diario: 3 }, ['demo', 'cc'])).toEqual({
      diario_id: 3,
      filtro_contas: '11, 21-25',
      terceiro_id: undefined,
      numero_lan: undefined,
      numero_documento: undefined,
      referencia: undefined,
      pesquisa: undefined,
      incluir_classe_9: 1,
      data_inicio: undefined,
      data_fim: undefined,
      sem: ['demo', 'cc'],
    });
    expect(filtrosLancamentos({}, []).sem).toBeUndefined();
  });

  it('alteração em massa: vazio mantém, REMOVER envia null', () => {
    expect(camposMassa({ nota_demonstracao_id: 7, centro_custo_id: REMOVER, unidade_negocio_id: undefined })).toEqual({ nota_demonstracao_id: 7, centro_custo_id: null });
    expect(camposMassa({})).toEqual({});
  });
});

describe('lançamento em moeda estrangeira (A-07)', () => {
  it('distingue a moeda base', () => {
    expect(emMoeda('AOA')).toBe(false);
    expect(emMoeda('aoa')).toBe(false);
    expect(emMoeda(undefined)).toBe(false);
    expect(emMoeda('USD')).toBe(true);
  });

  it('contravalor em Kz arredondado ao cêntimo', () => {
    expect(contravalorKz('100.00', 900.555)).toBe('90055.50');
    expect(contravalorKz('10.01', 900.555)).toBe('9014.56');
    expect(contravalorKz('10.00', null)).toBeNull();
  });

  it('em moeda, o valor das linhas vai em valor_moeda', () => {
    expect(linhasComMoeda([{ codigo_conta: '431', tipo_dc: 'D', valor: 10 }], 'USD')).toEqual([{ codigo_conta: '431', tipo_dc: 'D', valor_moeda: 10 }]);
    expect(linhasComMoeda([{ codigo_conta: '431', tipo_dc: 'D', valor: 10 }], 'AOA')).toEqual([{ codigo_conta: '431', tipo_dc: 'D', valor: 10 }]);
  });

  it('«Equilibrar» preenche a linha vazia do lado em falta ou acerta a última', () => {
    expect(equilibrarLinhas([{ tipo_dc: 'D', valor: 100 }, { tipo_dc: 'C', valor: undefined }])).toEqual([{ tipo_dc: 'D', valor: 100 }, { tipo_dc: 'C', valor: 100 }]);
    expect(equilibrarLinhas([{ tipo_dc: 'D', valor: 100 }, { tipo_dc: 'C', valor: 60.5 }])).toEqual([{ tipo_dc: 'D', valor: 100 }, { tipo_dc: 'C', valor: 100 }]);
    expect(equilibrarLinhas([{ tipo_dc: 'D', valor: 10 }, { tipo_dc: 'C', valor: 10 }])).toBeNull();
  });
});
