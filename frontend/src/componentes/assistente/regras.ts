import type { ModuloMenu } from '@/api/tipos';
import type { AccaoIA, EstadoIA } from './tipos';

/** Regras puras do assistente de IA (sem React): quota, anexos, ecrã actual, segurança das acções propostas. */

export const MAX_ANEXOS = 5;
export const MAX_BYTES_ANEXO = 10 * 1024 * 1024;
export const EXTENSOES_ANEXO = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'xlsx', 'xls', 'csv', 'txt', 'md', 'json', 'xml'];
export const MAX_REGRAS = 10000;

export type Disponibilidade = 'a_verificar' | 'indisponivel' | 'quota_esgotada' | 'disponivel';

/** A quota mensal de tokens (null = sem limite) já foi atingida? */
export function quotaEsgotada(estado: Pick<EstadoIA, 'quota_tokens_mes' | 'tokens_usados_mes'> | undefined | null): boolean {
  if (!estado || estado.quota_tokens_mes === null || estado.quota_tokens_mes === undefined) return false;
  return estado.tokens_usados_mes >= estado.quota_tokens_mes;
}

/** Percentagem da quota usada (0–100), ou null quando não há limite. */
export function percentagemQuota(estado: Pick<EstadoIA, 'quota_tokens_mes' | 'tokens_usados_mes'> | undefined | null): number | null {
  if (!estado || !estado.quota_tokens_mes) return null;
  return Math.min(100, Math.round((estado.tokens_usados_mes / estado.quota_tokens_mes) * 100));
}

/** Pode enviar mensagens? `ativo` tem de ser verdadeiro (qualquer outra forma de resposta conta como inactivo). */
export function disponibilidade(estado: Partial<EstadoIA> | undefined | null, aCarregar: boolean): Disponibilidade {
  if (aCarregar && !estado) return 'a_verificar';
  if (!estado || estado.ativo !== true) return 'indisponivel';
  return quotaEsgotada(estado as EstadoIA) ? 'quota_esgotada' : 'disponivel';
}

/** Módulo/ecrã do caminho actual (`/m/{modulo}/{ecra}`), enviado ao servidor como contexto. */
export function ecraDoCaminho(pathname: string): { modulo: string; ecra: string } | null {
  const [, prefixo, modulo, ecra] = pathname.split('/');
  if (prefixo !== 'm' || !modulo || !ecra) return null;
  return { modulo, ecra };
}

export interface ProblemaAnexo {
  nome: string;
  motivo: string;
}

const extensaoDe = (nome: string) => (nome.includes('.') ? nome.split('.').pop()!.toLowerCase() : '');

/** Valida os anexos escolhidos (até 5, 10 MB cada, extensões do servidor). Devolve só os problemas. */
export function validarAnexos(ficheiros: { name: string; size: number }[]): ProblemaAnexo[] {
  const problemas: ProblemaAnexo[] = [];
  if (ficheiros.length > MAX_ANEXOS) problemas.push({ nome: '', motivo: `No máximo ${MAX_ANEXOS} anexos por mensagem.` });
  for (const f of ficheiros) {
    if (!EXTENSOES_ANEXO.includes(extensaoDe(f.name))) problemas.push({ nome: f.name, motivo: 'Use PDF, imagem, Excel, CSV ou texto.' });
    else if (f.size > MAX_BYTES_ANEXO) problemas.push({ nome: f.name, motivo: 'Cada anexo pode ter até 10 MB.' });
  }
  return problemas;
}

/**
 * Caminho interno seguro: começa por «/», sem esquema (http:, javascript:), sem «//» (protocolo relativo), sem «\»,
 * sem «..» e sem caracteres de controlo. Aplica-se tanto a ecrãs do ERP como a rotas da API propostas pela IA.
 */
export function caminhoInternoSeguro(url: unknown): url is string {
  if (typeof url !== 'string' || url === '' || url.length > 500) return false;
  if (!url.startsWith('/') || url.startsWith('//')) return false;
  // eslint-disable-next-line no-control-regex
  if (/[\\\u0000-\u001f]/.test(url)) return false;
  if (/(^|\/)\.\.(\/|$|\?)/.test(url)) return false;
  return !/^\/+[a-z][a-z0-9+.-]*:/i.test(url);
}

const MUTAVEIS = ['POST', 'PUT', 'DELETE'] as const;
export type MetodoMutavel = (typeof MUTAVEIS)[number];

const normalizar = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();

/** Procura no menu (já filtrado pelas permissões do utilizador) o ecrã pedido pela IA, por id ou por nome. */
export function ecraPorNome(menu: ModuloMenu[], termo: string): { modulo: string; ecra: string; nome: string } | null {
  const t = normalizar(termo);
  if (!t) return null;
  const todos = menu.flatMap((m) => m.ecras.map((e) => ({ modulo: m.id, ecra: e.id, nome: e.nome })));
  const exacto = todos.find((x) => normalizar(x.ecra) === t || normalizar(x.nome) === t);
  if (exacto) return exacto;
  const parcial = todos.filter((x) => normalizar(x.nome).includes(t) || t.includes(normalizar(x.nome)));
  // só aceita um resultado inequívoco: ambíguo = não navega (o utilizador pesquisa no menu)
  return parcial.length === 1 ? parcial[0] : null;
}

export type AccaoResolvida =
  | { tipo: 'navegar'; caminho: string; rotulo: string }
  | { tipo: 'pedido'; metodo: MetodoMutavel; url: string; dados: Record<string, unknown> }
  | { tipo: 'invalida'; motivo: string };

/**
 * Converte a acção proposta num passo concreto — mas nunca o executa. Regras:
 *  - 'navegar': só para ecrãs que o utilizador vê no menu (`/m/{modulo}/{ecra}`) ou para um caminho `/m/…` seguro;
 *  - escritas: só POST/PUT/DELETE para um caminho interno seguro da API (nunca URL absoluto, nunca fora de `/`);
 *  - qualquer outra coisa (GET fora da navegação, método desconhecido) é inválida.
 */
export function resolverAccao(accao: Pick<AccaoIA, 'tipo' | 'metodo' | 'url' | 'dados'>, menu: ModuloMenu[]): AccaoResolvida {
  if (accao.tipo === 'navegar') {
    const alvo = ecraPorNome(menu, String(accao.dados?.ecra ?? ''));
    if (alvo) return { tipo: 'navegar', caminho: `/m/${alvo.modulo}/${alvo.ecra}`, rotulo: alvo.nome };
    if (caminhoInternoSeguro(accao.url) && accao.url.startsWith('/m/')) return { tipo: 'navegar', caminho: accao.url, rotulo: accao.url };
    return { tipo: 'invalida', motivo: 'Não encontrei esse ecrã no seu menu. Procure-o na pesquisa do topo.' };
  }
  const metodo = String(accao.metodo ?? '').toUpperCase();
  if (!(MUTAVEIS as readonly string[]).includes(metodo)) return { tipo: 'invalida', motivo: `Operação não suportada (${metodo || 'sem método'}).` };
  if (!caminhoInternoSeguro(accao.url) || accao.url.startsWith('/ia/')) return { tipo: 'invalida', motivo: 'Endereço da operação não permitido.' };
  const dados = accao.dados && typeof accao.dados === 'object' && !Array.isArray(accao.dados) ? accao.dados : {};
  return { tipo: 'pedido', metodo: metodo as MetodoMutavel, url: accao.url, dados };
}
