import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import SuportePainel from './SuportePainel';

vi.setConfig({ testTimeout: 120_000 });
const obter = vi.fn();
vi.mock('@/api/cliente', async (original) => ({
  ...(await original<typeof import('@/api/cliente')>()),
  obter: (...a: unknown[]) => obter(...a),
}));
vi.mock('@/modulos/config/comum/ConversaSuporte', () => ({ ConversaSuporte: () => null }));

describe('SuportePainel — as categorias do agente', () => {
  beforeEach(() => {
    obter.mockReset();
    obter.mockImplementation((url: string) => Promise.resolve(
      url === '/suporte/eu'
        ? { todas: false, categorias: ['PLANO', 'FACTURACAO'], grupos: [{ id: 'financeiro', rotulo: 'Financeiro (plano, facturação)', categorias: ['PLANO', 'FACTURACAO'] }], especializacao: 'Agente de Suporte Financeiro' }
        : [],
    ));
  });

  it('mostra «As suas categorias: …» com a especialização', async () => {
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter><SuportePainel /></MemoryRouter>
      </QueryClientProvider>,
    );
    const bloco = await screen.findByTestId('minhas-categorias');
    expect(bloco.textContent).toContain('As suas categorias:');
    expect(bloco.textContent).toContain('Financeiro');
    expect(bloco.textContent).toContain('Agente de Suporte Financeiro');
  });
});
