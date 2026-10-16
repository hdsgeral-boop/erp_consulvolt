/** Caminho do portal autónomo (o login e a escolha de empresa voltam para aqui quando se entra por /portal). */
export const ROTA_PORTAL = '/portal';

/** O endereço actual é o do portal autónomo? (o login e a escolha de empresa não mudam o endereço). */
export const naRotaPortal = (caminho: string = typeof window === 'undefined' ? '' : window.location.pathname): boolean =>
  caminho === ROTA_PORTAL || caminho.startsWith(`${ROTA_PORTAL}/`);
