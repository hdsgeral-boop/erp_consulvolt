import { useCallback, useState } from 'react';

/** Modo do menu da Central de Backoffice: barra lateral (por omissão) ou barra no topo. Guardado no navegador por utilizador. */
export type ModoMenuPlataforma = 'lateral' | 'topo';

export const chaveLocalModoMenu = (utilizador?: number | string | null) => `erp.plataforma.menu.modo.${utilizador ?? 'anonimo'}`;

function lerModoLocal(utilizador?: number | string | null): ModoMenuPlataforma {
  try {
    return localStorage.getItem(chaveLocalModoMenu(utilizador)) === 'topo' ? 'topo' : 'lateral';
  } catch {
    return 'lateral';
  }
}

export function useModoMenuPlataforma(utilizador?: number | string | null) {
  const [modo, setModoEstado] = useState<ModoMenuPlataforma>(() => lerModoLocal(utilizador));
  const definir = useCallback(
    (v: ModoMenuPlataforma) => {
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
