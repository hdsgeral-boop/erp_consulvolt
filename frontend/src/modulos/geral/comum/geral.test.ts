import { caminhoSector, corSerie, eixoValores, escalaLinear, fatiasDonut, formatarCompacto, paraNumero, passoRedondo } from '@/componentes/graficos/escalas';
import { construirPivot, moverDimensao, pivotParaCsv, rotuloChave, seriesPivot, type ResultadoCubo } from './pivot';
import { formatarPorFormato } from './componentes';

describe('escalas dos gráficos', () => {
  it('converte valores da API', () => {
    expect(paraNumero('1234.50')).toBe(1234.5);
    expect(paraNumero(null)).toBe(0);
    expect(paraNumero('abc')).toBe(0);
    expect(paraNumero(7)).toBe(7);
  });

  it('calcula passos redondos', () => {
    expect(passoRedondo(100, 5)).toBe(20);
    expect(passoRedondo(1_000_000, 5)).toBe(200_000);
    expect(passoRedondo(12, 5)).toBe(2.5);
    expect(passoRedondo(0)).toBe(1);
  });

  it('inclui o zero e arredonda o domínio', () => {
    const e = eixoValores([120, 870]);
    expect(e.minimo).toBe(0);
    expect(e.maximo).toBe(1000);
    expect(e.marcas[0]).toBe(0);
    expect(e.marcas[e.marcas.length - 1]).toBe(1000);
    const n = eixoValores([-300, 450]);
    expect(n.minimo).toBeLessThanOrEqual(-300);
    expect(n.maximo).toBeGreaterThanOrEqual(450);
    expect(n.marcas).toContain(0);
  });

  it('trata séries vazias ou só com zeros', () => {
    expect(eixoValores([])).toEqual({ minimo: 0, maximo: 1, marcas: expect.arrayContaining([0, 1]) });
    expect(eixoValores([0, 0]).maximo).toBe(1);
  });

  it('escala linearmente (eixo invertido em y)', () => {
    const y = escalaLinear(0, 100, 200, 0);
    expect(y(0)).toBe(200);
    expect(y(100)).toBe(0);
    expect(y(50)).toBe(100);
  });

  it('formata valores compactos', () => {
    expect(formatarCompacto(950)).toBe('950');
    expect(formatarCompacto(1500)).toBe('1,5 mil');
    expect(formatarCompacto(3_400_000)).toBe('3,4 M');
    expect(formatarCompacto(-2_100_000_000)).toBe('-2,1 mM');
  });

  it('calcula as fatias do donut sem negativos', () => {
    const f = fatiasDonut(['A', 'B', 'C'], [30, -5, 10]);
    expect(f.map((x) => x.rotulo)).toEqual(['A', 'C']);
    expect(f[0].fraccao).toBeCloseTo(0.75);
    expect(f[1].fim).toBeCloseTo(2 * Math.PI);
    expect(fatiasDonut(['A'], [0])).toEqual([]);
    expect(caminhoSector(50, 50, 40, 24, 0, Math.PI)).toMatch(/^M .* Z$/);
    expect(caminhoSector(50, 50, 40, 24, 0, 2 * Math.PI).match(/M /g)).toHaveLength(2);
  });

  it('usa cores fixas por posição e cinzento a partir da 9.ª série', () => {
    expect(corSerie(0)).toBe('#2a78d6');
    expect(corSerie(8)).toBe('#8c8c8c');
  });
});

const RESULTADO: ResultadoCubo = {
  conjunto: { id: 'vendas', nome: 'Vendas' },
  periodo: { data_inicio: '2026-01-01', data_fim: '2026-12-31' },
  linhas: [{ id: 'cliente', rotulo: 'Cliente' }],
  colunas: [{ id: 'mes', rotulo: 'Mês' }],
  medidas: [
    { medida: 'total', agregacao: 'soma', rotulo: 'Total', formato: 'kz' },
    { medida: null, agregacao: 'contagem', rotulo: 'Contagem', formato: 'num' },
  ],
  chaves_colunas: [['Jan'], ['Fev']],
  resultado: [
    { chave: ['Cliente A'], valores: [['100.00', 2], null], total: ['100.00', 2] },
    { chave: [null], valores: [['50.00', 1], ['300.00', 3]], total: ['350.00', 4] },
  ],
  totais_colunas: [['150.00', 3], ['300.00', 3]],
  total_geral: ['450.00', 6],
};

describe('análise dinâmica (pivot)', () => {
  it('gera colunas por chave × medida e totais', () => {
    const p = construirPivot(RESULTADO);
    expect(p.colunas.map((c) => c.chave)).toEqual(['c0_0', 'c0_1', 'c1_0', 'c1_1', 't_0', 't_1']);
    expect(p.colunas[4]).toMatchObject({ grupo: 'Total', medida: 'Total', total: true });
    expect(p.linhas[0]).toMatchObject({ dimensoes: ['Cliente A'], c0_0: '100.00', c1_0: null, t_1: 2 });
    expect(p.linhas[1].dimensoes).toEqual(['(vazio)']);
    expect(p.totais).toMatchObject({ dimensoes: ['Total geral'], c1_0: '300.00', t_0: '450.00' });
  });

  it('sem dimensões nas colunas usa só o total por medida', () => {
    const p = construirPivot({ ...RESULTADO, colunas: [], chaves_colunas: [], totais_colunas: [] });
    expect(p.colunas.map((c) => c.chave)).toEqual(['t_0', 't_1']);
    expect(p.colunas[0].grupo).toBe('');
  });

  it('constrói séries ordenadas pelo total da 1.ª medida', () => {
    const s = seriesPivot(RESULTADO);
    expect(s.rotulos).toEqual(['(vazio)', 'Cliente A']);
    expect(s.series.map((x) => x.rotulo)).toEqual(['Jan', 'Fev']);
    expect(s.series[1].valores).toEqual(['300.00', null]);
  });

  it('exporta para CSV com cabeçalho e linha de totais', () => {
    const csv = pivotParaCsv(RESULTADO);
    expect(csv[0]).toEqual(['Cliente', 'Jan — Total', 'Jan — Contagem', 'Fev — Total', 'Fev — Contagem', 'Total — Total', 'Total — Contagem']);
    expect(csv[csv.length - 1][0]).toBe('Total geral');
    expect(csv[1][3]).toBe('');
  });

  it('move dimensões respeitando os limites', () => {
    expect(moverDimensao({ linhas: ['a'], colunas: ['b'] }, 'b', 'linhas')).toEqual({ linhas: ['a', 'b'], colunas: [] });
    expect(moverDimensao({ linhas: ['a'], colunas: ['b', 'c', 'd'] }, 'a', 'colunas')).toEqual({ linhas: [], colunas: ['b', 'c', 'd'] });
    expect(moverDimensao({ linhas: ['a'], colunas: [] }, 'a', 'nenhum')).toEqual({ linhas: [], colunas: [] });
    expect(rotuloChave(['2026', null])).toBe('2026 · (vazio)');
  });
});

describe('formatos de indicadores', () => {
  it('formata por tipo', () => {
    expect(formatarPorFormato('1234.5', 'kz')).toMatch(/1\s?234,50/);
    expect(formatarPorFormato(12.345, 'pct')).toBe('12,35%');
    expect(formatarPorFormato(3, 'dias')).toBe('3 dias');
    expect(formatarPorFormato('2026-03-01', 'data')).toBe('01/03/2026');
    expect(formatarPorFormato(null, 'kz')).toBe('—');
    expect(formatarPorFormato(4.256, 'nota', 1)).toBe('4,3');
    expect(formatarPorFormato('texto livre', 'texto')).toBe('texto livre');
  });
});
