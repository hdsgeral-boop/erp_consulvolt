import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: () => Promise.resolve({
    itens: [{ id: 1, nome_completo: 'Ana Silva', nif: '500000001', estado: 'ACTIVO' }, { id: 2, nome_completo: 'Bruno Costa', nif: '500000002', estado: 'ACTIVO' }],
    paginacao: { ultima_pagina: 1 },
  }),
  descarregar: vi.fn(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null, utilizador: null, menu: [] }) }));

import Ferias from '../Ferias';

vi.setConfig({ testTimeout: 480_000 });

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  window.localStorage.clear();
  // preferências: o servidor devolve o que foi gravado (como o PUT /sistema/preferencias/interface/{nome})
  const prefs: { nome: string; valor: unknown }[] = [];
  obter.mockReset().mockImplementation((url: string) => {
    if (url.startsWith('/sistema/preferencias')) return Promise.resolve([...prefs]);
    if (url === '/rh/ferias') return Promise.resolve({ resumo: [], periodos: [] });
    if (url === '/rh/configuracao') return Promise.resolve({});
    return Promise.resolve([]);
  });
  enviar.mockReset().mockImplementation((_m: string, url: string, dados: { valor?: unknown }) => {
    if (url.startsWith('/sistema/preferencias/interface/')) {
      const nome = url.split('/').pop()!;
      prefs.splice(0, prefs.length, ...prefs.filter((p) => p.nome !== nome), { nome, valor: dados.valor });
    }
    return Promise.resolve({ dados: {}, mensagem: 'ok' });
  });
});

describe('Férias › «Marcar férias» (Grade | Linhas)', () => {
  it('cola dois períodos do Excel e grava um POST /rh/ferias por linha com as mesmas regras da grade', async () => {
    const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(<QueryClientProvider client={cliente}><MemoryRouter><Ferias /></MemoryRouter></QueryClientProvider>);
    // indicadores do antigo (.rhf-kpis), sem ícone
    expect(await screen.findByText('Sem férias planeadas')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Novo período/ }));
    expect(await screen.findByText('Marcar férias')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Linhas/ }));
    await screen.findByTestId('painel-linhas');
    fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
    fireEvent.change(await screen.findByLabelText('Linhas a colar'), { target: { value: '500000001\t03/08/2026\t14/08/2026\nBruno Costa\t01/09/2026\t05/09/2026\tAprovado' } });
    fireEvent.click(screen.getByRole('button', { name: 'Acrescentar linhas' }));
    await waitFor(() => expect(screen.getAllByTestId('linha-formulario')).toHaveLength(2));
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Registar' }));
    const posts = () => enviar.mock.calls.filter((c) => c[1] === '/rh/ferias');
    await waitFor(() => expect(posts()).toHaveLength(2));
    expect(posts()[0]).toEqual(['post', '/rh/ferias', { colaborador_id: 1, data_inicio: '2026-08-03', data_fim: '2026-08-14', estado: 'PLANEADO', direito: null, observacoes: null }]);
    expect(posts()[1][2]).toMatchObject({ colaborador_id: 2, data_inicio: '2026-09-01', data_fim: '2026-09-05', estado: 'APROVADO' });
  });
});
