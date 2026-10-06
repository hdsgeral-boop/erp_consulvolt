import { useCallback, useSyncExternalStore } from 'react';

/**
 * «Modo responsivo» (botão da barra do legado — toggleResponsiveMode / body.force-mobile-view): força o aspecto de
 * telemóvel num ecrã largo (menu em gaveta, barra compacta, grelhas empilhadas, conteúdo com a largura de um telemóvel),
 * para rever os ecrãs como no terreno. É uma preferência do posto (como o legado), guardada no navegador; useEcra()
 * passa a responder como «xs» enquanto estiver activo.
 */
const CHAVE = 'erp.modoResponsivo';
const ouvintes = new Set<() => void>();

function lerGuardado(): boolean {
  try {
    return window.localStorage.getItem(CHAVE) === '1';
  } catch {
    return false;
  }
}

let activo = typeof window !== 'undefined' ? lerGuardado() : false;

export function modoResponsivoActivo(): boolean {
  return activo;
}

export function definirModoResponsivo(valor: boolean): void {
  activo = valor;
  try {
    if (valor) window.localStorage.setItem(CHAVE, '1');
    else window.localStorage.removeItem(CHAVE);
  } catch {
    /* armazenamento indisponível: fica só nesta sessão */
  }
  ouvintes.forEach((f) => f());
}

function subscrever(f: () => void): () => void {
  ouvintes.add(f);
  return () => ouvintes.delete(f);
}

export function useModoResponsivo(): [boolean, (valor: boolean) => void] {
  const valor = useSyncExternalStore(subscrever, modoResponsivoActivo, () => false);
  return [valor, useCallback((v: boolean) => definirModoResponsivo(v), [])];
}
