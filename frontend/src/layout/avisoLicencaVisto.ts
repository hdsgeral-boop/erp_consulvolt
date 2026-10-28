import { useCallback, useState } from 'react';
import { armazenamento } from '@/sessao/armazenamento';

/**
 * Dispensa do pop-up de aviso de licença, por utilizador e por login: a chave guarda o token da sessão em que foi
 * dispensado. Um login novo (SessaoContexto.entrar) grava um token diferente, por isso o pop-up volta a aparecer;
 * um simples recarregar da página reaproveita o mesmo token (SessaoContexto, arranque) e não o reabre.
 */
const chaveLocal = (utilizador?: number | string | null) => `erp.licenca.avisoVisto.${utilizador ?? 'anonimo'}`;

function lerVisto(utilizador?: number | string | null): string | null {
  try {
    return window.localStorage.getItem(chaveLocal(utilizador));
  } catch {
    return null;
  }
}

function gravarVisto(utilizador: number | string | null | undefined, token: string): void {
  try {
    window.localStorage.setItem(chaveLocal(utilizador), token);
  } catch {
    /* armazenamento indisponível: o pop-up pode voltar a aparecer na próxima vez, sem mais consequências */
  }
}

export function useAvisoLicencaVisto(utilizador?: number | string | null): { visto: boolean; dispensar: () => void } {
  const token = armazenamento.token();
  const [vistoToken, setVistoToken] = useState<string | null>(() => lerVisto(utilizador));

  const dispensar = useCallback(() => {
    if (!token) return;
    gravarVisto(utilizador, token);
    setVistoToken(token);
  }, [utilizador, token]);

  return { visto: !!token && vistoToken === token, dispensar };
}
