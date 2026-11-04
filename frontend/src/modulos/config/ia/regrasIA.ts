import type { AtribuicaoIA, ConsumoIA, FornecedorIA } from '@/componentes/assistente/tipos';

/** Regras puras do ecrã Plataforma › Inteligência artificial (sem React). */

export const QUOTA_MINIMA = 1000;

/**
 * Corpo do PUT de um fornecedor. A chave é só de ESCRITA: vai apenas quando o administrador a digitou (e é
 * aparada); em branco não vai, e o servidor mantém a actual. Nunca se reenvia nem se mostra a chave guardada.
 */
export function corpoFornecedor(valores: Record<string, unknown>): Record<string, unknown> {
  const { chave, ...resto } = valores;
  const nova = typeof chave === 'string' ? chave.trim() : '';
  return nova ? { ...resto, chave: nova } : resto;
}

/** Texto de estado do cartão: nunca revela nada da chave além de existir. */
export function estadoDaChave(f: Pick<FornecedorIA, 'configurado' | 'ativo'>): { texto: string; cor: 'green' | 'default' } {
  if (!f.configurado) return { texto: 'Sem chave', cor: 'default' };
  return f.ativo ? { texto: 'Chave configurada · activo', cor: 'green' } : { texto: 'Chave configurada · inactivo', cor: 'default' };
}

/** Fornecedores com chave configurada e activos: só estes podem ser atribuídos a clientes. */
export function fornecedoresAtribuiveis(fornecedores: FornecedorIA[]): FornecedorIA[] {
  return fornecedores.filter((f) => f.configurado && f.ativo);
}

/** Uma atribuição só pode ser gravada com fornecedor, modelo do fornecedor e quota (se existir) acima do mínimo. */
export function problemaAtribuicao(a: Pick<AtribuicaoIA, 'fornecedor' | 'modelo' | 'quota_tokens_mes'>, fornecedores: FornecedorIA[]): string | null {
  if (!a.fornecedor) return 'Escolha o fornecedor.';
  const f = fornecedores.find((x) => x.fornecedor === a.fornecedor);
  if (!f || !f.configurado) return 'O fornecedor escolhido ainda não tem chave configurada.';
  if (!a.modelo) return 'Escolha o modelo.';
  if (f.modelos.length > 0 && !f.modelos.includes(a.modelo)) return 'O modelo não está na lista do fornecedor.';
  if (a.quota_tokens_mes !== null && a.quota_tokens_mes < QUOTA_MINIMA) return `A quota mensal mínima é de ${QUOTA_MINIMA} tokens.`;
  return null;
}

export interface TotaisConsumo {
  tokens: number;
  custoUsd: number;
  pedidos: number;
  pesquisas: number;
}

export function totaisConsumo(c: Pick<ConsumoIA, 'clientes_mes'> | undefined): TotaisConsumo {
  const linhas = c?.clientes_mes ?? [];
  return {
    tokens: linhas.reduce((t, x) => t + (x.tokens || 0), 0),
    custoUsd: linhas.reduce((t, x) => t + (x.custo_usd || 0), 0),
    pedidos: linhas.reduce((t, x) => t + (x.pedidos || 0), 0),
    pesquisas: linhas.reduce((t, x) => t + (x.pesquisas || 0), 0),
  };
}

/** Cliente com consumo próximo ou acima da quota (≥ 80 %). */
export function nivelQuota(a: Pick<AtribuicaoIA, 'quota_tokens_mes' | 'tokens_mes'>): 'sem_limite' | 'normal' | 'alerta' | 'esgotada' {
  if (!a.quota_tokens_mes) return 'sem_limite';
  const p = a.tokens_mes / a.quota_tokens_mes;
  return p >= 1 ? 'esgotada' : p >= 0.8 ? 'alerta' : 'normal';
}
