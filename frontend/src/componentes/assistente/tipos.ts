/**
 * Contrato do assistente de IA (rotas `/api/ia/*`, empresa activa), conforme o controller da produção
 * (AssistenteChatController / ServicoAgenteIA). As respostas vêm no envelope habitual `{ sucesso, mensagem, dados }`.
 */

/** GET /ia/estado */
export interface EstadoIA {
  ativo: boolean;
  quota_tokens_mes: number | null;
  tokens_usados_mes: number;
}

/** GET /ia/conversas (as 200 mais recentes do utilizador na empresa activa) */
export interface ConversaIA {
  id: number;
  titulo: string;
  criado_em?: string;
  atualizado_em: string;
}

export type EstadoAccaoIA = 'PENDENTE' | 'EXECUTADA' | 'REJEITADA' | 'FALHOU';

/**
 * Acção proposta pela IA. `tipo` = 'navegar' (abrir um ecrã; `dados.ecra` traz o nome pedido e o `url` pode vir vazio)
 * ou uma escrita ('lancamento', 'terceiro', …) com `metodo` + `url` (relativo à API) + `dados`, só executada depois de
 * o utilizador confirmar. `estado` PENDENTE até à decisão.
 */
export interface AccaoIA {
  id: number;
  tipo: string;
  resumo: string;
  metodo: string;
  url: string;
  dados: Record<string, unknown>;
  estado: EstadoAccaoIA | string;
  resultado?: Record<string, unknown> | null;
}

export interface FonteIA {
  url: string;
  titulo?: string;
}

export interface MensagemIA {
  id: number;
  papel: 'utilizador' | 'assistente';
  texto: string;
  extra?: { accoes?: AccaoIA[]; fontes?: FonteIA[]; anexos?: unknown[]; ferramentas?: string[] } | null;
  criado_em?: string;
}

/** POST /ia/mensagens (multipart) */
export interface RespostaMensagemIA {
  conversa: ConversaIA;
  mensagens: MensagemIA[];
}

/** Códigos de erro do assistente (campo `codigo` do envelope de erro). */
export const CODIGOS_IA = {
  inactiva: 'IA_INATIVA',
  quota: 'IA_QUOTA_ATINGIDA',
  anexos: 'IA_ANEXOS',
  fornecedorMudou: 'IA_FORNECEDOR_MUDOU',
  accaoInexistente: 'IA_ACCAO_INEXISTENTE',
  accaoDecidida: 'IA_ACCAO_DECIDIDA',
  conversaInexistente: 'IA_CONVERSA_INEXISTENTE',
  argumentos: 'IA_ARGUMENTOS',
} as const;

// ───────────── Plataforma › Inteligência artificial (`/api/plataforma/ia/*`) ─────────────

export type IdFornecedorIA = 'anthropic' | 'openai' | 'gemini';

/** GET /plataforma/ia/fornecedores. A chave NUNCA vem: só `configurado` e os últimos 4 caracteres (`chave_final`). */
export interface FornecedorIA {
  id: number | null;
  fornecedor: IdFornecedorIA;
  nome: string;
  configurado: boolean;
  chave_final: string | null;
  modelos: string[];
  modelo_padrao: string | null;
  preco_entrada_mtok: number;
  preco_saida_mtok: number;
  ativo: boolean;
  testado_em: string | null;
  ultimo_erro: string | null;
}

/** GET /plataforma/ia/atribuicoes (uma linha por cliente). */
export interface AtribuicaoIA {
  tenant_id: number;
  cliente: string;
  slug: string;
  estado: string;
  fornecedor: string | null;
  modelo: string | null;
  ativo: boolean;
  quota_tokens_mes: number | null;
  pesquisa_web: boolean;
  accoes_erp: boolean;
  tokens_mes: number;
  custo_mes_usd: number;
}

/** GET /plataforma/ia/consumo */
export interface ConsumoIA {
  meses: string[];
  tokens_entrada: number[];
  tokens_saida: number[];
  custo_usd: number[];
  pedidos: number[];
  clientes_mes: { cliente: string; tokens: number; custo_usd: number; pedidos: number; pesquisas: number }[];
}
