import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { Entrar } from './Entrar';

let portal = false;
vi.mock('@/portal/rota', () => ({ naRotaPortal: () => portal }));

const entrar = vi.fn();
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ entrar, motivoSaida: null }) }));
vi.mock('@/api/cliente', () => ({ obter: vi.fn(() => Promise.resolve({ logotipo: null })) }));

function montar(rota = '/entrar') {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <MemoryRouter initialEntries={[rota]}>
        <Routes>
          <Route path="/entrar" element={<Entrar />} />
          <Route path="/portal" element={<Entrar />} />
          <Route path="/admin" element={<p>Consola da Plataforma</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const submeter = async () => {
  fireEvent.change(screen.getByLabelText('Utilizador'), { target: { value: 'admin' } });
  fireEvent.change(screen.getByLabelText('Palavra-passe'), { target: { value: 'SenhaForte123' } });
  fireEvent.click(screen.getByRole('button', { name: 'Entrar' }));
};

describe('Entrar', () => {
  beforeEach(() => {
    entrar.mockReset();
    portal = false;
  });

  it('super-administrador fora do portal: login explícito leva à consola da Plataforma', async () => {
    entrar.mockResolvedValue({ papel: 'SUPER_ADMINISTRADOR' });
    montar('/entrar');
    await submeter();
    expect(await screen.findByText('Consola da Plataforma')).toBeInTheDocument();
  });

  it('super-administrador pelo /portal: nunca sai do portal (ADR-071), mesmo sendo super-administrador', async () => {
    portal = true;
    entrar.mockResolvedValue({ papel: 'SUPER_ADMINISTRADOR' });
    montar('/portal');
    await submeter();
    await waitFor(() => expect(entrar).toHaveBeenCalled());
    expect(screen.queryByText('Consola da Plataforma')).toBeNull();
  });

  it('título e texto do cartão mudam no /portal', () => {
    portal = true;
    montar('/portal');
    expect(screen.getByText('Portal do Colaborador')).toBeInTheDocument();
    expect(screen.getByText('Entre com o seu utilizador para aceder ao portal')).toBeInTheDocument();
  });
});
