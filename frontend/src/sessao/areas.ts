import type { Utilizador } from '@/api/tipos';

/**
 * Áreas do SaaS que a conta pode abrir. O servidor é a autoridade (ExigirPlataforma/ExigirSuporte exigem o tenant da
 * plataforma): um Super Administrador de um CLIENTE tem o mesmo papel mas leva 403 em todo o /api/plataforma/*, por
 * isso o papel sozinho não decide. `area_plataforma`/`area_suporte` vêm de GET /autenticacao/eu; um servidor mais
 * antigo que não os envia cai de volta no critério do papel.
 *
 * Conta ROOT global (ADR-084, `conta_root` vindo do servidor): não pertence a nenhum cliente e abre tudo. A flag só
 * serve para mostrar ou esconder (UX) — a barreira real é sempre o servidor, que nunca aceita `conta_root` do cliente.
 */
type Conta = Pick<Utilizador, 'papel'> & { area_plataforma?: unknown; area_suporte?: unknown; conta_root?: unknown };

/** A conta é a conta root global? Só `true` literal vindo do servidor conta (qualquer outro valor é «não»). */
export function ehContaRoot(u: Conta | null | undefined): boolean {
  return !!u && u.conta_root === true;
}

export function podeConsolaPlataforma(u: Conta | null | undefined): boolean {
  if (!u) return false;
  if (ehContaRoot(u)) return true;
  const papel = u.papel === 'SUPER_ADMINISTRADOR' || u.papel === 'OPERADOR_PLATAFORMA';
  return papel && u.area_plataforma !== false;
}

export function podeBackofficeSuporte(u: Conta | null | undefined): boolean {
  if (!u) return false;
  if (ehContaRoot(u)) return true;
  const papel = u.papel === 'SUPER_ADMINISTRADOR' || u.papel === 'AGENTE_SUPORTE';
  return papel && u.area_suporte !== false;
}
