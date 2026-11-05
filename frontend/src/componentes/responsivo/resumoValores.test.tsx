import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ResumoValores, ValorMonetario, formatarValor } from './index';

describe('ResumoValores', () => {
  it('mostra rótulo e valor de cada item e realça o destaque', () => {
    render(
      <ResumoValores
        densidade="largo"
        itens={[
          { rotulo: 'Subtotal bruto', valor: '9 500 AOA' },
          { rotulo: 'Valor a pagar', valor: '9 500 AOA', destaque: true },
        ]}
      />,
    );
    expect(screen.getAllByRole('listitem')).toHaveLength(2);
    expect(screen.getByText('Subtotal bruto')).toBeInTheDocument();
    expect(screen.getByText('Valor a pagar').closest('.erp-resumo-valores__item')).toHaveClass('erp-resumo-valores__item--destaque');
    expect(screen.getByRole('list')).toHaveClass('erp-resumo-valores--largo');
  });
});

describe('ValorMonetario', () => {
  it('liga a moeda e o sufixo ao número com espaço não separável e classe nowrap', () => {
    const { container } = render(<ValorMonetario valor="9 500" moeda="AOA" sufixo="/mês" />);
    const el = container.querySelector('.erp-valor') as HTMLElement;
    expect(el.textContent).toBe('9 500 AOA/mês');
  });
  it('mostra travessão para valores vazios', () => {
    const { container } = render(<ValorMonetario valor={null} moeda="AOA" />);
    expect(container.textContent).toBe('—');
  });
  it('formata números em pt-AO com duas casas decimais', () => {
    expect(formatarValor(1234.5)).toMatch(/1.234,50|1\s234,50/);
  });
});
