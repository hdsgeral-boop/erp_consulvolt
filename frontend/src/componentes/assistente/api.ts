import { enviar, http, obter } from '@/api/cliente';
import type { Envelope } from '@/api/tipos';
import type { AccaoIA, ConversaIA, EstadoIA, MensagemIA, RespostaMensagemIA } from './tipos';

/** Cliente tipado das 9 rotas `/api/ia/*` do assistente de IA. Todas as rotas usam a empresa activa (X-Empresa-Id). */

export const chavesIA = {
  estado: ['ia', 'estado'] as const,
  conversas: ['ia', 'conversas'] as const,
  mensagens: (conversa: number | null) => ['ia', 'mensagens', conversa] as const,
  regras: ['ia', 'regras'] as const,
};

export const obterEstado = () => obter<EstadoIA>('/ia/estado');
export const obterRegras = () => obter<{ regras: string }>('/ia/regras');
export const guardarRegras = (regras: string) => enviar<{ regras: string }>('put', '/ia/regras', { regras });
export const listarConversas = () => obter<ConversaIA[]>('/ia/conversas');
export const listarMensagens = (conversa: number) => obter<MensagemIA[]>(`/ia/conversas/${conversa}/mensagens`);
export const renomearConversa = (conversa: number, titulo: string) => enviar('put', `/ia/conversas/${conversa}`, { titulo });
export const eliminarConversa = (conversa: number) => enviar('delete', `/ia/conversas/${conversa}`);

export interface PedidoMensagem {
  texto: string;
  conversa_id: number | null;
  modulo?: string;
  ecra?: string;
  anexos?: File[];
}

/**
 * POST /ia/mensagens (multipart/form-data: texto, conversa_id, modulo, ecra, anexos[]). Não há streaming: o servidor
 * corre o ciclo do agente (até 300 s) e devolve a conversa e as duas mensagens novas de uma vez.
 */
export async function enviarMensagem(p: PedidoMensagem): Promise<RespostaMensagemIA> {
  const corpo = new FormData();
  corpo.append('texto', p.texto);
  if (p.conversa_id !== null) corpo.append('conversa_id', String(p.conversa_id));
  if (p.modulo) corpo.append('modulo', p.modulo);
  if (p.ecra) corpo.append('ecra', p.ecra);
  for (const f of p.anexos ?? []) corpo.append('anexos[]', f, f.name);
  const r = await http.post<Envelope<RespostaMensagemIA>>('/ia/mensagens', corpo, { timeout: 300_000 });
  return r.data.dados;
}

/** POST /ia/accoes/{id}/decisao — regista o que o utilizador decidiu (o servidor devolve a acção actualizada). */
export const decidirAccao = (accao: number, estado: 'EXECUTADA' | 'REJEITADA' | 'FALHOU', resultado?: Record<string, unknown>) =>
  enviar<AccaoIA>('post', `/ia/accoes/${accao}/decisao`, resultado ? { estado, resultado } : { estado });
