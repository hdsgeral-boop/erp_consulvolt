import { act, renderHook } from '@testing-library/react';
import { chaveLocalModoMenu, useModoMenu } from './modoMenu';

afterEach(() => localStorage.clear());

describe('modo do menu principal (barra lateral / barra no topo)', () => {
  it('nasce em «lateral» e guarda/apaga a preferência por utilizador', () => {
    const { result } = renderHook(() => useModoMenu(9));
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
    const { result } = renderHook(() => useModoMenu(2));
    expect(result.current.modo).toBe('topo');
  });
});
