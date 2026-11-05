import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { EscolherEmpresa } from './EscolherEmpresa';

const obterPagina = vi.fn();
vi.mock('@/api/cliente', () => ({ obter: vi.fn(() => Promise.resolve({})), obterPagina: (...a: unknown[]) => obterPagina(...a) }));
vi.mock('@/portal/rota', () => ({ naRotaPortal: () => false }));

const escolherEmpresa = vi.fn(() => Promise.resolve());
let utilizador: Record<string, unknown> = { id: 1, nome_utilizador: 'admin', papel: 'SUPER_ADMINISTRADOR', conta_root: true };
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ empresas: [], escolherEmpresa, sair: vi.fn(), utilizador, ultimaEmpresaId: null }),
}));

function montar() {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <EscolherEmpresa />
    </QueryClientProvider>,
  );
}

const pagina = (itens: unknown[]) => ({ itens, paginacao: { pagina_atual: 1, por_pagina: 10, total: itens.length, ultima_pagina: 1 } });

describe('escolher empresa — conta root', () => {
  beforeEach(() => {
    obterPagina.mockReset();
    escolherEmpresa.mockClear();
    utilizador = { id: 1, nome_utilizador: 'admin', papel: 'SUPER_ADMINISTRADOR', conta_root: true };
  });

  it('sem pertencer a nenhum cliente, escolhe entre as empresas de todos eles', async () => {
    obterPagina.mockResolvedValue(pagina([
      { id: 11, nome: 'Alfa, Lda', estado: 'ATIVO', cliente_id: 2, cliente_nome: 'Cliente A' },
      { id: 22, nome: 'Beta, SA', estado: 'ATIVO', cliente_id: 3, cliente_nome: 'Cliente B' },
    ]));
    montar();
    expect(await screen.findByText('Alfa, Lda')).toBeInTheDocument();
    expect(screen.getByText('Cliente: Cliente B')).toBeInTheDocument();
    expect(screen.queryByText(/Não tem acesso a nenhuma empresa/)).toBeNull();
    expect(obterPagina).toHaveBeenCalledWith('/plataforma/root/empresas', expect.objectContaining({ pagina: 1, por_pagina: 25 }));

    fireEvent.click(screen.getAllByRole('button', { name: 'Entrar' })[1]);
    await waitFor(() => expect(escolherEmpresa).toHaveBeenCalledWith(22));
  });

  it('a pesquisa vai ao servidor (nome da empresa ou do cliente)', async () => {
    obterPagina.mockResolvedValue(pagina([]));
    montar();
    await screen.findByText('Nenhuma empresa encontrada.');
    fireEvent.change(screen.getByLabelText('Pesquisar empresa de qualquer cliente'), { target: { value: 'Cliente B' } });
    await waitFor(() => expect(obterPagina).toHaveBeenCalledWith('/plataforma/root/empresas', expect.objectContaining({ pesquisa: 'Cliente B', pagina: 1 })), { timeout: 2000 });
  });

  it('empresa inactiva não se pode abrir', async () => {
    obterPagina.mockResolvedValue(pagina([{ id: 5, nome: 'Parada, Lda', estado: 'INATIVO', cliente_id: 2, cliente_nome: 'Cliente A' }]));
    montar();
    await screen.findByText('Parada, Lda');
    expect(screen.getByRole('button', { name: 'Entrar' })).toBeDisabled();
  });

  it('quem não é root nunca vê o selector nem pede empresas de outros clientes', () => {
    utilizador = { id: 2, nome_utilizador: 'admin', papel: 'SUPER_ADMINISTRADOR', conta_root: false };
    montar();
    expect(screen.queryByTestId('seletor-root')).toBeNull();
    expect(obterPagina).not.toHaveBeenCalled();
    expect(screen.getByText(/Não tem acesso a nenhuma empresa/)).toBeInTheDocument();
  });
});
