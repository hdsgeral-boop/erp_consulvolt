import { useEffect, useRef } from 'react';

/**
 * Atalhos de teclado da frente de caixa (F2, F8, F9, …). As teclas de função funcionam mesmo com o foco num campo;
 * as restantes só fora de campos de texto. `activo = false` desliga-os (ex.: com um modal aberto por cima).
 */
export function useAtalhos(mapa: Record<string, (e: KeyboardEvent) => void>, activo = true): void {
  const ref = useRef(mapa);
  ref.current = mapa;
  useEffect(() => {
    if (!activo) return;
    const tratar = (e: KeyboardEvent) => {
      const accao = ref.current[e.key];
      if (!accao) return;
      const alvo = e.target as HTMLElement | null;
      const emCampo = !!alvo && (alvo.tagName === 'INPUT' || alvo.tagName === 'TEXTAREA' || alvo.isContentEditable);
      if (emCampo && !/^F\d{1,2}$/.test(e.key)) return;
      e.preventDefault();
      accao(e);
    };
    window.addEventListener('keydown', tratar);
    return () => window.removeEventListener('keydown', tratar);
  }, [activo]);
}
