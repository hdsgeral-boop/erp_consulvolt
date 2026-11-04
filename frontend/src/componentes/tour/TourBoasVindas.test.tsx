import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TourBoasVindas, reiniciarTourBoasVindas } from './TourBoasVindas';
import { idOpaco } from '@/sessao/chaveOpaca';

const definirVisto = vi.fn();
let visto = false;
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ estado: 'autenticado', utilizador: { id: 7, nome_completo: 'Ana Silva' }, empresa: null }) }));
vi.mock('@/modulos/config/estadoLicenca', () => ({ useEstadoLicenca: () => ({ data: undefined }) }));
vi.mock('@/componentes/preferencias/usePreferencias', () => ({ usePreferencia: () => [visto, definirVisto, false] }));

describe('Tour de boas-vindas', () => {
  beforeEach(() => {
    definirVisto.mockReset();
    localStorage.clear();
    visto = false;
  });

  it('aparece uma vez a quem ainda não o viu e «Agora não» grava que já foi visto', () => {
    render(<TourBoasVindas />);
    expect(screen.getByText('Bem-vindo(a), Ana!')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Agora não' }));
    expect(definirVisto).toHaveBeenCalledWith(true);
    expect(localStorage.getItem(`erp.tour_boas_vindas_v1:${idOpaco(7)}`)).toBe('1');
  });

  it('não aparece a quem já o viu, mas «Repetir tutorial» volta a abri-lo', () => {
    visto = true;
    render(<TourBoasVindas />);
    expect(screen.queryByText('Bem-vindo(a), Ana!')).toBeNull();
    act(() => reiniciarTourBoasVindas());
    expect(screen.getByText('Bem-vindo(a), Ana!')).toBeTruthy();
  });

  it('a cópia local de «já visto» evita repeti-lo quando a preferência do servidor não foi lida', () => {
    localStorage.setItem(`erp.tour_boas_vindas_v1:${idOpaco(7)}`, '1');
    render(<TourBoasVindas />);
    expect(screen.queryByText('Bem-vindo(a), Ana!')).toBeNull();
  });
});
