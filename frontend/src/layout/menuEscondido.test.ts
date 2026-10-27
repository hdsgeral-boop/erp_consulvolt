import { act, renderHook } from '@testing-library/react';
import { chaveLocalEscondido, useMenuEscondido } from './menuEscondido';

afterEach(() => localStorage.clear());

describe('esconder o menu lateral por completo (diferente do recolher normal, só ícones)', () => {
  it('nasce visível e guarda/apaga a preferência por utilizador', () => {
    const { result } = renderHook(() => useMenuEscondido(9));
    expect(result.current.escondido).toBe(false);
    act(() => result.current.definir(true));
    expect(result.current.escondido).toBe(true);
    expect(localStorage.getItem(chaveLocalEscondido(9))).toBe('1');
    act(() => result.current.definir(false));
    expect(result.current.escondido).toBe(false);
    expect(localStorage.getItem(chaveLocalEscondido(9))).toBeNull();
  });

  it('lê o estado guardado ao montar', () => {
    localStorage.setItem(chaveLocalEscondido(2), '1');
    const { result } = renderHook(() => useMenuEscondido(2));
    expect(result.current.escondido).toBe(true);
  });
});
