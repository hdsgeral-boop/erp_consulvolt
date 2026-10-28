import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ConversaSuporte } from './ConversaSuporte';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  descarregar: vi.fn(),
}));

const MENSAGENS = [
  { id: 1, autor_tipo: 'CLIENTE', autor_nome: 'Ana', mensagem: 'Não emite facturas.', criado_em: '2026-10-28T09:00:00Z', anexos: [{ id: 9, nome: 'ecra.png', tamanho_bytes: 2048, tipo_mime: 'image/png' }] },
  { id: 2, autor_tipo: 'PLATAFORMA', autor_nome: 'Suporte', mensagem: 'A analisar.', criado_em: '2026-10-28T10:00:00Z', anexos: [] },
];

function montar(props: Partial<{ agente: boolean; respostasRapidasBase: string }> = {}) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <ConversaSuporte base="/suporte/tickets" anexosBase="/suporte/anexos" ticketId={5} aoFechar={() => {}} {...props} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  obter.mockReset().mockImplementation((url: string) => {
    if (url === '/suporte/tickets/5/mensagens') return Promise.resolve(MENSAGENS);
    if (url === '/suporte/tickets/5/notas') return Promise.resolve([{ id: 1, autor_nome: 'Agente', nota: 'Cliente VIP.', criado_em: '2026-10-28T08:00:00Z' }]);
    if (url === '/suporte/respostas-rapidas') return Promise.resolve([{ id: 1, titulo: 'Pedir acesso remoto', texto: 'Pode autorizar o acesso remoto?' }]);
    return Promise.resolve(null);
  });
});

describe('ConversaSuporte', () => {
  it('mostra as mensagens e os anexos de cada uma, sem notas internas nem respostas rápidas do lado do cliente', async () => {
    montar();
    await screen.findByText('Não emite facturas.');
    expect(screen.getByText('A analisar.')).toBeInTheDocument();
    expect(screen.getByText(/ecra\.png/)).toBeInTheDocument();

    expect(screen.queryByText('Notas internas')).not.toBeInTheDocument();
  });

  it('do lado do agente mostra as notas internas (separador) e as respostas rápidas', async () => {
    montar({ agente: true, respostasRapidasBase: '/suporte/respostas-rapidas' });
    await screen.findByText('Não emite facturas.');

    expect(screen.getByText('Notas internas')).toBeInTheDocument();
    await waitFor(() => expect(obter).toHaveBeenCalledWith('/suporte/respostas-rapidas'));
  });
});
