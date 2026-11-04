import { useCallback, useState } from 'react';
import { identificadorSessao } from '@/api/cliente';
import { hashCurto, idOpaco } from '@/sessao/chaveOpaca';

/**
 * Dispensa do pop-up de aviso de licença, por utilizador e por login: a chave guarda o identificador da sessão em
 * que foi dispensado (cookie CSRF — Fase D; o token em si vai num cookie HttpOnly, nunca lido aqui). Um login novo
 * (SessaoContexto.entrar) gera um identificador diferente, por isso o pop-up volta a aparecer; um simples
 * recarregar da página reaproveita o mesmo (SessaoContexto, arranque) e não o reabre.
 */
const chaveLocal = (utilizador?: number | string | null) => `erp.licenca.avisoVisto.${idOpaco(utilizador)}`;

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
  // só o HASH do identificador da sessão fica guardado: o valor do cookie CSRF nunca vai para o localStorage
  const identificador = identificadorSessao();
  const token = identificador ? hashCurto(identificador) : null;
  const [vistoToken, setVistoToken] = useState<string | null>(() => lerVisto(utilizador));

  const dispensar = useCallback(() => {
    if (!token) return;
    gravarVisto(utilizador, token);
    setVistoToken(token);
  }, [utilizador, token]);

  return { visto: !!token && vistoToken === token, dispensar };
}
