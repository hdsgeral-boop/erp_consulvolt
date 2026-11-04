import { describe, expect, it, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MinhasSessoes } from './MinhasSessoes';

const enviarMock = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: vi.fn(() =>
    Promise.resolve([
      { id: 1, dispositivo: 'Chrome em Windows', ip_aproximado: '41.1.2.x', criada_em: '2026-11-19T09:00:00+00:00', ultima_actividade_em: '2026-11-19T09:10:00+00:00', esta_sessao: true },
      { id: 2, dispositivo: 'Safari em iOS', ip_aproximado: '102.9.8.x', criada_em: '2026-11-19T08:00:00+00:00', ultima_actividade_em: '2026-11-19T08:30:00+00:00', esta_sessao: false },
    ]),
  ),
  enviar: (...a: unknown[]) => enviarMock(...a),
}));

function montar() {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <MinhasSessoes />
    </QueryClientProvider>,
  );
}

describe('As minhas sessões', { timeout: 30_000 }, () => {
  beforeEach(() => enviarMock.mockReset());

  it('lista os dispositivos, marca «Esta sessão» e só oferece terminar as outras', async () => {
    montar();
    expect(await screen.findByText('Chrome em Windows', undefined, { timeout: 10_000 })).toBeInTheDocument();
    expect(screen.getByText('Safari em iOS')).toBeInTheDocument();
    expect(screen.getByText('Esta sessão')).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Terminar' })).toHaveLength(1);
  });

  it('termina uma sessão alheia depois de confirmar', async () => {
    enviarMock.mockResolvedValue({ dados: null, mensagem: 'Sessão terminada.' });
    montar();
    fireEvent.click(await screen.findByRole('button', { name: 'Terminar' }, { timeout: 10_000 }));
    // o botão de confirmação do Popconfirm é o último «Terminar» que aparece
    await waitFor(() => expect(screen.getAllByRole('button', { name: 'Terminar' }).length).toBeGreaterThan(1), { timeout: 10_000 });
    const botoes = screen.getAllByRole('button', { name: 'Terminar' });
    fireEvent.click(botoes[botoes.length - 1]);
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('delete', '/autenticacao/sessoes/2'), { timeout: 10_000 });
  });
});
