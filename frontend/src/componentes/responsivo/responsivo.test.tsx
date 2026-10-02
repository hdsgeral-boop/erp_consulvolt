import { render, renderHook, screen } from '@testing-library/react';
import { BarraFiltros, COL_CAMPO, larguraGaveta, larguraModal, scrollTabela, useEcra, useEcraPequeno } from '.';
import { simularLargura } from './testes/simularLargura';

describe('utilitários responsivos', () => {
  it('larguraModal nunca ultrapassa o ecrã', () => {
    expect(larguraModal(900)).toBe('min(900px, calc(100vw - 32px))');
    expect(larguraModal(640.4)).toBe('min(640px, calc(100vw - 32px))');
  });

  it('larguraGaveta e scrollTabela', () => {
    expect(larguraGaveta(720)).toBe('min(720px, 100vw)');
    expect(scrollTabela()).toEqual({ x: 'max-content' });
    expect(scrollTabela(400)).toEqual({ x: 'max-content', y: 400 });
    expect(COL_CAMPO.xs).toBe(24);
  });

  it('BarraFiltros envolve os filtros na classe que quebra linha', () => {
    render(
      <BarraFiltros accoes={<button type="button">Novo</button>}>
        <input aria-label="Pesquisar" />
      </BarraFiltros>,
    );
    expect(screen.getByLabelText('Pesquisar').parentElement).toHaveClass('erp-barra-filtros');
    expect(screen.getByRole('button', { name: 'Novo' }).parentElement).toHaveClass('erp-barra-filtros-accoes');
  });
});

describe('useEcra / useEcraPequeno', () => {
  afterEach(() => simularLargura(null));

  it('telemóvel (375 px)', () => {
    simularLargura(375);
    const { result } = renderHook(() => ({ e: useEcra(), p: useEcraPequeno(), pLg: useEcraPequeno('lg') }));
    expect(result.current.e).toMatchObject({ actual: 'xs', telemovel: true, tablet: false, pequeno: true });
    expect(result.current.p).toBe(true);
    expect(result.current.pLg).toBe(true);
  });

  it('tablet (800 px)', () => {
    simularLargura(800);
    const { result } = renderHook(() => ({ e: useEcra(), p: useEcraPequeno(), pLg: useEcraPequeno('lg') }));
    expect(result.current.e).toMatchObject({ actual: 'md', telemovel: false, tablet: true, pequeno: true });
    expect(result.current.p).toBe(false);
    expect(result.current.pLg).toBe(true);
  });

  it('ecrã grande (1920 px)', () => {
    simularLargura(1920);
    const { result } = renderHook(() => useEcra());
    expect(result.current).toMatchObject({ actual: 'xxl', telemovel: false, tablet: false, pequeno: false });
  });
});
