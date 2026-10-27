import { useCallback, useState } from 'react';

/**
 * Esconder o menu lateral por completo (desliza para cima e desaparece), diferente do recolher actual (que só
 * esconde os nomes, mantendo os ícones) — um botão no topo do menu esconde-o, um botão flutuante repõe-o. Estado
 * guardado no navegador por utilizador, como a largura do menu (ver larguraMenu.ts).
 */
export const chaveLocalEscondido = (utilizador?: number | string | null) => `erp.menu.escondido.${utilizador ?? 'anonimo'}`;

function lerEscondidoLocal(utilizador?: number | string | null): boolean {
  try {
    return localStorage.getItem(chaveLocalEscondido(utilizador)) === '1';
  } catch {
    return false;
  }
}

export function useMenuEscondido(utilizador?: number | string | null) {
  const [escondido, setEscondidoEstado] = useState(() => lerEscondidoLocal(utilizador));
  const definir = useCallback(
    (v: boolean) => {
      setEscondidoEstado(v);
      try {
        if (v) localStorage.setItem(chaveLocalEscondido(utilizador), '1');
        else localStorage.removeItem(chaveLocalEscondido(utilizador));
      } catch {
        // localStorage indisponível (privado/bloqueado): o estado fica só na sessão
      }
    },
    [utilizador],
  );
  return { escondido, definir };
}
