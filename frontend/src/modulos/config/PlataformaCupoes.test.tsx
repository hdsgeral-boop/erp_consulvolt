import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, waitFor } from '@testing-library/react';
import PlataformaCupoes from './PlataformaCupoes';

vi.setConfig({ testTimeout: 120_000 });
const obter = vi.fn();
vi.mock('@/api/cliente', async (original) => ({
  ...(await original<typeof import('@/api/cliente')>()),
  obter: (...a: unknown[]) => obter(...a),
}));

describe('PlataformaCupoes — a consola /admin não tem empresa activa', () => {
  beforeEach(() => {
    obter.mockReset();
    obter.mockImplementation((url: string) => Promise.resolve(url === '/plataforma/planos' ? { planos: [{ id: 1, codigo: 'basico', nome: 'Básico' }] } : []));
  });

  it('só pede endpoints da plataforma (nunca /licenca/*, que exige X-Empresa-Id e dava 422)', async () => {
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <PlataformaCupoes />
      </QueryClientProvider>,
    );
    await waitFor(() => expect(obter).toHaveBeenCalledWith('/plataforma/planos'));
    expect(obter).toHaveBeenCalledWith('/plataforma/cupoes');
    expect(obter.mock.calls.map((c) => String(c[0])).filter((u) => u.startsWith('/licenca'))).toEqual([]);
  });
});
