import { useCallback, useState } from 'react';

/** Modo do menu principal: barra lateral (por omissão) ou barra no topo. Guardado no navegador por utilizador. */
export type ModoMenu = 'lateral' | 'topo';

export const chaveLocalModoMenu = (utilizador?: number | string | null) => `erp.menu.modo.${utilizador ?? 'anonimo'}`;

function lerModoLocal(utilizador?: number | string | null): ModoMenu {
  try {
    return localStorage.getItem(chaveLocalModoMenu(utilizador)) === 'topo' ? 'topo' : 'lateral';
  } catch {
    return 'lateral';
  }
}

export function useModoMenu(utilizador?: number | string | null) {
  const [modo, setModoEstado] = useState<ModoMenu>(() => lerModoLocal(utilizador));
  const definir = useCallback(
    (v: ModoMenu) => {
      setModoEstado(v);
      try {
        if (v === 'topo') localStorage.setItem(chaveLocalModoMenu(utilizador), 'topo');
        else localStorage.removeItem(chaveLocalModoMenu(utilizador));
      } catch {
        // localStorage indisponível (privado/bloqueado): o estado fica só na sessão
      }
    },
    [utilizador],
  );
  return { modo, definir };
}
