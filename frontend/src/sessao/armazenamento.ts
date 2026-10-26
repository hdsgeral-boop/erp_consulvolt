/**
 * Persistência mínima da sessão no navegador: o token e a empresa activa (o resto vem de /autenticacao/eu).
 * As empresas usadas recentemente ficam por utilizador e sobrevivem ao «Sair», para a escolha as mostrar no topo.
 */
const CHAVE_TOKEN = 'erp.token';
const CHAVE_EMPRESA = 'erp.empresa';
const CHAVE_RECENTES = 'erp.empresas_recentes.';
const MAX_RECENTES = 5;

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
  /** Ids das empresas usadas pelo utilizador, da mais recente para a mais antiga. */
  empresasRecentes: (utilizadorId: number): number[] => {
    try {
      const v: unknown = JSON.parse(ler(CHAVE_RECENTES + utilizadorId) ?? '[]');
      return Array.isArray(v) ? v.filter((id): id is number => Number.isInteger(id)) : [];
    } catch {
      return [];
    }
  },
  registarEmpresaRecente: (utilizadorId: number, empresaId: number) => {
    const lista = [empresaId, ...armazenamento.empresasRecentes(utilizadorId).filter((id) => id !== empresaId)].slice(0, MAX_RECENTES);
    escrever(CHAVE_RECENTES + utilizadorId, JSON.stringify(lista));
  },
  limpar: () => {
    escrever(CHAVE_TOKEN, null);
    escrever(CHAVE_EMPRESA, null);
  },
};
