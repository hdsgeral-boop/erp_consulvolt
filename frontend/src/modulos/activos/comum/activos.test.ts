import {
  accoesActivo, accoesPeriodo, codigoPeriodo, lerCsvImportacao, lerNumero, ordemPeriodo, ordenarPeriodos, quotaEditavel, repartirValor,
  resultadoAbate, rotuloPeriodo, somaMesesLinha, totaisMapa, validarInventariacao,
} from './regras';
import type { LinhaMapa } from './tipos';

const todas = () => true;
const nenhuma = () => false;
const so = (...chaves: string[]) => (...pedidas: string[]) => pedidas.some((p) => chaves.includes(p));

describe('períodos (MM-AAAA)', () => {
  it('gera, ordena e rotula períodos', () => {
    expect(codigoPeriodo(2026, 9)).toBe('09-2026');
    expect(ordemPeriodo('01-2027')).toBeGreaterThan(ordemPeriodo('12-2026'));
    expect(Number.isNaN(ordemPeriodo('2026-09'))).toBe(true);
    expect(ordenarPeriodos(['02-2027', '11-2026', '02-2027', '01-2027'])).toEqual(['11-2026', '01-2027', '02-2027']);
    expect(rotuloPeriodo('09-2026')).toBe('Set 2026');
  });
});

describe('visibilidade das acções', () => {
  it('período: calcular/integrar/reabrir conforme o estado', () => {
    expect(accoesPeriodo('ABERTO', todas)).toMatchObject({ calcular: true, integrar: false, reabrir: false });
    expect(accoesPeriodo('CALCULADO', todas)).toMatchObject({ calcular: true, integrar: true, reabrir: false });
    expect(accoesPeriodo('PARCIAL', todas)).toMatchObject({ integrar: true, reabrir: true });
    expect(accoesPeriodo('INTEGRADO', todas)).toMatchObject({ calcular: false, quotaManual: false, integrar: false, reabrir: true });
    expect(accoesPeriodo('CALCULADO', so('activos_amort_calcular'))).toMatchObject({ calcular: true, integrar: false });
    expect(accoesPeriodo('CALCULADO', nenhuma)).toEqual({ calcular: false, quotaManual: false, integrar: false, reabrir: false });
  });

  it('quota manual só fora do estado integrado', () => {
    expect(quotaEditavel({ estado: 'RASCUNHO' })).toBe(true);
    expect(quotaEditavel({ estado: 'INTEGRADO' })).toBe(false);
  });

  it('ficha do activo: abatido não se edita nem abate', () => {
    expect(accoesActivo({ estado: 'ACTIVO' }, todas)).toMatchObject({ editar: true, transferir: true, abater: true });
    expect(accoesActivo({ estado: 'ABATIDO' }, todas)).toMatchObject({ editar: false, transferir: false, abater: false, eliminar: true });
    expect(accoesActivo({ estado: 'ACTIVO' }, so('activos_abater'))).toMatchObject({ editar: false, abater: true });
    expect(accoesActivo(null, todas).editar).toBe(false);
  });
});

describe('mapa anual', () => {
  const linha = (meses: Record<string, string | null>, ano: string): LinhaMapa => ({
    ativo_imobilizado_id: 1, codigo: 'A', descricao: 'x', estado: 'ACTIVO', categoria: 'C', taxa: '20', anos_vida: '5', data_aquisicao: null,
    aquisicao_anos_anteriores: '0.00', aquisicao_ano: '100.00', acumulado_anterior: '0.00', ano, acumulado: ano, liquido: '0.00',
    meses: Object.fromEntries(Array.from({ length: 12 }, (_, i) => [String(i + 1), meses[String(i + 1)] ? { valor: meses[String(i + 1)]!, contabilizado: true, amortizacao_id: i } : null])),
  });

  it('a soma dos 12 meses iguala o total anual, ao cêntimo', () => {
    const l = linha({ 3: '3653.51', 4: '3653.51', 5: '3653.51', 6: '3653.51', 7: '3653.51', 8: '3653.51' }, '21921.06');
    expect(somaMesesLinha(l)).toBe('21921.06');
  });

  it('totais filtrados somam por mês sem erros de vírgula flutuante', () => {
    const t = totaisMapa([linha({ 1: '0.10' }, '0.10'), linha({ 1: '0.20' }, '0.20')]);
    expect(t.meses['1']).toBe('0.30');
    expect(t.meses['2']).toBe('0.00');
    expect(t.ano).toBe('0.30');
    expect(t.aquisicao_ano).toBe('200.00');
  });
});

describe('abate', () => {
  it('calcula mais-valia, menos-valia e resultado nulo', () => {
    expect(resultadoAbate('150', '1000', '900')).toEqual({ liquido: '100.00', resultado: '50.00', tipo: 'MAIS_VALIA' });
    expect(resultadoAbate(0, '1000', '400')).toEqual({ liquido: '600.00', resultado: '-600.00', tipo: 'MENOS_VALIA' });
    expect(resultadoAbate('600', '1000', '400').tipo).toBe('NULO');
  });
});

describe('inventariação e repartição', () => {
  it('reparte ao cêntimo com o resto na última parte', () => {
    expect(repartirValor('100.00', 3)).toEqual(['33.33', '33.33', '33.34']);
    expect(repartirValor('0.05', 2)).toEqual(['0.02', '0.03']);
    expect(repartirValor('10', 0)).toEqual(['10.00']);
  });

  it('não deixa exceder o valor por inventariar', () => {
    expect(validarInventariacao([{ valor_aquisicao: 600 }, { valor_aquisicao: '400.00' }], '1000.00')).toEqual({ total: '1000.00', restante: '0.00', excede: false });
    expect(validarInventariacao([{ valor_aquisicao: 600.01 }, { valor_aquisicao: 400 }], '1000.00').excede).toBe(true);
  });
});

describe('importação CSV', () => {
  it('lê números em formato português e técnico', () => {
    expect(lerNumero('1 234,56')).toBe(1234.56);
    expect(lerNumero('1.234,56')).toBe(1234.56);
    expect(lerNumero('1234.56')).toBe(1234.56);
    expect(lerNumero('1,234.56')).toBe(1234.56);
    expect(lerNumero('')).toBeUndefined();
    expect(lerNumero('abc')).toBeUndefined();
  });

  it('mapeia cabeçalhos, ignora os desconhecidos e respeita aspas', () => {
    const csv = '﻿Código;Descrição;Valor;Categoria;Vida útil;Cor\nAST-1;"Mesa; grande";1 500,00;Mobiliário;60;azul\n;Cadeira;200;Mobiliário;;\n';
    const r = lerCsvImportacao(csv);
    expect(r.ignoradas).toEqual(['cor']);
    expect(r.linhas).toEqual([
      { codigo: 'AST-1', descricao: 'Mesa; grande', valor_aquisicao: 1500, categoria: 'Mobiliário', vida_util: 60 },
      { descricao: 'Cadeira', valor_aquisicao: 200, categoria: 'Mobiliário' },
    ]);
  });

  it('aceita vírgula como separador e devolve vazio sem dados', () => {
    expect(lerCsvImportacao('codigo,descricao\nX,Y').linhas).toEqual([{ codigo: 'X', descricao: 'Y' }]);
    expect(lerCsvImportacao('codigo;descricao').linhas).toEqual([]);
  });
});
