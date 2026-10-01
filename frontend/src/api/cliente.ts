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

/** Resultado de uma descarga: o nome com que o ficheiro foi gravado e os cabeçalhos da resposta (ex.: avisos do SAF-T). */
export interface Descarga {
  nome: string;
  cabecalhos: Record<string, string>;
}

/**
 * GET de um ficheiro binário (blob) e descarga no navegador, com o mesmo tratamento de erros dos outros pedidos:
 * um 401 termina a sessão e, quando o servidor responde erro, lê-se o envelope JSON que vem dentro do blob
 * (mensagem, código e detalhes) para lançar um ErroApi. O nome vem do Content-Disposition ou, na falta dele, de `nomeFicheiro`.
 */
export async function descarregar(url: string, params: Record<string, unknown> | undefined, nomeFicheiro: string): Promise<Descarga> {
  // validateStatus aceita tudo para o corpo (blob) do erro não se perder no interceptor;
  // sem resposta (rede), o interceptor já converte em ErroApi.
  const r = await http.get<Blob>(url, { params: limpar(params), responseType: 'blob', validateStatus: () => true });
  if (r.status >= 400) {
    if (r.status === 401 && aoExpirar) aoExpirar();
    let mensagem = `Não foi possível obter o ficheiro (${r.status}).`;
    let codigo: string | undefined;
    let erros: Record<string, unknown> | undefined;
    try {
      const corpo = JSON.parse(await lerTexto(r.data)) as Partial<Envelope<unknown>>;
      mensagem = corpo.mensagem ?? mensagem;
      codigo = corpo.codigo;
      erros = corpo.erros as Record<string, unknown> | undefined;
    } catch {
      /* corpo não é JSON */
    }
    throw new ErroApi(mensagem, r.status, codigo, erros);
  }
  const cabecalhos: Record<string, string> = {};
  for (const [k, v] of Object.entries((r.headers ?? {}) as Record<string, unknown>)) {
    if (v !== undefined && v !== null) cabecalhos[k.toLowerCase()] = String(v);
  }
  const nome = nomeDoContentDisposition(cabecalhos['content-disposition'], nomeFicheiro);
  const ligacao = URL.createObjectURL(r.data);
  const a = document.createElement('a');
  a.href = ligacao;
  a.download = nome;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(ligacao);
  return { nome, cabecalhos };
}

/** Nome do ficheiro no cabeçalho Content-Disposition (filename*=UTF-8'' ou filename=). */
export function nomeDoContentDisposition(cabecalho: string | undefined | null, padrao: string): string {
  if (!cabecalho) return padrao;
  const utf = /filename\*=UTF-8''([^;]+)/i.exec(cabecalho);
  if (utf) return decodeURIComponent(utf[1].replace(/"/g, ''));
  const simples = /filename="?([^";]+)"?/i.exec(cabecalho);
  return simples ? simples[1] : padrao;
}

function lerTexto(blob: Blob): Promise<string> {
  if (typeof blob.text === 'function') return blob.text();
  return new Promise((resolve, reject) => {
    const leitor = new FileReader();
    leitor.onload = () => resolve(String(leitor.result));
    leitor.onerror = () => reject(leitor.error);
    leitor.readAsText(blob);
  });
}

/** Remove parâmetros vazios (o Laravel valida '' como valor). */
function limpar(params?: Record<string, unknown>): Record<string, unknown> | undefined {
  if (!params) return undefined;
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''));
}
