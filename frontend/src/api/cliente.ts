import axios, { AxiosError, type AxiosRequestConfig } from 'axios';
import { ErroApi, type Envelope, type Pagina } from './tipos';
import { armazenamento } from '@/sessao/armazenamento';

/**
 * Cliente HTTP da API (/api). Acrescenta o token Bearer e o X-Empresa-Id da sessão, desembrulha o envelope
 * e converte qualquer erro em ErroApi (mensagem em português vinda do servidor, código e detalhes).
 */
export const http = axios.create({ baseURL: '/api', headers: { Accept: 'application/json' }, timeout: 120_000 });

let aoExpirar: (() => void) | null = null;
/** A sessão regista aqui o que fazer quando o servidor responde 401 (sessão expirada ou inválida). */
export function definirAoExpirar(funcao: () => void): void {
  aoExpirar = funcao;
}

http.interceptors.request.use((config) => {
  const token = armazenamento.token();
  const empresa = armazenamento.empresaId();
  if (token) config.headers.set('Authorization', `Bearer ${token}`);
  if (empresa && !config.headers.has('X-Empresa-Id')) config.headers.set('X-Empresa-Id', String(empresa));
  return config;
});

http.interceptors.response.use(
  (r) => r,
  (e: AxiosError<Partial<Envelope<unknown>>>) => {
    const estado = e.response?.status ?? 0;
    const corpo = e.response?.data;
    if (estado === 401 && aoExpirar && !e.config?.url?.includes('autenticacao/entrar')) aoExpirar();
    const mensagem =
      corpo?.mensagem ??
      (estado === 0 ? 'Sem ligação ao servidor. Verifique a rede e tente novamente.' : `Erro inesperado (${estado}).`);
    return Promise.reject(new ErroApi(mensagem, estado, corpo?.codigo, corpo?.erros as Record<string, unknown> | undefined));
  },
);

export async function obter<T>(url: string, params?: Record<string, unknown>, config?: AxiosRequestConfig): Promise<T> {
  const r = await http.get<Envelope<T>>(url, { params: limpar(params), ...config });
  return r.data.dados;
}

export async function obterPagina<T>(url: string, params?: Record<string, unknown>): Promise<Pagina<T>> {
  const r = await http.get<Envelope<T[]>>(url, { params: limpar(params) });
  return {
    itens: r.data.dados ?? [],
    paginacao: r.data.metadados.paginacao ?? { pagina_atual: 1, por_pagina: r.data.dados?.length ?? 0, total: r.data.dados?.length ?? 0, ultima_pagina: 1 },
  };
}

export async function enviar<T>(metodo: 'post' | 'put' | 'delete', url: string, dados?: unknown): Promise<{ dados: T; mensagem: string }> {
  const r = await http.request<Envelope<T>>({ method: metodo, url, data: dados });
  return { dados: r.data.dados, mensagem: r.data.mensagem };
}

/** Remove parâmetros vazios (o Laravel valida '' como valor). */
function limpar(params?: Record<string, unknown>): Record<string, unknown> | undefined {
  if (!params) return undefined;
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''));
}
