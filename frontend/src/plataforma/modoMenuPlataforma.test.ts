import { act, renderHook } from '@testing-library/react';
import { chaveLocalModoMenu, useModoMenuPlataforma } from './modoMenuPlataforma';

afterEach(() => localStorage.clear());

describe('modo do menu da Central de Backoffice (barra lateral / barra no topo)', () => {
  it('nasce em «lateral» e guarda/apaga a preferência por utilizador', () => {
    const { result } = renderHook(() => useModoMenuPlataforma(9));
    expect(result.current.modo).toBe('lateral');
    act(() => result.current.definir('topo'));
    expect(result.current.modo).toBe('topo');
    expect(localStorage.getItem(chaveLocalModoMenu(9))).toBe('topo');
    act(() => result.current.definir('lateral'));
    expect(result.current.modo).toBe('lateral');
    expect(localStorage.getItem(chaveLocalModoMenu(9))).toBeNull();
  });

  it('lê o estado guardado ao montar', () => {
    localStorage.setItem(chaveLocalModoMenu(2), 'topo');
    const { result } = renderHook(() => useModoMenuPlataforma(2));
    expect(result.current.modo).toBe('topo');
  });
});
