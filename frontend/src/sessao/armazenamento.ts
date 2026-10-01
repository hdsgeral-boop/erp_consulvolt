/** Persistência mínima da sessão no navegador: só o token e a empresa activa (o resto vem de /autenticacao/eu). */
const CHAVE_TOKEN = 'erp.token';
const CHAVE_EMPRESA = 'erp.empresa';

function ler(chave: string): string | null {
  try {
    return window.localStorage.getItem(chave);
  } catch {
    return null;
  }
}

function escrever(chave: string, valor: string | null): void {
  try {
    if (valor === null) window.localStorage.removeItem(chave);
    else window.localStorage.setItem(chave, valor);
  } catch {
    /* modo privado ou armazenamento bloqueado: a sessão fica só em memória */
  }
}

export const armazenamento = {
  token: () => ler(CHAVE_TOKEN),
  definirToken: (t: string | null) => escrever(CHAVE_TOKEN, t),
  empresaId: (): number | null => {
    const v = ler(CHAVE_EMPRESA);
    return v ? Number(v) : null;
  },
  definirEmpresa: (id: number | null) => escrever(CHAVE_EMPRESA, id === null ? null : String(id)),
  limpar: () => {
    escrever(CHAVE_TOKEN, null);
    escrever(CHAVE_EMPRESA, null);
  },
};
