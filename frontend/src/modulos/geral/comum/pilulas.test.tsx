import { fireEvent, render, screen } from '@testing-library/react';
import { vi } from 'vitest';
import { PilulasSeparadores } from './PilulasSeparadores';
import { SeparadoresFluxo } from '../fluxo/componentes';
import { estiloPainel } from './estiloPainel';

const FLUXOS = [
  { id: 'rh', nome: 'Salários e RH', icone: 'users' },
  { id: 'ferias', nome: 'Plano de Férias', icone: 'umbrella-beach' },
  { id: 'bancos', nome: 'Bancos (43) e Cartões', icone: 'university' },
];

describe('Pílulas partilhadas', () => {
  it('o Fluxo de Processos usa as mesmas classes e cor por tipo que o Dashboard', () => {
    const aoEscolher = vi.fn();
    const { container } = render(<SeparadoresFluxo fluxos={FLUXOS} activo="ferias" aoEscolher={aoEscolher} />);
    expect(container.querySelector('.painel-modulos')).not.toBeNull();
    const abas = screen.getAllByRole('tab');
    expect(abas).toHaveLength(3);
    abas.forEach((b) => expect(b.className).toContain('painel-modulo-btn'));
    expect(abas[1].className).toContain('activo');
    expect(abas[1].getAttribute('aria-selected')).toBe('true');
    expect((abas[1] as HTMLElement).style.getPropertyValue('--painel-cor')).toBe(estiloPainel('ferias').cor);
    expect((abas[0] as HTMLElement).style.getPropertyValue('--painel-cor')).toBe(estiloPainel('rh').cor);
    expect(abas[2].textContent).toContain('Bancos (43) e Cartões');
    fireEvent.click(abas[2]);
    expect(aoEscolher).toHaveBeenCalledWith('bancos');
    fireEvent.keyDown(abas[1], { key: 'ArrowRight' });
    expect(aoEscolher).toHaveBeenLastCalledWith('bancos');
  });

  it('o Dashboard mantém botões com aria-pressed na mesma estrutura', () => {
    const { container } = render(<PilulasSeparadores itens={[{ id: 'geral', nome: 'Visão Geral' }, { id: 'rh', nome: 'Recursos Humanos' }]} activo="rh" aoEscolher={() => undefined} rotulo="Módulos do dashboard" />);
    expect(container.querySelector('nav.painel-modulos')).not.toBeNull();
    expect(screen.getByRole('button', { name: /Recursos Humanos/, pressed: true }).className).toContain('painel-modulo-btn activo');
  });

  it('todos os tipos de fluxo têm cor própria', () => {
    for (const id of ['rh', 'ferias', 'avaliacao', 'vendas', 'pos', 'lavandaria', 'projetos', 'compras', 'imobilizado', 'bancos', 'caixa', 'orcamento', 'crm', 'acrescimos']) {
      expect(estiloPainel(id).cor).not.toBe('#64748b');
    }
  });
});
