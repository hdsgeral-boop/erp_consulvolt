/** Envelope padronizado da API Laravel (RespostaApi): { sucesso, mensagem, dados, metadados, codigo?, erros? }. */
export interface Envelope<T> {
  sucesso: boolean;
  mensagem: string;
  codigo?: string;
  /** Só nos 5xx: referência curta que liga a resposta ao log do servidor. */
  referencia?: string;
  dados: T;
  erros?: Record<string, unknown>;
  metadados: { empresa_id: number | null; executado_em: string; paginacao?: Paginacao } & Record<string, unknown>;
}

export interface Paginacao {
  pagina_atual: number;
  por_pagina: number;
  total: number;
  ultima_pagina: number;
}

export interface Pagina<T> {
  itens: T[];
  paginacao: Paginacao;
}

export interface Empresa {
  id: number;
  nome: string;
  nif?: string | null;
  e_consolidacao?: boolean;
  /** Há logótipo (o próprio só vem no detalhe GET /sistema/empresas/{id}). */
  tem_logotipo?: boolean;
  [chave: string]: unknown;
}

export interface Utilizador {
  id: number;
  nome_utilizador: string;
  nome_completo?: string | null;
  papel?: string | null;
  /** Password gerada pelo sistema (criação, reposição pelo admin ou recuperação): troca obrigatória no próximo acesso. */
  deve_alterar_password?: boolean;
  /** Conta root global (ADR-084): só o servidor a define; o frontend nunca a envia nem a decide. */
  conta_root?: boolean;
  [chave: string]: unknown;
}

export interface EcraMenu {
  id: string;
  nome: string;
  vistas: string[];
  pai: string | null;
  /** Funções (tarefas do catálogo) que o utilizador pode fazer neste ecrã — índice da pesquisa «Procurar». */
  tarefas?: { chave: string; rotulo: string }[];
}

export interface ModuloMenu {
  id: string;
  nome: string;
  ecras: EcraMenu[];
}

/**
 * Erro normalizado da API: `message` é SEMPRE segura para mostrar (já passou por utilitarios/mensagensErro), código estável,
 * detalhes por campo (`erros`) e, nos 5xx, a `referencia` que o suporte cruza com o log do servidor. `estado` 0 = sem resposta
 * do servidor (rede, timeout ou pedido cancelado).
 */
export class ErroApi extends Error {
  constructor(
    mensagem: string,
    public readonly estado: number,
    public readonly codigo?: string,
    public readonly erros?: Record<string, unknown>,
    public readonly referencia?: string,
  ) {
    super(mensagem);
    this.name = 'ErroApi';
  }
}
