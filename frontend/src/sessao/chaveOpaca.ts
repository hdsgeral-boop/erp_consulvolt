/**
 * Chaves de localStorage sem dados pessoais (ADR-082): em vez de `erp.aspecto.<id do utilizador>`, a chave leva um
 * identificador opaco — hash curto do id com um sal fixo da aplicação — para que copiar o perfil do navegador não diga
 * quem usa o sistema nem com que empresas trabalha. Não é segredo (o id é pequeno e o sal está no código: quem tiver
 * os dois consegue testar ids), apenas deixa de haver nomes, e-mails ou ids legíveis nas chaves. O que fica guardado
 * por estas chaves são só preferências de interface (ver docs/arquitetura/SESSOES_E_COOKIES.md).
 */
const SAL = 'erp-ui-v1';

/** cyrb53: hash de 53 bits, síncrono e sem dependências (suficiente para chaves; não é criptográfico). */
function cyrb53(texto: string): number {
  let h1 = 0xdeadbeef;
  let h2 = 0x41c6ce57;
  for (let i = 0; i < texto.length; i++) {
    const c = texto.charCodeAt(i);
    h1 = Math.imul(h1 ^ c, 2654435761);
    h2 = Math.imul(h2 ^ c, 1597334677);
  }
  h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909);
  h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909);
  return 4294967296 * (2097151 & h2) + (h1 >>> 0);
}

/** Identificador opaco de um utilizador/empresa/valor para usar numa chave. `null`/`undefined` → «anonimo». */
export function idOpaco(valor: number | string | null | undefined): string {
  if (valor === null || valor === undefined || valor === '') return 'anonimo';
  return `u${cyrb53(`${SAL}:${valor}`).toString(36)}`;
}

/** Hash curto de um texto (ex.: marcar «este aviso já foi visto nesta sessão» sem guardar o valor da sessão). */
export function hashCurto(texto: string): string {
  return cyrb53(`${SAL}:h:${texto}`).toString(36);
}
