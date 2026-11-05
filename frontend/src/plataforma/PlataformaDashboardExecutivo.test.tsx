import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { ErroApi } from '@/api/tipos';
import { PlataformaDashboardExecutivo } from './PlataformaDashboardExecutivo';

vi.setConfig({ testTimeout: 120_000 });
const obter = vi.fn();
vi.mock('@/api/cliente', async (original) => ({
  ...(await original<typeof import('@/api/cliente')>()),
  obter: (...a: unknown[]) => obter(...a),
}));

function montar() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter><PlataformaDashboardExecutivo /></MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('PlataformaDashboardExecutivo — falha ao carregar o painel', () => {
  beforeEach(() => { obter.mockReset(); });

  it('mostra o motivo dado pelo servidor (403: conta que não é da plataforma) e não só «erro»', async () => {
    obter.mockImplementation(() => Promise.reject(new ErroApi('Esta área é reservada à administração da plataforma.', 403, 'AREA_RESERVADA')));
    montar();
    expect(await screen.findByText('Não foi possível carregar o painel da plataforma.')).toBeInTheDocument();
    expect(screen.getByText('Esta área é reservada à administração da plataforma.')).toBeInTheDocument();
    expect(obter).toHaveBeenCalledWith('/plataforma/painel');
  });

  it('erro sem corpo (rede) continua a mostrar o aviso genérico sem inventar motivo', async () => {
    obter.mockImplementation(() => Promise.reject(new Error('Network Error')));
    montar();
    expect(await screen.findByText('Não foi possível carregar o painel da plataforma.')).toBeInTheDocument();
    expect(screen.queryByText('Network Error')).toBeNull();
  });
});
