/** Envelope padronizado da API Laravel (RespostaApi): { sucesso, mensagem, dados, metadados, codigo?, erros? }. */
export interface Envelope<T> {
  sucesso: boolean;
  mensagem: string;
  codigo?: string;
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
  [chave: string]: unknown;
}

export interface Utilizador {
  id: number;
  nome_utilizador: string;
  nome_completo?: string | null;
  papel?: string | null;
  [chave: string]: unknown;
}

export interface EcraMenu {
  id: string;
  nome: string;
  vistas: string[];
  pai: string | null;
}

export interface ModuloMenu {
  id: string;
  nome: string;
  ecras: EcraMenu[];
}

/** Erro normalizado da API: mensagem para o utilizador, código estável e detalhes (chave `erros`). */
export class ErroApi extends Error {
  constructor(
    mensagem: string,
    public readonly estado: number,
    public readonly codigo?: string,
    public readonly erros?: Record<string, unknown>,
  ) {
    super(mensagem);
    this.name = 'ErroApi';
  }
}
