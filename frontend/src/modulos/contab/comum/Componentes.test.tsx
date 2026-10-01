import { render, screen } from '@testing-library/react';
import { EtiquetaEstado, IndicadorEquilibrio } from './Componentes';

describe('IndicadorEquilibrio', () => {
  it('mostra «Equilibrado» quando D = C', () => {
    render(<IndicadorEquilibrio linhas={[{ tipo_dc: 'D', valor: 10 }, { tipo_dc: 'C', valor: 10 }]} />);
    expect(screen.getByText('Equilibrado')).toBeInTheDocument();
  });

  it('mostra «Desequilibrado» e a diferença', () => {
    render(<IndicadorEquilibrio linhas={[{ tipo_dc: 'D', valor: 10 }, { tipo_dc: 'C', valor: 7.5 }]} />);
    expect(screen.getByText('Desequilibrado')).toBeInTheDocument();
    expect(screen.getByText('2,50')).toBeInTheDocument();
  });
});

describe('EtiquetaEstado', () => {
  it('traduz o código do estado', () => {
    render(<EtiquetaEstado estado="APROVADO" />);
    expect(screen.getByText('Concluído')).toBeInTheDocument();
  });
});
