/**
 * Escolha do cliente nas Vendas (GET /vendas/clientes/pesquisa): resumo do terceiro devolvido pelo servidor e regras puras
 * do seletor (rótulo, quem precisa de passar a cliente, pesquisa adiada).
 */

export interface ClienteResumo {
  id: number;
  nome: string;
  nif: string | null;
  tipo: string | null;
  codigo_moeda?: string | null;
  /** já tem o papel de cliente (CLIENTE ou CLIENTE_FORNECEDOR) */
  e_cliente: boolean;
  /** conta de cliente (classe 31) da ficha, ou null */
  conta_cliente: string | null;
}

export interface RespostaPesquisaClientes {
  itens: ClienteResumo[];
  /** conta das configurações de vendas, só como sugestão visível no diálogo «passar a cliente» */
  conta_cliente_sugerida: string | null;
}

/** Tempo de espera depois da última tecla antes de pesquisar no servidor. */
export const ATRASO_PESQUISA_MS = 300;

/** Máximo de resultados pedidos ao servidor por pesquisa. */
export const LIMITE_PESQUISA = 20;

const ROTULOS_TIPO: Record<string, string> = { FORNECEDOR: 'Fornecedor', CLIENTE_FORNECEDOR: 'Cliente e Fornecedor', CLIENTE: 'Cliente' };

/** «Nome (NIF 123)» — o mesmo texto do seletor anterior (os testes E2E procuram o nome nas opções). */
export function rotuloCliente(c: Pick<ClienteResumo, 'nome' | 'nif'>): string {
  return `${c.nome.trim()}${c.nif ? ` (NIF ${c.nif})` : ''}`;
}

/** Indicação extra da opção: quem ainda não é cliente passa a sê-lo ao ser escolhido. */
export function notaCliente(c: ClienteResumo): string | null {
  if (!c.e_cliente) return `${ROTULOS_TIPO[c.tipo ?? ''] ?? 'Terceiro'} — passa a cliente`;
  return null;
}

/** Escolher este terceiro exige primeiro acrescentar-lhe o papel de cliente (com a conta da classe 31)? */
export function precisaPapelCliente(c: ClienteResumo | undefined): boolean {
  return !!c && !c.e_cliente;
}

/** Conta de cliente aceitável no diálogo: só algarismos (e pontos), da classe 31. */
export function contaClienteValida(conta: string | undefined | null): boolean {
  const d = (conta ?? '').replace(/\./g, '');
  return /^[0-9.]+$/.test(conta ?? '') && d.startsWith('31') && d.length > 2;
}

/** Junta listas de clientes sem repetir ids (o primeiro ganha). */
export function juntarClientes(...listas: (ClienteResumo | null | undefined)[][]): ClienteResumo[] {
  const vistos = new Map<number, ClienteResumo>();
  for (const lista of listas) for (const c of lista) if (c && !vistos.has(c.id)) vistos.set(c.id, c);
  return [...vistos.values()];
}
