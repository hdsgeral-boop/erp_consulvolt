import { useLayoutEffect, type RefObject } from 'react';

/**
 * Valor dos cartões KPI sempre completo (pedido de 17/10/2026: nunca «…»). O valor começa numa linha com o tamanho de letra
 * da folha de estilos; se não couber, a letra encolhe até caber (no mínimo FRACCAO_MINIMA do tamanho base, nunca abaixo de
 * TAMANHO_MINIMO px); se mesmo assim não couber, a unidade passa para a linha seguinte (`data-quebra="unidade"`) e, em
 * último caso, o próprio número pode quebrar (`data-quebra="tudo"`). Recalcula quando a largura do cartão muda
 * (ResizeObserver) e quando o valor muda.
 */
export const FRACCAO_MINIMA = 0.6;
export const TAMANHO_MINIMO = 12;

/** O que o ajuste precisa de um elemento (interface mínima, para testar sem navegador). */
export interface ElementoAjustavel {
  readonly clientWidth: number;
  readonly scrollWidth: number;
  style: { fontSize: string };
  dataset: { quebra?: string };
}

const transborda = (el: ElementoAjustavel) => el.scrollWidth > el.clientWidth + 0.5;

/**
 * Ajusta o tamanho de letra de `el` para o conteúdo caber na largura. `tamanhoBase` é o tamanho da folha de estilos (px).
 * Devolve o tamanho final (px) — igual ao base quando já cabia.
 */
export function ajustarValor(el: ElementoAjustavel, tamanhoBase: number): number {
  el.style.fontSize = '';
  delete el.dataset.quebra;
  if (!(tamanhoBase > 0) || el.clientWidth <= 0 || !transborda(el)) return tamanhoBase;
  const minimo = Math.min(tamanhoBase, Math.max(TAMANHO_MINIMO, tamanhoBase * FRACCAO_MINIMA));
  // 1.º palpite proporcional (o texto encolhe quase linearmente com a letra) e depois afinação de meio em meio píxel
  let t = Math.max(minimo, Math.floor(tamanhoBase * (el.clientWidth / el.scrollWidth) * 2) / 2);
  el.style.fontSize = `${t}px`;
  for (let i = 0; i < 40 && transborda(el) && t > minimo; i++) {
    t = Math.max(minimo, t - 0.5);
    el.style.fontSize = `${t}px`;
  }
  if (transborda(el)) {
    el.dataset.quebra = 'unidade';
    if (transborda(el)) el.dataset.quebra = 'tudo';
  }
  return t;
}

/** Liga o ajuste a um elemento: no arranque, quando `chave` muda e quando a largura muda. */
export function useAjusteValorKpi(ref: RefObject<HTMLElement | null>, chave: unknown): void {
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el || typeof window === 'undefined') return;
    let largura = -1;
    const ajustar = () => {
      el.style.fontSize = '';
      const base = parseFloat(window.getComputedStyle(el).fontSize);
      ajustarValor(el, base);
      largura = el.clientWidth;
    };
    ajustar();
    // a letra Inter pode chegar depois da 1.ª medição
    let vivo = true;
    void document.fonts?.ready?.then(() => vivo && ajustar());
    if (typeof ResizeObserver === 'undefined') {
      return () => {
        vivo = false;
      };
    }
    // só a largura interessa: a altura muda com o próprio ajuste (evita ciclos)
    const observador = new ResizeObserver(() => {
      if (el.clientWidth !== largura) ajustar();
    });
    observador.observe(el);
    return () => {
      vivo = false;
      observador.disconnect();
    };
  }, [ref, chave]);
}
