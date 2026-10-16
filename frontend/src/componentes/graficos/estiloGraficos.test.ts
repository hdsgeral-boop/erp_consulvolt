import { CORES_SERIES, ESTILO_GRAFICO, corSerie } from './estiloGraficos';

describe('estilo único dos gráficos (Chart.js do legado)', () => {
  it('paleta categórica igual à PALETA de js/ui_painel_modulos.js, pela mesma ordem; «Outros» a cinzento', () => {
    expect(CORES_SERIES).toEqual(['#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16', '#64748b', '#f97316']);
    expect(corSerie(0)).toBe('#2563eb');
    expect(corSerie(3, '#ea580c')).toBe('#ea580c');
    expect(corSerie(10)).toBe('#94a3b8');
  });

  it('espessuras e opacidades das opções do Chart.js do legado', () => {
    expect(ESTILO_GRAFICO.barraOpacidade).toBe(0.8); // cor + 'cc'
    expect(ESTILO_GRAFICO.barraRaio).toBe(6);
    expect(ESTILO_GRAFICO.barraEspessuraMaxima).toBe(38);
    expect(ESTILO_GRAFICO.linhaEspessuraComArea).toBe(3);
    expect(ESTILO_GRAFICO.linhaEspessura).toBe(2);
    expect(ESTILO_GRAFICO.ponto).toBe(3);
    expect(ESTILO_GRAFICO.donutBorda).toBe(2);
    expect(ESTILO_GRAFICO.areaOpacidade).toBeCloseTo(0x22 / 255, 2); // cor + '22'
  });
});
