import { act, renderHook } from '@testing-library/react';
import { chaveLocalPosicao, temPosicaoFlutuante, usePosicaoFlutuante } from './posicaoFlutuante';

afterEach(() => localStorage.clear());

describe('posição flutuante (pesquisa arrastável, ver larguraMenu.ts para o mesmo padrão)', () => {
  it('nasce na posição por omissão e dentro do ecrã', () => {
    const { result } = renderHook(() => usePosicaoFlutuante('teste', { x: 10, y: 20 }, { largura: 100, altura: 50 }));
    expect(result.current.posicao).toEqual({ x: 10, y: 20 });
    expect(temPosicaoFlutuante('teste')).toBe(false);
  });

  it('prende a posição guardada ao viewport actual (nunca sai do ecrã)', () => {
    localStorage.setItem(chaveLocalPosicao('teste', 5), JSON.stringify({ x: 99999, y: -50 }));
    const { result } = renderHook(() => usePosicaoFlutuante('teste', { x: 0, y: 0 }, { largura: 100, altura: 50 }, 5));
    expect(result.current.posicao.x).toBeLessThanOrEqual(window.innerWidth - 100);
    expect(result.current.posicao.y).toBe(0);
    expect(temPosicaoFlutuante('teste', 5)).toBe(true);
  });

  it('a pega arrasta por pointerdown/pointermove/pointerup e grava a posição final, por utilizador', () => {
    const { result } = renderHook(() => usePosicaoFlutuante('teste', { x: 50, y: 50 }, { largura: 100, altura: 50 }, 3));
    const down = { button: 0, pointerType: 'mouse', clientX: 50, clientY: 50, preventDefault: vi.fn(), currentTarget: { setPointerCapture: vi.fn() } };
    act(() => result.current.pega.onPointerDown(down as never));
    expect(result.current.pega.aArrastar).toBe(true);
    act(() => document.dispatchEvent(new MouseEvent('mousemove', { clientX: 80, clientY: 70 })));
    act(() => document.dispatchEvent(new MouseEvent('mouseup')));
    expect(result.current.pega.aArrastar).toBe(false);
    expect(result.current.posicao).toEqual({ x: 80, y: 70 });
    expect(JSON.parse(localStorage.getItem(chaveLocalPosicao('teste', 3)) ?? '{}')).toEqual({ x: 80, y: 70 });
  });

  it('duplo clique na pega repõe a posição por omissão e apaga a preferência', () => {
    const { result } = renderHook(() => usePosicaoFlutuante('teste', { x: 10, y: 20 }, { largura: 100, altura: 50 }, 4));
    localStorage.setItem(chaveLocalPosicao('teste', 4), JSON.stringify({ x: 300, y: 300 }));
    act(() => result.current.repor());
    expect(result.current.posicao).toEqual({ x: 10, y: 20 });
    expect(localStorage.getItem(chaveLocalPosicao('teste', 4))).toBeNull();
  });
});
