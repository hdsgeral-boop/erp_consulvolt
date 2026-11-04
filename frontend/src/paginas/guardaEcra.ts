import type { ModuloMenu } from '@/api/tipos';

/**
 * Ecrãs do catálogo que só transportam permissões e nunca são um ecrã de menu (espelha ECRAS_SEM_MENU do servidor):
 * `ia_chat` é o chat flutuante do assistente. Um URL digitado para eles volta ao Início, com aviso.
 */
export const ECRAS_SEM_ROTA: readonly string[] = ['ia_chat'];

export type DecisaoEcra = 'permitido' | 'sem_rota' | 'negado';

/**
 * Guarda de /m/:modulo/:ecra — só UX; o servidor valida sempre cada pedido. Um ecrã só abre se estiver no menu
 * devolvido pelo servidor (já filtrado por perfil, plano e holding) sob esse módulo.
 */
export function decidirEcra(menu: ModuloMenu[], modulo: string | undefined, ecra: string | undefined, pode: (...chaves: string[]) => boolean): DecisaoEcra {
  if (!ecra || ECRAS_SEM_ROTA.includes(ecra)) return 'sem_rota';
  if (menu.find((m) => m.id === modulo)?.ecras.some((e) => e.id === ecra)) return 'permitido';
  if (ecra === 'notificacoes_centro' && pode('notificacoes_centro_view', 'notificacoes_centro_ver', 'notificacoes_view')) return 'permitido';
  return 'negado';
}
