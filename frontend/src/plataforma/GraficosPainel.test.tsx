import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { GraficosPainel, temSeries } from './GraficosPainel';

describe('GraficosPainel', () => {
  it('sem séries (servidor antigo) não desenha nada', () => {
    const { container } = render(<GraficosPainel series={undefined} />);
    expect(container).toBeEmptyDOMElement();
    expect(temSeries({ moeda: 'AOA' })).toBe(false);
  });

  it('com séries mostra os quatro cartões', () => {
    render(
      <GraficosPainel
        series={{
          moeda: 'AOA',
          meses: ['2026-09', '2026-10'],
          clientes_novos_mes: [1, 2],
          receita_mes: [1000, 2000],
          dias: ['01', '02'],
          acessos_dia: [3, 4],
          por_plano: [{ plano: 'Profissional', clientes: 2 }],
        }}
      />,
    );
    for (const t of ['Clientes novos', 'Receita recebida', 'Clientes por plano', 'Acessos']) expect(screen.getByText(t)).toBeInTheDocument();
  });

  it('sem clientes por plano mostra o estado vazio', () => {
    render(<GraficosPainel series={{ por_plano: [] }} />);
    expect(screen.getByText('Sem clientes')).toBeInTheDocument();
  });
});
