import { act, renderHook } from '@testing-library/react';
import {
  LARGURA_MENU_MAXIMA,
  LARGURA_MENU_MINIMA,
  LARGURA_MENU_PADRAO,
  chaveLocalLargura,
  larguraPorTecla,
  lerLarguraLocal,
  limitarLargura,
  useLarguraMenu,
} from './larguraMenu';

afterEach(() => localStorage.clear());

describe('largura do menu lateral (legado: .sidebar-resizer)', () => {
  it('usa os limites do legado: 220 a 800 px, 280 por omissão', () => {
    expect([LARGURA_MENU_MINIMA, LARGURA_MENU_PADRAO, LARGURA_MENU_MAXIMA]).toEqual([220, 280, 800]);
    expect(limitarLargura(100)).toBe(220);
    expect(limitarLargura(5000)).toBe(800);
    expect(limitarLargura('333.6px')).toBe(334);
    expect(limitarLargura('lixo')).toBe(280);
    expect(limitarLargura(undefined)).toBe(280);
  });

  it('teclas do friso: setas ±10 (Shift ±50), Home/End nos limites, Enter/Delete repõem, as outras não fazem nada', () => {
    expect(larguraPorTecla(280, 'ArrowRight')).toBe(290);
    expect(larguraPorTecla(280, 'ArrowLeft')).toBe(270);
    expect(larguraPorTecla(280, 'ArrowRight', true)).toBe(330);
    expect(larguraPorTecla(795, 'ArrowRight', true)).toBe(800);
    expect(larguraPorTecla(225, 'ArrowLeft')).toBe(220);
    expect(larguraPorTecla(400, 'Home')).toBe(220);
    expect(larguraPorTecla(400, 'End')).toBe(800);
    expect(larguraPorTecla(400, 'Enter')).toBe('repor');
    expect(larguraPorTecla(400, 'Delete')).toBe('repor');
    expect(larguraPorTecla(400, 'a')).toBeNull();
  });

  it('guarda por utilizador no navegador e ignora valores adulterados', () => {
    localStorage.setItem(chaveLocalLargura(7), '450');
    localStorage.setItem(chaveLocalLargura(8), '99999');
    expect(lerLarguraLocal(7)).toBe(450);
    expect(lerLarguraLocal(8)).toBe(800);
    expect(lerLarguraLocal(9)).toBe(280);
  });

  it('o gancho grava ao definir, repõe ao duplo clique e responde ao teclado', () => {
    const { result } = renderHook(() => useLarguraMenu(3));
    expect(result.current.largura).toBe(280);
    act(() => result.current.definir(512));
    expect(result.current.largura).toBe(512);
    expect(localStorage.getItem('erp.menu.largura.3')).toBe('512');
    const tecla = (key: string, shiftKey = false) => {
      const ev = { key, shiftKey, preventDefault: vi.fn() };
      act(() => result.current.puxador.onKeyDown(ev as never));
      return ev.preventDefault;
    };
    expect(tecla('ArrowLeft')).toHaveBeenCalled();
    expect(result.current.largura).toBe(502);
    tecla('End');
    expect(result.current.largura).toBe(800);
    expect(tecla('Tab')).not.toHaveBeenCalled();
    act(() => result.current.puxador.onDoubleClick());
    expect(result.current.largura).toBe(280);
    expect(localStorage.getItem('erp.menu.largura.3')).toBeNull();
  });
});
