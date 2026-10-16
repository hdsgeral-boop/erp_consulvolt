import { useCallback, useEffect, useRef, useState, type KeyboardEvent as EventoTeclado, type MouseEvent as EventoRato, type PointerEvent as EventoPonteiro } from 'react';

/**
 * Largura do menu lateral ajustável à mão (Versão Actual: app_v2.js «Sidebar Resizer Logic» + styles.css .sidebar-resizer):
 *  - arrastar o friso do bordo direito do menu (rato, caneta ou toque) muda a largura entre 220 e 800 px;
 *  - duplo clique no friso repõe a largura de origem (280 px) e apaga a preferência;
 *  - teclado (acessibilidade, não existia no legado): o friso é um `role="separator"` focável; ← → mudam 10 px
 *    (Shift: 50 px), Home/End vão ao mínimo/máximo e Enter/Delete repõem;
 *  - a largura fica guardada no navegador por utilizador (`erp.menu.largura.<id>`; no legado era `sidebar-width`, sem
 *    utilizador) e não se aplica ao menu recolhido (só símbolos, 76 px) nem à gaveta dos ecrãs pequenos.
 */
export const LARGURA_MENU_PADRAO = 280;
export const LARGURA_MENU_MINIMA = 220;
export const LARGURA_MENU_MAXIMA = 800;
/** Menu recolhido (legado: --sidebar-estreita). */
export const LARGURA_MENU_RECOLHIDO = 76;
export const PASSO_TECLADO = 10;
export const PASSO_TECLADO_GRANDE = 50;

export const chaveLocalLargura = (utilizador?: number | string | null) => `erp.menu.largura.${utilizador ?? 'anonimo'}`;

/** Arredonda e prende a largura aos limites do legado; valores inválidos → largura de origem. */
export function limitarLargura(v: unknown): number {
  const n = typeof v === 'number' ? v : Number.parseFloat(String(v ?? ''));
  if (!Number.isFinite(n)) return LARGURA_MENU_PADRAO;
  return Math.min(LARGURA_MENU_MAXIMA, Math.max(LARGURA_MENU_MINIMA, Math.round(n)));
}

export function lerLarguraLocal(utilizador?: number | string | null): number {
  try {
    const t = window.localStorage.getItem(chaveLocalLargura(utilizador));
    return t ? limitarLargura(t) : LARGURA_MENU_PADRAO;
  } catch {
    return LARGURA_MENU_PADRAO;
  }
}

function gravarLocal(utilizador: number | string | null | undefined, largura: number | null): void {
  try {
    if (largura === null) window.localStorage.removeItem(chaveLocalLargura(utilizador));
    else window.localStorage.setItem(chaveLocalLargura(utilizador), String(largura));
  } catch {
    /* armazenamento indisponível: fica só nesta sessão */
  }
}

/** Nova largura para uma tecla do friso (null = tecla sem efeito; «repor» = voltar à largura de origem). */
export function larguraPorTecla(actual: number, tecla: string, grande = false): number | 'repor' | null {
  const passo = grande ? PASSO_TECLADO_GRANDE : PASSO_TECLADO;
  switch (tecla) {
    case 'ArrowRight':
    case 'ArrowUp':
      return limitarLargura(actual + passo);
    case 'ArrowLeft':
    case 'ArrowDown':
      return limitarLargura(actual - passo);
    case 'Home':
      return LARGURA_MENU_MINIMA;
    case 'End':
      return LARGURA_MENU_MAXIMA;
    case 'Enter':
    case 'Delete':
      return 'repor';
    default:
      return null;
  }
}

export interface PropsPuxador {
  aArrastar: boolean;
  onPointerDown: (e: EventoPonteiro<HTMLElement>) => void;
  onMouseDown: (e: EventoRato<HTMLElement>) => void;
  onDoubleClick: () => void;
  onKeyDown: (e: EventoTeclado<HTMLElement>) => void;
}

/**
 * Estado da largura do menu com o comportamento do friso. A largura acompanha o rato a cada fotograma (requestAnimationFrame)
 * e só é gravada ao largar, como no legado.
 */
export function useLarguraMenu(utilizador?: number | string | null): { largura: number; repor: () => void; definir: (n: number) => void; puxador: PropsPuxador } {
  const [largura, setLargura] = useState<number>(() => lerLarguraLocal(utilizador));
  const [aArrastar, setAArrastar] = useState(false);
  const arrasto = useRef<{ x0: number; l0: number; ultima: number } | null>(null);
  const fotograma = useRef<number | null>(null);
  const limpar = useRef<(() => void) | null>(null);

  // outro utilizador no mesmo posto: lê a largura dele
  useEffect(() => {
    setLargura(lerLarguraLocal(utilizador));
  }, [utilizador]);

  const definir = useCallback(
    (n: number) => {
      const l = limitarLargura(n);
      setLargura(l);
      gravarLocal(utilizador, l);
    },
    [utilizador],
  );
  const repor = useCallback(() => {
    setLargura(LARGURA_MENU_PADRAO);
    gravarLocal(utilizador, null);
  }, [utilizador]);

  const terminar = useCallback(() => {
    const a = arrasto.current;
    if (!a) return;
    arrasto.current = null;
    if (fotograma.current !== null && typeof cancelAnimationFrame === 'function') cancelAnimationFrame(fotograma.current);
    fotograma.current = null;
    limpar.current?.();
    limpar.current = null;
    document.body.style.cursor = '';
    document.body.style.userSelect = '';
    setAArrastar(false);
    setLargura(a.ultima);
    gravarLocal(utilizador, a.ultima);
  }, [utilizador]);

  const comecar = useCallback(
    (x: number) => {
      if (arrasto.current || !Number.isFinite(x)) return;
      arrasto.current = { x0: x, l0: largura, ultima: largura };
      setAArrastar(true);
      document.body.style.cursor = 'col-resize';
      document.body.style.userSelect = 'none';
      const mover = (ev: MouseEvent | PointerEvent) => {
        const a = arrasto.current;
        if (!a || !Number.isFinite(ev.clientX)) return;
        a.ultima = limitarLargura(a.l0 + (ev.clientX - a.x0));
        if (typeof requestAnimationFrame !== 'function') {
          setLargura(a.ultima);
          return;
        }
        if (fotograma.current !== null) return;
        fotograma.current = requestAnimationFrame(() => {
          fotograma.current = null;
          if (arrasto.current) setLargura(arrasto.current.ultima);
        });
      };
      const fim = () => terminar();
      // rato e ponteiro: no navegador chegam os dois (o mesmo clientX); nos testes (jsdom) só o rato
      document.addEventListener('pointermove', mover);
      document.addEventListener('mousemove', mover);
      document.addEventListener('pointerup', fim);
      document.addEventListener('mouseup', fim);
      document.addEventListener('pointercancel', fim);
      window.addEventListener('blur', fim);
      limpar.current = () => {
        document.removeEventListener('pointermove', mover);
        document.removeEventListener('mousemove', mover);
        document.removeEventListener('pointerup', fim);
        document.removeEventListener('mouseup', fim);
        document.removeEventListener('pointercancel', fim);
        window.removeEventListener('blur', fim);
      };
    },
    [largura, terminar],
  );

  // desmontar a meio de um arrasto não deixa ouvintes nem o cursor presos
  useEffect(
    () => () => {
      limpar.current?.();
      if (arrasto.current) {
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
      }
    },
    [],
  );

  const puxador: PropsPuxador = {
    aArrastar,
    onPointerDown: (e) => {
      if (e.button !== 0 && e.pointerType === 'mouse') return;
      try {
        e.currentTarget.setPointerCapture?.(e.pointerId);
      } catch {
        /* sem captura */
      }
      e.preventDefault();
      comecar(e.clientX);
    },
    onMouseDown: (e) => {
      if (e.button !== 0) return;
      e.preventDefault();
      comecar(e.clientX);
    },
    onDoubleClick: repor,
    onKeyDown: (e) => {
      const n = larguraPorTecla(largura, e.key, e.shiftKey);
      if (n === null) return;
      e.preventDefault();
      if (n === 'repor') repor();
      else definir(n);
    },
  };

  return { largura, repor, definir, puxador };
}
