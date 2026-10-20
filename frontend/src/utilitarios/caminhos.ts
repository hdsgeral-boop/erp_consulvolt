/**
 * Endereços do ERP: `/{modulo}/{ecra}/…` (ex.: `/config/config_licenca`, `/vendas/faturacao`). Não há prefixo.
 * Este é o único sítio que lê um endereço por posição; os restantes usam `lerCaminhoEcra`.
 */

/** Primeiros segmentos que NÃO são módulos do ERP (rotas próprias da aplicação). */
const RESERVADOS = new Set(['portal', 'dados-pessoais', 'plataforma', 'redefinir-senha', 'build', 'api', 'up']);

/** Módulo e ecrã de um endereço do ERP, ou null se o endereço não for de um ecrã (Início, portal, dados pessoais…). */
export function lerCaminhoEcra(caminho: string): { modulo: string; ecra: string } | null {
  const [, modulo, ecra] = caminho.split('#')[0].split('?')[0].split('/');
  if (!modulo || !ecra || RESERVADOS.has(modulo)) return null;
  return { modulo, ecra };
}

/**
 * Endereços antigos, com o prefixo `/m/` (favoritos guardados, ligações já enviadas): devolve o endereço novo.
 * Endereços que já são novos passam sem alteração.
 */
export function normalizarCaminhoLegado(caminho: string): string {
  return caminho.replace(/^\/m\/(?=[^/]+\/[^/])/, '/');
}
