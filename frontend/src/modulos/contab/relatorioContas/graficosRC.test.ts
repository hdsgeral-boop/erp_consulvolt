import { describe, expect, it } from 'vitest';
import { barrasComparadas, barrasEmpilhadas, circular, duo, escalaEixo, fmtCurto, graficoSeccao, GRAFICOS } from './graficosRC';

describe('Relatório e Contas — gráficos SVG', () => {
  it('formato curto do legado', () => {
    expect(fmtCurto(1_500_000)).toBe('1,5 M');
    expect(fmtCurto(2_000_000_000)).toBe('2,0 mil M');
    expect(fmtCurto(35_000)).toBe('35 mil');
    expect(fmtCurto(12.5)).toBe('12,5');
  });

  it('escala do eixo inclui o zero e cobre os valores', () => {
    const e = escalaEixo(-120, 830);
    expect(e.min).toBeLessThanOrEqual(-120);
    expect(e.max).toBeGreaterThanOrEqual(830);
    expect(e.valores).toContain(0);
  });

  it('barras N vs N-1: vazio sem valores; legenda com os anos; texto escapado', () => {
    expect(barrasComparadas('T', ['a'], [0], [0], [2026, 2025])).toBe('');
    const s = barrasComparadas('Título <x>', ['Rótulo & c'], ['100.00'], ['50.00'], [2026, 2025]);
    expect(s.startsWith('<svg')).toBe(true);
    expect(s).toContain('Título &lt;x&gt;');
    expect(s).toContain('Rótulo &amp; c');
    expect(s).toContain('>2026<');
    expect(s).toContain('>2025<');
    expect(s).not.toMatch(/<script|on\w+=/i);
    expect(barrasComparadas('H', ['a', 'b'], [1, 2], [3, 4], [2026, 2025], { horizontal: true })).toContain('<rect');
  });

  it('circular só com partes positivas e percentagens na legenda', () => {
    expect(circular('C', ['a', 'b'], [0, -5])).toBe('');
    const s = circular('C', ['A', 'B'], [75, 25]);
    expect(s).toContain('75,0 %');
    expect(s).toContain('25,0 %');
    expect(circular('C', ['Única'], [10])).toContain('<circle');
  });

  it('empilhadas ignoram séries a zero', () => {
    expect(barrasEmpilhadas('E', ['x'], [{ rotulo: 'z', cor: '#000', valores: [0] }])).toBe('');
    expect(barrasEmpilhadas('E', ['x', 'y'], [{ rotulo: 'A', cor: '#000', valores: [5, 0] }, { rotulo: 'B', cor: '#111', valores: [0, -3] }])).toContain('>A<');
  });

  it('duo põe dois gráficos lado a lado (bloco indivisível)', () => {
    expect(duo('', '')).toBe('');
    expect(duo('<svg></svg>', '<svg></svg>')).toContain('rc-graf-duo imp-sem-quebra');
  });

  it('gráficos por secção como no legado; desligados pela configuração', () => {
    const d = {
      ano: 2026,
      ano_anterior: 2025,
      n: { indicadores: { liquidez_geral: 2, roe: 0.2, rl: '100.00' }, fluxo: { operacionais: '10.00' } },
      n1: { indicadores: { liquidez_geral: 1 } },
    };
    expect(Object.keys(GRAFICOS)).toEqual(expect.arrayContaining(['acontecimentos', 'analise_balanco', 'recursos_humanos', 'liquidez', 'estrutura', 'rentabilidade', 'aplicacao']));
    const liq = graficoSeccao('liquidez', d, {});
    expect(liq).toContain('Rácios de liquidez');
    expect(liq).toContain('Fluxos de caixa por actividade');
    expect(graficoSeccao('aplicacao', d, { pct_transitados: 100 })).toContain('Proposta de aplicação do resultado');
    expect(graficoSeccao('liquidez', d, { graficos: false })).toBe('');
    expect(graficoSeccao('economia', d, {})).toBe('');
    expect(graficoSeccao('constructor', d, {})).toBe('');
  });
});
