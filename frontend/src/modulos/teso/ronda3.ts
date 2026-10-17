/**
 * Regras da ronda 3 da Tesouraria (r3_teso) — funções puras, testadas em ronda3.test.ts. O servidor valida sempre.
 */

/**
 * Lista de Operações (Pagamentos / Recebimentos / Todos): como renderMovimentosTesouraria do legado (js/ui_tesouraria.js:403-430),
 * sem pesquisa aplicada a lista abre só com o registo mais recente; com pesquisa/filtros mostra todos os que coincidem.
 * Aqui com «Ver mais» (mais PASSO_VER_MAIS registos) e «Ver todos» (lista paginada no servidor).
 */
export const PASSO_VER_MAIS = 10;

export interface FiltrosOperacoes {
  pesquisa?: string;
  estado?: string;
  conta?: string;
  inicio?: unknown;
  fim?: unknown;
}

/** Há algum filtro aplicado? (o legado: _tesoFilterActive depois de «Buscar»). */
export function filtroAplicado(f: FiltrosOperacoes): boolean {
  return !!(f.pesquisa?.trim() || f.estado || f.conta || f.inicio || f.fim);
}

/** Abre em modo «mais recente» (só os `limite` registos mais recentes) quando não há filtro nem «Ver todos». */
export function emModoRecente(f: FiltrosOperacoes, verTodos: boolean): boolean {
  return !verTodos && !filtroAplicado(f);
}

/** Próximo limite de «Ver mais»: 1 → 10 → 20 → …, nunca acima do total conhecido. */
export function proximoLimite(limite: number, total?: number): number {
  const proximo = limite < PASSO_VER_MAIS ? PASSO_VER_MAIS : limite + PASSO_VER_MAIS;
  return total !== undefined && total > 0 ? Math.min(proximo, total) : proximo;
}

/** Texto do modo «mais recente»: «A mostrar o registo mais recente (1 de 37).» */
export function textoRecentes(limite: number, total: number | undefined): string {
  const n = total === undefined ? limite : Math.min(limite, total);
  const de = total === undefined ? '' : ` (${n} de ${total.toLocaleString('pt-PT')})`;
  return n <= 1 ? `A mostrar o registo mais recente${de}.` : `A mostrar os ${n} registos mais recentes${de}.`;
}

/**
 * Contas de tesouraria com restrição de prefixo (pedido do utilizador; ServicoConfigTesouraria::PREFIXOS): sobras de caixa só
 * em contas 68 e quebras só em 78. O servidor também envia `prefixos` em GET /tesouraria/configuracao/contas.
 */
export const PREFIXOS_CONTAS_TESOURARIA: Record<string, string[]> = { caixa_sobras: ['68'], caixa_quebras: ['78'] };

export function prefixosDaChave(chave: string, doServidor?: string[] | null): string[] | undefined {
  return doServidor?.length ? doServidor : PREFIXOS_CONTAS_TESOURARIA[chave];
}

/** A Folha de Caixa só abre sessões em contas de caixa (45). */
export const PREFIXOS_CAIXA = ['45'];

export function eContaCaixa(conta: string | null | undefined): boolean {
  return !!conta && PREFIXOS_CAIXA.some((p) => conta.trim().startsWith(p));
}
