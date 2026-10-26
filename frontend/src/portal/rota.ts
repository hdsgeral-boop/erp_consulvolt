import type { ModuloMenu } from '@/api/tipos';

/** Caminho do portal autónomo (o login e a escolha de empresa voltam para aqui quando se entra por /portal). */
export const ROTA_PORTAL = '/portal';

/** O endereço actual é o do portal autónomo? (o login e a escolha de empresa não mudam o endereço). */
export const naRotaPortal = (caminho: string = typeof window === 'undefined' ? '' : window.location.pathname): boolean =>
  caminho === ROTA_PORTAL || caminho.startsWith(`${ROTA_PORTAL}/`);

/**
 * O endereço é o subdomínio externo do portal (portal.<base>)? Espelha `DominioPedido::PORTAL` do servidor: o
 * primeiro segmento do host é literalmente «portal» — sem variável de ambiente nova, como o backend. A barreira real
 * é do lado do servidor (`ExigirAreaPortal`); isto só decide o que a SPA mostra neste endereço.
 */
export const ehHostPortal = (hostname: string = typeof window === 'undefined' ? '' : window.location.hostname): boolean =>
  hostname.split('.')[0] === 'portal';

/** Ecrãs do menu que pertencem ao próprio portal (não contam como acesso ao ERP). */
export const ECRAS_DO_PORTAL: ReadonlySet<string> = new Set(['rh_portal']);

/**
 * O utilizador tem ecrãs do ERP além do portal? (ADR-071: quem só tem o portal vê só o portal; quem tem o ERP tem no
 * portal o botão «Voltar ao ERP»). Decide-se pelo menu que o servidor devolve (GET /sistema/menu, já filtrado pelas permissões).
 */
export const temAcessoAoErp = (menu: readonly ModuloMenu[] | undefined | null): boolean =>
  (menu ?? []).some((m) => m.ecras.some((e) => !ECRAS_DO_PORTAL.has(e.id)));
