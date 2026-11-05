/**
 * Step-up (ADR-085): o servidor responde 403 `MFA_REQUERIDO` às operações críticas quando a sessão não tem uma
 * autenticação forte recente. Este módulo é o ponto de encontro entre o cliente HTTP (api/cliente.ts), que detecta o
 * erro, e o componente `PedidoReautenticacao`, que mostra o pedido de código/palavra-passe. Não importa o cliente HTTP
 * (o componente é que chama `/autenticacao/reautenticar`), para não criar uma dependência circular.
 *
 * Nada fica guardado no navegador: o estado é só em memória e o resultado é uma marca na sessão do servidor.
 */
export type MetodoReautenticacao = 'codigo_mfa' | 'palavra_passe';

type Ouvinte = (pedido: { metodo: MetodoReautenticacao; resolver: (confirmado: boolean) => void }) => void;

let ouvinte: Ouvinte | null = null;
let pendente: Promise<boolean> | null = null;

/** O componente regista-se aqui; devolve a função que o desliga. */
export function registarOuvinteReautenticacao(o: Ouvinte): () => void {
  ouvinte = o;
  return () => {
    if (ouvinte === o) ouvinte = null;
  };
}

/**
 * Pede ao utilizador para confirmar a identidade. Vários pedidos a falhar ao mesmo tempo partilham o mesmo pedido
 * (uma só janela). Resolve `true` se confirmou, `false` se cancelou (ou se não há interface para perguntar).
 */
export function pedirReautenticacao(metodo: MetodoReautenticacao): Promise<boolean> {
  if (pendente) return pendente;
  if (!ouvinte) return Promise.resolve(false);
  const aberto = ouvinte;
  const pedido = new Promise<boolean>((resolver) => {
    aberto({ metodo, resolver });
  });
  pendente = pedido;
  // liberta no fim, mesmo que o ouvinte resolva de imediato (síncrono) ou falhe
  const libertar = () => {
    if (pendente === pedido) pendente = null;
  };
  pedido.then(libertar, libertar);
  return pendente;
}

/** Lê o método indicado pelo servidor em `erros.metodo` (omissão: palavra-passe). */
export function metodoDoErro(erros: Record<string, unknown> | undefined): MetodoReautenticacao {
  return erros?.metodo === 'codigo_mfa' ? 'codigo_mfa' : 'palavra_passe';
}
