/**
 * Persistência mínima da sessão no navegador: só a empresa activa (o resto vem de /autenticacao/eu).
 * Fase D: o token deixou de passar por aqui — vai num cookie HttpOnly (nunca acessível a JavaScript),
 * definido pelo servidor no login e enviado automaticamente pelo browser em cada pedido (cliente.ts).
 */
const CHAVE_EMPRESA = 'erp.empresa';
const CHAVE_DISPOSITIVO = 'erp.dispositivo';

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

/**
 * Identificador deste browser (não desta sessão: sobrevive ao logout), gerado uma só vez e reutilizado em todos os
 * logins futuros — é o «dispositivo» que o backend usa para saber qual sessão completa anterior substituir
 * (ServicoAutenticacao::emitirTokenCompleto, Fase D). Sem isto, todos os browsers enviariam o mesmo valor fixo e um
 * login num computador diferente apagaria, por engano, a sessão de outro computador do mesmo utilizador. O prefixo
 * «web-» mantém o valor legível no ecrã «Sessões» da Plataforma (SessoesController lê esta coluna como "dispositivo").
 */
function idDispositivo(): string {
  const actual = ler(CHAVE_DISPOSITIVO);
  if (actual) return actual;
  const sufixo = typeof crypto?.randomUUID === 'function' ? crypto.randomUUID().slice(0, 8) : Math.random().toString(36).slice(2, 10);
  const novo = `web-${sufixo}`;
  escrever(CHAVE_DISPOSITIVO, novo);
  return novo;
}

export const armazenamento = {
  empresaId: (): number | null => {
    const v = ler(CHAVE_EMPRESA);
    return v ? Number(v) : null;
  },
  definirEmpresa: (id: number | null) => escrever(CHAVE_EMPRESA, id === null ? null : String(id)),
  idDispositivo,
  limpar: () => {
    escrever(CHAVE_EMPRESA, null);
  },
};
