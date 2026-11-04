import { ecraDaPlataforma } from '@/layout/menuLegado';
import { ENDERECO_ECRA_PLATAFORMA, PAPEIS_CONSOLA_PLATAFORMA } from '@/plataforma/enderecosPlataforma';

export interface Redireccao {
  para: string;
  /** aviso mostrado ao utilizador (omitido quando a mudança é só de endereço) */
  aviso?: string;
}

/**
 * Endereços de ecrãs que mudaram de sítio ou que deixaram de existir no ERP do cliente (só UX; a guarda de permissões do
 * destino e o servidor continuam a mandar — um redireccionamento nunca abre um ecrã que o utilizador não possa ver):
 *  - /m/contab/ia_assistente → /m/config/ia_assistente (o Assistente IA passou de Contabilidade para Configurações);
 *  - /m/config/plataforma_* → o ecrã equivalente da consola /admin, se o utilizador é da plataforma; senão, o Início.
 */
export function redireccaoEcra(modulo: string | undefined, ecra: string | undefined, papel: string | null | undefined): Redireccao | null {
  if (!ecra) return null;
  if (modulo === 'contab' && ecra === 'ia_assistente') return { para: '/m/config/ia_assistente' };
  if (ecraDaPlataforma(ecra)) {
    const destino = ENDERECO_ECRA_PLATAFORMA[ecra];
    if (destino && papel && PAPEIS_CONSOLA_PLATAFORMA.includes(papel)) return { para: destino };
    return { para: '/inicio', aviso: 'A administração da plataforma não faz parte do ERP da empresa. Foi reencaminhado para o Início.' };
  }
  return null;
}
