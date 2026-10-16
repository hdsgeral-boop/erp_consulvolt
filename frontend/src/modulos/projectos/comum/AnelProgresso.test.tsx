import { render, screen } from '@testing-library/react';
import { AnelProgresso, geometriaAnel } from './AnelProgresso';

describe('AnelProgresso (mostrador do legado, projectos_dashboard.js:247-259)', () => {
  it('geometria: limita a 0–100 e calcula o tracejado como o legado (r = 34)', () => {
    expect(geometriaAnel(50)).toEqual({ valor: 50, tracejado: '106.8 213.6' });
    expect(geometriaAnel(0).tracejado).toBe('0.0 213.6');
    expect(geometriaAnel(140).valor).toBe(100);
    expect(geometriaAnel(-5).valor).toBe(0);
    expect(geometriaAnel(null).valor).toBe(0);
    expect(geometriaAnel('37.5').valor).toBe(37.5);
  });

  it('desenha o anel com a cor e a espessura do legado, a percentagem arredondada e a legenda', () => {
    render(<AnelProgresso valor={42.6} cor="#2563eb" legenda="Execução física" nota="Média dos ramos do WBS" />);
    expect(screen.getByRole('img', { name: 'Execução física: 43%' })).toBeInTheDocument();
    expect(screen.getByText('43%')).toBeInTheDocument();
    expect(screen.getByText('Execução física')).toBeInTheDocument();
    expect(screen.getByText('Média dos ramos do WBS')).toBeInTheDocument();
    const arco = screen.getByTestId('anel-arco');
    expect(arco).toHaveAttribute('stroke', '#2563eb');
    expect(arco).toHaveAttribute('stroke-width', '8');
    expect(arco).toHaveAttribute('r', '34');
    expect(arco).toHaveAttribute('transform', 'rotate(-90 42 42)');
  });
});
