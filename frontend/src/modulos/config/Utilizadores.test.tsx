import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { ErroApi } from '@/api/tipos';

/**
 * Configurações › Utilizadores: «Gravar» com «Acesso a todas as empresas» (o campo Empresas não é mostrado e não vem
 * nos valores) e reactivação de um utilizador eliminado (UTILIZADOR_ELIMINADO → confirmação → novo POST com reactivar:true).
 */
const obter = vi.fn();
const enviar = vi.fn();
const obterPagina = vi.fn();
vi.mock('@/api/cliente', async (original) => ({
  ...(await original<typeof import('@/api/cliente')>()),
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: (...a: unknown[]) => obterPagina(...a),
  descarregar: vi.fn(),
  http: { post: vi.fn() },
}));
const SESSAO = {
  pode: () => true,
  empresa: { id: 7, nome: 'Empresa Fictícia' },
  empresas: [{ id: 7, nome: 'Empresa Fictícia' }],
  utilizador: { id: 1, papel: 'ADMINISTRADOR' },
  escolherEmpresa: () => Promise.resolve(),
  menu: [],
};
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => SESSAO }));
vi.setConfig({ testTimeout: 120_000 });

const UTILIZADOR = {
  id: 5, nome_utilizador: 'rui', nome_completo: 'Rui Fictício', email: 'rui@exemplo.invalid', papel: 'UTILIZADOR',
  perfil: { id: 3, nome: 'Salários', acesso_total: false }, acesso_todas_empresas: true, ativo: true, credencial_por_migrar: false,
  ultimo_acesso_em: null, palavra_passe_alterada_em: null, empresas: [],
};

function envolver(ui: React.ReactNode) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={cliente}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>);
}

beforeEach(() => {
  window.localStorage.clear();
  obter.mockReset().mockImplementation((url: string) => {
    if (url === '/sistema/perfis') return Promise.resolve([{ id: 3, nome: 'Salários', acesso_total: false }]);
    return Promise.resolve([]);
  });
  obterPagina.mockReset().mockResolvedValue({ itens: [UTILIZADOR], paginacao: { pagina_atual: 1, por_pagina: 50, total: 1, ultima_pagina: 1 } });
  enviar.mockReset().mockResolvedValue({ dados: {}, mensagem: 'ok' });
});

describe('Utilizadores — Gravar e reactivação', () => {
  it('«Gravar» com acesso a todas as empresas envia o pedido (antes rebentava em v.empresas.map)', async () => {
    const { default: Utilizadores } = await import('./Utilizadores');
    envolver(<Utilizadores />);
    fireEvent.click(await screen.findByRole('button', { name: 'Editar' }, { timeout: 60_000 }));
    const dialogo = await screen.findByRole('dialog');
    await within(dialogo).findByText('Tem acesso a todas as empresas (actuais e futuras).');
    fireEvent.click(within(dialogo).getByRole('button', { name: 'Gravar' }));
    await waitFor(() => expect(enviar.mock.calls.filter((c) => c[0] === 'put' && c[1] === '/sistema/utilizadores/5')).toHaveLength(1));
    const corpo = enviar.mock.calls.find((c) => c[1] === '/sistema/utilizadores/5')![2] as Record<string, unknown>;
    expect(corpo).toMatchObject({ nome_utilizador: 'rui', acesso_todas_empresas: true });
    expect(Array.isArray(corpo.empresas)).toBe(true);
  });

  it('UTILIZADOR_ELIMINADO oferece reactivar e repete o POST com reactivar:true', async () => {
    enviar.mockImplementation((metodo: string, _url: string, dados: { reactivar?: boolean }) =>
      metodo === 'post' && !dados.reactivar
        ? Promise.reject(new ErroApi('Já existiu um utilizador com este nome (eliminado).', 422, 'UTILIZADOR_ELIMINADO'))
        : Promise.resolve({ dados: {}, mensagem: 'ok' }));
    const { default: Utilizadores } = await import('./Utilizadores');
    envolver(<Utilizadores />);
    fireEvent.click(await screen.findByRole('button', { name: /Novo utilizador/ }, { timeout: 60_000 }));
    const dialogo = await screen.findByRole('dialog');
    fireEvent.change(dialogo.querySelector('#nome_completo')!, { target: { value: 'Ana Maria Simão' } });
    await waitFor(() => expect((dialogo.querySelector('#nome_utilizador') as HTMLInputElement).value).toBe('ana.simao'));
    fireEvent.mouseDown(dialogo.querySelector('#perfil_utilizador_id')!);
    fireEvent.click(await screen.findByText('Salários', { selector: '.ant-select-item-option-content' }));
    fireEvent.click(within(dialogo).getByRole('button', { name: 'Gravar' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Reactivar' }));
    await waitFor(() => expect(enviar.mock.calls.filter((c) => c[0] === 'post')).toHaveLength(2));
    expect(enviar.mock.calls.filter((c) => c[0] === 'post')[1][2]).toMatchObject({ nome_utilizador: 'ana.simao', reactivar: true });
  });
});
