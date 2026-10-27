import { useCallback, useEffect, useRef, useState, type PointerEvent as EventoPonteiro } from 'react';

/**
 * Posição (x, y) de um painel flutuante arrastável, no mesmo padrão do friso do menu (ver larguraMenu.ts):
 * pointerdown na pega → a posição acompanha o ponteiro a cada fotograma (requestAnimationFrame) → solta e grava.
 * Duplo clique na pega repõe a posição por omissão (o painel volta a ficar ancorado perto do gatilho). A posição
 * fica dentro do viewport (clamp) e é guardada no navegador por utilizador.
 */
export const chaveLocalPosicao = (chave: string, utilizador?: number | string | null) => `erp.${chave}.posicao.${utilizador ?? 'anonimo'}`;

export interface Posicao {
  x: number;
  y: number;
}

function limitar(p: Posicao, largura: number, altura: number): Posicao {
  const maxX = Math.max(0, (typeof window !== 'undefined' ? window.innerWidth : 1920) - largura);
  const maxY = Math.max(0, (typeof window !== 'undefined' ? window.innerHeight : 1080) - altura);
  return { x: Math.min(maxX, Math.max(0, Math.round(p.x))), y: Math.min(maxY, Math.max(0, Math.round(p.y))) };
}

/** Já foi arrastado antes (há uma posição gravada)? Usa-se para decidir se um painel nasce flutuante ou ancorado. */
export function temPosicaoFlutuante(chave: string, utilizador?: number | string | null): boolean {
  return lerPosicaoLocal(chave, utilizador) !== null;
}

function lerPosicaoLocal(chave: string, utilizador?: number | string | null): Posicao | null {
  try {
    const t = window.localStorage.getItem(chaveLocalPosicao(chave, utilizador));
    if (!t) return null;
    const p = JSON.parse(t) as Partial<Posicao>;
    return Number.isFinite(p.x) && Number.isFinite(p.y) ? { x: Number(p.x), y: Number(p.y) } : null;
  } catch {
    return null;
  }
}

function gravarLocal(chave: string, utilizador: number | string | null | undefined, p: Posicao | null): void {
  try {
    if (p === null) window.localStorage.removeItem(chaveLocalPosicao(chave, utilizador));
    else window.localStorage.setItem(chaveLocalPosicao(chave, utilizador), JSON.stringify(p));
  } catch {
    /* armazenamento indisponível: fica só nesta sessão */
  }
}

export interface PropsPega {
  aArrastar: boolean;
  onPointerDown: (e: EventoPonteiro<HTMLElement>) => void;
  onDoubleClick: () => void;
}

/**
 * `padrao`: posição de ancoragem inicial (perto do gatilho que abriu o painel); `tamanho`: largura/altura actuais do
 * painel, para o manter dentro do ecrã.
 */
export function usePosicaoFlutuante(chave: string, padrao: Posicao, tamanho: { largura: number; altura: number }, utilizador?: number | string | null) {
  const [posicao, setPosicao] = useState<Posicao>(() => limitar(lerPosicaoLocal(chave, utilizador) ?? padrao, tamanho.largura, tamanho.altura));
  const [aArrastar, setAArrastar] = useState(false);
  const arrasto = useRef<{ x0: number; y0: number; p0: Posicao; ultima: Posicao } | null>(null);
  const fotograma = useRef<number | null>(null);
  const limpar = useRef<(() => void) | null>(null);

  useEffect(() => {
    setPosicao(limitar(lerPosicaoLocal(chave, utilizador) ?? padrao, tamanho.largura, tamanho.altura));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chave, utilizador]);

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
    setPosicao(a.ultima);
    gravarLocal(chave, utilizador, a.ultima);
  }, [chave, utilizador]);

  const comecar = useCallback(
    (x: number, y: number) => {
      if (arrasto.current || !Number.isFinite(x) || !Number.isFinite(y)) return;
      arrasto.current = { x0: x, y0: y, p0: posicao, ultima: posicao };
      setAArrastar(true);
      document.body.style.cursor = 'grabbing';
      document.body.style.userSelect = 'none';
      const mover = (ev: MouseEvent | PointerEvent) => {
        const a = arrasto.current;
        if (!a || !Number.isFinite(ev.clientX) || !Number.isFinite(ev.clientY)) return;
        a.ultima = limitar({ x: a.p0.x + (ev.clientX - a.x0), y: a.p0.y + (ev.clientY - a.y0) }, tamanho.largura, tamanho.altura);
        if (typeof requestAnimationFrame !== 'function') {
          setPosicao(a.ultima);
          return;
        }
        if (fotograma.current !== null) return;
        fotograma.current = requestAnimationFrame(() => {
          fotograma.current = null;
          if (arrasto.current) setPosicao(arrasto.current.ultima);
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
    [posicao, tamanho.largura, tamanho.altura, terminar],
  );

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

  const repor = useCallback(() => {
    const p = limitar(padrao, tamanho.largura, tamanho.altura);
    setPosicao(p);
    gravarLocal(chave, utilizador, null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chave, utilizador, padrao.x, padrao.y, tamanho.largura, tamanho.altura]);

  const pega: PropsPega = {
    aArrastar,
    onPointerDown: (e) => {
      if (e.button !== 0 && e.pointerType === 'mouse') return;
      try {
        e.currentTarget.setPointerCapture?.(e.pointerId);
      } catch {
        /* sem captura */
      }
      e.preventDefault();
      comecar(e.clientX, e.clientY);
    },
    onDoubleClick: repor,
  };

  return { posicao, repor, pega };
}
