import { describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

vi.mock('@/api/cliente', () => ({
  definirAoExpirar: vi.fn(),
  obter: vi.fn(() => Promise.reject(new Error('sem sessão'))),
  enviar: vi.fn(() => Promise.resolve({ dados: null, mensagem: 'ok' })),
}));

import { SessaoProvider, useSessao } from './SessaoContexto';

function Botoes() {
  const { sair, largarEmpresa } = useSessao();
  return (
    <>
      <button onClick={() => void sair()}>sair</button>
      <button onClick={() => largarEmpresa()}>largar</button>
    </>
  );
}

function montar() {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <SessaoProvider>
        <Botoes />
      </SessaoProvider>
    </QueryClientProvider>,
  );
}

describe('sessão: terminar sessão e trocar de empresa voltam ao início', () => {
  it('terminar sessão limpa o endereço do ecrã onde se estava', async () => {
    window.history.replaceState(null, '', '/rh/colaboradores');
    montar();
    await act(async () => fireEvent.click(screen.getByText('sair')));
    expect(window.location.pathname).toBe('/');
  });

  it('largar a empresa (trocar empresa) volta ao início; no portal volta ao portal', () => {
    window.history.replaceState(null, '', '/contab/lancamentos?x=1');
    montar();
    fireEvent.click(screen.getByText('largar'));
    expect(window.location.pathname + window.location.search).toBe('/');

    window.history.replaceState(null, '', '/portal/ferias');
    fireEvent.click(screen.getByText('largar'));
    expect(window.location.pathname).toBe('/portal');
  });

  it('o link de redefinir a senha não é tocado', async () => {
    window.history.replaceState(null, '', '/redefinir-senha?token=abc');
    montar();
    await act(async () => fireEvent.click(screen.getByText('sair')));
    expect(window.location.pathname).toBe('/redefinir-senha');
  });
});
