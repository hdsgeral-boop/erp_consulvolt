import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import PlataformaClientes from './PlataformaClientes';

const obter = vi.fn();
const enviar = vi.fn();
vi.setConfig({ testTimeout: 120_000 });
vi.mock('@/api/cliente', async (original) => ({
  ...(await original<typeof import('@/api/cliente')>()),
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
}));
vi.mock('@/utilitarios/erros', () => ({ notificarErro: vi.fn() }));

const CLIENTES = [
  { id: 2, nome: 'Alfa Lda', slug: 'alfa', estado: 'ATIVO', plano: 'profissional', empresas: 1, utilizadores: 2, criado_em: '2026-10-01T10:00:00Z' },
  { id: 3, nome: 'Beta SA', slug: 'beta', estado: 'SUSPENSO', plano: null, empresas: 0, utilizadores: 1, criado_em: '2026-10-02T10:00:00Z' },
];

function montar(entrada = '/admin/clientes') {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <MemoryRouter initialEntries={[entrada]}><PlataformaClientes /></MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  obter.mockReset().mockImplementation((url: string) => {
    if (url === '/plataforma/clientes') return Promise.resolve(CLIENTES);
    if (url === '/plataforma/planos') return Promise.resolve({ planos: [{ codigo: 'profissional', nome: 'Profissional' }] });
    if (url === '/plataforma/clientes/2') return Promise.resolve({ nome: 'Alfa Lda', plano: 'profissional', utilizadores: 2, empresas: [] });
    if (url === '/plataforma/clientes/2/utilizadores') return Promise.resolve([{ id: 9, nome_utilizador: 'rui', nome_completo: 'Rui', email: 'rui@exemplo.invalid', papel: 'UTILIZADOR', ativo: true }]);
    return Promise.resolve([]);
  });
  enviar.mockReset().mockResolvedValue({ dados: {}, mensagem: 'ok' });
});

describe('Plataforma › Clientes', () => {
  it('mostra a coluna Plano (nome do plano) e filtra por pesquisa e por estado', async () => {
    montar();
    await screen.findByText('Alfa Lda');
    expect(screen.getByText('Profissional')).toBeInTheDocument();
    expect(screen.getByText('Beta SA')).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Procurar cliente'), { target: { value: 'alf' } });
    await waitFor(() => expect(screen.queryByText('Beta SA')).toBeNull());
    expect(screen.getByText('Alfa Lda')).toBeInTheDocument();
  });

  it('?q= preenche a pesquisa e ?novo=1 abre o formulário de novo cliente', async () => {
    montar('/admin/clientes?q=beta&novo=1');
    expect(await screen.findByRole('dialog')).toBeInTheDocument();
    expect(screen.getByText('Utilizador administrador')).toBeInTheDocument();
    expect((screen.getByLabelText('Procurar cliente') as HTMLInputElement).value).toBe('beta');
  });

  it('o detalhe tem o separador Utilizadores: editar grava com PUT e repor palavra-passe usa o servidor', async () => {
    montar();
    await screen.findByText('Alfa Lda');
    fireEvent.click(screen.getAllByRole('button', { name: /Detalhe/ })[0]);
    fireEvent.click(await screen.findByRole('tab', { name: /Utilizadores/ }));
    const linha = (await screen.findByText('@rui · rui@exemplo.invalid')).closest('tr')!;
    fireEvent.click(within(linha).getByRole('switch'));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('put', '/plataforma/clientes/2/utilizadores/9', { ativo: false }));
    fireEvent.click(within(linha).getByRole('button', { name: 'Repor palavra-passe' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Repor' }));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('post', '/plataforma/clientes/2/utilizadores/9/redefinir', {}));
  });
});
