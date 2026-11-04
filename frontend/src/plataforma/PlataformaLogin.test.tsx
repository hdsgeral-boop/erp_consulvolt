import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { PlataformaLogin } from './PlataformaLogin';

vi.setConfig({ testTimeout: 120_000 });
const entrar = vi.fn();
const obter = vi.fn();
const post = vi.fn();
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ entrar, sair: vi.fn(), utilizador: null }) }));
vi.mock('@/api/cliente', async (original) => ({
  ...(await original<typeof import('@/api/cliente')>()),
  obter: (...a: unknown[]) => obter(...a),
  http: { post: (...a: unknown[]) => post(...a) },
}));

function montar() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter><PlataformaLogin /></MemoryRouter>
    </QueryClientProvider>,
  );
}

function estado(precisa: boolean, exige = false) {
  obter.mockImplementation((url: string) => {
    if (url === '/plataforma-inicial/estado') return Promise.resolve({ precisa_criar_admin: precisa, exige_segredo: exige });
    return Promise.resolve({ logotipo: null, nome_sistema: null });
  });
}

describe('PlataformaLogin — primeiro acesso', () => {
  beforeEach(() => { entrar.mockReset(); obter.mockReset(); post.mockReset(); });

  it('com administrador já criado mostra só o login', async () => {
    estado(false);
    montar();
    expect(await screen.findByRole('button', { name: 'Entrar' })).toBeInTheDocument();
    expect(screen.queryByText('Criar administrador')).toBeNull();
  });

  it('sem administrador pede nome, utilizador e e-mail (sem palavra-passe) e não inicia sessão', async () => {
    estado(true);
    post.mockResolvedValue({ data: { mensagem: 'Administrador criado. Verifique o e-mail.' } });
    montar();
    fireEvent.change(await screen.findByLabelText('Nome completo'), { target: { value: 'Ana Fictícia' } });
    fireEvent.change(screen.getByLabelText('Utilizador'), { target: { value: 'ana' } });
    fireEvent.change(screen.getByLabelText('E-mail'), { target: { value: 'ana@exemplo.invalid' } });
    expect(screen.queryByLabelText(/Palavra-passe/)).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Criar administrador' }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    expect(post.mock.calls[0][0]).toBe('/plataforma-inicial/criar-admin');
    expect(post.mock.calls[0][1]).toEqual({ nome_completo: 'Ana Fictícia', nome_utilizador: 'ana', email: 'ana@exemplo.invalid' });
    expect(post.mock.calls[0][2]).toBeUndefined();
    expect(await screen.findByText('Administrador criado. Verifique o e-mail.')).toBeInTheDocument();
    expect(entrar).not.toHaveBeenCalled();
    // depois de criado volta o formulário de entrada
    expect(await screen.findByRole('button', { name: 'Entrar' })).toBeInTheDocument();
  });

  it('com código de instalação exigido envia-o em X-Setup-Token e não no corpo', async () => {
    estado(true, true);
    post.mockResolvedValue({ data: {} });
    montar();
    fireEvent.change(await screen.findByLabelText('Nome completo'), { target: { value: 'Ana' } });
    fireEvent.change(screen.getByLabelText('Utilizador'), { target: { value: 'ana' } });
    fireEvent.change(screen.getByLabelText('E-mail'), { target: { value: 'ana@exemplo.invalid' } });
    fireEvent.change(screen.getByLabelText('Código de instalação'), { target: { value: 'segredo-123' } });
    fireEvent.click(screen.getByRole('button', { name: 'Criar administrador' }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    expect(post.mock.calls[0][1]).not.toHaveProperty('segredo');
    expect(post.mock.calls[0][2]).toEqual({ headers: { 'X-Setup-Token': 'segredo-123' } });
  });
});
