import { render } from '@testing-library/react';
import { GraficoBarras, GraficoDonut, corBarra } from './Graficos';
import { corCategoria } from './estiloGraficos';
import { GraficoPainel } from '@/modulos/geral/comum/GraficoPainel';

const cores = (c: HTMLElement, sel: string) => [...c.querySelectorAll(sel)].map((e) => e.getAttribute('fill'));

describe('cor por barra/fatia (backgroundColor: [...] do Chart.js do legado)', () => {
  it('corCategoria aceita lista ou função; vazio cai na cor da série/paleta', () => {
    expect(corCategoria(['#111', null], 'a', 0)).toBe('#111');
    expect(corCategoria(['#111', null], 'b', 1)).toBeUndefined();
    expect(corCategoria((r) => (r === 'x' ? '#222' : undefined), 'x', 5)).toBe('#222');
    expect(corBarra({ rotulo: 's', valores: [], cor: '#333' }, 0, 'b', 1, ['#111'])).toBe('#333');
    expect(corBarra({ rotulo: 's', valores: [], cores: ['#444'] }, 0, 'a', 0, ['#111'])).toBe('#444');
    expect(corBarra(undefined, 1, 'a', 0)).toBe('#10b981');
  });

  it('GraficoBarras pinta cada barra com a cor da categoria; sem cores usa a da série', () => {
    const { container, rerender } = render(
      <GraficoBarras rotulos={['A', 'B']} series={[{ rotulo: 'S', valores: [1, 2], cor: '#16a34a', cores: ['#dc2626', null] }]} />,
    );
    expect(cores(container, 'path.grafico-anim-v')).toEqual(['#dc2626', '#16a34a']);
    rerender(<GraficoBarras rotulos={['A', 'B']} series={[{ rotulo: 'S', valores: [1, 2] }]} coresCategorias={(_r, i) => ['#7c3aed', '#0891b2'][i]} />);
    expect(cores(container, 'path.grafico-anim-v')).toEqual(['#7c3aed', '#0891b2']);
    rerender(<GraficoBarras rotulos={['A', 'B']} series={[{ rotulo: 'S', valores: [1, 2] }]} />);
    expect(cores(container, 'path.grafico-anim-v')).toEqual(['#2563eb', '#2563eb']);
  });

  it('GraficoDonut usa as cores por categoria (pelo rótulo original) e a paleta por omissão', () => {
    const { container, rerender } = render(<GraficoDonut rotulos={['A', 'B', 'C']} series={[{ rotulo: 'S', valores: [1, 0, 3] }]} coresCategorias={['#7c3aed', '#2563eb', '#64748b']} />);
    expect(cores(container, 'svg[role="img"] path')).toEqual(['#7c3aed', '#64748b']);
    rerender(<GraficoDonut rotulos={['A', 'B']} series={[{ rotulo: 'S', valores: [1, 3] }]} />);
    expect(cores(container, 'svg[role="img"] path')).toEqual(['#2563eb', '#10b981']);
  });

  it('GraficoPainel (mensal) aceita coresCategorias', () => {
    const { container } = render(
      <GraficoPainel grafico={{ id: 'g', titulo: 'G', tipo: 'barras', rotulos: ['Jan', 'Fev'], series: [{ rotulo: 'S', valores: [1, 2] }] }} coresCategorias={['#111111', '#222222']} />,
    );
    expect(cores(container, 'svg[role="img"] path')).toEqual(['#111111', '#222222']);
  });
});
