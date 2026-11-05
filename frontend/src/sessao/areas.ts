import type { Utilizador } from '@/api/tipos';

/**
 * Áreas do SaaS que a conta pode abrir. O servidor é a autoridade (ExigirPlataforma/ExigirSuporte exigem o tenant da
 * plataforma): um Super Administrador de um CLIENTE tem o mesmo papel mas leva 403 em todo o /api/plataforma/*, por
 * isso o papel sozinho não decide. `area_plataforma`/`area_suporte` vêm de GET /autenticacao/eu; um servidor mais
 * antigo que não os envia cai de volta no critério do papel.
 */
type Conta = Pick<Utilizador, 'papel'> & { area_plataforma?: unknown; area_suporte?: unknown };

export function podeConsolaPlataforma(u: Conta | null | undefined): boolean {
  if (!u) return false;
  const papel = u.papel === 'SUPER_ADMINISTRADOR' || u.papel === 'OPERADOR_PLATAFORMA';
  return papel && u.area_plataforma !== false;
}

export function podeBackofficeSuporte(u: Conta | null | undefined): boolean {
  if (!u) return false;
  const papel = u.papel === 'SUPER_ADMINISTRADOR' || u.papel === 'AGENTE_SUPORTE';
  return papel && u.area_suporte !== false;
}
